<?php

namespace sabbajohn\Examples;

require_once __DIR__.'/../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\NFeFacade;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\SafeCertificateManager;
use sabbajohn\FiscalCore\Support\SafeConfigManager;
use sabbajohn\FiscalCore\Support\ToolsFactory;

/**
 * Exemplo de inicialização segura com tratamento de erros padronizado
 * Demonstra como evitar erros 500 durante setup e configuração
 */
function exemploInicializacaoSegura(): void
{
    echo "🔧 Inicialização Segura do Sistema Fiscal\n";
    echo "=========================================\n\n";

    // 1. Verificar configurações
    echo "1. Verificando configurações do sistema...\n";
    $configResponse = SafeConfigManager::validateCompleteConfigSafe();
    exibirResponse($configResponse, 'Validação de Configuração');

    // 2. Configurar para desenvolvimento se necessário
    if ($configResponse->isError()) {
        echo "\n2. Configurando ambiente de desenvolvimento...\n";
        $setupResponse = ToolsFactory::setupForDevelopmentSafe([
            'uf' => 'SC',
            'municipio_ibge' => '4205407',
            'token_ibpt' => 'TEST_TOKEN',
        ]);
        exibirResponse($setupResponse, 'Setup Desenvolvimento');
    }

    // 3. Verificar status do certificado
    echo "\n3. Verificando certificado digital...\n";
    $certResponse = SafeCertificateManager::getStatusSafe();
    exibirResponse($certResponse, 'Status do Certificado');

    // 4. Tentar carregar certificado se necessário
    if ($certResponse->isError()) {
        echo "\n4. Tentando carregar certificado de variáveis de ambiente...\n";
        $loadResponse = SafeCertificateManager::loadFromEnvironmentSafe();
        exibirResponse($loadResponse, 'Carregamento de Certificado');
    }

    // 5. Validar ambiente completo
    echo "\n5. Validando ambiente completo...\n";
    $envResponse = ToolsFactory::validateEnvironmentSafe();
    exibirResponse($envResponse, 'Validação de Ambiente');

    // 6. Testar criação de Facade
    echo "\n6. Testando inicialização do Facade NFe...\n";
    testFacadeInitialization();
}

function testFacadeInitialization(): void
{
    try {
        $facade = new NFeFacade;
        echo "✅ NFeFacade inicializado com sucesso!\n";

        // Testa operação simples
        $statusResponse = $facade->verificarStatusSefaz('SC', 2);
        exibirResponse($statusResponse, 'Teste de Status SEFAZ');

    } catch (\Throwable $e) {
        echo '❌ ERRO na inicialização do Facade: '.$e->getMessage()."\n";
        echo "   Isso NÃO deveria acontecer com a nova implementação!\n";
    }
}

function exemploConfiguracaoCompleta(): void
{
    echo "\n🔧 Exemplo de Configuração Completa\n";
    echo "===================================\n\n";

    // Configuração para produção (simulada)
    echo "1. Tentando configurar para produção (sem dados reais)...\n";
    $prodResponse = ToolsFactory::setupForProductionSafe([
        'uf' => 'SP',
        'municipio_ibge' => '3550308',
        // 'csc' => 'SEU_CSC_AQUI',
        // 'csc_id' => '000001'
    ]);
    exibirResponse($prodResponse, 'Setup Produção (Erro Esperado)');

    // Configuração segura para desenvolvimento
    echo "\n2. Configurando para desenvolvimento...\n";
    $devResponse = ToolsFactory::setupForDevelopmentSafe([
        'uf' => 'RJ',
        'municipio_ibge' => '3304557',
        'serie_nfe' => '2',
        'serie_nfce' => '3',
        'token_ibpt' => 'FAKE_TOKEN_FOR_TESTS',
    ]);
    exibirResponse($devResponse, 'Setup Desenvolvimento');

    // Verificar configuração específica
    echo "\n3. Verificando configurações específicas...\n";
    $ufResponse = SafeConfigManager::getConfigSafe('uf');
    exibirResponse($ufResponse, 'Configuração UF');

    $ambienteResponse = SafeConfigManager::isProductionSafe();
    exibirResponse($ambienteResponse, 'Verificação de Ambiente');
}

