---
name: br-fiscal-note-emission
description: Guides building, extending, or debugging Brazilian electronic fiscal document emission — NF-e, NFS-e, NFC-e primarily, plus CT-e/MDF-e and other DF-e types when relevant. Covers Focus NFe, Spedy, and NFePHP/sped-nfe with accurate per-engine coverage, CFOP/CST-CSOSN/ICMS/ISS rules with legal basis, reliability patterns (idempotency, status reconciliation, schema validation, certificate lifecycle, regulatory versioning), a cStat rejection-code table, and a catalog of real production bugs. Use whenever the user mentions nota fiscal, documento fiscal, NF-e, NFS-e, NFC-e, CT-e, MDF-e, CFOP, CST, CSOSN, ICMS, ISS "por dentro", SEFAZ, homologação/produção fiscal, certificado A1, contingência/EPEC, cStat, or asks to integrate Focus NFe/Spedy/NFePHP/ACBr — even without the word "fiscal," e.g. "a nota saiu com valor errado," "preciso emitir nota pro cliente," or "como emitir NF-e." Also use when building a fiscal emission module from scratch, not just when modifying an existing one.
---

# Emissão de nota fiscal eletrônica (Brasil)

## Why this exists

Brazilian fiscal documents (NF-e, NFS-e, NFC-e) are legal instruments, not just
receipts. A wrong value on one isn't a cosmetic bug — it's an incorrect
statement to a tax authority and, often, an amount actually charged to a real
customer. This domain punishes the normal LLM instinct to "fill in something
reasonable when data is missing." That instinct is exactly backwards here.

The single most important idea in this skill, before any architecture or code:

> **Never guess a fiscal value. When you don't know it, block and ask — don't
> default and hope.**

This shows up constantly in real code as an explicit, typed refusal instead of
a fallback:

```php
if ($produto->origem === null) {
    // origem nula bloqueia — 0 é um valor fiscal válido e distinto
    // (mercadoria nacional). Defaultar pra 0 afirmaria um fato falso.
    throw new EmissaoBloqueadaException(
        "Produto \"{$produto->nome}\" está com a origem da mercadoria "
        . "pendente de revisão. Complete antes de emitir."
    );
}
```

Notice the comment explains *why* — `0` is itself a meaningful, valid answer
("nationally sourced"), so `?? 0` or `empty()` would silently turn "unknown"
into a specific false statement. That distinction (missing vs. a valid falsy
value) is the single most common source of the bugs in
`references/pitfalls.md`. Apply the same discipline to CFOP, CST/CSOSN, NCM,
tax regime, UF, and anything else that ends up on a document sent to a
government system — a resolver that can't classify an input combination
should throw, not fall through to a plausible-looking default.

**The same discipline applies to claims about the system you're helping
with, not just to values inside a document.** If you have tool access to
*some* codebase while working on this — your own working directory, a repo
that happens to be open — never assume it's the same system the user is
asking about unless you've actually confirmed that (they named the repo,
you opened the file they pointed you to, etc.). Presenting specifics from
an unrelated codebase as if they were the user's own system is the same
category of error as defaulting a missing fiscal value: a confident, precise
answer that sounds authoritative and is not grounded in what was actually
given. This applies with extra force to debugging (`debugging-checklist.md`)
and to anything you didn't directly read for *this* project: don't invent
file paths, method names, commit dates, or "this was already fixed on
[date]" narratives. If you haven't verified the code in front of you belongs
to the user's actual system, say what you'd need to look at instead of
presenting a guess as a finding.

## Which situation are you in?

**Building fiscal emission from scratch for a new project.**
Before designing anything, apply the same "don't guess" discipline to the
*requirements* that the rest of this skill applies to fiscal values — an
architecture built on an assumed answer to one of these is expensive to
unwind later, because it's usually a data-model decision (one CNPJ vs. many,
one município vs. many), not a one-line fix:

- **One company/CNPJ, or multi-tenant/multi-filial?** Each CNPJ needs its own
  IE/IM, tokens, certificate, and série/numeração — this decides whether
  "configuração fiscal" is a single row or a table keyed by filial.
- **Which UF(s), and — if NFS-e is involved — which município(s)?** NFS-e is
  mid-transition to a national standard (mandatory for municípios since Jan
  2026, still a real minority of legacy layouts as of this writing) — each
  non-migrated município can mean a different layout, ISS rate, and
  service-code table (see `other-documents.md` for current status,
  `domain-concepts.md` for the ISS math). This is the single biggest scope
  multiplier and the easiest to underestimate.
