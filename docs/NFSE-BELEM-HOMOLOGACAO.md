# NFSe Belém - Homologação do ciclo municipal

Este guia cobre o provider municipal atual de Belém (layout ABRASF 2.03, sem uso operacional de DSF) no `fiscal-core` com foco em:

- emissão síncrona por `RecepcionarLoteRpsSincrono`
- consulta por lote
- consulta por RPS
- cancelamento

## 1) Pré-requisitos

- PHP com `ext-dom`, `ext-openssl` e `ext-curl`
- certificado A1 válido
- ambiente configurado como `homologacao`
- `FISCAL_IM` configurado para a inscrição municipal do prestador
- `OPENSSL_CONF` apontando para um `openssl.cnf` com provider `legacy` quando o certificado for PKCS#12 legado
- prestador configurado com:
  - `cnpj`
  - `inscricaoMunicipal`
  - `codigo_municipio`

## 2) Toolkit de debug

O provider municipal de Belém suporta captura estruturada de request/response via configuração:

```php
$config['debug_http'] = true;
$config['debug_log_file'] = '/tmp/nfse-belem-debug.log';
```

Quando ativo, o provider grava por operação:

- XML do request
- envelope SOAP
- XML da resposta
- resposta parseada
- metadados do transporte

O log é mascarado para CNPJ/CPF, e-mail, telefone, protocolo, código de verificação e identificadores sensíveis.

## 3) Fluxo recomendado de homologação

1. Emitir uma NFSe municipal normal de Belém.
2. Confirmar na resposta:
   - `protocolo`
   - `numero_lote`
   - `numero` da NFSe
   - `codigo_verificacao`
3. Consultar o lote com o protocolo retornado.
4. Consultar a NFSe pelo RPS usado na emissão.
5. Cancelar a NFSe emitida com um motivo de teste válido no ambiente.

## 4) Defaults operacionais desta etapa

- emissão síncrona com 1 RPS por lote
- assinatura habilitada por padrão apenas para emissão
- cancelamento usa `CodigoCancelamento=1` por padrão

Para sobrescrever o código de cancelamento:

```php
$config['cancelamento_codigo'] = '2';
```

## 5) Observações de uso

- MEI não deve seguir pelo provider municipal de Belém.
- Consulta por lote, consulta por RPS e cancelamento dependem do contexto do prestador.
- Esse contexto pode vir:
  - da emissão anterior feita na mesma instância
  - da configuração do provider em `prestador`

Exemplo:

```php
$config['prestador'] = [
    'cnpj' => '12345678000195',
    'inscricaoMunicipal' => '4007197',
    'codigo_municipio' => '1501402',
];
```

Para scripts reais deste repositório, o CNPJ do tomador pode ser enriquecido via consulta pública, mas a inscrição municipal do prestador continua vindo de `FISCAL_IM`.
