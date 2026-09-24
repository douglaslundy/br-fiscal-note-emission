# Pitfalls catalog — real bugs, already found and fixed once

Every entry here actually shipped to production in a working fiscal system
before being caught and fixed. Read this before writing new fiscal code, not
just when something's already broken — most of these are the kind of bug
that looks completely reasonable at review time.

Format: **Symptom → Root cause → Fix → Generalized lesson.**

## 1. ISS added to the total instead of subtracted

**Symptom**: a R$100 service came out on the invoice as R$105 (5% ISS).
**Root cause**: `valor_total = subtotal + valor_iss` instead of treating ISS
as embedded in the price already charged.
**Fix**: `valor_total = subtotal - desconto`; ISS is computed and stored
separately as composition info, never added to the charged total.
**Lesson**: when a tax is "por dentro" (embedded), it must never increase the
displayed or reported total. See `domain-concepts.md`.

## 2. `origem` (or any real-but-falsy fiscal field) defaulted via `?? 0` / `empty()`

**Symptom**: products nobody had actually classified got silently reported
as "origem 0 — nacional" on real invoices.
**Root cause**: PHP's `empty()`/`??` treat `0` the same as "not set," but `0`
is itself a specific, legally meaningful, distinct answer here.
**Fix**: check `=== null` explicitly; block emission with a typed exception
when the field was genuinely never set.
**Lesson**: for any field where `0`/`false`/`''` is a *valid, distinct*
answer, truthiness checks are the wrong tool for "is this set?"

## 3. CEST silently missing even though it existed in the database

**Symptom**: SEFAZ rejected documents with `cStat=806` — "operação com
ICMS-ST sem informação do CEST."
**Root cause**: the product table already had a CEST column, but nothing in
the emission payload-building code actually read it in — the field existed,
was even populated for some products, and was simply never wired into the
document.
**Fix**: added an explicit pre-emission check — when a product's ICMS
classification is ST, CEST becomes required, and its absence blocks emission
with a clear message rather than letting SEFAZ discover it.
**Lesson**: when a government schema conditionally requires field B whenever
field A has a specific value, that conditional requirement needs to be an
explicit blocking check in your own validation — don't assume "we have a
column for it" means "we're actually sending it correctly in every case that
requires it."

## 4. GTIN omitted instead of using the schema's own "no barcode" sentinel

