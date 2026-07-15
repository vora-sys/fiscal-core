<?php

namespace sabbajohn\Examples;

require_once __DIR__.'/../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\NFCeFacade;
use sabbajohn\FiscalCore\Facade\NFeFacade;
use sabbajohn\FiscalCore\Support\FiscalResponse;

/**
 * Exemplos de uso dos Facades com tratamento de erros
 * Demonstra como evitar erros 500 nas aplicações
 */
function salvarArtefatosFiscais(FiscalResponse $response, string $prefixo): void
{
    if (! $response->isSuccess()) {
        return;
    }

    $outputDir = __DIR__.'/output';
    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $documento = $response->getData('documento') ?? [];
    $impressao = $response->getData('impressao') ?? [];

    if (! empty($documento['xml'])) {
        $xmlPath = $outputDir.'/'.$prefixo.'.xml';
        file_put_contents($xmlPath, (string) $documento['xml']);
        echo "XML salvo em: {$xmlPath}\n";
    }

    if (($impressao['modo'] ?? null) === 'pdf_base64' && ! empty($impressao['pdf_base64'])) {
        $pdfPath = $outputDir.'/'.($impressao['filename'] ?? ($prefixo.'.pdf'));
        file_put_contents($pdfPath, base64_decode((string) $impressao['pdf_base64']));
        echo "PDF salvo em: {$pdfPath}\n";
    }

    if (($impressao['modo'] ?? null) === 'url' && ! empty($impressao['url'])) {
        $urlPath = $outputDir.'/'.$prefixo.'.url.txt';
        file_put_contents($urlPath, (string) $impressao['url']);
        echo "URL de impressao salva em: {$urlPath}\n";
    }
}

function exemploNFeComTratamentoErros(): void
{
    echo "=== Teste NFe com Facade e Response Handler ===\n\n";

    try {
        $nfeFacade = new NFeFacade;

        // Dados de exemplo para NFe
        $dadosNFe = [
            'identificacao' => [
                'tpAmb' => 2, // Homologação
                'tpEmis' => 1,
                'serie' => 1,
                'nNF' => 1,
                'dhEmi' => date('Y-m-d\TH:i:sP'),
            ],
            'emitente' => [
                'CNPJ' => '11222333000181',
                'xNome' => 'Empresa de Testes',
                'endereco' => [
                    'cUF' => '42',
                    'xLgr' => 'Rua das Flores, 123',
                    'xBairro' => 'Centro',
                    'cMun' => '4205407',
                    'xMun' => 'Florianópolis',
                    'UF' => 'SC',
                    'CEP' => '88010000',
                ],
            ],
            'destinatario' => [
                'CPF' => '12345678901',
                'xNome' => 'Cliente Teste',
            ],
        ];

        // 1. Teste de criação de nota (validação prévia)
        echo "1. Validando dados da NFe...\n";
        $responseValidacao = $nfeFacade->criarNota($dadosNFe);

        exibirResponse($responseValidacao, 'Validação NFe');

        // 2. Teste de consulta com chave inválida
        echo "\n2. Testando consulta com chave inválida...\n";
        $responseConsulta = $nfeFacade->consultar('123'); // Chave inválida

        exibirResponse($responseConsulta, 'Consulta NFe (erro esperado)');

        // 3. Teste de verificação de status SEFAZ
        echo "\n3. Verificando status da SEFAZ...\n";
        $responseStatus = $nfeFacade->verificarStatusSefaz('SC', 2);

        exibirResponse($responseStatus, 'Status SEFAZ');

    } catch (\Throwable $e) {
        echo 'Erro inesperado (não deveria acontecer com Facade): '.$e->getMessage()."\n";
    }
}

function exemploNFCeComTratamentoErros(): void
{
    echo "\n=== Teste NFCe com Facade e Response Handler ===\n\n";

    try {
        $nfceFacade = new NFCeFacade;

        // Dados de exemplo para NFCe
        $dadosNFCe = [
            'identificacao' => [
                'mod' => 65,
                'tpAmb' => 2,
                'tpEmis' => 1,
                'serie' => 1,
                'nNF' => 1,
                'dhEmi' => date('Y-m-d\TH:i:sP'),
            ],
            'emitente' => [
                'CNPJ' => '11222333000181',
                'xNome' => 'Loja de Conveniência',
            ],
            'destinatario' => [
                'xNome' => 'CONSUMIDOR',
            ],
        ];

        echo "1. Criando NFCe para validação...\n";
        $responseNFCe = $nfceFacade->criarNota($dadosNFCe);

        exibirResponse($responseNFCe, 'Criação NFCe');

        // Teste de cancelamento com motivo inválido
        echo "\n2. Testando cancelamento com motivo muito curto...\n";
        $responseCancelamento = $nfceFacade->cancelar(
            '42000111222333000181650010000000010000000017',
            'Teste', // Motivo muito curto
            '123456789012345'
        );

        exibirResponse($responseCancelamento, 'Cancelamento NFCe (erro esperado)');

    } catch (\Throwable $e) {
        echo 'Erro inesperado: '.$e->getMessage()."\n";
    }
}

function exemploSemTratamentoErros(): void
{
    echo "\n=== Teste SEM Facade (erro direto) ===\n\n";

    try {
        // Simula uso direto do adapter sem tratamento
        throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
    } catch (\InvalidArgumentException $e) {
        echo '❌ ERRO 500 seria lançado na aplicação: '.$e->getMessage()."\n";
        echo "   Com o Facade, isso seria capturado e retornado como FiscalResponse!\n";
    }
}

function exibirResponse(FiscalResponse $response, string $titulo): void
{
    echo "--- {$titulo} ---\n";

    if ($response->isSuccess()) {
        echo "✅ Sucesso!\n";
        echo 'Dados: '.json_encode($response->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
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
    }

    echo 'Operação: '.$response->getOperation()."\n";
    echo 'Timestamp: '.$response->getMetadata('timestamp')."\n";
}

// Executa os exemplos
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    echo "🔧 Exemplos de Uso dos Facades com Response Handler\n";
    echo "==================================================\n\n";

    exemploNFeComTratamentoErros();
    exemploNFCeComTratamentoErros();
    exemploSemTratamentoErros();

    echo "\n✨ Conclusões:\n";
    echo "1. Os Facades capturam TODAS as exceções\n";
    echo "2. Retornam sempre FiscalResponse padronizado\n";
    echo "3. Aplicações nunca recebem erros 500 diretos\n";
    echo "4. Metadata inclui sugestões para corrigir problemas\n";
    echo "5. Logs e debugging facilitados com operation tracking\n";
}
