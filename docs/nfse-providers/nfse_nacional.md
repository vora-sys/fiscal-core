# nfse_nacional

## Identificação

- Provider: `NacionalProvider`
- Transporte: REST
- Layout: NACIONAL
- Municípios atuais relevantes: nacional, manaus, emissões MEI

## Requisitos

- certificado válido e mTLS quando exigido
- configuração completa em `config/nfse/nfse-provider-families.json`
- payload DPS aderente ao layout nacional

## Operações

- emitir
- consultar
- cancelar
- consultar por RPS
- consultar lote
- baixar XML
- baixar DANFSe com geracao local preferencial
- CNC e parametrização municipal

## Overrides conhecidos

- Manaus roteia para o nacional no catálogo ativo
- MEI sempre emite pelo nacional, independentemente do município de origem

## Limitações

- aceitação final depende da parametrização municipal e do `cTribNac`
- a API oficial de geracao de DANFSe nacional sera descontinuada em `2026-07-01`
- `baixarDanfse()` agora tenta primeiro reaproveitar PDF/URL remoto, mas faz fallback para renderizacao local a partir do XML final da NFS-e
- quando o provider nao entregar PDF pronto, a disponibilidade do DANFSe depende de conseguir resolver o XML final via resposta da operacao, `baixarXml()` ou `consultar()`