- **Regime tributário already defined** (Simples Nacional / Lucro Presumido /
  Lucro Real)? This decides CST vs. CSOSN and the CRT — don't assume Simples
  Nacional just because the business is small.
- **Selling to consumer (NFC-e) or business (NF-e), or both?** Changes which
  CFOP resolver you need (see `domain-concepts.md`) and whether you need the
  simpler B2C rules or the full ST-aware B2B ones.
- **Expected volume / is this synchronous checkout or an async backend job?**
  Shapes whether you need a queue + polling/reconciliation job on day one or
  can defer it.
- **Any existing constraint already picking the engine for you** — an
  existing vendor account, an A1 certificate already in hand, a hard
  no-per-document-fee requirement? See the decision table below, but a real
  constraint the user already has beats a theoretical trade-off analysis.
- **Does any large/corporate/public buyer impose extra per-note requirements**
  (statement of tax regime, vehicle/contract data, XML delivery, a specific
  service code, a specific recipient CNPJ)? Get them in writing and map each
  to a field before designing — see "Additional information on the document"
  in `domain-concepts.md`.
- **Only NF-e/NFS-e/NFC-e, or does the business also move freight (CT-e/
  MDF-e) or need one of the niche DF-e types?** Most projects only need the
  three this skill focuses on — don't build for CT-e/MDF-e speculatively.
  If the business genuinely is a carrier or logistics operation, see
  `references/other-documents.md` before assuming the same engine covers it
  (Spedy notably doesn't).

Ask whichever of these aren't already answered in what the user told you —
in one batch, not one at a time — before proposing a schema or picking an
engine. Once you have real answers, read `references/architecture.md` to
design the provider abstraction (keep tax-rule logic separate from whichever
engine talks to the government), then `references/engines.md` to pick the
engine (see the decision table below for a first pass), then
`references/domain-concepts.md` to get the actual tax rules right, then
`references/reliability-patterns.md` for the idempotency/reconciliation/
validation patterns a production build needs from day one — before you
write a single resolver.

**Extending or modifying fiscal code that already exists in a project.**
Read `references/domain-concepts.md` and `references/pitfalls.md` *before*
touching anything. Existing fiscal code in a working system almost always
encodes hard-won rules that aren't obvious from the diff alone — a CFOP
`match` with only 4 branches and a `throw` on anything else looks
over-strict until you know it's guarding against exactly the kind of
silent-wrong-default bug in `references/pitfalls.md`. Don't relax a
validation guard or add a fallback default to "unblock" a case without first
checking whether it's protecting against one of these.

**Auditing/validating an existing fiscal integration for correctness** —
not one reported symptom, but a systematic check: a pre-launch review, "make
sure we didn't miss anything," or hardening a system after fixing one real
bug (the bug you just fixed is rarely the only place that pattern exists).
Follow `references/audit-checklist.md` — it's built from a real case where
naive round-after-round re-checking each found a *different* bug, because
nothing forced checking the same pattern across every document type and
every engine at once. Don't substitute reading `pitfalls.md` once for
actually running the checklist; the value is in the systematic cross-check,
not just pattern-recognition against a list.

**Debugging a reported fiscal problem** ("a nota saiu com valor errado," "a
SEFAZ rejeitou," "o cliente foi cobrado errado," "a nota ficou travada em
processando"). Follow `references/debugging-checklist.md` step by step. Cross-
check the symptom against `references/pitfalls.md` first — there's a good
chance this exact failure mode has already happened once and the fix (and the
generalized lesson) is already written down. If there's a real `cStat`
rejection code involved, check it against `assets/cstat-table.md` — don't
guess what a code number means from the number alone.

## Baseline every new fiscal tool should ship with

These come from real incidents (see `references/pitfalls.md` #20–#25) — build
them in from day one instead of discovering them in production:

- **One builder for "additional information" text** (Simples statement,
  vehicle/customer data, work-order free text) shared by every engine and
  document type; each engine only decides where it goes
  (`domain-concepts.md` has the per-engine field table). Sanitize it to the
  schema's character set and length (#20).
- **Store the access key exactly as the authority defines it** — NFS-e
  national: 50 digits, never the XML `Id` with its `NFS` prefix (#21).
- **Numbering recovery**: on `cStat=539`, consult the occupying key and skip the
  number only if it is cancelled (#22); never delete fiscal rows without
  accounting for numbers.
- **PDFs print what was authorized** (from the XML or a saved snapshot), not a
  parallel column (#23).
- **Mirror the authority's input limits at the UI and the API** (cancellation
  justification 15–255) and name downloads by document model from the server
  (#24).
- **Make the environment unmistakable**: show it where the user emits, audit
  changes to it, confirm before production, and read `tpAmb` from the XML when
  validating (#25).
- **Validate one real authorized XML per document type against the official
  XSD** before go-live (`audit-checklist.md` Check 8).
- **Run one input through every engine and compare** — enum codes vs. text,
  shared regime resolver, same information in each payload (#26, Check 10).
- **Keep private fiscal data out of public repos**: anonymized fixtures, source
  PDFs in `.gitignore`, explicit `git add` paths, `gh repo view --json
  visibility` before publishing (#27, Check 11).
- **Client-facing printed document = clone of the official one** when the
  customer expects it: copy `assets/pdf-layouts/` (DANFSe already cloned and
  verified) or apply `references/danfse-clone.md` to the customer's reference PDF.
## Engine decision table (first pass — read `references/engines.md` for detail)

| | Focus NFe | Spedy | NFePHP (direto SEFAZ) |
|---|---|---|---|
| What it is | REST API vendor | REST API vendor | PHP library talking to SEFAZ directly |
| Needs | API token | API token | A1 (.pfx) certificate |
| Vendor dependency | Yes | Yes | No (open source, self-hosted) |
| Docs types | NF-e, NFC-e, NFS-e | NF-e, NFC-e, NFS-e | NF-e, NFC-e via `sped-nfe`; **NFS-e nacional via a different library, `nfse-nacional/nfse-php`** (see `references/nfse-nacional-direct.md`) |
| Response pattern | Poll until terminal | Poll until terminal | Sync per SEFAZ call |
| Contingência/EPEC | Not exposed | Not exposed | Yes — the only one of the three |
| Best fit | Fastest to integrate, low fiscal domain knowledge needed | Same, plus optional server-side tax calculation | No per-document vendor fee, more control, but you own CFOP/CST/XML correctness |

None of these is strictly "better" — this project supports all three behind
one interface and lets each tenant pick, which is itself a reasonable default
if you're building something reusable rather than a single fixed integration.

## Core domain vocabulary (quick reference — full detail in `references/domain-concepts.md`)

- **CFOP** — 4-digit code for *what kind of movement this is* (same state vs.
  different state, ICMS-ST or not) — not about the product itself.
- **CST / CSOSN** — the ICMS tax-situation code. Which *table* applies depends
  on the seller's tax regime: Simples Nacional → CSOSN, everything else → CST.
- **CRT** — single digit on the emitter identifying tax regime (1 = Simples
  Nacional, 3 = Regime Normal). Watch for regime *aliases* (e.g. "MEI" is
  legally Simples Nacional even if the label doesn't say so).
- **Origem da mercadoria** — single digit, `0` = nacional. A real, valid value
  — never treat it as "empty."
- **ISS "por dentro"** — the service tax is *carved out of* the price, not
  added on top. `valor_total = subtotal - desconto`, ISS is reported as
  composition info, never summed into the charged total.
- **Homologação vs. produção** — fully separate environments and separate
  databases on the government side. Querying a real third party's document
  will never work against homologação, even for your own tenant.
- **Numeração/série** — a monotonic, row-locked counter per series; a number
  burned by a rejected attempt should be reused on retry, not discarded.
- **Contingência** — the legally defined fallback when the government
  webservice itself is unreachable. Must trigger only on genuine
  communication failure — never on a validation error or misconfiguration,
  because it registers a real, binding event with the tax authority.

## Don't hand-compute what's already implemented and tested

`scripts/reference-calculations.php` has working, tested implementations of
the ISS "por dentro" formula and the discount-rateio-with-residual-rounding
logic described above — both have already had a real bug found and fixed
against them (see `pitfalls.md` #1 and the rateio pattern validated across
independent eval runs). If the target project is PHP, use these functions
directly rather than re-typing the formula. If it isn't, **port this exact
logic** rather than re-deriving the rounding approach from scratch — re-
deriving "the obvious way" is exactly how this class of bug gets
reintroduced. `scripts/test_reference_calculations.php` shows expected
behavior on real numbers (run with `php -d zend.assertions=1
-d assert.exception=1 scripts/test_reference_calculations.php`) — useful as
a spec to port against even in another language.

## Reference files

- `references/architecture.md` — the provider-interface pattern (methods,
  input/output DTOs) that keeps tax logic independent of the engine
- `references/engines.md` — full comparison of Focus NFe, Spedy, and NFePHP:
  request shape, sync vs. async, what each needs as input, quirks, and
  actual per-engine document-type coverage (they're not identical)
- `references/domain-concepts.md` — CFOP, CST/CSOSN, CRT, origem, ISS,
  ambiente, numeração, contingência — explained as *rules*, with legal basis,
  not trivia
- `references/other-documents.md` — CT-e, MDF-e, and the rest of the DF-e
  family beyond NF-e/NFS-e/NFC-e: what's realistic to support vs. niche vs.
  sunset (CF-e-SAT), and NFS-e's current transition to a national standard
- `references/reliability-patterns.md` — idempotency, status reconciliation
  ("Two-Path Status Verification"), fail-fast schema validation, certificate
  expiry alerting, a verified-vendor-schema-cache workflow, and handling a
  regulator that changes the contract on its own schedule — general
  patterns, sourced from how mature systems (Stripe, EU/Mexico e-invoicing)
  solve the same class of problem
- `references/pitfalls.md` — 27 real production bugs, each as symptom → root
  cause → fix → generalized lesson. Read before writing new fiscal code, not
  just when debugging.
- `assets/pdf-layouts/` — **ready-to-copy printed-document layouts**: the DANFSe
  v2.0 clone (renderer with all coordinates + field mapping, Blade, logo,
  anonymized XML fixture, test), the DANFE and the NFC-e cupom templates, and the
  wiring that picks the template per document. Start here when the user needs
  the PDF of a note; its README says what is a verified clone and what is not.
- `references/nfse-nacional-direct.md` — how the national NFS-e is emitted
  directly (no vendor) with `nfse-nacional/nfse-php`: engine selection, flow,
  DPS field mapping with the real ADN rejections (`E0120`, `E0128`, `E0625`,
  `E0712`, `E1235`), key vs. `Id`, query/cancel event `101101`, local DANFSe.
  Read it before promising NFS-e without a vendor.
- `references/danfse-clone.md` — how to clone an official auxiliary document
  (worked example: the DANFSe v2.0 of the national NFS-e) so it matches the
  government's PDF: extract geometry/fonts/QR with `mupdf`, rebuild by baseline
  in Dompdf, map every field from the authorized XML, and prove the match
  (text diff, run-by-run positions, geometry). Includes the privacy rules for
  the reference document.
- `references/audit-checklist.md` — systematic checklist for validating an
  *existing* fiscal integration end to end (cross-document-type and
  cross-engine mirroring, sibling-lock consistency, enum completeness, and
  how to brief a second-pass audit so it actually finds something new) —
  not for a single reported bug, see `debugging-checklist.md` for that
- `references/debugging-checklist.md` — step-by-step triage flow for a
  reported fiscal problem
- `assets/cstat-table.md` — lookup table of common SEFAZ rejection codes
  (`cStat`) sourced from `nfephp-org/sped-nfe`'s own docs — check a real
  rejection against this before guessing what a code means
- `assets/spedy-schema-reference.md` — cached, verbatim extraction of
  Spedy's real OpenAPI schema (request DTOs for NF-e/NFC-e/NFS-e, the
  `isFinalCustomer`/`stateTaxNumber` fields, the `indFinal` ≠ `indIEDest`
  distinction). Consult this before re-fetching Spedy's live docs — it's a
  dated cache, not a permanent source of truth; the file itself says when
  to trust it vs. re-fetch (new field needed, a live rejection cites
  something that doesn't match, or too much time has passed since the
  date recorded at its top)
- `assets/focus-nfe-schema-reference.md` — same idea for Focus NFe: cached
  NF-e/NFC-e/NFS-e field names, status vocabularies (including the
  NFC-e-only `denegado` value that a status-mapping function shared across
  document types needs to keep handling — see pitfall #19), and
  cancellation windows, fetched live 2026-09-23
- `assets/nfephp-sped-nfe-reference.md` — real, source-verified field
  contracts for the NFePHP (`sped-nfe`) library's own tag-builder methods
  (`tagide`, `tagdest`, `tagICMS`/`tagICMSSN`, `tagICMSUFDest`,
  cancelamento), read directly from the installed package rather than
  inferred — version-specific, re-verify if the installed `sped-nfe`
  version differs
- `scripts/reference-calculations.php` — tested reference implementations,
  see above
