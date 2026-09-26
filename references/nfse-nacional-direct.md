# NFS-e Nacional emitted directly (no vendor) — the "NFEPHP" engine's NFS-e

**Correction to earlier text in this skill.** `engines.md` says NFePHP has no
NFS-e package. True for the *sped-nfe family* (`sped-nfe`, `sped-cte`,
`sped-mdfe`) — but a project can (and the reference project does) issue the
**national NFS-e directly** with a *different, independent* library:
**`nfse-nacional/nfse-php`** (Composer constraint used: `^1.21@beta` — it was a
beta release line in 2026-09; re-check maturity before adopting). In that
project the engine called `NFEPHP` is really "self-hosted, certificate A1, no
vendor": **NF-e/NFC-e → `nfephp-org/sped-nfe`; NFS-e → `nfse-nacional/nfse-php`**
(the SEFIN Nacional / ADN web service). Same certificate, different library,
different protocol (REST + JSON envelope, not SOAP).

This works only where the município is on the national standard (mandatory for
municípios since 2026-01-01; for ME/EPP of Simples Nacional issuing is mandatory
via the national system from 2026-11-01 — see `other-documents.md`).

## How the engine is chosen (multi-tenant)

Per-company override (`oficinas.provedor_fiscal` ∈ SPEDY | FOCUS | NFEPHP); if
empty, the SaaS-wide default (`provedor_fiscal_padrao`, falls back to SPEDY). The
`FiscalProvider` interface routes by document model: NF-e/NFC-e → `MotorNfe` /
`MotorNfce` (sped-nfe), NFS-e → `MotorNfse` (nfse-php).

## Flow (synchronous)

1. Certificate A1 is stored encrypted; the engine decrypts it to a **temporary
   file for the duration of the call only** and deletes it (also when the
   callback throws).
2. Build the **DPS** (Declaração de Prestação de Serviço) from the note data.
   Numbering: own counter (`nDPS`) + `serie`; the DPS `Id` is `"DPS"` + cMunEmit(7)
   + tpInsc(1) + CNPJ(14) + série(5) + nDPS(15) = 45 chars (`IdGenerator`).
   In the reference project this counter is **shared across homologação and
   produção** (observed: homologation and production DPS numbers interleave —
   gaps were accepted by the ADN).
3. The library signs and POSTs it to the SEFIN Nacional. Homologação is called
   **"produção restrita"**: observed host
   `sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse`.
4. Response = the authorized `NFSe` XML (`infNFSe`, `cStat` 100). Store: the
   **50-digit key** (not the `Id`, see pitfall #21), `nNFSe`, the XML, status.
5. Render the PDF locally (`assets/pdf-layouts/danfse/`) — the government's own
   DANFSe-generation API is being shut down (the library documents it for
   2026-07-01; a call on 2026-09-25 returned 404).

## DPS mapping and the rejections that shaped it (all real, from production)

| Field | Rule | Real rejection if wrong |
|---|---|---|
| `tpAmb` | 1 produção / 2 homologação | — |
| `tpEmit` | 1 = prestador is the emitter | — |
| `prest` | **CNPJ only.** Do NOT send `IM` unless the company has a record in the CNC NFS-e (a different registry from the municipal IM; no reliable way to know) | `E0120` |
| `prest` address | Do NOT send when `tpEmit=1` | `E0128` |
| `prest/regTrib/opSimpNac` | **Its own scale**: 1 Não optante, 2 MEI, 3 ME/EPP — NOT the CRT scale (1/3) of NF-e. Derive "is Simples" from the shared resolver, then map | wrong regime on the document |
| `regApTribSN` | 1 = federal + municipal by Simples Nacional | — |
| `cServ/cTribNac` | **6 digits** = item(2)+subitem(2)+desdobro(2). Classic LC 116 "14.01" → `140101`. Keep an explicit mapping table; throw on unmapped codes | `E1235` (schema) |
| `cServ/cTribMun` | exactly 3 digits and a *municipal* table — omit unless you have a confirmed one | `E1235` |
| `xDescServ` | free text (no character restriction) | — |
| `infoCompl/xInfComp` | "additional information": Latin-1 pattern, use ≤255 chars, sanitize (pitfall #20) | `E1235` |
| `valores/trib/tribMun/tribISSQN` | 1 = operação tributável | — |
| `tpRetISSQN` | **1 = Não retido**, 2 = retido pelo tomador, 3 = pelo intermediário (a brief once had it inverted) | wrong retention |
| `pAliq` | **Omit for Simples + not retained** — the ADN computes it | `E0625` |
| `totTrib` | ME/EPP: `pTotTribSN` (the library only emits it when truthy — send `0.001`, which it formats as `0.00`); other regimes: `indTotTrib=0`. `indTotTrib` is forbidden for ME/EPP | `E0712` |

## Query and cancel

- Query with the **50-digit key**. A cancelled NFS-e still reads as authorized in
  the status field: decide "cancelled" from the **cancellation event**
  (`eventos/101101/1`), not from `cStat`.
- Cancel = register event **`101101`**: `chNFSe` (50 digits), `CNPJAutor`,
  `tpAmb`, `e101101{ xDesc = "Cancelamento de NFS-e" (fixed by the XSD),
  cMotivo, xMotivo }`. `cMotivo` from `TSCodJustCanc`: 1 Erro na emissão,
  2 Serviço não prestado, 9 Outros — classify the free text by keyword
  (normalize accents first) and **default to 9**, never invent 1 or 2.
  `xMotivo` must be 15–255 characters (pitfall #24).

## Testing without the network

`MotorNfse::montarDps()` is pure (no I/O): unit-test the DPS DTO fields, build
the XML with the library's `DpsXmlBuilder`, validate against the official XSD
(`audit-checklist.md` Check 8 — note the lookahead-regex and `cNBS` gotchas), and
compare with the other engines' payloads (Check 10). A live emission in
homologação, passing the environment explicitly, is the final proof.
