# ISSWEB_AM

## Identificação

- Provider: `IsswebProvider`
- Transporte: SOAP
- Layout: ISSWEB
- Municípios atuais: Presidente Figueiredo/AM (`1303536`) e Rio Preto da Eva/AM (`1303569`)

## Requisitos

- validar endpoints reais por município
- confirmar chave/autenticação do ISSWEB
- revisar requisitos de item de serviço e retorno oficial

## Operações

- emitir
- consultar
- cancelar
- consultar disponibilidade por número da NFSe
- baixar DANFSe como URL oficial quando `official_validation_url_template` estiver configurado

## Overrides conhecidos

- Presidente Figueiredo usa `official_validation_url_template`
- Presidente Figueiredo e Rio Preto da Eva usam `payload_defaults` diferentes de homologação

## Retorno operacional

- `emitirCompleto()` resolve `documento.numero`, `documento.codigo_verificacao`, `documento.protocolo` e `impressao.url` a partir do retorno ISSWEB
- `consultarDisponibilidade(['numero_nfse' => ...])` consulta a nota no ISSWEB e normaliza disponibilidade, status de autorização e URL oficial
- `baixarDanfse($numero)` não baixa PDF local; retorna `impressao.modo=url` para a validação/impressão oficial quando disponível

## Limitações

- família compartilhada, mas payload e URL oficial não são necessariamente idênticos
- evidências reais continuam obrigatórias por município