function exemploGerenciamentoCertificado(): void
{
    echo "\n🔐 Exemplo de Gerenciamento de Certificado\n";
    echo "==========================================\n\n";

    // Informações do certificado
    echo "1. Obtendo informações do certificado...\n";
    $infoResponse = SafeCertificateManager::getCertificateInfoSafe();
    exibirResponse($infoResponse, 'Informações do Certificado');

    // Validação completa
    echo "\n2. Validação completa do certificado...\n";
    $validResponse = SafeCertificateManager::validateSafe();
    exibirResponse($validResponse, 'Validação do Certificado');

    // Tentar reload
    echo "\n3. Tentando reload do certificado...\n";
    $reloadResponse = SafeCertificateManager::reloadSafe();
    exibirResponse($reloadResponse, 'Reload do Certificado');
}

function exibirResponse(FiscalResponse $response, string $titulo): void
{
    echo "--- {$titulo} ---\n";

    if ($response->isSuccess()) {
        echo "✅ Sucesso!\n";
        $data = $response->getData();

        // Exibe dados relevantes de forma organizada
        if (isset($data['valid'])) {
            echo 'Válido: '.($data['valid'] ? 'Sim' : 'Não')."\n";
        }
        if (isset($data['environment'])) {
            echo 'Ambiente: '.$data['environment']."\n";
        }
        if (isset($data['loaded'])) {
            echo 'Carregado: '.($data['loaded'] ? 'Sim' : 'Não')."\n";
        }
        if (isset($data['errors']) && ! empty($data['errors'])) {
            echo 'Erros encontrados: '.count($data['errors'])."\n";
        }
        if (isset($data['warnings']) && ! empty($data['warnings'])) {
            echo 'Avisos: '.count($data['warnings'])."\n";
        }

    } else {
        echo "⚠️  Erro tratado (sem crash):\n";
        echo 'Código: '.$response->getErrorCode()."\n";
        echo 'Mensagem: '.$response->getError()."\n";

        $metadata = $response->getMetadata();
        if (isset($metadata['suggestions'])) {
            echo "Sugestões:\n";
            foreach ($metadata['suggestions'] as $suggestion) {
                echo "  - $suggestion\n";
            }
        }

        if (isset($metadata['severity'])) {
            echo 'Severidade: '.$metadata['severity']."\n";
        }
    }

    echo 'Operação: '.$response->getOperation()."\n";
    echo 'Timestamp: '.$response->getMetadata('timestamp')."\n";
    echo "\n";
}

function exemploComparacaoAntesDepois(): void
{
    echo "🆚 COMPARAÇÃO: ANTES vs DEPOIS\n";
    echo "==============================\n\n";

    echo "❌ ANTES (Código que podia gerar erro 500):\n";
    echo "```php\n";
    echo "try {\n";
    echo "    \$tools = ToolsFactory::createNFeTools(); // Podia lançar Exception\n";
    echo "    \$adapter = new NFeAdapter(\$tools);\n";
    echo "} catch (\\Exception \$e) {\n";
    echo "    // App precisa tratar cada tipo de erro manualmente\n";
    echo "    throw new \\RuntimeException('Erro 500 para o usuário');\n";
    echo "}\n";
    echo "```\n\n";

    echo "✅ DEPOIS (Nunca gera erro 500):\n";
    echo "```php\n";
    echo "\$response = ToolsFactory::createNFeToolsSafe();\n";
    echo "if (\$response->isSuccess()) {\n";
    echo "    \$tools = \$response->getData()['result'];\n";
    echo "    \$adapter = new NFeAdapter(\$tools);\n";
    echo "} else {\n";
    echo "    // Erro já tratado, metadata com sugestões\n";
    echo "    \$error = \$response->getError();\n";
    echo "    \$suggestions = \$response->getMetadata('suggestions');\n";
    echo "}\n";
    echo "```\n\n";

    echo "📊 BENEFÍCIOS:\n";
    echo "• Nunca lança exceções não tratadas\n";
    echo "• Response estruturado e consistente\n";
    echo "• Metadados com sugestões de correção\n";
    echo "• Rastreamento de operações\n";
    echo "• Códigos de erro padronizados\n";
    echo "• Logs facilitados\n";
    echo "• Zero erros 500 em produção\n";
}

// Executa os exemplos
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    exemploInicializacaoSegura();
    exemploConfiguracaoCompleta();
    exemploGerenciamentoCertificado();
    exemploComparacaoAntesDepois();

    echo "\n🎉 CONCLUSÃO\n";
    echo "============\n";
    echo "✅ Sistema fiscal totalmente protegido contra erros 500\n";
    echo "✅ Inicialização, configuração e operações sempre retornam FiscalResponse\n";
    echo "✅ Debugging facilitado com metadados estruturados\n";
    echo "✅ Pronto para produção com tratamento robusto de erros\n";
}
