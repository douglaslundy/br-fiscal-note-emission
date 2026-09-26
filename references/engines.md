# The three engines, compared

All three can emit NF-e, NFS-e, and NFC-e — the three document types this
skill focuses on. Their real coverage differs beyond that (see the table at
the end of this file if the project needs CT-e/MDF-e/other DF-e types — see
`other-documents.md`). They also differ in who actually talks to the
government, how much tax domain knowledge you need to bring yourself, and
what failure modes you have to design around.

## Focus NFe (REST vendor)

- **Auth**: HTTP Basic with an API token — separate tokens for sandbox
  (homologação) and production.
- **Document coverage beyond the core three**: Focus NFe's own product
  pages (as of this research pass) also list CT-e, MDF-e, NFCom, and DC-e —
  broader than just NF-e/NFS-e/NFC-e. If the project needs one of those,
  confirm current support and pricing directly on focusnfe.com.br rather
  than assuming parity with the NF-e/NFS-e/NFC-e integration already built —
  each product page documents its own request/response shape.
- **Request shape**: one `POST` per document type
  (`/v2/nfse`, `/v2/nfe`, `/v2/nfce`), with a client-supplied idempotency
  reference passed as a query param (`?ref=`).
- **Response pattern**: nominally synchronous-ish (the initial call often
  returns fast), but the real status still comes back as one of
  `processando_autorizacao | autorizado | erro_autorizacao | cancelado` —
  treat it the same as an async engine and poll until you hit a terminal
  state. Don't assume the first response is final just because it's fast.
  **NFC-e has a real 5th status, `denegado`, that NF-e/NFS-e don't** —
  confirmed live against `consultar_nfce`'s schema (pitfall #19). If a
  status-mapping function is shared across document types (it usually is —
  same vendor, same shape), don't assume one document type's status
  vocabulary is the whole vocabulary; check every sibling type's own docs
  before deciding a value is "dead" and removing it.
- **Company onboarding**: register the company + certificate with Focus once;
  it holds the A1 certificate on your behalf from then on.
- **Notable feature**: has an internal "Automations" product for
  auto-calculating tax fields server-side, but without a confirmed, stable
  API contract for it. Don't build against an integration you can't pin down
  from documentation alone — if you can't find the exact request/response
  shape in writing, don't guess it into your integration; treat it as
  unavailable until you have a sandbox account to actually observe it.
- **Version discipline**: endpoint paths for different document types on the
  same account are easy to get subtly wrong (e.g. one sibling call missing
  the `/v2/` prefix that every other call has) — see pitfall #9 in
  `pitfalls.md`. When one document type's request differs in shape from its
  siblings, double check it against the docs rather than assuming symmetry.
- Cached NF-e/NFC-e/NFS-e field names, status vocabularies (including the
  NFC-e-only `denegado` value above), and cancellation windows (NF-e 24h,
  NFC-e 30min) are in `assets/focus-nfe-schema-reference.md`, fetched live
  2026-09-23 — check there before re-fetching Focus's docs.

## Spedy (REST vendor)

- **Auth**: API key.
- **Scope is narrower by design**: Spedy's own docs (docs.spedy.com.br, as of
  this research pass) cover only NF-e, NFC-e, and NFS-e — no CT-e/MDF-e
  anywhere in their guides or API reference. Pick Spedy only when you're
  confident the project will never need those; Focus NFe or NFePHP (via
  `sped-cte`) are the options if it might.
- **Request shape**: three separate resource families for the three document
  types — `product-invoices` (NF-e), `consumer-invoices` (NFC-e),
  `service-invoices` (NFS-e).
