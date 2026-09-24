# br-fiscal-note-emission

A [Claude Agent Skill](https://docs.claude.com/en/docs/claude-code/skills) for building, extending, or debugging Brazilian electronic fiscal document emission (NF-e, NFS-e, NFC-e, and — more lightly — CT-e/MDF-e and other DF-e types).

Covers three integration paths — **Focus NFe**, **Spedy**, and **NFePHP/sped-nfe** — with accurate, source-verified per-engine coverage, CFOP/CST-CSOSN/ICMS/ISS rules with legal citations, reliability patterns for production systems (idempotency, status reconciliation, schema validation, certificate lifecycle, handling a regulator that changes the contract on its own schedule), and a systematic checklist for auditing an existing integration.

## Why

Brazilian fiscal documents are legal instruments, not receipts. A wrong value isn't cosmetic — it's an incorrect statement to a tax authority and often a real charge to a real customer. This skill's central discipline: **never guess a fiscal value; block and ask instead of defaulting and hoping.** See `SKILL.md` for how that shows up in practice.

## What's in here

- `SKILL.md` — entry point: the "never guess" principle, a router for which situation you're in (building from scratch / extending existing code / auditing an existing integration / debugging a reported bug), an engine decision table, and pointers into everything below.
- `references/architecture.md` — the provider-interface pattern that keeps tax logic independent of the engine.
- `references/engines.md` — full comparison of Focus NFe, Spedy, and NFePHP: request shape, sync vs. async, quirks, real per-engine document-type coverage.
- `references/domain-concepts.md` — CFOP, CST/CSOSN, CRT, origem, ISS, ambiente, numeração, contingência, with legal citations.
- `references/other-documents.md` — CT-e, MDF-e, and the rest of the DF-e family, including NFS-e's transition to a national standard.
- `references/reliability-patterns.md` — idempotency, status reconciliation, fail-fast schema validation, certificate expiry alerting, and a repeatable recipe for caching a vendor's real, verified schema locally.
- `references/pitfalls.md` — 19 real production bugs, each as symptom → root cause → fix → generalized lesson.
- `references/audit-checklist.md` — a systematic method for auditing an *existing* fiscal integration end to end, so a review catches a whole class of bug in one pass instead of finding a different one each round.
- `references/debugging-checklist.md` — step-by-step triage for a single reported fiscal problem.
- `assets/` — cached, dated, source-cited references: a SEFAZ `cStat` rejection-code table, and verified schema/field caches for Focus NFe, Spedy, and the installed NFePHP (`sped-nfe`) package.
- `scripts/reference-calculations.php` — tested reference implementations for the domain math (ISS "por dentro", etc.) so you don't hand-derive it.
- `evals/` — eval prompts used while developing and hardening this skill.

## Using it

Drop this directory into a project's `.claude/skills/` (or wherever your Claude Code setup loads skills from) and it triggers on the usual Brazilian fiscal vocabulary — nota fiscal, NF-e, NFS-e, NFC-e, CT-e, MDF-e, CFOP, CST, CSOSN, ICMS, ISS, SEFAZ, homologação/produção, certificado A1, contingência/EPEC, cStat — or when asked to integrate Focus NFe, Spedy, NFePHP, or ACBr.
