# Domain concepts — the actual rules, not trivia

Each of these is a real rule with real branching logic, not just a term to
recognize. Treat each one as something a resolver function should encode
explicitly (with an exhaustive match and a `throw` on the unhandled case),
not something to eyeball per document.

## CFOP (Código Fiscal de Operações e Prestações)

Governed by Ajuste SINIEF 07/2005 (the NF-e layout) and the CFOP table
itself (Convênio s/n de 15/12/1970, as amended). A 4-digit code describing
*the nature of the movement* — not the product. Determined purely by
operation-level facts:

- Same state (UF) as buyer, or different state? → `5xxx` (internal) vs.
  `6xxx` (interstate).
- Subject to ICMS substituição tributária (ST)?

A B2B goods-sale resolver typically needs exactly these four outcomes:
`5102` (venda dentro do estado), `5405` (venda dentro do estado, ST),
`6102` (venda fora do estado), `6404` (venda fora do estado, ST). Anything
else the resolver receives is a bug upstream — throw, don't guess.

For consumer-facing sales (NFC-e), the rule *simplifies*: an end consumer is
by definition not an ICMS taxpayer, so the ST distinction drops out entirely
— just same-state (`5102`) vs. different-state (`6108`). Don't reuse the
B2B resolver for B2C; the input space is genuinely smaller and conflating
them either loses a real branch or adds one that can never legitimately fire.

## CST / CSOSN

Governed by Convênio ICMS 142/2018 for the ST-related codes, and the base
ICMS regulations for the rest. The ICMS tax-situation code — but which
*table* applies depends on the seller's own tax regime, not the transaction:

- Simples Nacional → **CSOSN** (e.g. `102` = normal, `500` = ST).
- Any other regime (Lucro Presumido, Lucro Real) → **CST**
  (e.g. `00` = normal, `60` = ST).

A resolver for this needs exactly two inputs — regime tributário, and a
simplified NORMAL/ST classification already stored on the product — and
should refuse to produce anything outside those four values.

## CRT (Código de Regime Tributário)

Single digit on the emitter block: `1` = Simples Nacional, `3` = Regime
Normal (Lucro Presumido/Real also map here in most implementations — check
the current NT for the authoritative table). The trap worth generalizing:
**regime names have aliases.** A MEI (micro-entrepreneur) is legally inside
Simples Nacional even when a free-text config field just says "MEI" without
the word "Simples" anywhere in it. If you resolve CRT by string-matching a
regime name, enumerate the aliases explicitly rather than assuming the
canonical name is the only spelling you'll ever see in real data.

## Origem da mercadoria

Single digit, `0`–`8`. `0` = nacional (the common case), `1`–`8` cover various
import scenarios. The generalizable trap: **`0` is a real, valid, meaningful
answer** — "this product is nationally sourced" — not an absence of data.
Any code that treats falsy-as-missing (`?? 0`, `empty($x)`) will silently
convert "we never actually classified this product" into the specific false
claim "this is nationally sourced merchandise." Check identity (`=== null`)
for whether the field was ever set; never infer "unset" from "zero."

## ISS "por dentro" (service tax, embedded in price)

Under Brazilian law (LC 116/2003, art. 7º), for services the tax base *is*
the price charged — ISS is a percentage carved out of what the customer
already paid, not an amount added on top at checkout. Concretely:

```
valor_total = subtotal - desconto        # NOT subtotal + valor_iss
valor_iss   = (subtotal - desconto) * aliquota_iss / 100   # reported separately
```

Get the sign wrong and every invoice both overcharges the customer by the
tax rate *and* misreports the total to the tax authority — this is a bug
that has actually shipped to production (see `pitfalls.md` #1). If you're
porting logic from an NF-e (goods) code path to an NFS-e (services) code
path, don't assume totals compose the same way — goods and services have
genuinely different tax-base rules.

## Ambiente: homologação vs. produção

Two fully separate environments, with separate credentials, separate base
URLs, and — the part people forget — **separate databases on the government
side.** A real document issued by a real third-party supplier will never
show up if you query the government's "received documents" endpoint while
configured for homologação; it's not a propagation delay, homologação simply
has no real-world data in it at all. Any feature that reads *someone else's*
already-issued document (supplier XML lookup, reconciliation, etc.) needs to
hit produção regardless of which environment your own tenant's emission is
configured for — these are two independent settings, not one toggle.

## Numeração / série

The document number is a monotonic, per-series counter. Allocate it inside a
row-locked transaction (`SELECT ... FOR UPDATE`, or the equivalent
`lockForUpdate()` in an ORM) immediately before the emission attempt, to
avoid two concurrent requests getting the same number. The refinement worth
keeping: a number "burned" by a document that gets *rejected* should be
reused on the retry, not discarded — otherwise the sequence drifts upward
forever every time SEFAZ rejects something for a fixable, resubmittable
reason (a typo, a missing CEST, etc.), leaving unexplained gaps that an
auditor will eventually ask about.

## Contingência

Governed by Ajuste SINIEF 07/2005, the same instrument that defines the
NF-e layout itself. The legally defined fallback for when the government
webservice itself is unreachable (not when your own request is malformed).
For NF-e/NFC-e, EPEC lets you register a signed "prior emission" event that
makes the document valid immediately, with a deadline (7 days) to transmit
the real XML before SEFAZ blocks further EPEC use for that emitter.

The rule worth enforcing in code: **trigger contingency only on genuine
communication failure** — connection timeout, connection refused, malformed
transport-level response. Never on a validation error, an authentication
failure, or a misconfigured URL. Contingency registers a real, binding legal
event with the tax authority; triggering it for the wrong reason (e.g.
because your own request was invalid) creates a legal record that doesn't
match what actually happened.

## GTIN / código de barras

Since a specific NT (nota técnica) version, SEFAZ schemas require a GTIN
field on items even when the product has no real barcode — and the schema
defines an explicit sentinel value for that case (`SEM GTIN`), not an empty
or omitted field. This is a specific instance of a general Brazilian-fiscal
pattern: **government XML schemas often define an explicit "I don't have
this" value.** Omitting the field and sending the sentinel are not
equivalent — only one of them passes schema validation. When a government
schema conditionally requires field B whenever field A has a certain value,
encode that as an explicit pre-emission check, not an assumption that
supporting field A automatically means you're already sending field B
correctly (see `pitfalls.md` #3 for a real CEST/ICMS-ST version of this).
