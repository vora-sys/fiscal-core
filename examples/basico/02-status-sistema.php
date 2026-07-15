<?php

/**
 * Verificação de status dos componentes fiscal-core
 * Mostra quais funcionalidades estão disponíveis
 */

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;

$fiscal = new FiscalFacade;
$status = $fiscal->verificarStatus();

if ($status->isSuccess()) {
    $data = $status->getData();

    echo "Sistema fiscal-core - Status:\n";
    echo 'PHP: '.$data['system_info']['php_version']."\n";
    echo 'Memória: '.number_format($data['system_info']['memory_usage'] / 1024 / 1024, 1)."MB\n\n";

    echo "Componentes:\n";
    foreach ($data['services'] as $servico => $info) {
        $icon = $info->isSuccess() ? '✅' : '⚠️';
        $message = $info->isSuccess() ? 'OK' : $info->getError();
        echo "{$servico}: {$icon} {$message}\n";
    }
} else {
    echo 'Erro: '.$status->getError()."\n";
}
