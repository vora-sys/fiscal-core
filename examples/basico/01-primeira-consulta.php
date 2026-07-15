<?php

/**
 * Primeira operação fiscal usando fiscal-core
 * Consulta NCM para cálculo tributário
 */

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;

$fiscal = new FiscalFacade;
$resultado = $fiscal->consultarNCM('84715010');

if ($resultado->isSuccess()) {
    $data = $resultado->getData();
    echo "NCM encontrado:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "✅ Sucesso!\n";
    echo 'Código: '.($data['codigo'] ?? 'N/A')."\n";
    echo 'Descrição: '.($data['descricao'] ?? 'N/A')."\n";
    echo 'Unidade: '.($data['unidade'] ?? 'N/A')."\n";
} else {
    echo 'Erro: '.$resultado->getError()."\n";
    echo '❌ Erro: '.$resultado->getError()."\n";
    echo 'Código do erro: '.$resultado->getErrorCode()."\n";

    // Verificar se há sugestões
    $metadata = $resultado->getMetadata();
    if (isset($metadata['suggestions'])) {
        echo "\n💡 Sugestões:\n";
        foreach ($metadata['suggestions'] as $sugestao) {
            echo "  • {$sugestao}\n";
        }
    }
}

echo "\n🎯 PRÓXIMO PASSO:\n";
echo "Teste outros exemplos em examples/basico/\n";
