# Focus NFe — schema real (cache local, não adivinhado)

**Fonte**: `https://doc.focusnfe.com.br/reference/emitir_nfe.md`,
`emitir_nfce.md`, `consultar_nfe.md`, `consultar_nfce.md`,
`consultar_nfse.md`, `cancelar_nfe.md`, `cancelar_nfce.md`, `reference/`
(índice de tipos de documento) — todos buscados ao vivo (WebFetch, não
resumo de memória de sessão anterior) em 2026-09-23.

**Como usar este arquivo**: cache local, não fonte de verdade permanente.
Consulte aqui primeiro em vez de sair buscando na web toda vez. Só volte a
buscar ao vivo se: (a) o campo que você precisa não está aqui, (b) uma
rejeição real citar um campo/formato que não bate com o que está
documentado aqui, ou (c) já se passou muito tempo desde 2026-09-23 e o
risco de estar chutando um contrato desatualizado for real. Se
reconfirmar e algo mudou, atualize este arquivo — mesma política de
`spedy-schema-reference.md`.

## ⚠ Achado ao construir este arquivo, diferente do que uma auditoria
## anterior desta mesma sessão concluiu — reconfira antes de confiar

Uma auditoria anterior (dentro desta mesma sessão, antes deste arquivo
existir) concluiu "Focus não tem um campo equivalente a indFinal" e,
separadamente, removeu como "código morto" um branch que tratava o status
`denegado` em `mapStatus()`, citando que a NT 2024.001 teria extinto esse
status. **As duas conclusões precisam ser reconfirmadas contra o código
real de quem usa este arquivo** — o que este fetch ao vivo encontrou:

