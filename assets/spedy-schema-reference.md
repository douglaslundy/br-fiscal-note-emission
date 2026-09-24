# Spedy — schema real (cache local, não adivinhado)

**Fonte**: `https://docs.spedy.com.br/openapi/v1.json` (OpenAPI 3, ~555KB),
baixado e lido em bruto (não resumido por um modelo intermediário) em
2026-09-23. Guias em prosa: `https://docs.spedy.com.br/guides/emissao-nfe.md`,
`https://docs.spedy.com.br/api-reference/nf-e/criar-nf-e.md`.

**Como usar este arquivo**: é um cache local, não a fonte de verdade
permanente. Consulte aqui primeiro em vez de sair buscando na web toda vez
— economiza uma chamada de rede e chega no campo certo mais rápido. Só
volte a buscar ao vivo (`https://docs.spedy.com.br/openapi/v1.json`) se:
(a) o campo que você precisa não está aqui, (b) uma rejeição real citar um
campo/formato que não bate com o que está documentado aqui (sinal de que a
Spedy mudou algo desde 2026-09-23), ou (c) já se passou muito tempo desde
essa data e o risco de estar chutando um contrato desatualizado for real.
Se reconfirmar e algo mudou, atualize este arquivo — é exatamente o tipo
de "nota técnica do vendor" que o resto desta skill já trata como
configuração, não constante fixa (ver `reliability-patterns.md`).

## Achado central: `indFinal` ≠ `indIEDest`

O schema da Spedy **não expõe um campo `indIEDest` explícito**. O único
indicador relacionado é `isFinalCustomer` (raiz do payload, obrigatório),
documentado literalmente como `"Consumidor Final [indFinal]"` — ou seja, é
o indicador **indFinal** da NF-e real, que é um campo fiscal **diferente**
de `indIEDest` (situação da IE do destinatário). A Spedy provavelmente
deriva `indIEDest` internamente a partir de `receiver.stateTaxNumber` estar
preenchido ou não — mas isso **não está confirmado nesta doc**, é inferência
razoável, não fato lido.

## `POST /v1/product-invoices` — NF-e (modelo 55)

Schema: `CreateProductInvoiceDto`. Campo obrigatório na raiz:
`isFinalCustomer` (boolean).

Campos relevantes pra este bug (achado 2026-09-23, NF-e real #13,
`cStat=232`):

```json
{
  "isFinalCustomer": {
    "type": "boolean",
    "description": "Consumidor Final [indFinal]"
  },
  "receiver": {
    "$ref": "#/components/schemas/SefazInvoiceReceiverDto",
    "description": "Destinatário [dest]"
  }
}
```

`SefazInvoiceReceiverDto` (usado por `receiver` em NF-e e NFC-e):

```json
{
  "name":              { "type": "string",  "nullable": true, "description": "Nome" },
  "federalTaxNumber":  { "type": "string",  "nullable": true, "description": "CPF / CNPJ / Doc Estrangeiro" },
  "stateTaxNumber":    { "type": "string",  "nullable": true, "description": "Inscrição estadual" },
  "suframaTaxNumber":  { "type": "string",  "nullable": true, "description": "Inscrição na SUFRAMA do destinatário [ISUF] — obrigatória em vendas com isenção para ZFM/Áreas de Livre Comércio" },
  "cityTaxNumber":     { "type": "string",  "nullable": true, "description": "Inscrição municipal" },
  "email":             { "type": "string",  "nullable": true },
  "phoneNumber":        { "type": "string",  "nullable": true },
  "address":           { "$ref": "#/components/schemas/AddressCreateDto", "nullable": true }
}
```

**Não existe campo separado pra "isento de IE"** neste schema — nem em
`SefazInvoiceReceiverDto` nem em `CreateProductInvoiceDto`. Pra um
destinatário PJ isento (indicador 2 no sentido real da NF-e), a única
coisa confirmada é: não mandar `stateTaxNumber`. Se isso é suficiente pra
Spedy derivar indIEDest=2 corretamente (em vez de cair em 9 ou 1 por
omissão), **não está testado contra o sandbox real** — ver
`engines.md`/pitfalls relacionados.

Correção aplicada no código (`SpedyProvider::montarPayloadNfe()`):
`isFinalCustomer = true` só quando o destinatário é pessoa física/não
contribuinte (mesmo valor de antes pra esse caso); `false` + envia
`stateTaxNumber` quando há IE real; `false` sem `stateTaxNumber` quando
isento. A correlação "venda B2B pra contribuinte não é consumidor final" é
regra de domínio fiscal (LC/Ajustes SINIEF), não uma suposição sobre a API
da Spedy — só o nome e existência do campo `stateTaxNumber` vieram
confirmados desta doc.

## `POST /v1/consumer-invoices` — NFC-e (modelo 65)

Schema: `CreateConsumerInvoiceDto`. Mesmo campo obrigatório
(`isFinalCustomer`) e mesmo `receiver` (`SefazInvoiceReceiverDto`) do NF-e.
Diferente do NF-e, aqui `isFinalCustomer=true` é sempre correto — NFC-e é
por definição venda a consumidor final, isso nunca foi o bug (ver
`domain-concepts.md`, seção CFOP — mesma regra de domínio, não específica
da Spedy).

## `POST /v1/service-invoices` — NFS-e

Schema: `CreateServiceInvoiceDto`. Campos obrigatórios: `description`,
`total`. **Não tem `isFinalCustomer` nem o conceito de indIEDest** —
esperado, ISS/NFS-e não tem essa distinção de contribuinte de ICMS. Usa
`receiver`, mas campos fiscais próprios de serviço (`cnaeCode`,
`cityServiceCode`, `taxationType`, `total`, `location`) — schema não
detalhado aqui porque não fazia parte do bug investigado; buscar ao vivo se
precisar.

## Campos tributáveis obrigatórios do item (NF-e/NFC-e)

Já confirmado e em uso desde 2026-09-10 (ver `pitfalls.md`): `quantityTax`/
`unitTaxAmount` (uTrib/qTrib/vUnTrib) são obrigatórios no item mesmo sem
unidade de conversão real — schema `SefazInvoiceItemDto`, compartilhado
entre NF-e e NFC-e.
