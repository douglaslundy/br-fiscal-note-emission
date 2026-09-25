# Auditing an existing fiscal integration

Use this when the task is "validate/harden what's already built" — a
pre-launch review, a "make sure we didn't miss anything," or a systematic
check that isn't triggered by one specific reported symptom. For a single
reported bug, use `debugging-checklist.md` instead; its Step 4 points back
here once that bug is fixed.

This file exists because of a real, documented case (see `pitfalls.md`
#12–#19) where sequential audit rounds each found a *different* bug in the
same codebase — not because the codebase was unusually bad, but because
each round was organized file-by-file/engine-by-engine, and several of the
real bugs were the SAME underlying pattern recurring in a sibling file
nothing had explicitly re-checked. The goal here is to catch as much of a
bug *class* as possible in one pass, by checking systematically across the
axes bugs actually recur on, instead of hoping the next round finds what
this one missed.

## Before you start: build the grid

List every (engine) × (document type) combination the project actually
implements — e.g. Focus NFe×NF-e, Focus NFe×NFC-e, NFePHP×NF-e, NFePHP×
NFC-e, NFePHP×NFS-e. Most projects won't fill every cell, that's fine — but
you need the real list before auditing, because most of the bugs this
checklist exists to catch are not unique to one cell. They recur across
cells that share code shape.

## Check 1 — Cross-document-type mirroring

For every fiscal field, indicator, or validation you find (or just fixed)
on one document type, explicitly check every SIBLING document type the
same engine issues for the same field name / same underlying concept.
Concrete process: name the field, list the engine's document-type
builders, check each one — don't stop at the first place you found it.

Concrete case this caught (`pitfalls.md` #15): a destinatário's Inscrição
Estadual missing from one document type was found and fixed there, then
found AGAIN on a sibling document type of a different engine, then found a
THIRD time on a sibling document type of the FIRST engine too — three
separate discoveries of the identical gap, each on a different day,
because nothing forced this check right after the first fix.

This check runs in both directions — it applies just as much to REMOVING
something as to adding it. Concrete case (`pitfalls.md` #19): a status
value was correctly confirmed "no longer returned" for one document type
and deleted from a function shared by three — breaking a sibling type
where that exact value was still real. Before deleting a branch/field/
value as dead, confirm the claim against every document type sharing the
code you're touching, not just the one you were looking at.

## Check 2 — Cross-engine mirroring

For every bug found in one engine's code, explicitly re-check the SAME bug
shape in every OTHER engine the project integrates — even if that other
engine's file "looks fine" on a first read. A conflated-indicator bug
(`pitfalls.md` #14) doesn't look wrong until you specifically check the two
concepts it confuses against each other; a clean-looking file isn't
evidence the same confusion isn't present in a different, harder-to-spot
form.

## Check 3 — Sibling-lock/guard consistency

Whenever you find (or add) a lock, guard clause, idempotency check, or
"block-instead-of-guess" exception protecting shared state that triggers
an external side effect (billing, email, sequential numbering, a real
government submission), search explicitly for every OTHER method in the
codebase with the same shape: reads current state, decides based on a
transition, then triggers something external/irreversible. Apply the
identical protection there too — don't assume a method living in a
different file or "layer" doesn't share the exact shape.

Concrete case this caught (`pitfalls.md` #17): a double-submission race was
correctly fixed with a row lock in one method. A second, separate method —
same shape (read status, act on a transition, fire an external side
effect) — had the identical gap, missed entirely in the first pass because
the audit was organized per-file, not per-pattern.

## Check 4 — Enum/status completeness against the LIVE vendor contract

For every status/enum field a vendor returns, fetch the vendor's real,
CURRENT documentation or schema directly — not memory, not a prior
session's summary. Enumerate every value it says is possible. Every
`match`/`switch`/if-chain handling that field needs an explicit, deliberate
arm for each one — no default-to-a-safe-looking-value fallback for an
unhandled value.

Then ask specifically: which of the unhandled values are TERMINAL (never
going to resolve into a handled one on their own)? Those are the dangerous
ones. A transient/pending value silently defaulting to "still pending" is
usually harmless; a terminal value doing the same is a silent, permanent
failure to ever notify anyone. See `pitfalls.md` #16 for the concrete case
(a "Denegado" — legally distinct from an ordinary rejection — status
looping as "still processing" forever).

## Check 5 — Treat "this is a business rule" comments as hypotheses

Grep the codebase for confident, undocumented business-rule assertions
near fiscal logic — comments with words like "always," "never," "this
business doesn't do X." List every one you find. For each, ask: is it
backed by (a) a legal/regulatory citation, (b) the vendor's own schema/
docs, or (c) nothing but the original author's assumption? Anything in
bucket (c) goes back to whoever owns the business decision to confirm —
don't accept it as already-verified just because it's phrased with
confidence, and don't "fix" it yourself either; you don't have the
authority to decide a real business rule. See `pitfalls.md` #18.

## Check 6 — Never fabricate missing regulatory data

When a real calculation needs data you don't have and can't verify (a
per-jurisdiction rate table, a fee schedule, a threshold set by law), don't
guess a plausible number and don't silently skip the check. Detect the
exact triggering condition and block emission with a clear, actionable
local error. A missing feature that fails loud and local beats one that
fails silently now and gets discovered by a real government rejection
later. See `pitfalls.md` #12 for the concrete DIFAL example.

## How to brief a verification/second-pass audit so it actually finds things

A pass told only "re-verify the fixes from round 1" will mostly do exactly
that — confirm what's already there — because that's a narrower, easier
task than actively hunting for gaps. If you're running a second (or Nth)
pass over already-audited/already-fixed code, brief it explicitly to:

- re-derive each claimed fix from the actual current code, not from a prior
  report's *description* of it — a report can be wrong about what actually
  shipped
- specifically run Checks 1–3 above AGAINST the previous round's fixes
  themselves — did the fix that closed one instance of a pattern get
  applied to every sibling instance, or just the one that was reported?
- be tasked with finding something NEW, not just confirming — "actively try
  to break the assumption that this file is fine" surfaces real gaps in a
  way that "does this look OK?" does not. See `pitfalls.md` #17, found only
  because a second pass was briefed this way.

## Verified-schema-cache workflow

See "Verified vendor schema cache" in `reliability-patterns.md` for the
full recipe. Short version: before trusting what you remember about a
vendor's API, fetch their real, current spec directly and cache the
relevant excerpt locally with a source URL and fetch date, so Check 4 above
is actually checking the live contract, not a stale mental model.

## When you're done

You haven't finished auditing a fiscal integration just because you've
read each file once, in isolation. You're done once you can say, for every
fiscal field/indicator the project sends: which document types and engines
send it, whether each one derives it the same correct way, and — for
anything you couldn't verify against a citable source — who confirmed it's
actually true for this business.

## Check 7 — The printed document must come from what was authorized

For every document type and engine: find where the PDF/DANFE/e-mail body gets
each field and confirm it is read from the **authorized XML** (or a snapshot
saved at emission), not from a parallel column that merely usually matches.
Render the real template from a real authorized XML in a test (pitfall #23).

## Check 8 — Validate real authorized XMLs against the official XSD

Take one authorized XML per document type and validate it with the schema
shipped in the library (`DOMDocument::schemaValidate`). Gotchas learned the
hard way:
- libxml implements XSD 1.0 — a `pattern` using regex lookahead (the NFS-e
  national `^(?!0{1,5}$)\d{1,5}$`) fails to *compile*. Validate a **copy** of
  the schema with an equivalent rewrite; never edit `vendor/`.
- NF-e: validate `nfeProc` against `procNFe_v4.00.xsd` (not `NFe`), or the
  protocol block is skipped.
- A mismatch between the bundled XSD and what the authority actually accepts
  (e.g. `cNBS` required by the bundled XSD but authorized without it) is a
  finding to **report**, not to "fix" by inventing a value — see Check 6
  (never fabricate missing regulatory data).
- Read `tpAmb` from the XML while you're there (pitfall #25).

## Check 9 — Numbering drift against the authority

List the numbers the authority knows (query a sample of recent keys) versus
the local counter/rows. Any local deletion of fiscal rows, restore from
backup, or manual counter change can put the counter behind what SEFAZ
remembers (pitfall #22). The automatic recovery must confirm the occupying
document is cancelled before skipping a number.