1. **NF-e TEM, sim, um campo equivalente a indFinal**: `consumidor_final`
   (integer, opcional) — `"0=Normal, 1=Consumidor final"` —, no payload de
   `emitir_nfe`. Isso não apareceu na auditoria anterior porque ela
   aparentemente só olhou o payload de NFC-e (que de fato não tem esse
   campo) e generalizou pra NF-e sem checar separadamente. Se o código que
   você está auditando/construindo deriva `consumidor_final` da mesma
   forma errada que o achado original desta sessão (indFinal ≠ indIEDest,
   ver `pitfalls.md` #14) — i.e., de `indicador_inscricao_estadual_destinatario`
   em vez de uma regra de negócio real sobre revenda — **isso é
   exatamente o mesmo bug shape, só que num motor onde a auditoria anterior
   concluiu (errado) que o campo nem existia.** Confira o código real antes
   de assumir que já foi coberto.
2. **NFC-e TEM um 5º status real, `denegado`, que NF-e/NFS-e não têm.**
   `consultar_nfce`'s schema documenta `NFCeConsultaResponse` como podendo
   retornar `autorizado, erro_autorizacao, denegado` (mais
   `processando_autorizacao`/`cancelado` via outros schemas) — 5 valores
   reais pra NFC-e, contra 4 pra NF-e/NFS-e. Se o código que você está
   auditando tem um único `mapStatus()` compartilhado entre NF-e e NFC-e
   (comum, é o padrão deste projeto) e o branch `denegado` foi removido
   citando só a doc de NF-e, **NFC-e pode ter perdido o tratamento de um
   status real e ainda válido**, não morto — confirme se o parsing de
   status é realmente unificado ou se há uma checagem por tipo de
   documento antes de reintroduzir/remover esse branch.

Ambos os pontos acima são reportados aqui como achado de schema, não como
bug confirmado no código de nenhum projeto específico — quem usa este
arquivo precisa checar o código real antes de agir.

## `POST` emissão NF-e (`emitir_nfe`)

Campos relevantes a destinatário/classificação (schema `NFeRequest`):

```
cnpj_destinatario                          (string, opcional — usar este OU cpf_destinatario)
cpf_destinatario                           (string, opcional)
inscricao_estadual_destinatario            (string, opcional)
indicador_inscricao_estadual_destinatario  (integer, opcional) — "1=Contribuinte ICMS, 2=isento, 9=Não Contribuinte"
consumidor_final                           (integer, opcional) — "0=Normal, 1=Consumidor final"  ← indFinal real, ver achado acima
natureza_operacao                          (string, OBRIGATÓRIO)
finalidade_emissao                         (integer, OBRIGATÓRIO) — "1=Normal, 2=Complementar, 3=Nota de ajuste, 4=Devolução"
local_destino                              (integer, opcional) — "1=interna, 2=interestadual, 3=com exterior"
presenca_comprador                         (integer, opcional) — escala 0–9; "1=presencial, 2=Internet, 3=Teleatendimento, 4=entrega domicílio"
modalidade_frete                           (integer, opcional) — "0=emitente, 1=destinatário, 2=terceiros, 9=Sem frete"
valor_frete                                (number, opcional)
data_emissao                               (string ISO 8601, OBRIGATÓRIO)
tipo_documento                             (integer, OBRIGATÓRIO) — "0=entrada, 1=saída"
items                                      (array, OBRIGATÓRIO)
```

## `POST` emissão NFC-e (`emitir_nfce`)

Schema `NFCeRequest` — mesma família de campos de destinatário que NF-e,
**mas sem `consumidor_final`/indFinal-equivalente** (confirmado: não existe
esse campo no schema de NFC-e — domínio correto, já que NFC-e é sempre
consumidor final por definição, isso nunca foi bug aqui):

```
nome_destinatario                          (string, opcional)
cnpj_destinatario                          (string, opcional) — campo real, documentado, NÃO exclusivo de NF-e
cpf_destinatario                           (string, opcional)
indicador_inscricao_estadual_destinatario  (string, opcional) — "1=Contribuinte ICMS, 2=Isento, 9=Não contribuinte"
presenca_comprador                         (string, OBRIGATÓRIO em NFC-e, ao contrário de NF-e) — "1=Presencial, 4=Entrega a domicílio"
```

A doc do schema menciona que NFC-e "possui centenas de campos" e aponta
pra `https://campos.focusnfe.com.br/nfe/NotaFiscalXML.html` como
referência completa — este arquivo extrai só os campos relevantes a bugs
já investigados nesta sessão, não o schema inteiro.

## Vocabulário de status — 3 conjuntos DIFERENTES, não um só

| Documento | Valores confirmados |
|---|---|
| NF-e (`consultar_nfe`) | `autorizado`, `processando_autorizacao`, `cancelado`, `erro_autorizacao` (4) |
| NFS-e (`consultar_nfse`) | idêntico a NF-e (4) |
| NFC-e (`consultar_nfce`) | `autorizado`, `processando_autorizacao`, `cancelado`, `erro_autorizacao`, **`denegado`** (5) |

Campos de contexto em qualquer consulta: `status_sefaz` (código numérico
real da SEFAZ, ex. `100`=autorizado, `135`=evento de cancelamento,
`598`=rejeição de validação), `mensagem_sefaz` (texto descritivo),
`erros` (array de detalhes de validação/rejeição).

## Cancelamento

| Documento | `justificativa` | Janela de tempo |
|---|---|---|
| NF-e (`cancelar_nfe`) | obrigatória, 15–255 chars | até 24h da emissão (alguns estados permitem mais) |
| NFC-e (`cancelar_nfce`) | obrigatória, 15–255 chars | até **30 minutos** da emissão |

NFC-e cancelamento devolve `status` (`cancelado`/`erro_cancelamento`),
`status_sefaz`, `mensagem_sefaz`, `caminho_xml_cancelamento`,
`numero_protocolo`. NF-e cancelamento é síncrono (HTTP DELETE, comunica
direto com a SEFAZ, resposta imediata).

## Cobertura de tipos de documento — mais ampla que os outros dois motores

Confirmado ao vivo (índice `reference/`): Focus emite **CT-e, CT-e OS,
CT-e Simplificado, MDF-e, NF-e, NFC-e, NFCom, NFS-e e NFS-e nacional**, e
consulta documentos recebidos de CT-e/NF-e/NFS-e nacional. CT-e e MDF-e
têm páginas de referência reais (`/reference/ctecteos`, `/reference/mdfe`)
— confirma a claim já existente em `engines.md` de que Focus tem cobertura
mais ampla que Spedy (só NF-e/NFC-e/NFS-e) e mais ampla que o NFePHP deste
projeto (que só usa `sped-nfe`, NF-e/NFC-e). **Não extraído aqui**: o
schema de payload de CT-e/MDF-e em si — as páginas existem mas não foram
abertas até o nível de campo nesta passada, porque nenhum bug real desta
sessão envolveu esses dois tipos. Buscar ao vivo se for construir/depurar
CT-e ou MDF-e via Focus.

## O que NÃO está coberto aqui

Schema completo de NFS-e (campos de serviço, CNAE, código de tributação
municipal — Focus varia por município, mais complexo que NF-e/NFC-e);
payload de CT-e/MDF-e (ver acima); autenticação/registro de emissor;
upload de certificado A1; webhooks; NFCom. Buscar ao vivo
(`https://doc.focusnfe.com.br/reference/`) se precisar de qualquer um
desses.
