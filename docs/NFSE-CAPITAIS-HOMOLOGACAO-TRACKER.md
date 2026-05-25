# NFSe Capitais - Tracker de Homologacao por Onda

Este tracker formaliza o criterio de aceite por capital sem bloquear a onda quando houver pendencia de credencial/endpoint.

## Regra operacional vigente

- Municipio com `provider_family = nfse_nacional` fica `DISPENSADO_NACIONAL` de homologacao municipal individual.
- Excecao: Manaus/AM permanece como `HOMOLOGADO_REFERENCIA_NACIONAL` (cidade ancora da validacao nacional).
- Municipios municipais (ex.: ABRASF, ISSNET, GINFES, etc.) continuam exigindo homologacao real por municipio.

## Legenda de status

- `PENDENTE`: ainda sem homologacao real registrada.
- `EM_ANDAMENTO`: testes automatizados e/ou tentativa homologatoria iniciada, sem fechamento real completo.
- `CONCLUIDA`: emissao + consulta + cancelamento validados (ou limitacao oficial documentada).
- `BLOQUEIO_FORMAL`: impedimento externo registrado com responsavel e proximo passo.
- `DISPENSADO_NACIONAL`: nao exige homologacao municipal individual pela cobertura do fluxo nacional.
- `HOMOLOGADO_REFERENCIA_NACIONAL`: cidade referencia homologada do fluxo nacional.

## Onda 1 - Capitais em NFSe nacional

| Capital | IBGE | Provider ativo | Vigencia nacional | Status | Evidencias | Bloqueio formal |
| --- | --- | --- | --- | --- | --- | --- |
| Rio Branco/AC | 1200401 | `nfse_nacional` | 2022-12-30 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Vitoria/ES | 3205309 | `nfse_nacional` | 2025-11-11 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Sao Luis/MA | 2111300 | `nfse_nacional` | 2025-12-15 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Belo Horizonte/MG | 3106200 | `nfse_nacional` | 2022-12-01 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Recife/PE | 2611606 | `nfse_nacional` | 2025-10-15 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Curitiba/PR | 4106902 | `nfse_nacional` | 2023-08-30 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Rio de Janeiro/RJ | 3304557 | `nfse_nacional` | 2026-01-01 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Natal/RN | 2408102 | `nfse_nacional` | 2026-01-01 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Boa Vista/RR | 1400100 | `nfse_nacional` | 2025-12-04 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Porto Alegre/RS | 4314902 | `nfse_nacional` | 2023-04-17 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Florianopolis/SC | 4205407 | `nfse_nacional` | 2025-08-27 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). | - |
| Manaus/AM | 1302603 | `nfse_nacional` | 2026-01-01 | HOMOLOGADO_REFERENCIA_NACIONAL | Emissao/consulta/cancelamento homologados no fluxo nacional. | - |

## Onda 2 - ABRASF (Belém-like)

| Capital | IBGE | Provider ativo | Status | Evidencias | Bloqueio formal |
| --- | --- | --- | --- | --- | --- |
| Belem/PA | 1501402 | `BELEM_MUNICIPAL_2025` | CONCLUIDA | Emissao + consulta + cancelamento homologados no provider municipal ABRASF. | - |
| Campo Grande/MS | 5002704 | `ABRASF_SHARED` | EM_ANDAMENTO | Roteamento ABRASF_SHARED + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Joao Pessoa/PB | 2507507 | `ABRASF_SHARED` | EM_ANDAMENTO | Roteamento ABRASF_SHARED validado em testes automatizados; pendente homologacao real. | - |
| Teresina/PI | 2211001 | `ABRASF_SHARED` | EM_ANDAMENTO | Roteamento ABRASF_SHARED + payload defaults canonizados no catalogo; pendente homologacao real. | - |

## Onda 3 - Demais capitais (municipais)

### Lote 1

| Capital | IBGE | Family alvo | Status | Evidencias | Bloqueio formal |
| --- | --- | --- | --- | --- | --- |
| Brasilia/DF | 5300108 | `ISSNET` | EM_ANDAMENTO | Roteamento ISSNET + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Goiania/GO | 5208707 | `ISSNET` | EM_ANDAMENTO | Roteamento ISSNET + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Cuiaba/MT | 5103403 | `ISSNET` | EM_ANDAMENTO | Roteamento ISSNET + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Fortaleza/CE | 2304400 | `GINFES` | EM_ANDAMENTO | Roteamento GINFES + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Maceio/AL | 2704302 | `GINFES` | EM_ANDAMENTO | Roteamento GINFES + payload defaults canonizados no catalogo; pendente homologacao real. | - |

