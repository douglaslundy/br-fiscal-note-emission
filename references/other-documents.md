# Beyond NF-e/NFS-e/NFC-e: the rest of the Brazilian DF-e family

This skill's architecture, engine comparison, and pitfalls catalog focus on
NF-e, NFS-e, and NFC-e because that's what a typical retail/service/e-commerce
business needs. Brazil's electronic-fiscal-document family is bigger than
that. Use this file to recognize when a project actually needs one of these
— and, just as important, to recognize when it doesn't and a request for one
of these is scope creep worth pushing back on.

## Worth covering: CT-e and MDF-e (if the business is a carrier)

**CT-e (Conhecimento de Transporte Eletrônico, modelo 57)** — issued by
whoever *performs* freight/cargo transport, not by whoever ships or
receives goods. A business buying freight from a carrier receives/
references a CT-e; it doesn't issue one. Legal basis: **Ajuste SINIEF
09/07** (25/10/2007) — confirmed live, 2026-09-23. Covers 5 transport
modais (rodoviário, aquaviário, aéreo, ferroviário, dutoviário) and can
substitute the older model-specific "conhecimentos" for each. The
**tomador do serviço** (service recipient) is a distinct role from NF-e's
destinatário: if they're not themselves authorized to issue CT-e, they
keep the **DACTE** (Documento Auxiliar do CT-e) as their accounting record
instead of the CT-e XML itself — don't assume the NF-e destinatário
pattern transfers directly.

**MDF-e (Manifesto Eletrônico de Documentos Fiscais, modelo 58)** —
bundles a set of CT-e/NF-e documents onto one vehicle for one trip. Legal
basis: **Ajuste SINIEF 21/2010** (confirmed live, 2026-09-23), replacing
the older "Manifesto de Carga" modelo 25. Same population as CT-e:
relevant only if the project *is* a transport/logistics operation, or is
building software for one. **Not a standalone sale document** — it
references already-issued NF-e/CT-e for one physical trip and has its own
event-driven lifecycle NF-e/NFC-e don't: the **encerramento** (closing)
event, provided for in that Ajuste's Cláusula Décima Quarta, is a
mandatory step at the end of the route (cargo delivered) — registered via
the events Web Service with UF/município de descarga, chave de acesso, and
protocolo. A project modeling MDF-e as "emit once, done" (the NF-e mental
model) will miss this required closing step.

These fit the same `FiscalProvider`-style abstraction from `architecture.md`
reasonably well — they're additional document types with their own
CFOP/tax-rule resolvers, going through the same emit/consult/cancel
lifecycle. If a project genuinely needs these, extend the existing
architecture rather than building a parallel system for them.

