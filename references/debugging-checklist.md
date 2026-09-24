# Debugging a reported fiscal problem

A user reporting a fiscal issue is usually describing a *symptom*, not a
cause — "a nota saiu errada" could mean a dozen different things. Triage
before touching code.

## Step 1 — Get the exact document, not a description of it

Ask for (or find) the actual `chave de acesso` / número / protocolo, or the
internal record ID. "The invoice from yesterday" isn't enough — a business
issuing several documents a day needs the specific one. If the report is
about a value being wrong, get the exact expected value and the exact value
that actually appeared, not "the total is wrong."

**"Find it" means find it in the user's actual system — not in whatever
codebase happens to be open in your own working directory.** If you don't
have verified access to the user's real records/logs/repo (they haven't
shown you, or the project in front of you hasn't been confirmed as theirs),
say so plainly and give the general triage procedure below instead of
inventing specific file paths, method names, or a "this was already found
and fixed on [date]" story. A precise-sounding fabricated root cause is
worse than an honest "here's what to check in your own system," because it
sends the user to check the wrong thing with false confidence.

## Step 2 — Classify the symptom into one of these buckets

**A. Wrong value on an authorized/successful document.**
Go straight to the tax-computation layer (the `CriarNotaFiscalService`
equivalent in `architecture.md`), not the provider/engine code — a wrong
*value* almost always means a resolver or a subtotal/discount/ISS
calculation, not a transport problem. Cross-check against `pitfalls.md` #1
and #2 first; both produce "the number is wrong" as the visible symptom.

**B. Government rejected the document (`REJEITADA`, has a `cStat`/error
code from SEFAZ or the prefeitura).**
The rejection message is authoritative — don't guess at the cause, read it.
Check the code against `assets/cstat-table.md` first (e.g. `cStat=806` =
missing CEST on ICMS-ST, `cStat=611`/`612` = GTIN problems) — cross-reference
with `pitfalls.md` #3 and #4 for two real examples of "the schema needed
field X under condition Y and we weren't sending it." If the `cStat` isn't
in either file, look it up against the current government documentation for
that document type — don't guess a code's meaning from the number alone,
and don't trust a remembered code number that isn't in a checkable source;
this skill's own pitfalls.md had a wrong code number in an earlier draft
until it was checked against the real source and corrected.

**C. Document stuck in `PROCESSANDO` indefinitely, or flipped to
`REJEITADA` with a message that doesn't look like something a government
system would actually say.**
This is very likely pitfall #6 — a polling/network failure getting
misclassified as a real rejection. Check the actual HTTP response from the
last status-check call, not just the status stored in your database; if it
was a timeout/5xx/malformed response rather than a real government payload,
the record's status is wrong and needs correcting, and the polling code path
needs the fix from #6 if it doesn't already have it.

**D. Works for one document type (NF-e) but not a sibling (NFC-e/NFS-e) on
the same vendor/engine.**
Suspect copy-pasted, drifted field-mapping code — pitfall #7. Diff the
mapping logic for the working type against the broken one field by field.

**E. Works in homologação, fails (or "does nothing") in produção, or vice
versa.**
First confirm which environment the *specific feature in question* actually
needs to hit. If it's reading someone else's already-issued document
(reconciliation, supplier XML lookup), it needs produção regardless of the
tenant's own emission environment — see the homologação/produção note in
`domain-concepts.md`. This is a very common false alarm that isn't a bug at
all, just a misunderstanding of what homologação contains.

**F. Contingency/EPEC behaving unexpectedly** (triggered when it shouldn't
have, or failed to trigger when SEFAZ was genuinely down).
Check what actually triggered it — a validation error or config problem
should never invoke contingency (see the rule in `domain-concepts.md`). If
it's the library's own EPEC code path erroring out, read pitfall #8 before
assuming your integration code is at fault; the library itself may have a
bug in this exact area.

## Step 3 — Reproduce before fixing

Fiscal bugs are cheap to reproduce safely in homologação (it's a real
sandbox against the government, not a mock) — use it. Resist the urge to
patch based on the reported symptom alone; confirm the actual root cause by
reproducing the exact input that triggers it, especially for value-mismatch
bugs where several different upstream causes can look identical from the
outside (ISS sign error and a rounding bug both just look like "the total's
off by a bit").

## Step 4 — After fixing, ask what else might have the same bug

Several of the entries in `pitfalls.md` are one instance of a *pattern*
(falsy-as-missing, copy-pasted per-type mapping, stale test fixtures). If you
just fixed one instance, grep for the same pattern elsewhere in the fiscal
code before calling it done — pitfall #2 and #7 in particular are the kind
of bug that tends to exist in more than one place once you know what to
look for. Pitfalls #14, #15, and #17 are three more instances of exactly
this — each fix stayed scoped to the file where the bug was reported, and
the identical bug shape resurfaced in a sibling document type, a sibling
engine, or a sibling method, sometimes more than once. If the fix you just
made touches more than one document type or more than one engine could
plausibly share, don't stop at "grep this file" — run
`references/audit-checklist.md`'s Check 1–3 against the rest of the
codebase before calling it done.
