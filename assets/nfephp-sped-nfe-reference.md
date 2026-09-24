# NFePHP/sped-nfe — campos reais confirmados (cache local, não adivinhado)

**Fonte**: não é uma API REST com spec pública — é a biblioteca PHP
`nfephp-org/sped-nfe`, que monta o XML da NF-e diretamente (`Make.php` +
`Traits/`). O "contrato real" aqui é o código-fonte da lib de verdade, lido
na instalação real de um projeto: **versão `v5.2.8`** (confirmada em
`composer.lock`), arquivos lidos em 2026-09-23 em
`vendor/nfephp-org/sped-nfe/src/Traits/{TraitTagIde,TraitTagDest,
TraitTagDetICMS}.php` e `src/Tools.php`.

**Como usar este arquivo**: cache local, não fonte de verdade permanente.
Consulte aqui primeiro. Só volte a ler o código-fonte de verdade se: (a) o
campo que você precisa não está aqui, (b) a versão instalada de
`nfephp-org/sped-nfe` no projeto em mãos for **diferente** de `v5.2.8`
(assinaturas de método de tag mudam entre versões — não assuma que este
cache ainda vale sem checar `composer.lock` primeiro), ou (c) uma rejeição
real citar um campo que não bate com o que está documentado aqui.

## Achado central: a biblioteca já se protege PARCIALMENTE contra o bug indIEDest — só numa direção

`TraitTagDest.php::tagdest()` (linhas 24-130) tem lógica própria de
correção do indicador de IE, mas só em UM sentido:

```php
$temIE = !empty($std->IE) && $std->IE !== 'ISENTO';
if (!$temIE && $std->indIEDest == 1) {
    $std->indIEDest = 2; // sem IE real mas afirmou indIEDest=1 → corrigido pra 2 (isento)
}
if ($this->mod == '65') {
    $std->indIEDest = 9; // NFC-e: SEMPRE 9, incondicional — a lib decide isso, não é escolha do chamador
}
```

Ou seja: a lib corrige "você disse indIEDest=1 mas não mandou IE de
verdade" → rebaixa pra 2. Ela **não** corrige o sentido oposto — "você
mandou indIEDest=9 mas a IE está preenchida" (`$temIE=true`) — que foi
exatamente o bug real desta sessão (cStat=232). A tag `IE` em si (linhas
99-107) é adicionada ao XML sempre que `$temIE` for verdadeiro,
**independente do modelo** — inclusive em NFC-e, onde `indIEDest` já saiu
forçado como 9 pela lib. Ou seja: dá pra mandar a IE junto numa NFC-e
mesmo com indIEDest=9 (não é contraditório pro schema), só que ninguém vai
ler esse indicador como "este destinatário é contribuinte".

