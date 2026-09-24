# Architecture: keep tax law out of the engine adapters

The core design decision that makes it possible to support three completely
different engines (two REST vendors, one direct-to-SEFAZ library) without
duplicating tax logic three times: **the provider layer only translates
format. It never decides a tax rule.** All CFOP/CST-CSOSN/ISS/discount
decisions happen once, upstream, and arrive at the provider as a finished,
unambiguous instruction.

## The shape of the abstraction

```php
interface FiscalProvider
{
    public function registrarEmissor(EmissorData $emissor): RegistroResultado;
    public function enviarCertificado(EmissorData $emissor, string $pfxBinary, string $senha): void;
    public function emitir(NotaFiscalData $nota): EmissaoResultado;
    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado;
    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado;
}
```

Five methods cover the whole document lifecycle:

1. **`registrarEmissor`** — onboard the issuing company with the engine (for
   a REST vendor, this is a real API call that creates a company record; for
   a direct-to-SEFAZ engine, this degenerates into local validation that the
   config has the fields the government XML layout requires — there's no
   third party to register with).
2. **`enviarCertificado`** — attach the A1 digital certificate, when the
   engine needs one directly (REST vendors that hold the certificate on your
   behalf need this; if you're driving SEFAZ yourself, the certificate is
   read locally per emission instead).
3. **`emitir`** — send one finished document for authorization.
4. **`consultar`** — poll status by reference (needed because several engines
   are asynchronous — see `engines.md`).
5. **`cancelar`** — cancel an already-authorized document, which in Brazilian
   fiscal law is its own signed event, not a delete.

`$modelo` (`'NFE' | 'NFSE' | 'NFCE'`) lets one provider instance route
internally to different sub-resources or engines for different document
types, since a single vendor account often issues all three.

## The two DTOs that cross the boundary

**Input: `NotaFiscalData`.** A flat, engine-agnostic description of exactly
one document. By the time this object exists, every tax decision has already
been made — CFOP, CST/CSOSN, NCM, origem, and the final computed values are
already resolved fields on it, not things the provider figures out. It
typically carries:

- Tomador (recipient): document number, name, address, UF
- Itens: description, quantity, unit value, and the already-resolved
  CFOP/CST-CSOSN/NCM/origem for each
- Totais: subtotal, desconto, valor_total, tax base/amounts by type
- Forma de pagamento
- Número/série already allocated (see `domain-concepts.md` on numeração)
- Regime tributário of the issuer (needed for some engines' payload shape,
  even though the *decision* of what regime implies was already made upstream)

**Output: `EmissaoResultado`.** Normalizes wildly different vendor response
shapes into one status enum plus a common set of fields, so nothing above the
provider layer needs to know which engine produced the result:

- `status`: `AUTORIZADA | PROCESSANDO | REJEITADA | CANCELADA | CONTINGENCIA | ERRO`
- `chaveAcesso`, `protocolo`, `numero`
- `xml`, `pdfUrl`, `qrCodeUrl` (NFC-e needs the QR code; NF-e/NFS-e usually don't)
- `mensagemErro` (when `REJEITADA` or `ERRO`)

The status enum matters more than it looks: `PROCESSANDO` and `ERRO` must
stay genuinely distinct from `REJEITADA` — see pitfall #6 in
`pitfalls.md` for what happens when a polling failure gets conflated with an
actual government rejection.

## Where the real tax logic lives

Above the provider layer, one service owns tax computation and validation —
call it the equivalent of `CriarNotaFiscalService`. Its job:

1. Compute subtotal from items, apply discount (see `domain-concepts.md` for
   how ISS interacts with this).
2. Resolve CFOP and CST/CSOSN per item via small, single-purpose resolvers
   that take only the inputs that legally determine the answer (e.g. "same
   state or not" + "ICMS-ST or not" → CFOP; "tax regime" + "ICMS
   classification" → CST/CSOSN) and **throw on any combination they weren't
   built to handle** rather than falling through to a default.
3. Run the missing-data checks from the "never guess" principle in
   `SKILL.md` — block with a typed exception before ever reaching a provider.
4. Build the finished `NotaFiscalData` and hand it to whichever
   `FiscalProvider` the tenant/environment is configured to use.

A higher-level orchestrator can sit above this when one business transaction
produces more than one document type (e.g. an order with both goods and
services → one NF-e for the goods, one NFS-e for the services). Keep those
two emissions **independent**: one document type failing validation or
getting rejected should never block the other from going out. Report what
succeeded and what didn't rather than treating the whole operation as
atomic — these are two separate legal documents, not one transaction.

## Why this pays off

This is what makes it possible to add a third engine to a working system
without touching a single tax-rule resolver: a new engine is a new class that
implements the five-method interface and translates
`NotaFiscalData → engine's own request shape` and
`engine's own response shape → EmissaoResultado`. If you ever find yourself
writing CFOP or ISS logic *inside* a provider/adapter class, that's a sign
the abstraction boundary has leaked — move it upstream.