### Lote 2

| Capital | IBGE | Family alvo | Status | Evidencias | Bloqueio formal |
| --- | --- | --- | --- | --- | --- |
| Sao Paulo/SP | 3550308 | `PAULISTANA` | EM_ANDAMENTO | Roteamento PAULISTANA + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Salvador/BA | 2927408 | `SALVADOR_BA` | EM_ANDAMENTO | Roteamento SALVADOR_BA + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Porto Velho/RO | 1100205 | `EL` | EM_ANDAMENTO | Roteamento EL + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Aracaju/SE | 2800308 | `WEBISS` | EM_ANDAMENTO | Roteamento WEBISS + payload defaults canonizados no catalogo; pendente homologacao real. | - |
| Palmas/TO | 1721000 | `WEBISS` | EM_ANDAMENTO | Roteamento WEBISS + payload defaults canonizados no catalogo; pendente homologacao real. | - |

### Lote 3

| Capital | IBGE | Family alvo | Status | Evidencias | Bloqueio formal |
| --- | --- | --- | --- | --- | --- |
| Macapa/AP | 1600303 | `ABRASF_SHARED` (provisorio) | EM_ANDAMENTO | Roteamento ABRASF_SHARED + payload default municipal canonizado; pendente homologacao real. | - |

## Focos operacionais adicionais (fora de capitais)

| Municipio | IBGE | Provider ativo | Vigencia nacional | Status | Observacao |
| --- | --- | --- | --- | --- | --- |
| Presidente Figueiredo/AM | 1303536 | `ISSWEB_AM` | - | PENDENTE | Municipio municipal ISSWEB; homologacao real obrigatoria por municipio. |
| Rio Preto da Eva/AM | 1303569 | `ISSWEB_AM` | - | PENDENTE | Municipio municipal ISSWEB; homologacao real obrigatoria por municipio. |
| Ananindeua/PA | 1500800 | `nfse_nacional` | 2026-01-01 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). |
| Maraba/PA | 1504208 | `nfse_nacional` | 2023-01-23 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). |
| Joinville/SC | 4209102 | `PUBLICA` | - | EM_ANDAMENTO | Roteamento PUBLICA validado em testes automatizados; pendente homologacao real. |
| Balneario Camboriu/SC | 4202008 | `nfse_nacional` | 2025-10-01 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). |
| Itajai/SC | 4208203 | `PUBLICA` | - | EM_ANDAMENTO | Roteamento PUBLICA validado em testes automatizados; pendente homologacao real. |
| Campo Alegre/SC | 4203303 | `IPM` | - | PENDENTE | Catalogado em familia municipal; aguardando homologacao real. |
| Sao Bento do Sul/SC | 4215802 | `IPM` | - | EM_ANDAMENTO | Roteamento IPM validado em testes automatizados; pendente homologacao real. |
| Jaragua do Sul/SC | 4208906 | `nfse_nacional` | 2025-12-10 | DISPENSADO_NACIONAL | Coberto por homologacao de referencia nacional (Manaus). |
| Balneario Barra do Sul/SC | 4202057 | `IPM` | - | PENDENTE | Catalogado em familia municipal; aguardando homologacao real. |
| Sao Francisco do Sul/SC | 4216206 | `IPM` | - | PENDENTE | Catalogado em familia municipal; aguardando homologacao real. |
| Garuva/SC | 4205803 | `IPM` | - | PENDENTE | Catalogado em familia municipal; aguardando homologacao real. |
| Itapoa/SC | 4208450 | `IPM` | - | PENDENTE | Catalogado em familia municipal; aguardando homologacao real. |

## Checklist minimo por municipio municipal

- Request e response sanitizados da emissao.
- Identificador de consulta homologado.
- Evidencia de cancelamento ou limitacao oficial documentada.
- Evidencia final de documento (XML, DANFSe ou URL oficial).
- Quando bloqueado: responsavel, causa externa, data e proximo passo.