**Implicação prática**: se um projeto usando esta skill encontrar o mesmo
"NFC-e nunca declara IE do PJ" (`pitfalls.md` #15) via NFePHP, o
`indIEDest=9` forçado em NFC-e **não é uma decisão de negócio configurável
— é a própria lib que decide isso**, incondicionalmente, pro modelo 65.
Não adianta tentar mandar `indIEDest=1` numa NFC-e esperando que ele saia
no XML; ele vai ser sobrescrito. A única coisa que um projeto pode
escolher é se manda a tag `IE` (auxiliar, sem afetar o indicador) ou não.

## `tagide()` — `NFe/infNFe/ide` (identificação)

Campos aceitos (`$possible`, linha 37-67 de `TraitTagIde.php`): `cUF, cNF,
natOp, indPag, mod, serie, nNF, dhEmi, dhSaiEnt, dPrevEntrega, tpNF,
idDest, cMunFG, cMunFGIBS, tpImp, tpEmis, tpNFDebito, tpNFCredito, cDV,
tpAmb, finNFe, indFinal, indPres, indIntermed, cIndOp, procEmi, verProc,
dhCont, xJust`.

Confirmados **obrigatórios** (4º parâmetro `true` de `addChild()`, não
"parece obrigatório"): `cUF, cNF, natOp, mod, idDest, cMunFG, tpImp,
tpEmis, cDV, tpAmb, finNFe, indFinal, indPres, procEmi`. `cMunFGIBS`,
`tpNFDebito`/`tpNFCredito`, `indIntermed`, `cIndOp` são opcionais
(`false`) — reforma tributária (NT 2025.002), só relevantes em schema
versão >9.

Auto-derivação que a lib faz por você (não precisa fazer no seu código):
- `dhEmi`: se vazio, a lib gera `now()` **no fuso correto da UF do
  emitente** via `TimeZoneByUF::get($cUF)` — não hardcoda
  `America/Sao_Paulo`. Se seu projeto atende emitentes em UFs de fuso
  diferente (AC, AM parte oeste, etc.), isso já é tratado pela lib.
- `cNF` (código numérico aleatório da chave): auto-gerado via
  `Keys::random()` se vazio.
- `cDV`: `'0'` se vazio (a lib recalcula o dígito verificador real da
  chave em outro lugar, não aqui).
- Valida que `cNF != nNF` (NT 2019.001) e rejeita se forem iguais.

## `tagdest()` — `NFe/infNFe/dest` (destinatário, opcional no modelo 65)

Campos aceitos: `xNome, indIEDest, IE, ISUF, IM, email, CNPJ, CPF,
idEstrangeiro`. `CNPJ`/`CPF`/`idEstrangeiro` são mutuamente exclusivos
(primeiro não-vazio que a lib encontra, nessa ordem, é o que é escrito).
`indIEDest` é sempre obrigatório e sempre escrito (ver lógica de correção
acima). `IE` só é escrita se `$temIE` for verdadeiro. `ISUF`, `IM`,
`email` são opcionais. Comprador estrangeiro (`idEstrangeiro` não-nulo)
força `indIEDest='9'` também, mesma lógica do modelo 65.

Ambiente de homologação (`tpAmb==2`): a lib **sobrescreve `xNome`**
incondicionalmente para `"NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM
VALOR FISCAL"` — não precisa (e não deve) fazer isso no seu código
chamador, a lib já garante.

## `tagICMS()` (CST) vs `tagICMSSN()` (CSOSN) — são métodos DIFERENTES

`tagICMS()` (`TraitTagDetICMS.php:32`) usa o campo `CST`. Existe um método
**separado**, `tagICMSSN()` (mesma trait, ~linha 1823), pro Simples
Nacional, que usa `CSOSN` em vez de `CST`. A lib não decide sozinha qual
chamar — isso é responsabilidade do código do projeto (por isso a
correção do bug CRT/CSOSN nesta sessão, `pitfalls.md` #13, é uma
responsabilidade da aplicação, não algo a lib detecta por você: se seu
código chamar `tagICMS()` com CST pra um emitente CRT=1, a lib vai montar
o XML errado sem reclamar).

## `tagICMSUFDest()` — grupo DIFAL (NA01, opcional)

*"Grupo a ser informado nas vendas interestaduais para consumidor final,
não contribuinte do ICMS"* (docblock da própria lib,
`TraitTagDetICMS.php:2344-2345`).

Campos aceitos: `item, vBCUFDest, vBCFCPUFDest, pFCPUFDest, pICMSUFDest,
pICMSInter, pICMSInterPart, vFCPUFDest, vICMSUFDest, vICMSUFRemet`.
Obrigatórios: `vBCUFDest, pICMSUFDest, pICMSInter, pICMSInterPart,
vICMSUFDest, vICMSUFRemet`. Opcionais: `vBCFCPUFDest, pFCPUFDest,
vFCPUFDest` (FCP — Fundo de Combate à Pobreza, só se a UF de destino
cobrar).