**Engine support differs from what this skill's core coverage might imply**:
Focus NFe's own product lineup includes CT-e and MDF-e. Spedy's does not —
their documented scope is NF-e/NFC-e/NFS-e only. For NFePHP, checked
directly against `nfephp-org`'s real GitHub activity (2026-09-23, via `gh
api repos/nfephp-org/<pkg>`): `sped-cte` (17 open issues/123 stars) and
`sped-mdfe` (8 open issues/55 stars) were BOTH pushed the same day as the
flagship `sped-nfe` package (3 open issues/1542 stars) — by raw recent-
activity evidence, `sped-mdfe` isn't meaningfully behind `sped-cte`; the
"newer/less established" framing doesn't hold up as strongly as this
skill's `engines.md` currently states (flagged there for correction). The
real, much sharper gap is **NFS-e**: there is no general-purpose
`sped-nfse` package at all. Only narrow, município-model-specific packages
exist — `sped-nfse-dsf` is explicitly marked "ABANDONADO, não será mais
mantido" by its own maintainers (last pushed 2020), and `sped-nfse-ginfes`
(GINFES-model municípios only) was last pushed December 2023 — neither is
remotely close to `sped-nfe`'s maintenance level, and neither covers NFS-e
Nacional's new national standard. See `engines.md` for the full engine
comparison. Confirm current support directly with whichever engine before
committing, since vendor product lineups change.

## Mention, don't go deep: niche enough that most projects won't need them

- **BP-e (Bilhete de Passagem Eletrônico)** — passenger transport tickets
  (bus/rail/ship, and an air-transport variant becoming mandatory
  Dec 1, 2026). Relevant only to passenger-transport operators.
- **NF3e (modelo 66)** — electric energy invoicing. Relevant only to
  utilities.
- **NFCom (modelo 62)** — telecom services, replacing older models 21/22.
  **Correction (confirmed live, 2026-09-23): this was already mandatory as
  of November 1, 2025** — not "becoming mandatory," already in force for
  over 10 months as of this writing. `Ajuste SINIEF 25/2025` (09/10/2025)
  allowed a narrow extension to August 1, 2026 for taxpayers already
  issuing NFCom for ≥60% of their models-21/22/62 volume as of Nov/2025 —
  that exception window has also now closed. If a project needs NFCom
  today, treat it as a live, currently-enforced requirement, not an
  upcoming one. Relevant only to telecom providers.
- **GNRE (Guia Nacional de Recolhimento de Tributos Estaduais)** — not a
  fiscal *document* in the same sense as the others; it's a payment slip for
  state taxes, most often ICMS-ST owed to a different state than the
  emitter's own. Comes up alongside interstate ICMS-ST operations. Worth
  knowing it exists if a project does interstate B2B sales with ST; not
  worth building deep support for unless that's a confirmed, real
  requirement.

If a request comes in for any of these, treat it as "this is a real,
distinct document type with its own rules — research it properly the way
this skill's core three were researched, don't guess based on family
resemblance to NF-e." A CFOP resolver or ISS calculation pattern from NF-e/
NFS-e does not transfer to BP-e/NF3e/NFCom by analogy.

## Sunset: CF-e-SAT (don't build new integrations against this)

**CF-e-SAT** (cupom fiscal issued via SAT hardware, used mainly in São
Paulo retail) is being phased out — São Paulo prohibits new CF-e-SAT
issuance starting **January 1, 2026**; existing users must migrate to
NFC-e (or NF-e). If a request mentions SAT/CF-e-SAT, the answer is almost
always "use NFC-e instead, SAT is being retired," not "integrate with SAT."

## NFS-e is mid-transition to a national standard — this changes over time

`domain-concepts.md` describes NFS-e as fragmented across hundreds of
município-specific layouts with no single national standard. That's true
historically, but Brazil is actively rolling out **NFS-e Nacional** (the
padrão nacional / ADN — Ambiente de Dados Nacional): adoption by
**municípios** became mandatory January 1, 2026. **Re-verified live,
2026-09-23**: per CNM (Confederação Nacional de Municípios), 4,000+ of
Brazil's ~5,570 municípios have joined the convênio, and the national
platform already carries roughly 70% of the country's total NFS-e volume —
adoption is well past "early rollout" at this point, though a real tail of
smaller municípios still hasn't joined (municípios that don't adhere lose
access to voluntary federal transfers, which is the actual enforcement
lever, not a technical block).

**Mandatory issuance by businesses — corrected, this had already changed
by the date in this skill's prior research pass.** The rule is specifically
about **ME/EPP opting into Simples Nacional**, not businesses generally,
and the date moved: **Resolução CGSN nº 189/2026** originally set September
1, 2026; **Resolução CGSN nº 191/2026** (published 2026-08-14, confirmed
live 2026-09-23) revoked that and pushed it to **November 1, 2026**. The
same resolution separately confirms IBS/CBS-specific rules for Simples
Nacional participants only take effect 2027-01-01 — don't conflate the
NFS-e-issuance deadline with the tax-reform-fields deadline, they're two
different dates in the same resolution. **This is a live, government-set
date that can move again** (it already moved once) — re-verify against
`gov.br/receitafederal` or `gov.br/nfse` before treating it as fixed if a
real deadline depends on it.

**What this means in practice, as of when this was last checked**: some
municípios are on the new national standard, some are still on legacy
layouts (ABRASF, Ginfes, WebISS, and others), and the honest current
guidance is "check whether the specific município has migrated" rather than
either extreme — don't assume every município is unified yet, and don't
assume fragmentation is permanent either. This is exactly the kind of fact
that changes on a government timeline (see `reliability-patterns.md`'s
regulatory-versioning section) — verify current status rather than trusting
this file's date indefinitely.
