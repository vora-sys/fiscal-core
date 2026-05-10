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
- [📊 Casos de Uso](#-casos-de-uso)
- [🔧 Requisitos](#-requisitos-técnicos)
- [🚨 Troubleshooting](#-troubleshooting)
- [🗺️ Roadmap](#️-roadmap)

## 📦 Instalação via Composer

```bash
composer require fiscal/fiscal-core
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
- **Cache** inteligente
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

# Ou sobrescrevendo documento/CEP do tomador
php examples/homologacao/01-emitir-belem-real.php --tomador-doc=00980556236 --tomador-cep=66065112
php examples/homologacao/02-emitir-joinville-real.php --tomador-doc=00980556236 --tomador-cep=89220650
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
- **Cache** de resultados
- **Logging** detalhado
- **Retry** inteligente

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

- **PHP** ^8.0
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

### ✅ **Concluído**

- [x] Interface unificada (Facades)
- [x] Sistema de respostas padronizado
- [x] Error handling robusto
- [x] Múltiplos providers NFSe
- [x] Consultas públicas
- [x] Tributação IBPT

### 🔄 **Em Desenvolvimento**

- [ ] Interface web de administração
- [ ] Mais municípios NFSe  
- [ ] Integração com bancos de dados
- [ ] Dashboard de monitoramento

### 🎯 **Planejado**

- [ ] API REST para microserviços
- [ ] SDK JavaScript/Python
- [ ] Plugins para principais ERPs
- [ ] Certificação digital em nuvem

## 🛠️ Configuração Avançada

Para informações detalhadas sobre configuração de certificados e providers, consulte:

- 📄 [docs/providers-and-config.md](docs/providers-and-config.md)
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
- 🔄 NFSe: arquitetura provider-based (stubs implementados)
- 🔄 Facades: orquestração de múltiplos adapters

Roadmap

📋 **Ver TODO completo:** [TODO.md](TODO.md)

🚀 **Sistema de Providers NFSe:**

- ✅ Catálogo municipal, families config, registry e runtime bootstrap implementados
- ✅ Providers municipais reais para fluxos específicos já integrados
- 📚 Playbook mestre: [docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md)
- 📚 Ponte legada: [docs/PROVIDERS-RETOMADA.md](docs/PROVIDERS-RETOMADA.md)

**Próximas features:**

- [ ] Expandir cobertura municipal seguindo o playbook ([ver guia](docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md))
- [ ] Facades com APIs coesas (NFe/NFCe/NFSe/Impressão/Tributação)
- [ ] Service Provider para Laravel
- [ ] Middleware para validação automática
- [ ] Cache de consultas e configurações
- [ ] Publicar pacote no Packagist/GitHub Packages
- [ ] Documentação detalhada de cada Facade e Adapter

**Quick start para retomar:**

```bash
# Ver estrutura criada
tree src/Providers config/

# Rodar exemplo funcional
php scripts/exemplo-providers-nfse.php

# Ler playbook municipal
cat docs/NFSE-MUNICIPAL-PROVIDER-PLAYBOOK.md
```

Contribuição

- Issues e PRs são bem-vindos. Antes de abrir PR:
  - Rode `vendor/bin/phpunit` e garanta verde.
  - Siga o estilo existente e mantenha mudanças focadas.

Licença

- MIT. Veja `composer.json`.
