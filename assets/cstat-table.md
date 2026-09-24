# cStat rejection-code lookup table (NF-e / NFC-e)

Source: `nfephp-org/sped-nfe`'s own `docs/cStat.md`
(https://raw.githubusercontent.com/nfephp-org/sped-nfe/master/docs/cStat.md) —
the same library this skill treats as authoritative for the NFePHP engine.
This is a lookup table, not prose to summarize from memory — when a real
`cStat` shows up in a rejection, check it against this table (or the source
file itself for anything not listed here) rather than guessing what a code
number means.

| Code | Category | Meaning | Typical fix |
|---|---|---|---|
| 100 | Success | Autorizado o uso da NF-e | — |
| 103 | Success | Lote recebido com sucesso | Poll for the batch's final result |
| 104 | Success | Lote processado | Read the per-document result inside the batch |
| 135 | Success | Evento registrado e vinculado a NF-e | (used for cancelamento/CC-e events) |
| 204 | Duplicidade | Duplicidade de NF-e (nota já autorizada) | Don't resend — query by chave/protocolo instead, this is the exact bug pattern in pitfalls.md #7's cousin: treat "already exists" as a signal to reconcile, not to retry blindly |
| 215 | Schema/XML | Falha no schema XML do lote de envio | Validate locally against the official XSD before submitting (see architecture/reliability guidance) |
| 225 | Schema/XML | Falha no schema do XML do lote de eventos | Same — validate the event XML shape before sending |
| 243 | Schema/XML | XML mal formado | Usually a serialization bug in the XML builder, not a business-rule error |
| 383 | CST/CSOSN | Item com CSOSN indevido | Regime tributário do emitente não é Simples Nacional mas o item usa CSOSN — usar CST |
| 384 | CST/CSOSN | CSOSN não permitido para a UF do emitente | |
| 516/517 | Schema/XML | Lote/tag raiz do XML ausente ou inválida | Structural XML problem, check the envelope, not the business fields |
| 518/519 | CFOP | CFOP de entrada usado numa operação de saída (ou o inverso) | Resolver bug — check the CFOP resolver's direction logic |
| 520/521 | CFOP | CFOP incompatível com a UF do emitente/destinatário | Resolver picked the wrong same-state/interstate branch |
| 523 | CFOP | CFOP de operação com o exterior usado indevidamente | |
| 539 | Duplicidade | Duplicidade com NF-e de outra chave de acesso | Real key-generation bug, not a simple retry — investigate before resending |
| 590 | CST/CSOSN | CST informado por emitente do Simples Nacional (deveria ser CSOSN) | Regime × código table mismatch — see domain-concepts.md |
| 591 | CST/CSOSN | CSOSN informado por quem não é do Simples Nacional | |
| 600 | CST/CSOSN | CSOSN incompatível em operação com não-contribuinte | |
| 611 | GTIN | `cEAN` inválido | See pitfalls.md #4 — send the `SEM GTIN` sentinel, don't omit the field |
| 612 | GTIN | `cEANTrib` inválido | Same as 611, but the tributável-unit EAN field specifically |
| 724/725 | CFOP (NFC-e) | CFOP inválido para NFC-e | NFC-e's CFOP resolver only has 2 valid outputs (5102/6108) — check nothing else is leaking through |
| 777/778 | NCM | NCM incompleto ou inexistente na tabela vigente | NCM table itself may be stale — confirm against the current Receita Federal table |
| 806 | ICMS-ST | Operação com ICMS-ST sem informação do CEST | See pitfalls.md #3 |
| 817 | NCM | Unidade tributável incompatível com o NCM informado | |
| 142, 467, 468, 485, 691, 692 | Contingência/EPEC | Various: EPEC bloqueado, divergência de dados no EPEC x NF-e real, duplicidade de numeração em EPEC, chave divergente | See domain-concepts.md's contingência section — these only apply to the NFePHP/EPEC path |

**Not exhaustive.** This covers the codes most relevant to the mistakes already
catalogued in `pitfalls.md`. For anything else, go to the source file above —
don't guess at what an unlisted `cStat` means from the number alone.
