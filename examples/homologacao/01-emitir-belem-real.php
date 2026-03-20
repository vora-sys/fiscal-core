<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';

$projectRoot = dirname(__DIR__, 2);

exit(nfseMunicipalRunScript(
    'belem',
    $argv,
    nfseMunicipalBuildEnvOverrides('belem', 'homologacao', $projectRoot)
));
