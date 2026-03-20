<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../homologacao/common.php';

$projectRoot = dirname(__DIR__, 2);

exit(nfseMunicipalRunScript(
    'belem',
    $argv,
    nfseMunicipalBuildEnvOverrides('belem', 'producao', $projectRoot),
    [
        'ambiente' => 'producao',
        'payload_overrides' => [
            'valor_servicos' => 10.00,
            'servico' => [
                'aliquota' => 0.05,
                'descricao' => 'Servicos de tecnologia da informacao em producao.',
                'discriminacao' => 'Servicos de tecnologia da informacao em producao.',
            ],
        ],
    ]
));