**Achado direto e acionável pra quem for implementar o cálculo real do
DIFAL** (a lacuna que esta sessão deixou deliberadamente em aberto — ver
`pitfalls.md` #12): `pICMSInterPart` é **literal, hardcoded pela própria
lib como `100`** (linha 2408: `addChild($icmsUFDest, "pICMSInterPart",
100, true, ...)`)! Isso confirma, agora por código de verdade e não só por
pesquisa legal: o percentual de partilha do EC 87/2015 (que foi
progressivo de 2016 a 2019) **já está 100% completo** desde 2019 — a lib
nem deixa você configurar outra coisa. Quem for implementar o cálculo real
só precisa de:
1. **`pICMSUFDest`** — a alíquota **interna** da UF de destino (a que essa
   UF cobra normalmente, por dentro do estado dela). Este é o dado que
   realmente falta e que este projeto não tinha — não tem como chutar,
   varia por UF (e às vezes por NCM/categoria de produto dentro da UF).
   Fonte confiável: legislação tributária de cada UF, ou uma tabela
   comercial mantida (tipo IBPT/Sintegra) — não invente.
2. **`pICMSInter`** — a alíquota **interestadual** entre a UF do emitente
   e a UF de destino. Esta é bem menos variável: **4%** pra produto
   importado ou com conteúdo de importação >40% (Resolução do Senado
   13/2012), **7%** de UF do Sul/Sudeste (exceto ES) pra UF do
   Norte/Nordeste/Centro-Oeste/ES, **12%** nos demais casos — uma tabela
   pequena e pública, não específica de nenhum projeto.
3. Com esses dois números + o valor da operação, `vBCUFDest`/
   `vICMSUFDest`/`vICMSUFRemet` são aritmética direta (ver
   `pICMSInterPart=100` acima — não precisa mais dividir a partilha).
`pICMSInterPart` NÃO precisa ser buscado — já é 100 sempre, garantido pela
própria lib.

## Cancelamento (`Tools.php::sefazCancela()` / `sefazCancelaPorSubstituicao()`)

`sefazCancela(string $chave, string $xJust, string $nProt, ?DateTimeInterface
$dhEvento = null, ?string $lote = null)`:
- Lança `InvalidArgumentException` se `chave`, `xJust` ou `nProt` vierem
  vazios (`Tools.php:607-609`) — validação real, não silenciosa.
- `xJust` é **truncada silenciosamente pra 255 caracteres** pela própria
  lib (`substr(trim($xJust), 0, 255)`, linha 611) — não lança erro se você
  mandar mais que isso, só corta. Não há checagem de comprimento mínimo
  aqui (a SEFAZ real exige mínimo 15 caracteres pra `xJust` de
  cancelamento — a lib não valida isso, é responsabilidade do chamador).

`sefazCancelaPorSubstituicao(...)` — método **específico de NFC-e**
(modelo 65 apenas; lança `InvalidArgumentException` se `$this->modelo !=
65`), pro caso real de "cancelamento por substituição" (emitir uma NFC-e
nova referenciando a cancelada, em vez de só cancelar). Exige `chave`,
`xJust`, `nProt` (iguais ao cancelamento normal) **mais** `chNFeRef`
(chave da NFC-e substituta) e `verAplic` (versão do aplicativo emissor) —
todos obrigatórios, mesma checagem de vazio. Se um projeto usando esta
skill só implementou `sefazCancela()` genérico, um fluxo de troca de NFC-e
por erro de item pode precisar deste método específico em vez do
genérico — vale checar antes de assumir que "cancelar" é sempre a mesma
chamada pros dois modelos.

## Inventário rápido de outras tags relevantes (não aprofundado)

- **`tagEmit()`** (`TraitTagEmit.php:24`) — dados do emitente: `xNome,
  xFant, IE, IEST, IM, CNAE, CRT, CNPJ/CPF, ISUFEmit`. Note que `CRT` é
  lido AQUI e guardado em `$this->crt` — é o mesmo CRT que
  `tagICMS()`/`tagICMSSN()` (acima) dependem pra saber qual método chamar,
  então a ordem de montagem da NF-e importa (`tagEmit()` antes dos itens).
- **`TraitTagTransp.php`** — tag de transporte/frete, existe, não lida em
  profundidade nesta rodada.
- **`TraitTagTotal.php::tagICMSTot()`** — totais da nota (soma de todos os
  itens), existe, não lida em profundidade nesta rodada.
- **`TraitTagCobr.php::tagfat()`/`tagdup()`** — fatura/duplicatas
  (indPag=1, parcelamento) — existe e está pronto pra uso na lib, mesmo
  que um projeto específico ainda não tenha modelado parcelas no seu
  próprio banco (ver `reliability-patterns.md` sobre o caso do MecânicaPro
  que decidiu não usar isso ainda por falta de dado de vencimento real).

## O que NÃO está coberto aqui

Eventos de manifestação do destinatário (`sefazManifesta`), inutilização
de numeração (`sefazInutiliza`), contingência EPEC (já coberto em
`pitfalls.md` #11/#7 e `domain-concepts.md`, não repetido aqui), NFC-e
específico além do já citado, CT-e/MDF-e (pacotes irmãos `sped-cte`/
`sped-mdfe`, código-fonte diferente — ver `references/other-documents.md`
e `engines.md`), e a maioria dos ~80 outros métodos `tag*()` que existem
na lib (IBS/CBS da reforma tributária, agropecuário, combustível, etc.) —
busque `grep -rn "public function tag" vendor/nfephp-org/sped-nfe/src/`
na instalação real do projeto em mãos antes de assumir que um campo não
existe.
