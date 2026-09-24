# Reliability patterns for fiscal document emission

These aren't Brazil-specific — they're how mature systems handle "create a
legally/financially significant document via a slow, occasionally-unreliable
third party I don't control." Apply them on top of the architecture in
`architecture.md`, not instead of it.

## Idempotency — one key, one cached result, never two different payloads

The reference implementation for this is Stripe's idempotency-key mechanic,
because it's the most precisely documented version of a pattern every vendor
here (Focus NFe's `ref`, Spedy's `integrationId`) is a variant of:

- **Generate the key as a real UUID (v4), never a short/predictable string.**
  This is pitfall #5 in `pitfalls.md` in miniature: a `nf-<uuid>`-style
  format blew past a vendor's undocumented field-length limit. A plain UUID
  avoids inventing your own format at all.
- **The first response for a key is what gets cached and replayed on
  retry — including error responses.** A retry with the same key doesn't get
  a fresh attempt if the first one failed; it gets the same failure back. If
  your local state disagrees with that (e.g. you fixed the underlying bug and
  want to actually retry), you need a *new* key, not a resend of the old one.
- **Never reuse a key with a different payload.** If a retry needs to change
  the item list or values, that's a new emission attempt, not the same one —
  mint a new reference. Treat "vendor says this ref was already used" as a
  signal to check whether your own key-generation logic accidentally reused
  one, not as something to route around by minting a new ref out of habit
  without checking why the collision happened.

Source: [Stripe idempotent requests](https://docs.stripe.com/api/idempotent_requests).

## Reconciliation — "Two-Path Status Verification"

The webhook (or fast poll response) is a *hint*, not the authoritative
record. The named pattern for this: **Two-Path Status Verification** — a
fast/best-effort push path (webhook) and a slow/authoritative pull path
(scheduled reconciliation poll), both converging on the same local state via
an idempotent upsert, so acting on the same status update twice — once from
each path — never corrupts anything.

The concrete, useful part: a **bounded, tiered polling schedule**, not naive
fixed-interval polling or unbounded exponential backoff — e.g. every 15s for
the first 2 minutes, every 2 minutes for the next hour, hourly after that,
with a hard ceiling where the system stops polling and alerts a human instead
of retrying forever. Only stop polling on a genuinely terminal state
(`AUTORIZADA`/`REJEITADA`/`CANCELADA`) — never on a transient error, which is
exactly pitfall #6 in `pitfalls.md` (a polling failure that got misclassified
as a real rejection).

**Test the reconciliation path against a document that was never actually
submitted anywhere, not just one that's genuinely mid-flight.** A real gap
found in this exact area: a process can crash between "marked PROCESSANDO,
a real number allocated" and "actually dispatched to the vendor/SEFAZ" —
leaving a document stuck at PROCESSANDO with nothing to reconcile against,
because it never reached the vendor at all. A reconciliation job that only
knows how to ask "what's the status of the thing I already sent" will
silently do nothing for this case forever. Explicitly test this scenario
(a PROCESSANDO record with no vendor reference/chave to poll) against your
reconciliation logic, not just the "vendor is slow to respond" case.