- **Response pattern**: genuinely asynchronous. Creation returns
  `enqueued`/`processing` immediately; there is **no "GET by your own
  reference" endpoint** — you poll a *list* endpoint filtered by a
  client-supplied `integrationId`, meaning you cannot fetch a document until
  you already know the ID you gave it, and that ID has vendor-side length
  limits worth checking explicitly (see pitfall #5).
- **Notable feature**: the only one of the three with an opt-in mode where
  the vendor computes CFOP/CST/ICMS/ISS server-side from a near-empty
  request (`POST /v1/orders`). Treat this as a genuine trade-off, not a free
  upgrade — you gain less code to maintain, you lose the ability to enforce
  your own "never guess" validation before the number is legally committed.
  A safe default: keep this as an explicit per-tenant opt-in, not the
  default path, until you've verified in a sandbox exactly how it resolves
  ambiguous cases.
- **`indFinal` ≠ `indIEDest` — don't conflate them**: Spedy's schema exposes
  `isFinalCustomer` (root of the NF-e/NFC-e payload) and a `receiver.
  stateTaxNumber` string field, but **no explicit `indIEDest` field**.
  `isFinalCustomer` maps to the real NF-e `indFinal` tag — a genuinely
  different fiscal indicator from `indIEDest` (situação da IE do
  destinatário). Sending a real IE in `stateTaxNumber` presumably makes
  Spedy derive `indIEDest=1` server-side, but that derivation is **not
  confirmed in the docs** — only the field's existence and description are.
  This distinction cost a real production rejection (`cStat=232: IE do
  destinatario nao informada`) when a PJ-contribuinte NF-e was built as if
  `isFinalCustomer=false` alone were enough. The full verified field list
  (`SefazInvoiceReceiverDto`, the three create-DTOs) is cached in
  `assets/spedy-schema-reference.md` — check there first; it also documents
  that **no "isento de IE" field exists** in the schema, so an isento
  destinatário is expressed only by omitting `stateTaxNumber`, itself
  unconfirmed against a live sandbox.

## NFePHP (`sped-nfe` / `sped-common` / sister libraries — talks to SEFAZ directly)

- **Auth**: none in the API-key sense — you hold the issuing company's own
  A1 (.pfx) certificate and sign requests yourself.
- **Sister-package maturity — checked directly against GitHub, don't
  repeat the old assumption below**: `sped-nfe` (NF-e/NFC-e) is the mature,
  long-established package this skill treats as authoritative. `sped-cte`
  and `sped-mdfe` are BOTH actively maintained, same push cadence as
  `sped-nfe` itself (confirmed live via GitHub 2026-09-23) — don't assume
  MDF-e lags CT-e, an earlier pass of this skill claimed that without
  checking and was wrong. The real gap is NFS-e: **there is no general
  `sped-nfse` package** — only two narrow, município-model-specific ones,
  one self-marked "ABANDONADO" (`sped-nfse-dsf`) and one stale since 2023
  (`sped-nfse-ginfes`), neither supporting NFS-e Nacional. See
  `other-documents.md` for detail. **But the national NFS-e itself CAN be issued
  directly, without a vendor, with a separate package,
  `nfse-nacional/nfse-php`** — that is what the reference project's "NFEPHP"
  engine uses for NFS-e (and `sped-nfe` for NF-e/NFC-e). See
  `nfse-nacional-direct.md`. Lesson generalized in `pitfalls.md`
  #18/`audit-checklist.md` Check 5: a maturity/support claim about a
  dependency is exactly the kind of assertion to re-verify live, not carry
  forward from an earlier research pass.
- **Request shape**: build the actual government XML schema for the
  document, sign it with the certificate, submit via SOAP/webservice calls
  the library wraps. There is no third-party company registration step —
  `registrarEmissor` for this engine is really just "validate the
  certificate opens and the config has what the XML layout requires."
- **Response pattern**: synchronous per SEFAZ webservice call
  (`indSinc=1` — synchronous authorization mode). No polling loop needed for
  the common case, but SEFAZ webservices do go down, which is exactly what
  the next point exists for.
- **Notable feature — contingência EPEC**: the only one of the three engines
  that exposes this. If SEFAZ itself is unreachable, EPEC lets you register a
  signed "prior emission" event that makes the document legally valid
  immediately, with an obligation to transmit the real XML within 7 days
  before SEFAZ blocks further EPEC use for that emitter. This is real legal
  machinery, not a retry queue — see the contingência rules in
  `domain-concepts.md` before wiring it up, and treat the library's own
  contingency code path with extra suspicion (pitfall #8: it's exactly the
  kind of rarely-exercised path that ships with a real, subtle bug).
- **Cost model**: no per-document vendor fee, no dependency on a third
  party's uptime for the primary path — but you own correctness for
  everything the vendors would otherwise have abstracted away (CFOP/CST
  tables, exact XML schema per government NT — nota técnica — version,
  certificate lifecycle/renewal).
- **Timezone caution**: verify what timezone the library assumes internally
  for anything date/time-stamped (contingency start time, `dhEmi`, etc.) —
  don't assume it inherits your application's configured timezone just
  because everything else in your stack does (pitfall #11).
- A real, source-verified reference for the library's own tag-builder
  methods (`tagide`, `tagdest`, `tagICMS`/`tagICMSSN`, `tagICMSUFDest`,
  cancelamento) — required/optional fields read directly from the
  installed package, not inferred — is cached in
  `assets/nfephp-sped-nfe-reference.md`. It also documents two things
  worth knowing before assuming they're your call: the library **forces
  `indIEDest=9` unconditionally for NFC-e** (modelo 65) — so "does NFC-e
  need the destinatário's IE" isn't a business decision for this engine,
  it's a schema constraint the library already enforces — and DIFAL's
  `pICMSInterPart` is **hardcoded to `100`** by the library (EC 87/2015's
  phase-in is complete), meaning a future DIFAL implementation only
  actually needs the per-UF `pICMSUFDest` rate plus the small public
  `pICMSInter` 4/7/12% table, not a mystery amount of tax research.
  Version-specific — re-verify if the installed `sped-nfe` version
  differs from what the asset file states.

## Choosing

There's no universally-correct choice — it's a real trade-off:

- **Fastest to ship, least fiscal domain knowledge required, ongoing per-
  document cost and vendor dependency** → Focus NFe or Spedy.
- **No per-document fee, full control, but you own CFOP/CST/XML-schema
  correctness and certificate lifecycle** → NFePHP.
- **Building something reusable across many tenants with different needs/
  budgets** → support more than one behind the provider interface in
  `architecture.md` and let it be a per-tenant configuration choice, the way
  this project does. Don't build the abstraction "just in case" if you only
  ever need one engine for one fixed project — YAGNI still applies; the
  interface earns its keep once you actually need to swap or add a second
  engine, not before.

## Where each engine takes the "additional information" text

See the field table in `domain-concepts.md` ("Additional information on the
document"): NFePHP `infCpl` / `xInfComp`; Spedy `additionalInformation`; Focus
`informacoes_adicionais_contribuinte` for NF-e/NFC-e, and — because Focus
NFS-e municipal has no such field — the end of `servico.discriminacao`.
