# The reference system, module by module

What a complete fiscal module of this kind contains, taken from the working
reference project (Laravel, multi-tenant SaaS for workshops; audited
2026-09-25). Use it as a **scope checklist** when the user says "build/extend a
fiscal tool": every row below is something a production system ended up needing.
Where a rule is explained in depth, the row points to the reference file.

Legend: **[doc]** = explained in this skill; **[here]** = explained in this file
only. Class names are from the reference project — rename freely.

## 1. Emission pipeline (per note)

`Criar → Iniciar → Job → Aplicar`, each a separate service so the same code
serves the screen, the work-order orchestrator and the scheduled reconciler:

| Step | Class | What it guarantees |
|---|---|---|
| Create draft | `CriarNotaFiscalService` | all pre-emission blocks (below), model selection, CFOP/CST resolution, rounding, ISS rate from company config (payload > company config > legal 5 % ceiling), returns `RASCUNHO` |
| Start | `IniciarEmissaoNotaService` | inside a transaction with the row locked (`lockForUpdate`): skip if already AUTORIZADA/PROCESSANDO, allocate the number, set PROCESSANDO, snapshot provider. **Job dispatch stays outside the transaction** (`queue.after_commit=false`) so the worker never sees a row that isn't PROCESSANDO yet [doc: pitfall #17] |
| Emit | `EmitirNotaFiscalJob` | `tries=1`, timeout 180 s; loads the tenant context, calls the engine, on any throwable marks REJEITADA with the message (never leaves PROCESSANDO) |
| Apply | `AplicarResultadoNotaService` | one transaction + row lock persists status/key/protocol/XML/number/`contingencia_desde`/`emitido_em`; side effects (overage billing, authorization e-mail) fire **once**, only on the real transition to AUTORIZADA and only in PRODUCAO — read from the live environment, not from the value captured at dispatch [doc: pitfall #17] |

Status machine: `RASCUNHO → PROCESSANDO → AUTORIZADA | REJEITADA | ERRO |
CONTINGENCIA`, then `CANCELADA`. CONTINGENCIA (EPEC) returns to AUTORIZADA on
retransmission or ends in CANCELADA.

## 2. Model selection and pre-emission blocks

Sale of goods becomes **NFC-e only if all hold**: customer is a person
(CPF, 11 digits) **and** same UF as the issuer **and** the company switch
`modelo_venda_padrao = NFC-e` **and** the user did not force NF-e. Otherwise
NF-e. Legal-entity customers are always NF-e (ICMS credit); interstate is
always NF-e (NFC-e is `idDest=1`, SEFAZ rejects interstate). Services → NFS-e.

Blocks raised as a typed exception (`EmissaoBloqueadaException`), shown to the
user, never defaulted [doc: SKILL.md "never guess"]: company without UF or
regime; customer without UF; product with `tributacao_icms` null; product with
`origem` null (0 is valid — use `=== null`); product `ST` without CEST (else
SEFAZ `cStat=806`). DIFAL (`ICMSUFDest`) for regime normal + interstate to a
non-contributor is blocked locally instead of guessing the state rate [doc:
`assets/nfephp-sped-nfe-reference.md`].

Resolvers (each a small pure class, one source of truth) [doc:
`domain-concepts.md`]: `CfopSaidaResolver` (5102/5405/6102/6404),
`CfopConsumidorResolver` (5102/6108), `TributacaoIcmsSaidaResolver`
(CSOSN 102/500, CST 00/60, regime via `CrtResolver`), `CrtResolver` (Simples
or MEI ⇒ CRT 1), `IndicadorIeDestinatarioResolver` (indIEDest),
`CodigoTributacaoNacionalResolver` (14.01 → 140101),
`InformacoesComplementaresResolver` (additional-information text). Every one
**throws on an input outside its covered table**.

## 3. Work order → notes (orchestrator)

`EmissaoOrquestradorService`: a mixed work order issues **two independent
notes** — an NF-e/NFC-e with the parts and an NFS-e with the services.
Independent means one blocked note (product without NCM) does not stop the
other; the result lists what left and what didn't (`avisos`). Parts without a
linked product are left out (no NCM) with a warning. Parts-only → NF-e only;
services-only → NFS-e only. **Idempotent per category**: a category with a note
already AUTORIZADA/CONTINGENCIA/PROCESSANDO is skipped; REJEITADA/ERRO/
RASCUNHO/CANCELADA do not count, so calling again generates the missing one.
The work order's discount is split between the two notes proportionally to each
subtotal (only invoiceable items count, and the discount is clamped so no note
goes negative).

## 4. Scheduled jobs (scheduler container)

| Command | Cadence | Purpose |
|---|---|---|
| `nfe:reconciliar-processando` | every 15 min, `withoutOverlapping` | notes stuck in PROCESSANDO (older than 10 min) are re-queried at the provider and applied through the same `Aplicar` service [doc: Two-Path Status Verification] |
| `nfe:reconciliar-contingencia` | hourly | retransmits NF-e in EPEC contingency; sets AUTORIZADA, or CANCELADA if it was cancelled elsewhere; **alerts when ≤ 2 days remain of the 7-day EPEC deadline** (`PrazoContingencia`) |
| `nfe:verificar-notas-recebidas` | hourly | see §5 |
| `backup:executar` | daily 03:00 | encrypted DB dump, `pre-deploy` suffix on deploys |
| `cobrancas:gerar`, `alertas:verificar`, `oficina:recalcular-status-clientes` | daily | SaaS billing/alerts (outside fiscal scope) |

## 5. Received notes and purchase entry

- **Notes issued to the company's CNPJ** (supplier purchases): hourly job +
  manual "Notas Recebidas" screen, both through `VerificarNotasTerceiroService`.
  NFePHP uses DistDFe with a stored **NSU checkpoint**; Focus has its own
  mapper (`FocusNfeRecebidaMapper`). **`notas_terceiro_notificadas` is the source
  of truth** for "what is pending", never one live query (a manual live query
  advanced the shared NSU and hid already-alerted notes — real bug). Mark a key
  as known *immediately* inside the loop: the same key can arrive twice in one
  batch (`resNFe` summary, then the full `procNFe`). Alert only for notes both
  new and not yet posted; provider failure raises (never a silent 0).
- **Purchase entry (`EntradaNf`)**: parse an XML or fetch by access key →
  preview (matches items to products, proposes cost/markup, flags
  `fiscal_pendente` when NCM or ICMS treatment is missing) → post: creates/updates
  stock and records the note (`NotaEntrada`). `ConciliarFiscalNotaEntradaJob`
  re-queries an already-posted note and updates **only the fiscal fields** of
  linked products (never stock, never creates products) and marks the note
  `fiscal_conferida_em` when every item is complete.

## 6. Product fiscal data

Fields per product: `ncm` (8 digits), `cest` (7, Convênio ICMS 142/2018),
`origem` (0–8), `tributacao_icms` (`NORMAL`/`ST`), `cfop`, `cst_csosn`,
`codigo_barras` (GTIN, or the literal `SEM GTIN`), plus `fiscal_fonte` (XML or
manual).
- `ValidadorCamposFiscais`: malformed → **`null`, never the raw value** (a null
  shows in the "pending" screen; garbage passes as filled).
- `ClassificacaoIcms`: CST/CSOSN → ST/NORMAL with **three states** (unknown
  code → `null` → pending). The CST table is unstable (SINIEF 39/2023 created
  codes, 20/2024 revoked) — never `else NORMAL`.
- `PoliticaConflitoFiscal`: XML vs existing value → `PREENCHER` (empty field),
  `NADA`, or `DIVERGENCIA`. **Divergence never overwrites**; it becomes a record
  for a human to resolve (`resolverDivergencia`). `0` is not "empty".
- Default fiscal data per category (`categoria_padrao_fiscal`), applied when the
  product has none; the reference shop's categories are used as a grab-bag, so a
  per-category default can be wrong — leave it empty unless the user confirms.
- Screens/endpoints: pending list, mark as reviewed, resolve divergence, export
  products + fiscal data as JSON / XML / XLSX / PDF (`ExportacaoProdutosFiscal`).

## 7. Friendly rejection messages

`RejeicaoSefazTradutor` adds a plain-Portuguese explanation to an error message
of the form `cStat=<n>: <xMotivo>` **only for codes whose meaning was confirmed**
(883 GTIN, 204 duplicate/already authorized, 215/225 XML structure, 999 generic
SEFAZ instability, 217 unknown to SEFAZ, 558 contingency registration, 632 too
old to query, 806 ST without CEST). The technical message is always kept; an
unknown code gets no explanation. Grow the map one confirmed real rejection at a
time [doc: `assets/cstat-table.md`].

## 8. SaaS layer

- **Engine per tenant** (Spedy | Focus | NFEPHP) with a SaaS-wide default; vendor
  engines need a registered issuer (`RegistrarEmissorService`, `emissores_fiscais`
  per company × engine × environment, encrypted token); NFEPHP needs the A1
  certificate (encrypted, validated on upload by `CertificadoValidator`) and the
  "activate issuing" step [doc: `nfse-nacional-direct.md`, `engines.md`].
- **Overage billing**: notes never block at the plan limit; each authorized
  production note above the monthly limit creates a charge (idempotent per note:
  unique partial index `cobrancas(nota_fiscal_id, tipo)`).
- **Authorization e-mail** carries the PDF and XML (`montarAnexosEmail`, never
  throws — a render failure sends without attachment).
- Per-company switches: `ambiente_fiscal`, `calculo_tributario_modo`
  (`MANUAL` | `AUTOMATICO_PROVEDOR` = Spedy computes taxes via `/v1/orders`; Focus
  refuses), `modelo_venda_padrao`, ISS rate, series and counters (NF-e, NFC-e,
  DPS, legacy NF).

## 9. Documents and screens

History screen (filters, status pills, reason modal with the friendly
translation, PDF/XML download, bulk ZIP with PDF+XML, cancel with justification
15–255 chars, retransmit a contingency note, delete **homologação/draft notes
only**); issue screen (from a work order or free); numbering **inutilização**
endpoint (UI in the company screen); PDF per model — DANFSe clone, DANFE from the
authorized XML, 80 mm NFC-e cupom [doc: `assets/pdf-layouts/`]; downloads named
`NFSe-`/`NFe-`/`NFCe-<n>` [doc: pitfall #24].
**Not implemented in the reference project:** carta de correção (CC-e),
MDF-e/CT-e, Spedy `/orders` mode for corporate text, DIFAL calculation
(blocked instead), retry-with-next-number for Spedy/Focus on `cStat=539`.

## 10. Known risks worth designing around

- The history screen may **delete homologação notes**; SEFAZ keeps their numbers
  forever, and the counter is shared across environments → number drift
  (`cStat=539`) [doc: pitfall #22]. Prefer soft-delete/never reuse, or keep the
  counter per environment.
- The environment is a single per-company flag with no audit trail — a real
  production note was issued during a homologação session [doc: pitfall #25].
- DPS/NF-e counters shared across environments interleave homologação and
  production numbers.