**Symptom**: rejection `cStat=611` (`cEAN` inválido) or `cStat=612`
(`cEANTrib` inválido) — for perfectly normal products that just don't have a
barcode (common for services-adjacent goods, bulk items, etc.). *(Verified
against `nfephp-org/sped-nfe`'s own `docs/cStat.md` — the same library this
skill already treats as authoritative for NFePHP. An earlier draft of this
entry cited `cStat=883`, which doesn't appear in that source; corrected
rather than carried forward unverified — the "never guess a fiscal value"
principle applies to this skill's own content too.)*
**Root cause**: the field was omitted entirely for barcode-less products,
but the current NT (nota técnica) version requires the field to be present,
using the literal string `SEM GTIN` as the documented way to say "this
product has no barcode."
**Fix**: send the sentinel value instead of omitting the field.
**Lesson**: government XML schemas frequently define an explicit "I don't
have this" value. Omitting a field and using its defined absence-sentinel
are not interchangeable — only one passes validation, and you can't infer
which without checking the actual schema/NT documentation.

## 5. Vendor-imposed length limit on a self-invented idempotency key

**Symptom**: two real, otherwise-correct invoices were rejected by a vendor
API before anyone noticed a pattern.
**Root cause**: the app generated its own external-facing reference ID
(`nf-<uuid>`) for idempotency, and that format happened to be 3 characters
longer than the vendor's undocumented-until-you-hit-it 36-character limit on
that field.
**Fix**: shortened the reference format to fit within the vendor's limit.
**Lesson**: when you invent your own ID scheme for a field a third-party API
treats as opaque, verify that API's actual length/format constraints for the
field explicitly — "should be enough" isn't a check.

## 6. Polling failure (network error/timeout) conflated with genuine rejection

**Symptom**: roughly 10 real invoices ended up marked `REJEITADA` with a
fabricated error message the tax authority never actually sent.
**Root cause**: a network error, timeout, or 5xx *while polling a
document's status* was mapped straight into the same code path as an actual
`REJEITADA` response from the government.
**Fix**: separated "I don't know what happened yet" (retryable, stays
`PROCESSANDO`) from "the authority said no" (terminal `REJEITADA` with the
government's own message) as genuinely distinct states.
**Lesson**: "the request to check status failed" and "the authority
rejected the document" must never share a status code or a code path —
one is transient and retryable, the other is a terminal, legally meaningful
outcome, and conflating them fabricates government responses that were
never actually received.

## 7. A field mapped correctly for one document type, hardcoded `null` for a near-identical sibling

**Symptom**: a numeric protocol field worked fine for NF-e responses from a
vendor, but always came back empty for NFC-e responses from the *same*
vendor, on what was structurally the same field.
**Root cause**: the field-mapping logic for the response was copy-pasted per
document type instead of extracted once, and the NFC-e copy simply never got
updated to read the field — it hardcoded `null` where the NF-e copy read the
real value.
**Fix**: extracted one shared field-mapping function used by all document
types from that vendor.
**Lesson**: when one vendor's response shapes are "mostly the same" across
document types, extract the shared mapping once. Copy-pasted per-type mapping
code is exactly the kind of thing that silently drifts, because nobody
re-audits the copies once the original works.

## 8. A mature library's own rarely-exercised contingency code path was actually broken

**Symptom**: calling the official EPEC contingency method of a well-known,
widely-used open-source library failed with a confusing internal error.
**Root cause**: the library's own public entry point had an internal guard
that demanded exactly the value its own documented usage would only ever
supply, while a *different* internal check hard-rejected that same value
with a misleading message — the library's own test fixture for this exact
feature was broken at the identical point, meaning the bug had never
actually been caught by the library's own test suite either.
**Fix**: reproduced the library method's logic locally with a small,
clearly-commented workaround explaining exactly which upstream bug it routes
around, rather than depending on the broken entry point.
**Lesson**: don't assume a mature open-source library's rarely-exercised
paths — contingency and error-fallback logic are *exactly* the kind of thing
that's undertested in practice — actually work. Write a real integration
probe against them before depending on the path in production, and if you do
find a real upstream bug, vendor a small, well-commented fix rather than
routing around it silently.

## 9. Endpoint path typo masked by an overly broad test mock

**Symptom**: an NF-e creation call would have 404'd in real production, but
every test for it passed.
**Root cause**: one document type's create endpoint used `/nfe` where every
sibling call on the same vendor client correctly used the versioned
`/v2/nfe`. The test suite mocked HTTP calls with a wildcard matching the
whole domain, so both the correct and incorrect paths matched the same mock
and returned the same canned success.
**Fix**: corrected the endpoint, and tightened the test mocks to match full
paths, not just the domain.
**Lesson**: a test-mock wildcard "generous" enough to make a test pass is
also generous enough to hide the exact bug that test exists to catch. Match
mocked requests on the full path whenever the path itself is part of what's
being tested.

## 10. Relative URL from a vendor treated as absolute, masked by a stale test fixture

**Symptom**: downloading a document's XML/PDF silently failed in production
for a specific vendor, but the equivalent feature's tests all passed.
**Root cause**: the vendor's real API returns a *relative* file path, but
the download code assumed an absolute URL. The test for this exact feature
was written by copying an *older* fixture from a previously-existing
feature — and that older fixture happened to contain an absolute URL,
because the vendor's response shape had changed since that fixture was
captured.
**Fix**: prefix relative paths with the vendor's base URL before fetching;
recaptured the fixture from a live/sandbox response.
**Lesson**: when writing a fixture for "the same kind of response an
existing feature already handles," pull the actual shape from a live or
sandbox call rather than copying an older fixture — vendor response shapes
drift over time, and a stale fixture lets a real bug pass on green.

## 11. Contingency timestamp off by the local UTC offset

**Symptom**: a contingency event's declared start time came out roughly 3
hours ahead of wall-clock time.
**Root cause**: the library computing the contingency timestamp used a
different timezone internally than the rest of the application — it didn't
inherit the app's configured timezone just because everything else in the
stack did.
**Fix**: made the timezone used for this timestamp explicit rather than
implicit.
**Lesson**: for any library handling something date/time-stamped and legally
significant (contingency windows, `dhEmi`, etc.), verify its internal
timezone assumption explicitly — don't assume it shares your application's
configuration by default.

## 12. A real, legally-required calculation was never implemented for a code path nothing had exercised yet

**Symptom**: no rejection in production — the bug was silent because the
only tenants/scenarios live so far never triggered the affected branch (a
Regime Normal, i.e. non-Simples-Nacional, emitter selling interstate to a
final consumer without state registration).
**Root cause**: the underlying calculation (Brazil's DIFAL — Diferencial de
Alíquota, EC 87/2015) requires a real, verified table of each destination
state's internal ICMS rate that the project never had, so the code simply
never called the vendor library's own method for it — present, documented,
and fully wired into the library's own XML assembly, just never invoked by
the application. No test exercised the combination that would trigger it,
so nothing caught the gap until an audit went looking for it on purpose.
**Fix**: rather than fabricate a per-state rate table — a fiscal value with
real legal and financial consequences, exactly what "never guess a fiscal
value" already forbids — added a local guard that detects the precise
triggering combination (interstate + final-consumer + non-contribuinte +
non-Simples emitter) and blocks emission immediately with a clear,
actionable message, converting a certain-but-silent government rejection
into an immediate, loud, local one.
**Lesson**: "we've never seen this fail in production" is not evidence a
code path is correct — it may just mean nothing has exercised it yet. When
a real calculation is missing because the data to do it correctly doesn't
exist, don't skip the check silently: detect the exact condition that would
need it and fail loud and local, the same discipline you'd apply to any
other missing fiscal value.

## 13. Two independent resolvers derived the same classification from the same input, and drifted

**Symptom**: for one narrow tax-regime label ("MEI"), one part of the system
correctly treated the emitter as Simples Nacional while a sibling part did
not — producing an internally-inconsistent document (the wrong tax-
situation-code table used for the emitter's actual regime).
**Root cause**: two separate resolver functions each independently
reimplemented "is this emitter Simples Nacional?" from the same free-text
regime field, with slightly different string-matching logic — one
recognized the "MEI" alias, the other didn't. Nobody wrote this
inconsistency on purpose; it's what happens when the same business rule
gets expressed twice.
**Fix**: made the less-complete resolver delegate to the more-complete one
instead of re-deriving the same classification independently.
**Lesson**: when you find yourself writing "is X true about this input"
logic and a structurally identical check already exists elsewhere for a
related purpose, delegate to it rather than reimplementing — two
independent implementations of the same business fact will diverge over
time, not stay in sync by coincidence.

## 14. Two legally distinct indicators, explained as different in a comment, still got conflated — in opposite directions, on two different engines

**Symptom**: a real rejection (`cStat=232`: destinatário's Inscrição
Estadual not informed) on one engine; a second engine's equivalent field
was schema-correct but built from the wrong input.
**Root cause**: the NF-e schema has two separate, easily-confused
indicators about the destinatário — `indFinal` (is this sale to the true
final consumer, or for resale) and `indIEDest` (what's the destinatário's
ICMS-contribuinte/IE registration status). One engine's code never
populated `indIEDest` at all (always behaved as non-contribuinte). A
second engine's code populated it, but derived the unrelated `indFinal`
field FROM the IE-presence check meant for `indIEDest` — a comment sitting
right above the code even already explained the two indicators were
legally distinct, and the code below it conflated them anyway.
**Fix**: for the first engine, added a resolver that requires a real IE (or
an explicit "isento" flag) before allowing a document for a PJ
destinatário, blocking otherwise. For the second, decoupled `indFinal`
entirely from IE-presence and hardcoded it to the business's actual,
verified sales model (never sells for resale) — matching the assumption
already used correctly elsewhere.
**Lesson**: when a schema has two indicators that sound related but answer
different legal questions, treat every place either one is set as worth a
specific double-check for this exact conflation — a comment correctly
explaining they're different is not the same as the code actually keeping
them separate, and one engine having already produced this bug doesn't
mean a *different* engine's code is safe from the mirror-image version.

## 15. The identical field-omission bug recurred on every sibling document type and every sibling engine, one discovery at a time

**Symptom**: destinatário's Inscrição Estadual missing from the document —
found and fixed on NF-e for one engine; found again on NFC-e for a second
engine; found again on NFC-e for the first engine too (flagged, not fixed,
because closing it needed a business-rule decision, not just a code
change). Three separate discoveries of the same bug shape, across two axes
(document type × engine), each on a different pass.
**Root cause**: the fix for the first discovery was scoped to the file/
engine where it was found, with nothing that forced an explicit "does this
exact gap exist on this engine's OTHER document types, or on the OTHER
engines' equivalent code?" step immediately afterward.
**Fix**: fixed each instance as found; the remaining instance (a shared
assumption that no NFC-e destinatário is ever a registered ICMS
contribuinte) was left as a flagged, unconfirmed business question rather
than silently changed.
**Lesson**: a real fiscal integration is a grid — N document types × M
engines — not a list of independent files. When you fix a bug in one cell
of that grid, explicitly check the same field/pattern in every other cell
before calling the bug closed, instead of waiting for it to resurface on
its own schedule. See `audit-checklist.md` for the systematic version of
this check.

## 16. A vendor's real status enum had more values than the code's `match` handled, and the gap hid behind a friendly-looking default

**Symptom**: nothing visibly broke — a specific class of terminal failure
(the vendor's "Denegado" status, legally distinct from an ordinary
rejection) simply never surfaced. Documents in that state looked like they
were still processing, indefinitely.
**Root cause**: the status-mapping code only had explicit arms for the
common-case values (authorized/rejected/canceled/still-processing) and
routed everything else through a fallback that mapped to "still
processing" — reasonable-looking, since it avoided crashing on an
unrecognized value, but wrong, because several of the unhandled values
were actually terminal.
**Fix**: re-fetched the vendor's real, current API specification directly
(not from memory or an older summary) and added an explicit, deliberate
arm for every real value the enum can take.
**Lesson**: a default arm that silently degrades an unrecognized enum value
into "still pending" is exactly where a legally meaningful terminal state
goes to hide. Enumerate every value the vendor's live documentation says is
actually possible, and require an explicit decision for each one — "we've
never seen this value" is not the same as "this value can't occur."

## 17. A race-condition fix (lock, then mutate, then trigger a side effect) wasn't mirrored to a sibling method with the identical shape

**Symptom**: none observed directly — found by a deliberately skeptical
second audit pass, not by a production incident.
**Root cause**: one method (allocates a real sequential document number,
then submits it to a government system) had a genuine double-submission
race — two near-simultaneous calls could both pass a status check before
either persisted, each proceed to submit a real document. It was fixed
correctly with a database row lock around the whole check-then-mutate
sequence. A SECOND, separate method — sharing the exact same shape (reads
a status, and if it just transitioned, fires an external side effect: an
email, a billing charge) — had the identical class of gap, and was NOT
fixed in the same pass, because that audit was organized per-file/
per-component, with nothing that forced a search across the rest of the
codebase for every OTHER place sharing the shape.
**Fix**: applied the identical lock pattern to the sibling method once a
second, explicitly-skeptical audit pass went looking specifically for "did
the first fix get applied everywhere it needed to be," rather than just
re-confirming the first fix still worked.
**Lesson**: a lock, guard, or idempotency check added to fix ONE method
isn't done until you've searched for every OTHER method that mutates
shared state and triggers the same class of external side effect (billing,
email, legal submission, sequential numbering) — the underlying *shape*,
not the specific file, is what needs the fix. A verification pass that
only re-confirms the original fix, instead of being explicitly briefed to
hunt for what a narrower first pass might have missed, will mostly just
re-confirm. See `audit-checklist.md`.

## 18. A code comment confidently asserting "this is a business rule, not a bug" encoded an assumption nobody had actually verified

**Symptom**: none directly — found by treating an existing, confidently-
worded code comment as a claim to check rather than an established fact,
right after a *different*, structurally identical assumption (about a
related indicator, on the same class of document) had just been proven
wrong earlier in the same audit.
**Root cause**: a comment stated, as settled fact, that one entire class of
buyer on one document type "always" has a particular status — a real
business claim, not a technical one, that nothing in the code, the schema,
or the government's own rules actually required to be true. It may well be
correct; the point is nobody had confirmed it.
**Fix**: not changed — flagged explicitly to whoever owns the business
decision, rather than either leaving it unquestioned or silently
overriding a business rule with no authority to decide what the real rule
is.
**Lesson**: a code comment that confidently states a business rule is a
claim, not a verified fact, especially once a structurally similar claim
elsewhere just turned out to be wrong. Auditing existing fiscal code means
listing every such assertion and getting it confirmed by whoever actually
owns that business decision — accepting it as settled just because it
reads confidently is how the same wrong assumption survives multiple audit
rounds unchallenged.

## 19. A "dead code" cleanup, checked against only one document type's docs, deleted a status value that was still real and alive on a sibling document type sharing the same function

**Symptom**: a status-mapping function's unhandled-value fallback logs a
warning and treats the value as "still processing" — the exact failure
shape this whole audit exists to catch (pitfall #16) — except this time it
was *reintroduced*, not discovered fresh, in a cleanup commit from earlier
in the same audit.
**Root cause**: a vendor's changelog confirmed one status value was
retired and would never be returned again — true, but only checked
against ONE document type's status documentation. The mapping function
being "cleaned up" was shared across three document types issued by the
same vendor (confirmed by grepping every call site before touching the
function — it had three). The retirement was real for two of them and
NOT true for the third, which still returns that exact value under a
different, legally real condition. Removing the branch broke the one
sibling nobody checked.
**Fix**: restored the value's branch, with a comment explaining precisely
which sibling document type still needs it and why, so a future cleanup
pass doesn't repeat the mistake without at least seeing the warning.
**Lesson**: before deleting a branch/value/field as "dead" or "no longer
returned," confirm that claim against EVERY document type or endpoint that
shares the function you're touching, not just the one you happened to be
looking at when you decided it was dead. A shared function is shared risk
— see `audit-checklist.md` Check 1 (cross-document-type mirroring), which
this incident is the concrete case for; the mirroring check applies just
as much to removing something as to adding something. Caught here not by
a fresh audit round but by unrelated work (consolidating the vendor's
documentation into a reference cache) that happened to re-read the live
docs for a document type the original cleanup hadn't — a reminder that any
time you're re-reading a vendor's real docs for any reason, it's worth a
quick pass checking recent "cleanup" changes against what you're reading.
