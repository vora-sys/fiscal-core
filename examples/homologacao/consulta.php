<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';

use freeline\FiscalCore\Facade\FiscalFacade;

$projectRoot = dirname(__DIR__, 2);

$envOverrides = nfseMunicipalBuildEnvOverrides('belem', 'homologacao', $projectRoot);
nfseMunicipalApplyEnvOverrides($envOverrides);

$loteId = $argv[1] ?? nfseMunicipalRequiredEnvValue('TEST_NFSE_BELEM_PROTOCOLO');

$fiscalFacade = new FiscalFacade();
$nfse = $fiscalFacade->nfse('belem');
$providerInfo = $nfse->getProviderInfo();
$resultado = $nfse->consultarLote($loteId);

echo "Provider pronto: " . ($providerInfo->isSuccess() ? 'sim' : 'nao') . PHP_EOL;
echo "Consulta lote Belém:" . PHP_EOL;
echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
