<?php

/**
 * Exemplo focado em operações fiscais com NFe
 * Demonstra o contexto fiscal propriamente dito
 */

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Facade\FiscalFacade;

$fiscal = new FiscalFacade;

// Operação fiscal: consulta NCM para tributação
$ncm = $fiscal->consultarNCM('84715010');
if ($ncm->isSuccess()) {
    echo 'NCM: '.$ncm->getData()['descricao']."\n";
}

// Operação fiscal: cálculo de tributos
$tributos = $fiscal->calcularTributos([
    'ncm' => '84715010',
    'origem' => 'SP',
    'destino' => 'RJ',
    'valor' => 1000.00,
]);

if ($tributos->isSuccess()) {
    $dados = $tributos->getData();
    echo 'ICMS: '.($dados['icms'] ?? 'N/A')."\n";
    echo 'IPI: '.($dados['ipi'] ?? 'N/A')."\n";
}

// Operação fiscal: verificar status SEFAZ
$status = $fiscal->verificarStatus();
if ($status->isSuccess()) {
    $dados = $status->getData();
    echo 'SEFAZ disponível: '.($dados['sefaz_online'] ? 'Sim' : 'Não')."\n";
}
