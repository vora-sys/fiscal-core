<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';

$projectRoot = dirname(__DIR__, 2);

exit(nfseMunicipalRunScript('belem', $argv, [
    'FISCAL_ENVIRONMENT' => 'homologacao',
    'FISCAL_IM' => '4007197',
    'FISCAL_CERT_PATH' => $projectRoot . '/certs/cert_faives.p12',
    'FISCAL_CERT_PASSWORD' => '',
    'OPENSSL_CONF' => $projectRoot . '/openssl.cnf',
]));
