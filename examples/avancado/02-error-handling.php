<?php

/**
 * EXEMPLO AVANÇADO: Error handling e recuperação
 *
 * Como lidar com diferentes tipos de erro e implementar recuperação
 */

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;
use sabbajohn\FiscalCore\Support\FiscalResponse;

echo "🛡️ EXEMPLO AVANÇADO: Error Handling Robusto\n";
echo "============================================\n\n";

$fiscal = new FiscalFacade;

// === TIPOS DE ERRO ===
echo "1️⃣ TIPOS DE ERRO E TRATAMENTO\n";
echo "------------------------------\n";

// Simulador de diferentes cenários de erro
class ErrorHandlingDemo
{
    private FiscalFacade $fiscal;

    public function __construct()
    {
        $this->fiscal = new FiscalFacade;
    }

    public function demonstrarTiposDeErro(): void
    {
        $cenarios = [
            'consulta_ncm_invalido' => fn () => $this->fiscal->consultarNCM('invalid'),
            'municipio_inexistente' => fn () => $this->fiscal->emitirNFSe([], 'cidade_fantasma'),
            'dados_invalidos' => fn () => $this->fiscal->tributacao()->calcular(['ncm' => '']),
            'xml_invalido' => fn () => $this->fiscal->impressao()->validarXML('<xml_mal_formado>', 'nfe'),
        ];

        foreach ($cenarios as $nome => $callback) {
            echo "\n🧪 Testando: {$nome}\n";

            $resultado = $callback();
            $this->analisarResposta($resultado, $nome);
        }
    }

    private function analisarResposta(FiscalResponse $resposta, string $contexto): void
    {
        if ($resposta->isSuccess()) {
            echo "  ✅ Sucesso inesperado!\n";

            return;
        }

        // Análise detalhada do erro
        echo '  ❌ Erro: '.$resposta->getError()."\n";
        echo '  🏷️ Código: '.$resposta->getErrorCode()."\n";
        echo '  📍 Operação: '.$resposta->getOperation()."\n";

        $metadata = $resposta->getMetadata();

        // Severidade
        if (isset($metadata['severity'])) {
            $severityIcon = match ($metadata['severity']) {
                'critical' => '🚨',
                'error' => '❌',
                'warning' => '⚠️',
                default => 'ℹ️'
            };
            echo "  {$severityIcon} Severidade: ".$metadata['severity']."\n";
        }

        // Recuperável?
        if (isset($metadata['recoverable'])) {
            $recoverable = $metadata['recoverable'] ? '✅ Sim' : '❌ Não';
            echo "  🔄 Recuperável: {$recoverable}\n";
        }

        // Sugestões
        if (isset($metadata['suggestions'])) {
            echo "  💡 Sugestões:\n";
            foreach (array_slice($metadata['suggestions'], 0, 2) as $sugestao) {
                echo "    • {$sugestao}\n";
            }
        }
    }
}

$demo = new ErrorHandlingDemo;
$demo->demonstrarTiposDeErro();

// === ESTRATÉGIAS DE RECUPERAÇÃO ===
echo "\n\n2️⃣ ESTRATÉGIAS DE RECUPERAÇÃO\n";
echo "-----------------------------\n";

class RecoveryStrategies
{
    private FiscalFacade $fiscal;

    public function __construct()
    {
        $this->fiscal = new FiscalFacade;
    }

    /**
     * Tenta múltiplos NCMs até encontrar um válido
     */
    public function consultaComFallback(array $ncms): ?array
    {
        echo "🔍 Tentativa de consulta com fallback...\n";

        foreach ($ncms as $ncm) {
            echo "  Tentando NCM: {$ncm}... ";

            $resultado = $this->fiscal->consultarNCM($ncm);
            if ($resultado->isSuccess()) {
                echo "✅ Sucesso!\n";

                return $resultado->getData();
            }

            echo "❌ Falhou\n";
        }

        echo "  ❌ Nenhum NCM válido encontrado\n";

        return null;
    }

    /**
     * Tenta emitir NFSe em múltiplos municípios
     */
    public function emissaoComFallback(array $dadosNfse, array $municipios): ?array
    {
        echo "📋 Tentativa de emissão com fallback...\n";

        foreach ($municipios as $municipio) {
            echo "  Tentando município: {$municipio}... ";

            $resultado = $this->fiscal->emitirNFSe($dadosNfse, $municipio);
            if ($resultado->isSuccess()) {
                echo "✅ Emitido!\n";

                return array_merge($resultado->getData(), ['municipio_usado' => $municipio]);
            }

            echo '❌ Falhou ('.$resultado->getErrorCode().")\n";
        }

        echo "  ❌ Nenhum município conseguiu processar a NFSe\n";

        return null;
    }

