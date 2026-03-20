<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../homologacao/common.php';

$projectRoot = dirname(__DIR__, 2);

exit(nfseMunicipalRunScript('belem', $argv, [
    'FISCAL_ENVIRONMENT' => 'producao',
    'FISCAL_IM' => '4007197',
    'FISCAL_CERT_PATH' => $projectRoot . '/certs/cert_faives.p12',
    'FISCAL_CERT_PASSWORD' => '',
    'OPENSSL_CONF' => $projectRoot . '/openssl.cnf',
], [
    'ambiente' => 'producao',
    'payload_overrides' => [
        'valor_servicos' => 10.00,
        'servico' => [
            'aliquota' => 0.05,
            'descricao' => 'Servicos de tecnologia da informacao em producao.',
            'discriminacao' => 'Servicos de tecnologia da informacao em producao.',
        ],
    ],
]));
