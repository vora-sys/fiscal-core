# Matriz Operacional NFSe

Esta matriz resume as famílias/providers sob manutenção operacional ativa no `fiscal-core`.

| Família | Provider | Transporte | Operações | Assinatura | Origem dos schemas | Municípios atuais | Política MEI | DANFSe / pós-emissão | Gaps |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `nfse_nacional` | `NacionalProvider` | REST | emitir, consultar, cancelar, consultar RPS/lote, baixar XML/DANFSe, CNC | obrigatória | configuração canônica em `config/nfse/` | nacional, manaus | sempre nacional | XML e DANFSe via endpoints nacionais | depende de parametrização municipal real |
| `BELEM_MUNICIPAL_2025` | `BelemMunicipalProvider` | SOAP | emitir, consultar lote, consultar por RPS, cancelar | obrigatória | ABRASF 2.03 custom override (não DSF) | belem | classificação explícita; MEI vai para nacional | URL oficial da prefeitura | namespace/XSD e evidências continuam sensíveis |
| `ABRASF_SHARED` | `AbrasfSharedProvider` | SOAP | emitir, consultar lote, consultar por RPS, cancelar, substituir | opcional (por override municipal) | base ABRASF 2.03 compartilhada | capitais da onda ABRASF + migracoes DSF legado | MEI vai para nacional | depende de URL oficial municipal | homologação real por município ainda pendente |
| `PUBLICA` | `PublicaProvider` | SOAP | emitir, consultar lote, consultar por RPS, cancelar | obrigatória | custom override | joinville | MEI vai para nacional | fluxo municipal com consulta posterior quando aplicável | validar novos municípios da mesma família |
| `ISSWEB_AM` | `IsswebProvider` | SOAP | emitir, consultar, cancelar | opcional/nenhuma na família base | schemas ISSWEB | presidente-figueiredo, rio-preto-da-eva | MEI vai para nacional | URL oficial por override municipal quando existir | validar homologação real por município e endpoints oficiais |

## Leitura prática

- Use esta matriz para decidir reuso de família antes de criar novo provider.
- Quando a diferença for leve, prefira `provider_config_overrides` no catálogo.
- Consulte as fichas em `docs/nfse-providers/` para requisitos detalhados por família/provider.
- `DSF` deve ser tratado apenas como alias legado temporário para `ABRASF_SHARED`.
