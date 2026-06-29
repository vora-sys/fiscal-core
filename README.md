# 🚀 FISCAL-CORE - Biblioteca PHP para Operações Fiscais

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.0-blue)](https://php.net)
[![Composer](https://img.shields.io/badge/composer-ready-green)](https://getcomposer.org)
[![License](https://img.shields.io/badge/license-MIT-orange)](LICENSE)

> **Biblioteca robusta e modular para operações fiscais brasileiras**
>
> NFe, NFCe, NFSe, Consultas Públicas, Tributação IBPT e muito mais!

## 📋 Sumário

- [📦 Instalação](#-instalação-via-composer)
- [⚡ Início Rápido](#-início-rápido)  
- [🎯 Funcionalidades](#-funcionalidades-principais)
- [📚 Exemplos Práticos](#-exemplos-práticos)
- [⚙️ Configuração](#️-configuração-opcional)
- [🏗️ Arquitetura](#️-arquitetura)
- [📖 API das Facades](docs/API-FACADES.md)
- [🚢 Release Packagist](docs/RELEASE-PACKAGIST.md)
- [📊 Casos de Uso](#-casos-de-uso)
- [🔧 Requisitos](#-requisitos-técnicos)
- [🚨 Troubleshooting](#-troubleshooting)
- [🗺️ Roadmap](#️-roadmap)

## 📦 Instalação via Composer

```bash
composer require sabbajohn/fiscal-core
```
**Desenvolvimento local:**

```json
{
  "repositories": [
    { "type": "path", "url": "../fiscal-core" }
  ]
}
  
  2) Instale a dependência:
  
  ```bash
  composer require sabbajohn/fiscal-core:@dev
  ```

Desenvolvimento local

- Após clonar este repositório, instale dependências:
  
  ```bash
  composer install
  ```

- Execute a suíte de testes para validar o ambiente:
  
  ```php


## ⚡ Início Rápido

```php
<?php
require 'vendor/autoload.php';

use Fiscal\Facade\FiscalFacade;

// Interface unificada - Uma classe para tudo!
$fiscal = new FiscalFacade();

// Primeira consulta - sem configuração necessária
$resultado = $fiscal->consultar(['ncm' => '84715010']);

if ($resultado->sucesso) {
    echo "✅ Funcionou! Dados: " . json_encode($resultado->dados, JSON_PRETTY_PRINT);
} else {
    echo "❌ Erro: " . $resultado->erro;
}
```

## 🎯 Funcionalidades Principais

### 📋 **Consultas Públicas**

| Função | API | Status |
| -------- | ----- | -------- |
| **CEP** | ViaCEP + BrasilAPI | ✅ |
| **CNPJ** | ReceitaWS + BrasilAPI | ✅ |
| **Bancos** | BrasilAPI | ✅ |
| **NCM** | BrasilAPI | ✅ |

### 📄 **Documentos Fiscais**

| Documento | Status | Providers |
| ----------- | -------- | ----------- |
| **NFe** | ✅ Pronto | NFePHP |
| **NFCe** | ✅ Pronto | NFePHP |
| **NFSe** | ✅ Multi-município | 15+ cidades |

### 💰 **Tributação**

- **IBPT** - Cálculo automático de tributos
- **Múltiplos produtos** em lote
- **Cache** parcial em fluxos específicos
- **Fallbacks** por estado/federal

## 📚 Exemplos Práticos

### 🎓 **Para Iniciantes** ([examples/basico/](examples/basico/))

```bash
# Primeira consulta (sem configuração)
php examples/basico/01-primeira-consulta.php

# Status do sistema
php examples/basico/02-status-sistema.php  

# Consultas públicas (CEP, CNPJ, Bancos)
php examples/basico/03-consultas-publicas.php
```

### 🏢 **Para Produção** ([examples/avancado/](examples/avancado/))

```bash
# Múltiplos municípios NFSe
php examples/avancado/01-multiplos-municipios.php

# Emissão municipal funcional em preview local
php examples/avancado/03-emissao-municipal-funcional.php

# Error handling robusto
php examples/avancado/02-error-handling.php

# Homologação municipal segura
php scripts/nfse/scaffold-family.php --family=MINHA_FAMILIA --dry-run
php scripts/nfse/scaffold-municipio.php --ibge=1303536 --dry-run
php examples/homologacao/01-emitir-belem-real.php
php examples/homologacao/02-emitir-joinville-real.php
php examples/homologacao/03-emitir-belem-completo.php
php examples/homologacao/consulta.php

# Produção Belém: emissão, disponibilidade e URL oficial
php examples/producao/01-emitir-belem-real.php
php examples/producao/02-consultar-e-imprimir-belem.php --protocolo=059138577 --rps-numero=164344

# Ou sobrescrevendo dados do tomador
php examples/homologacao/01-emitir-belem-real.php --tomador-doc=00980556236 --tomador-cep=66065112
php examples/homologacao/02-emitir-joinville-real.php --tomador-doc=00980556236 --tomador-nome="TOMADOR TESTE"
```

### 📖 **Guia Completo**

```bash
# Visão geral de todas as funcionalidades
php examples/GuiaCompletoDeUso.php
```

> 📚 **Veja todos os exemplos organizados em [examples/README.md](examples/README.md)**

## ⚙️ Configuração (Opcional)

### 🔐 **Certificados NFe/NFCe**

```bash
# Configure via environment
export FISCAL_CERT_PATH="/caminho/para/certificado.pfx"
export FISCAL_CERT_PASSWORD="senha_do_certificado"
export FISCAL_IM="sua_inscricao_municipal"

# Certificados PKCS#12 legados podem exigir OpenSSL legacy
export OPENSSL_CONF="/caminho/para/openssl.cnf"
```

### 🧩 **Compatibilidade NFe/NFCe**

O `fiscal-core` usa `nfephp-org/sped-nfe` como motor NFe/NFCe. Para acompanhar novas Notas Técnicas sem alterar código de negócio, configure versão XML e pacote de schemas por ambiente ou por modelo:

```bash
# Defaults atuais
export FISCAL_NFE_XML_VERSION="4.00"
export FISCAL_NFCE_XML_VERSION="4.00"
export FISCAL_NFE_SCHEMA="PL_009_V4"
export FISCAL_NFCE_SCHEMA="PL_009_V4"

# Quando homologar/ativar layouts PL_010 no sped-nfe instalado
export FISCAL_NFE_SCHEMA="PL_010"
export FISCAL_NFCE_SCHEMA="PL_010"
```

Aliases aceitos para schema: `PL_009`, `PL_010`, `NT_2025_002`, `reforma_tributaria` e `IBSCBS`. O resolvedor usa o schema instalado no `sped-nfe` mais adequado, por exemplo `PL_010_V1.30` quando disponível.

Também é possível sobrescrever por payload:

```php
$dados['layout'] = [
    'schema' => 'PL_010',
    'xml_version' => '4.00',
];
```

Para diagnóstico, `FiscalFacade::getConfigInfo()` retorna `nfe_compatibility` com versões instaladas, schemas disponíveis e suporte a tags IBS/CBS.

### 💰 **IBPT (Tributação)**

```bash
export IBPT_CNPJ="11222333000181"
export IBPT_TOKEN="seu_token_ibpt"  
export IBPT_UF="SP"
```

### 🏘️ **NFSe Municípios**

- O catálogo municipal atual está em `config/nfse/`
- `FISCAL_IM` continua obrigatório para emissões municipais reais
- A consulta pública de CNPJ ajuda com razão social/contato/endereço, mas não fornece inscrição municipal
- `provider_config_overrides` no catálogo aplica merge sobre a família técnica no runtime
- `payload_defaults` no catálogo acelera exemplos e homologação sem duplicar a família
- Emissão classificada como MEI usa sempre o provider nacional
- Para Belém, o DANFSe é disponibilizado pela prefeitura em URL oficial; a biblioteca retorna isso no contrato canônico em `impressao.modo = url` e `impressao.url`
- O playbook canônico para implementação municipal está em [docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md)
- A migração de município municipal para nacional está em [docs/NFSE-MIGRACAO-MUNICIPAL-PARA-NACIONAL.md](docs/NFSE-MIGRACAO-MUNICIPAL-PARA-NACIONAL.md)
- A matriz operacional de famílias e providers está em [docs/NFSE-PROVIDER-MATRIX.md](docs/NFSE-PROVIDER-MATRIX.md)

#### Cidades-UF já compatíveis

Status usados abaixo:

- `HOMOLOGADO`: fluxo real já validado
- `DISPENSADO_NACIONAL`: compatível via provider nacional; não exige homologação municipal individual
- `PENDENTE_HOMOLOGACAO_REAL`: compatível em catálogo/runtime/testes, mas ainda depende de evidência real por município

| Cidade/UF | Provider | Status |
| --- | --- | --- |
| Manaus/AM | `nfse_nacional` | `HOMOLOGADO` |
| Belém/PA | `BELEM_MUNICIPAL_2025` | `HOMOLOGADO` |
| Castanhal/PA | `ABRASF_SHARED` | `PENDENTE_HOMOLOGACAO_REAL` |
| Rio Branco/AC | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Vitória/ES | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| São Luís/MA | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Belo Horizonte/MG | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Recife/PE | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Curitiba/PR | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Rio de Janeiro/RJ | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Natal/RN | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Porto Alegre/RS | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Florianópolis/SC | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Joinville/SC | `nfse_nacional` | `DISPENSADO_NACIONAL` |
| Brasília/DF | `ISSNET` | `PENDENTE_HOMOLOGACAO_REAL` |
| Goiânia/GO | `ISSNET` | `PENDENTE_HOMOLOGACAO_REAL` |
| Cuiabá/MT | `ISSNET` | `PENDENTE_HOMOLOGACAO_REAL` |
| Fortaleza/CE | `GINFES` | `PENDENTE_HOMOLOGACAO_REAL` |
| Maceió/AL | `GINFES` | `PENDENTE_HOMOLOGACAO_REAL` |
| São Paulo/SP | `PAULISTANA` | `PENDENTE_HOMOLOGACAO_REAL` |
| Salvador/BA | `SALVADOR_BA` | `PENDENTE_HOMOLOGACAO_REAL` |
| Porto Velho/RO | `EL` | `PENDENTE_HOMOLOGACAO_REAL` |
| Aracaju/SE | `WEBISS` | `PENDENTE_HOMOLOGACAO_REAL` |
| Palmas/TO | `WEBISS` | `PENDENTE_HOMOLOGACAO_REAL` |
| Feira de Santana/BA | `WEBISS` | `PENDENTE_HOMOLOGACAO_REAL` |
| Itabuna/BA | `WEBISS` | `PENDENTE_HOMOLOGACAO_REAL` |
| Vitória da Conquista/BA | `EL` | `PENDENTE_HOMOLOGACAO_REAL` |
| Presidente Figueiredo/AM | `ISSWEB_AM` | `PENDENTE_HOMOLOGACAO_REAL` |
| Rio Preto da Eva/AM | `ISSWEB_AM` | `PENDENTE_HOMOLOGACAO_REAL` |
| Itajaí/SC | `PUBLICA` | `PENDENTE_HOMOLOGACAO_REAL` |
| São Bento do Sul/SC | `IPM` | `PENDENTE_HOMOLOGACAO_REAL` |

Lista completa e status detalhado:

- [docs/NFSE-MUNICIPIOS-SUPORTADOS-HOMOLOGACAO.md](docs/NFSE-MUNICIPIOS-SUPORTADOS-HOMOLOGACAO.md)
- [docs/NFSE-CAPITAIS-HOMOLOGACAO-TRACKER.md](docs/NFSE-CAPITAIS-HOMOLOGACAO-TRACKER.md)
- [docs/nfse-municipios-suportados.csv](docs/nfse-municipios-suportados.csv)

## Uso Detalhado

### 1) **NFe: emitir, consultar e cancelar**

```php
use Fiscal\Facade\NFeFacade;

$nfe = new NFeFacade();

// Emissão
$resultado = $nfe->emitir($dadosNfe);
if ($resultado->sucesso) {
    echo "NFe emitida: " . $resultado->dados['chave'];
}

// Consulta por chave
$consulta = $nfe->consultar('43210315123456789012345678901234567890123456');
if ($consulta->sucesso) {
    echo "Status: " . $consulta->dados['status'];
}
```

### 2) **Impressão: DANFE/DANFCE**

```php
use Fiscal\Facade\ImpressaoFacade;

$impressao = new ImpressaoFacade();

// Gerar DANFE a partir do XML
$danfePdf = $impressao->gerarDanfe($xmlNfe);
file_put_contents('danfe.pdf', $danfePdf->dados);
```

### 3) **NFSe: múltiplos municípios**

```php
use sabbajohn\FiscalCore\Facade\NFSeFacade;

$nfse = new NFSeFacade('joinville');

$resultado = $nfse->emitir($dadosServico);
if ($resultado->isSuccess()) {
    $data = $resultado->getData();
    echo "NFSe processada pelo provider: " . $data['emissao']['effective_provider_class'];
}

$consulta = $nfse->consultarPorRps([
    'numero' => '1001',
    'serie' => 'A1',
    'tipo' => '1',
]);
```

### 4) **NFSe municipal pronta para uso**

```php
use sabbajohn\FiscalCore\Facade\FiscalFacade;

$fiscal = new FiscalFacade();
$nfse = $fiscal->nfse('belem');

$resultado = $nfse->emitirCompleto($dadosServico);
if ($resultado->isSuccess()) {
    echo $resultado->getData('flow_status');
    echo $resultado->getData('documento')['numero'] ?? '';
    echo $resultado->getData('impressao')['modo'] ?? '';
}
```

### 5) **DANFSe municipal**

```php
use sabbajohn\FiscalCore\Facade\NFSeFacade;

$nfse = new NFSeFacade('joinville');
$consulta = $nfse->consultarPorRps([
    'numero' => '1001',
    'serie' => 'A1',
    'tipo' => '1',
]);

if ($consulta->isSuccess()) {
    $documento = $consulta->getData('documento');
    $impressao = $consulta->getData('impressao');

    echo $documento['status_autorizacao'] ?? '';
    echo $impressao['modo'] ?? '';
    echo $impressao['url'] ?? '';
    echo $impressao['pdf_base64'] ?? '';
}
```

### 6) **Salvar XML e PDF retornados**

```php
$consulta = $fiscal->consultarNFe($chave);

if ($consulta->isSuccess()) {
    $documento = $consulta->getData('documento');
    file_put_contents('nfe.xml', $documento['xml']);
}

$danfe = $fiscal->gerarDanfe($xmlAutorizado);

if ($danfe->isSuccess()) {
    $impressao = $danfe->getData('impressao');
    file_put_contents(
        $impressao['filename'] ?? 'danfe.pdf',
        base64_decode($impressao['pdf_base64'])
    );
}
```

### 7) **Consulta padronizada de XML**

```php
$xmlNfe = $fiscal->baixarXmlNFe($chave);
$xmlNfce = $fiscal->baixarXmlNFCe($chave);
$xmlNfse = $fiscal->baixarXmlNFSe($chave, 'manaus');

foreach ([$xmlNfe, $xmlNfce, $xmlNfse] as $response) {
    if ($response->isSuccess()) {
        file_put_contents(
            'documento.xml',
            $response->getData('documento')['xml']
        );
    }
}
```

### 7.1) **Contrato canônico e reconciliação de XML antigo**

- `documento.xml` agora representa somente o XML fiscal imprimível.
- `raw.response_body` preserva payload bruto textual/JSON retornado pelo provedor.
- `raw.response_xml` preserva XML técnico bruto de transporte/resposta, quando existir.

Se você já salvou registros antigos com o XML errado na base:

- NFe:
  - Se houver `raw.parsed_response.xml_assinado` e `raw.response_xml`, tente remontar o `nfeProc` com `NFePHP\NFe\Complements::toAuthorize(...)`.
  - Se não houver material suficiente, reconsulte/baixe pela chave de acesso e substitua o registro pelo `documento.xml` canônico.
- NFSe:
  - Se o registro salvo contiver JSON, XML administrativo ou envelope SOAP, tente reextrair o documento fiscal a partir de `nfseXmlGZipB64`, `raw_xml` ou por nova consulta com chave/protocolo/RPS.

Critérios práticos para identificar registros corrompidos:

- NFe: raiz `retEnviNFe`, `retConsReciNFe`, `retConsSitNFe` ou ausência de `NFe/infNFe`.
- NFSe: JSON, `<string>`, envelope SOAP sem `CompNfse/Nfse/InfNfse` ou XML administrativo sem a nota final.

### 8) **Consultas Públicas**

```php
use Fiscal\Facade\FiscalFacade;

$fiscal = new FiscalFacade();

// CEP
$cep = $fiscal->consultarCEP('01310-100');

// CNPJ  
$cnpj = $fiscal->consultarCNPJ('11222333000181');

// NCM
$ncm = $fiscal->consultarNCM('84715010');
```

## 🏗️ Arquitetura

### 🎭 **Sistema de Facades**

```bash
FiscalFacade (Interface Unificada)
├── NFeFacade (Documentos NFe)
├── NFCeFacade (NFCe/Cupons)  
├── NFSeFacade (Notas de Serviço)
├── TributacaoFacade (Cálculos IBPT)
└── ImpressaoFacade (DANFE/DANFSE)
```

### 🔄 **Sistema de Respostas**

```php
FiscalResponse {
    bool $sucesso;      // true/false
    mixed $dados;       // dados retornados
    string $erro;       // mensagem de erro
    array $detalhes;    // informações extras
}
```

### 🛡️ **Error Handling**

- **Fallbacks** automáticos entre providers
- **Cache** local em fluxos específicos, com política unificada ainda pendente
- **Logging/diagnóstico** em evolução
- **Retry** ainda não é uniforme entre adapters

## 📊 Casos de Uso

### 💼 **E-commerce**

```php
// Calcular tributos em tempo real
$tributos = $fiscal->calcularTributos([
    'ncm' => '84715010',
    'origem' => 'SP',
    'destino' => 'RJ',
    'valor' => 1000.00
]);
```

### 🏭 **ERP/Contabilidade**  

```php
// Validar CNPJ antes de emitir NFe
$cnpj = $fiscal->consultarCNPJ('11222333000181');
if ($cnpj->sucesso) {
    // Proceder com emissão
}
```

### 🏢 **Software House**

```php
// Gerenciar múltiplos municípios
foreach ($clientes as $cliente) {
    $nfse = $fiscal->emitirNFSe($cliente->municipio, $dados);
}
```

## 🔧 Requisitos Técnicos

- **PHP** >=8.1
- **OpenSSL** (para certificados)
- **cURL** (para APIs externas)  
- **JSON** (manipulação de dados)

### 📦 **Dependências Principais**

```bash
nfephp-org/sped-nfe         # NFe/NFCe
guzzlehttp/guzzle          # HTTP Client  
monolog/monolog            # Logging
```

### 🧪 **Testes**

```bash
composer test
# ou
vendor/bin/phpunit

# Suite critica para CI/release
composer test:ci

# NFSe focado
composer test:nfse

# Analise estatica inicial
composer analyse
```

// App\Providers\AppServiceProvider.php
use NfePHP\NFe\Tools;
use sabbajohn\FiscalCore\Adapters\NFeAdapter;

public function register()
{
    $this->app->bind(NFeAdapter::class, function () {
        $configJson = json_encode([ /*sua config NFe*/ ]);
        return new NFeAdapter(new Tools($configJson));
    });
}

```bash

Estrutura do projeto

```

src/
  Contracts/          # Interfaces (contratos de domínio)
    NotaFiscalInterface.php
    NotaServicoInterface.php
    ImpressaoInterface.php
    TributacaoInterface.php
    ProdutoInterface.php
    DocumentoInterface.php
    ConsultaPublicaInterface.php

  Adapters/           # Implementações que integram com bibliotecas externas
    NFeAdapter.php
    NFCeAdapter.php
    NFSeAdapter.php
    ImpressaoAdapter.php
    IBPTAdapter.php
    GTINAdapter.php
    DocumentoAdapter.php
    BrasilAPIAdapter.php

  Support/            # Classes utilitárias e gerenciamento centralizado
    CertificateManager.php    # Singleton para certificados digitais
    ConfigManager.php         # Singleton para configurações fiscais
    ToolsFactory.php          # Factory para NFePHP Tools
    IBPTAdapter.php
    GTINAdapter.php

## 🚨 Troubleshooting

### ❓ **Problemas Comuns**

| Erro | Solução |
| ------ | --------- |
| Certificado inválido | Verificar formato .pfx e senha |
| API indisponível | Usar fallbacks automáticos |
| Município não configurado | Revisar `config/nfse/providers-catalog.json` e `config/nfse/nfse-provider-families.json` |
| Quota excedida | Implementar cache local |

### 🔍 **Debug Mode**

```bash
export FISCAL_DEBUG=true
php examples/GuiaCompletoDeUso.php
```

### 📞 **Suporte**

- Ver exemplos em [examples/](examples/)
- Logs detalhados em modo debug  
- Issues no repositório

## 🗺️ Roadmap

O status consolidado e a lista priorizada de pendências estão em [docs/STATUS-E-PENDENCIAS.md](docs/STATUS-E-PENDENCIAS.md).

### ✅ **Concluído ou operacional**

- [x] Facades principais: `FiscalFacade`, `NFeFacade`, `NFCeFacade`, `NFSeFacade`, `ImpressaoFacade`, `TributacaoFacade` e `UtilsFacade`
- [x] Adapters principais para documentos fiscais, impressão, consultas públicas, IBPT e GTIN
- [x] NFSe provider-based com catálogo municipal, families, resolver, registry, bootstrap e scripts operacionais
- [x] Playbook municipal e matriz operacional NFSe
- [x] Cache local em fluxos específicos, como catálogo nacional e instâncias NFSe por município
- [x] Nome Composer canônico definido como `sabbajohn/fiscal-core`
- [x] Scripts Composer, CI mínimo e PHPStan inicial preparados para release `v1.2.4`

### 🔄 **Pendente ou parcial**

- [ ] Publicar/atualizar o Packagist no pacote canônico `sabbajohn/fiscal-core`
- [ ] Expandir homologação municipal real seguindo o playbook, sem contar roteamento em catálogo como homologação
- [ ] Fechar contrato público uniforme das facades para respostas, erros, XML, impressão e eventos
- [ ] Criar Service Provider Laravel com publish de config e bindings
- [ ] Criar middleware de validação automática depois de estabilizar contrato/config Laravel
- [ ] Consolidar política de cache para consultas remotas e configurações
- [ ] Publicar/documentar GitHub Packages, se este canal for necessário
- [x] Criar referência inicial por Facade
- [ ] Criar referência detalhada por Adapter
- [ ] Definir formatter

## 🛠️ Configuração Avançada

Para informações detalhadas sobre configuração de certificados e providers, consulte:

- 📄 [docs/providers-and-config.md](docs/providers-and-config.md)
- 📄 [docs/API-FACADES.md](docs/API-FACADES.md)
- 📄 [docs/RELEASE-PACKAGIST.md](docs/RELEASE-PACKAGIST.md)
- 📄 [docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md)
- 📄 [config/nfse/providers-catalog.json](config/nfse/providers-catalog.json)
- 📄 [config/nfse/nfse-provider-families.json](config/nfse/nfse-provider-families.json)

Fluxo canônico de resolução NFSe:

`config/nfse/providers-catalog.json` -> `config/nfse/nfse-provider-families.json` -> `NFSeProviderResolver` -> `ProviderRegistry` -> `NFSeRuntimeBootstrap` -> provider concreto

## 🧪 Estrutura de Testes

```bash
vendor/bin/phpunit
```

### Gerenciamento Centralizado (Singletons)

```php
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;

// Certificados centralizados
$certManager = CertificateManager::getInstance();
$certManager->loadFromFile('/path/to/cert.pfx', 'password');

// Configurações centralizadas
$configManager = ConfigManager::getInstance();
$configManager->set('ambiente', 2); // homologação
```

## 📁 Estrutura do Projeto

```bash
src/
  Adapters/           # Integrações diretas com libs externas
    BrasilAPIAdapter.php
    DocumentoAdapter.php
    GTINAdapter.php
    IBPTAdapter.php
    ImpressaoAdapter.php
  Contracts/          # Interfaces padronizadas
  Facade/             # Interfaces unificadas
    FiscalFacade.php  # ✅ Interface principal
    NFeFacade.php     # ✅ NFe completa
    NFCeFacade.php    # ✅ NFCe completa
    NFSeFacade.php    # ✅ Multi-município
    ImpressaoFacade.php # ✅ DANFE/DANFSE
    TributacaoFacade.php # ✅ IBPT
  Support/            # Utilitários e helpers
examples/             # ✅ Exemplos práticos
  README.md           # ✅ Guia completo
  GuiaCompletoDeUso.php # ✅ Visão geral
  basico/             # ✅ Iniciantes
  avancado/           # ✅ Produção
```

$configManager->set('uf', 'SP');
$configManager->set('csc', 'SEU_CSC');

// Acesso em qualquer adapter
$isProduction = $configManager->isProduction();
$nfeConfig = $configManager->getNFeConfig();

```php

### ToolsFactory
```php
use sabbajohn\FiscalCore\Support\ToolsFactory;

// Setup rápido para desenvolvimento
ToolsFactory::setupForDevelopment(['uf' => 'SP']);

// Cria Tools pré-configurados
$nfeTools = ToolsFactory::createNFeTools();
$adapter = new NFeAdapter($nfeTools);

// Validação de ambiente
$validation = ToolsFactory::validateEnvironment();
```

Status do projeto

- ✅ NFe Adapter: enviar/consultar/cancelar
- ✅ NFCe Adapter: emissão modelo 65
- ✅ Impressão (DANFE/DANFCE/MDFe/CTe)
- ✅ IBPT Adapter: cálculo de impostos
- ✅ GTIN Adapter: validação de códigos
- ✅ Documento Adapter: validação CPF/CNPJ
- ✅ BrasilAPI Adapter: consultas públicas
- ✅ Singletons: CertificateManager, ConfigManager, ToolsFactory
- ✅ NFSe: arquitetura provider-based ativa (catálogo municipal, families, resolver, registry, runtime bootstrap, facade e scripts operacionais)
- ✅ Facades: orquestração de múltiplos adapters

Roadmap

📋 **Ver TODO completo:** [TODO.md](TODO.md)

🚀 **Sistema de Providers NFSe:**

- ✅ Catálogo municipal, families config, registry e runtime bootstrap implementados
- ✅ Providers municipais reais para fluxos específicos já integrados
- ✅ Hot swap operacional por município (`municipio-provider-overrides.json` + `provider-switch.php`)
- ✅ Reconciliação contínua com base Uninfe (`reconcile-uninfe-providers.php`)
- 📚 Playbook mestre: [docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md)
- 📚 Grid completa de municípios compatíveis: [docs/NFSE-MUNICIPIOS-COMPATIVEIS-GRID.md](docs/NFSE-MUNICIPIOS-COMPATIVEIS-GRID.md)
- 📚 Tabela completa provider x municípios: [docs/NFSE-PROVIDERS-MUNICIPIOS.md](docs/NFSE-PROVIDERS-MUNICIPIOS.md)
- 📚 Tracker de homologação por ondas: [docs/NFSE-CAPITAIS-HOMOLOGACAO-TRACKER.md](docs/NFSE-CAPITAIS-HOMOLOGACAO-TRACKER.md)

**Próximas features:**

- [ ] Expandir cobertura municipal seguindo o playbook ([ver guia](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md))
- [ ] Consolidar contrato público coeso das Facades já existentes (NFe/NFCe/NFSe/Impressão/Tributação)
- [ ] Service Provider para Laravel
- [ ] Middleware para validação automática
- [ ] Política unificada de cache de consultas e configurações
- [X] Publicar/atualizar pacote no Packagist como `sabbajohn/fiscal-core`
- [ ] Publicar/documentar GitHub Packages, se necessário
- [x] Documentação inicial de cada Facade
- [ ] Documentação detalhada de cada Adapter

📋 **Status consolidado:** [docs/STATUS-E-PENDENCIAS.md](docs/STATUS-E-PENDENCIAS.md)

**Quick start para retomar:**

```bash
# Ver estrutura de NFSe
tree src/Providers/NFSe config/nfse docs/nfse-providers

# Listar overrides operacionais
php scripts/nfse/provider-switch.php --list

# Gerar tabela de cobertura por provider
php scripts/nfse/generate-providers-municipios-doc.php

# Reconciliar catalogo local com base Uninfe
php scripts/nfse/reconcile-uninfe-providers.php --fail-on-unexpected

# Ler playbook municipal
cat docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md
```

Contribuição

- Issues e PRs são bem-vindos. Antes de abrir PR:
  - Rode `vendor/bin/phpunit` e garanta verde.
  - Siga o estilo existente e mantenha mudanças focadas.

Licença

- MIT. Veja `composer.json`.