    /**
     * Valida dados antes de processar
     */
    public function validacaoPrevia(array $produto): bool
    {
        echo "🔍 Validação prévia de produto...\n";

        $resultado = $this->fiscal->tributacao()->validarProduto($produto);

        if ($resultado->isSuccess()) {
            echo "  ✅ Produto válido para processamento\n";

            $data = $resultado->getData();
            if (! empty($data['warnings'])) {
                echo "  ⚠️ Avisos encontrados:\n";
                foreach ($data['warnings'] as $warning) {
                    echo "    • {$warning}\n";
                }
            }

            return true;
        }

        echo '  ❌ Produto inválido: '.$resultado->getError()."\n";

        return false;
    }
}

$recovery = new RecoveryStrategies;

// Teste de consulta com fallback
$ncmsParaTeste = ['22071000', '85171231', '90241000', 'invalid'];
$resultadoNCM = $recovery->consultaComFallback($ncmsParaTeste);

// Teste de validação prévia
$produtoTeste = [
    'ncm' => '85171231',
    'valor' => 299.90,
    'descricao' => 'Smartphone',
];

$produtoValido = $recovery->validacaoPrevia($produtoTeste);

// === LOGGING E MONITORAMENTO ===
echo "\n\n3️⃣ LOGGING E MONITORAMENTO\n";
echo "--------------------------\n";

class FiscalLogger
{
    private array $logs = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logs[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function logFiscalResponse(FiscalResponse $response, string $operation): void
    {
        $level = $response->isSuccess() ? 'INFO' : 'ERROR';
        $message = $response->isSuccess() ?
            "Operação {$operation} bem-sucedida" :
            "Erro em {$operation}: ".$response->getError();

        $context = [
            'operation' => $operation,
            'error_code' => $response->getErrorCode(),
            'metadata' => $response->getMetadata(),
        ];

        $this->log($level, $message, $context);
    }

    public function getStats(): array
    {
        $total = count($this->logs);
        $errors = count(array_filter($this->logs, fn ($log) => $log['level'] === 'ERROR'));
        $warnings = count(array_filter($this->logs, fn ($log) => $log['level'] === 'WARNING'));

        return [
            'total_operations' => $total,
            'errors' => $errors,
            'warnings' => $warnings,
            'success_rate' => $total > 0 ? round((($total - $errors) / $total) * 100, 2) : 0,
        ];
    }

    public function printLogs(): void
    {
        foreach ($this->logs as $log) {
            $icon = match ($log['level']) {
                'ERROR' => '❌',
                'WARNING' => '⚠️',
                'INFO' => 'ℹ️',
                default => '📝'
            };

            echo "{$icon} [{$log['timestamp']}] {$log['level']}: {$log['message']}\n";
        }
    }
}

$logger = new FiscalLogger;

// Simular operações com logging
$operacoes = [
    fn () => $fiscal->consultarNCM('22071000'),
    fn () => $fiscal->emitirNFSe([], 'municipio_inexistente'),
    fn () => $fiscal->tributacao()->consultarCEP('01310-100'),
];

foreach ($operacoes as $i => $operacao) {
    $resultado = $operacao();
    $logger->logFiscalResponse($resultado, 'operacao_'.($i + 1));
}

echo "📊 Logs das operações:\n";
$logger->printLogs();

echo "\n📈 Estatísticas:\n";
$stats = $logger->getStats();
foreach ($stats as $key => $value) {
    echo "  • {$key}: {$value}\n";
}

echo "\n🎯 MELHORES PRÁTICAS:\n";
echo "==============================\n";
echo "✅ Sempre verificar isSuccess() antes de usar dados\n";
echo "✅ Implementar fallbacks para operações críticas\n";
echo "✅ Validar dados de entrada quando possível\n";
echo "✅ Fazer log de todas as operações\n";
echo "✅ Monitorar taxa de sucesso\n";
echo "✅ Usar códigos de erro para lógica condicional\n";
echo "✅ Implementar retry com backoff exponencial\n";
echo "✅ Configurar alertas para erros críticos\n";
