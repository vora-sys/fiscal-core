# NFSe Nacional - Homologacao e Liberacao

Este guia cobre a validacao da versao `v1.1.1` em ambiente completo e os comandos de liberacao.

## 1) Pre-requisitos do ambiente

- PHP 8.1+ funcional
- extensoes:
  - `ext-dom`
  - `ext-openssl`
  - `ext-curl` (recomendado)
- certificado A1 valido para homologacao
- variaveis:
  - `FISCAL_CERT_PATH`
  - `FISCAL_CERT_PASSWORD`
- endpoint nacional homologacao configurado em `config/nfse/nfse-provider-families.json`

## 2) Validacao de prontidao (obrigatorio)

Executar via facade:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\NFSeFacade;

$nfse = NFSeFacade::nacional();
$ready = $nfse->verificarProntidaoHomologacao();
var_dump($ready->toArray());
```

Criterio de aceite:
- `ready = true`
- `missing_requirements = []`

## 3) Smoke test de emissao/consulta/cancelamento

### 3.1 Emitir
- montar payload minimo valido:
  - `prestador.cnpj`
  - `prestador.inscricaoMunicipal`
  - `tomador.documento`
  - `tomador.razaoSocial`
  - `servico.codigo`
  - `servico.discriminacao`
  - `servico.aliquota`
  - `valor_servicos`

### 3.2 Consultar
- consultar por chave/numero retornado.

### 3.3 Cancelar
- cancelar com motivo valido.

Criterio de aceite:
- retorno sem excecao de assinatura/transporte
- `cStat/xMotivo` consistente no parser
- numero NFSe/codigo verificacao disponiveis quando autorizada

## 4) Validacao de parser com XML real

Usar `ConsultaNfseExterno.xml` como fixture de referencia para validar extracao:
- numero NFSe
- codigo verificacao
- status/autorizacao

## 5) Comandos de release

Depois que homologacao passar:

```bash
git add CHANGELOG.md config/nfse src tests docs
git commit -m "release(nfse): v1.1.1 rotas oficiais adn/cnc"
git tag v1.1.1
git push origin main
git push origin v1.1.1
```

## 6) Pos-release

- monitorar respostas de homologacao por 24h
- coletar XMLs de rejeicao e mapear em testes adicionais
- iniciar janela de rollout em producao
