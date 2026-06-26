# PUBLICA

## Identificação

- Provider: `PublicaProvider`
- Transporte: SOAP RPC/literal
- Layout: PUBLICA 3.00
- Municípios ativos: Itajaí/SC, Mafra/SC e Assú/RN
- Histórico/retroativo: Joinville/SC

## Requisitos

- Prestador com CNPJ, IM e configuração de assinatura válida
- Código do município e alíquota coerentes no serviço
- WSDL principal e WSDL de consultas por ambiente

## Operações

- emitir
- consultar lote
- consultar por RPS
- cancelar
- emitir completo com DANFSe local

## Overrides conhecidos

- Joinville migrou para `nfse_nacional` em `2026-07-20`; o provider `PUBLICA` fica preservado para referência histórica e retroativos
- O fluxo histórico de Joinville opera por `RecepcionarLoteRps` com consulta posterior em `ConsultarSituacaoLoteRps`; o provider mantém fallback automático caso o município ainda aceite `GerarNfse`
- O renderer local registrado para `PUBLICA` gera PDF a partir do XML autorizado retornado em `GerarNfseResposta` ou `ConsultarLoteRpsResposta`
- Homologação de Joinville deve permanecer configurada com `https://nfsehomologacao.joinville.sc.gov.br/...`; o endpoint `http://` devolve HTTP 301 para HTTPS antes de retornar XML SOAP

## Limitações

- novos municípios da família devem preferir override leve no catálogo antes de subclass
- o DANFSe local é uma representação operacional do XML autorizado; quando a prefeitura disponibilizar URL oficial, prefira a fonte oficial para entrega ao tomador
- respostas HTML com HTTP 5xx do gateway municipal são normalizadas como `NFSE_EMISSION_FAILED`, `retryable=true` e `transport_error=gateway_unavailable`, sem marcar a nota como autorizada ou pendente fiscal
- respostas HTML com HTTP 3xx são normalizadas como `NFSE_EMISSION_FAILED`, `retryable=false`, `transport_error=redirect_response` e `redirect_location`, indicando configuração de endpoint não-final