Sources: [Two-Path Status Verification](https://arxiv.org/html/2607.15529v1),
[webhook reliability patterns](https://dev.to/diven_rastdus_c5af27d68f3/stripe-webhook-reliability-patterns-every-saas-should-implement-2pg1).

## Validate locally before submitting — fail fast against the real schema

NF-e, NFC-e, and NFS-e Nacional all have official, publicly published XSD
schemas. Validating the assembled XML against the actual XSD **locally,
before it ever reaches SEFAZ**, catches a whole class of schema-shape errors
(wrong field order, wrong type, a missing mandatory element) for free —
before spending a real, numbered submission attempt (and, for NFePHP, a real
SEFAZ webservice call) discovering the same problem. This is a different,
earlier check than the CFOP/CST/CEST business-rule resolvers in
`architecture.md` — those catch wrong *values*; XSD validation catches wrong
*shape*. Do both; neither substitutes for the other.

Source: [fail-fast client-side validation](https://medium.com/@marco.salis/why-clients-should-fail-fast-on-api-contract-violations-49ecfba3c43f).

## Certificate expiry — tiered alerts, not one warning

A1 certificates are valid for a fixed period (commonly 1 year). The
consensus across independent sources is a **tiered alert schedule — 90 / 60
/ 30 days out, with escalation at 14 and 7 days** — not a single 30-day
warning, which is exactly the kind of thing that gets missed once (someone's
on leave, the alert email goes to a dead inbox) and then it's too late.
Combine with the storage guidance already in `architecture.md`/`SKILL.md`
(encrypt the `.pfx` at rest, AES-256 minimum; HSM/air-gapped storage is the
upgrade path once scale justifies it, not required for a single-tenant
build).

Sources: [X.509 private key storage](https://securew2.com/blog/best-practices-private-keys),
[certificate expiry alert timing](https://pulsetic.com/blog/ssl-certificate-expiry-monitoring/).

## Verified vendor schema cache — don't re-derive a vendor's contract from memory

`assets/spedy-schema-reference.md` is a live example of this pattern for
one vendor; the recipe generalizes to any REST vendor this skill
integrates with:

- **Fetch the vendor's real, current API specification directly** — the
  raw OpenAPI/JSON spec if they publish one, not a summarized doc page and
  not what you remember from a previous session. A summarization step
  (yours or a prior one) is exactly where a subtle field-name/type
  mismatch gets lost — the conflated-indicator bug in `pitfalls.md` #14
  was found precisely by re-fetching a vendor's raw spec instead of
  trusting a remembered/summarized version of it.
- **Extract only the fields actually relevant to what you're building**,
  not the whole spec. A curated excerpt stays useful; a dumped 500KB spec
  doesn't get read.
- **Cite the exact source URL and the fetch date** at the top of the cache
  file.
- **State explicitly, in the cache file itself, when it needs
  re-verification**: a new field is needed that isn't in the cache; a live
  rejection cites something that doesn't match what's cached; or enough
  time has passed that drift is plausible. Treat the cache as a fast first
  stop, not a permanent source of truth — check there first (saves a
  round-trip, gets you to the right field faster), re-fetch live when one
  of those three conditions is true.
- When you re-fetch and confirm nothing changed, note that in the cache
  file too — a "still current as of [date]" line costs nothing and tells
  the next person the cache isn't stale.

This is also Check 4 in `audit-checklist.md` in practice: an enum/status
completeness check is only as good as the source it's checked against.

## The regulator's contract changes on a schedule you don't control

Brazil's SEFAZ publishes **NT** (nota técnica) revisions that change required
fields, effective on a future date — sometimes with a coexistence period
where both the old and new layout are accepted, sometimes not. This isn't
unique to Brazil: the EU's Peppol/EN16931 e-invoicing standard does the same
thing with a named, reusable shape — **version-in-document + graduated
rollout**. Every document carries an explicit schema-version identifier, and
new validation rules ship as *warnings* in one release before being promoted
to hard *errors* in a later one, so integrators see "this will start failing
soon" before it actually does. Mexico's CFDI standard shows the other half of
this lesson: its 3.3→4.0 cutover deadline was **announced, then actually
pushed back** (June 2022 → March 2023) after the initial announcement.

**The concrete, generalizable rule**: treat any NT's effective date as
**configuration, not a hardcoded constant** in the codebase. A regulator
moving an announced deadline is a documented, real occurrence (see CFDI
above) — a hardcoded cutover date is exactly the kind of stale, silently-wrong
assumption the "never guess a fiscal value" principle in `SKILL.md` already
warns against for data; this is the same discipline applied to *dates*.

Sources: [Peppol BIS 3.0 release notes](https://docs.peppol.eu/poacc/billing/3.0/release-notes/),
[CFDI 3.3→4.0 deadline history](https://blog.seeburger.com/mexico-is-planning-a-major-e-invoicing-update-to-version-4-of-cfdi-4-0/).
