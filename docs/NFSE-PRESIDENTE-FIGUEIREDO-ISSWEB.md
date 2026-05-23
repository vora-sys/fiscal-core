# NFSe Presidente Figueiredo - ISSWEB

Este guia registra o fluxo ativo ISSWEB de Presidente Figueiredo/AM.
No catalogo atual, o municipio usa `provider_family = ISSWEB_AM`.

## Status

- municipio catalogado com `provider_family = ISSWEB_AM`
- provider concreto: `IsswebProvider`
- familia compartilhada tambem usada por Rio Preto da Eva/AM
- schemas locais usados:
  - `XSDNFEletronica.xsd`
  - `XSDISSEConsultaNota.xsd`
  - `XSDISSECancelaNFe.xsd`
  - `XSDRetorno.xsd`
- homologacao real ainda depende de credenciais e endpoints oficiais
- homologacao de producao exige validacao final junto a prefeitura/fornecedor

## Variaveis obrigatorias

- `FISCAL_CNPJ`
- `FISCAL_RAZAO_SOCIAL`
- `FISCAL_IM`
- `FISCAL_CERT_PATH`
- `FISCAL_CERT_PASSWORD`
- `NFSE_ISSWEB_CHAVE`

`NFSE_ISSWEB_CHAVE` deve conter exatamente 48 caracteres.

## Payload minimo

- `prestador.cnpj`
- `prestador.inscricaoMunicipal`
- `tomador.documento`
- `tomador.razao_social` ou `tomador.razaoSocial`
- `tomador.endereco.codigo_municipio`
- `tomador.endereco.cep`
- `servico.codigo`
- `servico.descricao` ou `servico.discriminacao`
- `valor_servicos`

## Operacoes suportadas

- `emitir`
- `consultar`
- `cancelar`

Convencoes atuais do provider:

- `consultar(string $chave)` interpreta `chave` como numero da nota fiscal
- `cancelar(string $chave, string $motivo, ?string $protocolo)` interpreta:
  - `chave` como numero da nota fiscal
  - `protocolo` como chave de validacao ISSWEB no formato `9999-AAAAA`

## Impressao

O provider nao gera PDF local nesta etapa.

Quando `official_validation_url_template` estiver configurado e a resposta do ISSWEB retornar `numero` e `chave_validacao`, o parser expora `nfse_url` para consulta/validacao oficial.
Nesse caso:

- `emitirCompleto()` retorna `impressao.modo = url`
- `consultarDisponibilidade(['numero_nfse' => ...])` retorna `danfse_url`
- `baixarDanfse($numero)` retorna a URL oficial normalizada em `impressao.url`

## Pendencias operacionais

Antes de homologar de verdade, confirmar com a prefeitura ou fornecedor:

- endpoint de homologacao
- endpoint de producao
- se o host `servicosweb.pmpf.am.gov.br` e apenas portal ou tambem endpoint do webservice
- operacao SOAP exata de emissao
- operacao SOAP exata de consulta
- operacao SOAP exata de cancelamento
- se o certificado cliente TLS e obrigatorio no transporte
- se existe URL oficial deterministica para impressao/validacao

## Exemplo

Preview local:

```bash
php examples/homologacao/06-presidente-figueiredo-issweb.php
```

Envio real:

```bash
php examples/homologacao/06-presidente-figueiredo-issweb.php --send
```
