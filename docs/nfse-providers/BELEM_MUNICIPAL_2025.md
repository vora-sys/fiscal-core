# BELEM_MUNICIPAL_2025

## Identificação

- Provider: `BelemMunicipalProvider`
- Transporte: SOAP
- Layout: ABRASF 2.03
- Município atual: Belém/PA
- Observação: não usar DSF para Belém; DSF está mantido apenas como alias legado temporário para compatibilidade.

## Requisitos

- Prestador com CNPJ, IM e classificação explícita de MEI ou não-MEI
- Assinatura obrigatória
- URL oficial de validação/DANFSe da prefeitura

## Operações

- emitir
- consultar lote
- consultar por RPS
- cancelar

## Overrides conhecidos

- `requires_explicit_mei_classification = true`

## Limitações

- emissão de MEI não segue o municipal; é roteada para o nacional
- manter evidências reais de homologação sempre junto aos testes
