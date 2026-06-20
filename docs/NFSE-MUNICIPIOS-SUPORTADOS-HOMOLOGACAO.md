# NFSe - Municipios suportados, homologados e pendentes

Base: `config/nfse/providers-catalog.json` (snapshot em `2026-06-20`).

## Regra vigente para homologacao

- `nfse_nacional`: homologacao municipal individual `DISPENSADA`.
- Manaus/AM (`1302603`) e a cidade referencia homologada do fluxo nacional.
- Providers municipais (ABRASF, ISSNET, GINFES, IPM, etc.) seguem com homologacao real por municipio.

## Resumo consolidado

- Entradas ativas no catalogo: `633`
- `DISPENSADO_NACIONAL`: `210`
- `HOMOLOGADO_REFERENCIA_NACIONAL`: `1` (Manaus/AM)
- `HOMOLOGADO_MUNICIPAL`: `1` (Belem/PA)
- `PENDENTE_HOMOLOGACAO_MUNICIPAL`: `421`

## Municipios ja homologados

- Manaus/AM (`1302603`) - `HOMOLOGADO_REFERENCIA_NACIONAL` (`nfse_nacional`)
- Belem/PA (`1501402`) - `HOMOLOGADO_MUNICIPAL` (`BELEM_MUNICIPAL_2025`)

## Capitais dispensadas por uso 100% nacional

- Rio Branco/AC (`1200401`)
- Vitoria/ES (`3205309`)
- Sao Luis/MA (`2111300`)
- Belo Horizonte/MG (`3106200`)
- Recife/PE (`2611606`)
- Curitiba/PR (`4106902`)
- Rio de Janeiro/RJ (`3304557`)
- Natal/RN (`2408102`)
- Boa Vista/RR (`1400100`)
- Porto Alegre/RS (`4314902`)
- Florianopolis/SC (`4205407`)

## Capitais ainda pendentes de homologacao municipal real

- Campo Grande/MS (`5002704`) - `ABRASF_SHARED`
- Joao Pessoa/PB (`2507507`) - `ABRASF_SHARED`
- Teresina/PI (`2211001`) - `ABRASF_SHARED`
- Brasilia/DF (`5300108`) - `ISSNET`
- Goiania/GO (`5208707`) - `ISSNET`
- Cuiaba/MT (`5103403`) - `ISSNET`
- Fortaleza/CE (`2304400`) - `GINFES`
- Maceio/AL (`2704302`) - `GINFES`
- Sao Paulo/SP (`3550308`) - `PAULISTANA`
- Salvador/BA (`2927408`) - `SALVADOR_BA`
- Porto Velho/RO (`1100205`) - `EL`
- Aracaju/SE (`2800308`) - `WEBISS`
- Palmas/TO (`1721000`) - `WEBISS`
- Macapa/AP (`1600303`) - `ABRASF_SHARED` (provisorio)

## Municipios prioritarios AM pendentes de homologacao municipal real

- Presidente Figueiredo/AM (`1303536`) - `ISSWEB_AM`
- Rio Preto da Eva/AM (`1303569`) - `ISSWEB_AM`

## Municipios PA adicionados como ABRASF compartilhado

- Castanhal/PA (`1502400`) - `ABRASF_SHARED` (pendente de homologacao municipal real)

## Artefatos de apoio

- Lista completa suportada: `docs/nfse-municipios-suportados.csv`
- Lista pendente municipal: `docs/nfse-municipios-pendentes-homologacao.csv`
- Tracker de capitais: `docs/NFSE-CAPITAIS-HOMOLOGACAO-TRACKER.md`
- Reconciliacao Uninfe x catalogo: `docs/NFSE-UNINFE-RECONCILIACAO.md`
- Tabela completa provider x municipios: `docs/NFSE-PROVIDERS-MUNICIPIOS.md`

## Observacao sobre o catalogo

O CSV consolidado inclui algumas entradas tecnicas de familia/legado (`UF=AN` e `UF=XX`) para retrocompatibilidade de roteamento; a fila de homologacao municipal deve priorizar municipios reais e capitais.
