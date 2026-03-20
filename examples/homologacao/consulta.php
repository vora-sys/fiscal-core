<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use freeline\FiscalCore\Facade\FiscalFacade;

$projectRoot = dirname(__DIR__, 2);

$envOverrides = [
    'FISCAL_ENVIRONMENT' => 'homologacao',
    'FISCAL_IM' => '4007197',
    'FISCAL_CERT_PATH' => $projectRoot . '/certs/cert_faives_.p12',
    'FISCAL_CERT_PASSWORD' => '',
    'OPENSSL_CONF' => $projectRoot . '/openssl.cnf',
];

foreach ($envOverrides as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$loteId = $argv[1] ?? '056412883';

$fiscalFacade = new FiscalFacade();
$nfse = $fiscalFacade->nfse('belem');
$providerInfo = $nfse->getProviderInfo();
$resultado = $nfse->consultarLote($loteId);

echo "Provider pronto: " . ($providerInfo->isSuccess() ? 'sim' : 'nao') . PHP_EOL;
echo "Consulta lote Belém:" . PHP_EOL;
echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
