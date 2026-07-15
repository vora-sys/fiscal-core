<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/common.php';

use RuntimeException;
use sabbajohn\FiscalCore\Facade\FiscalFacade;

/**
 * Uso:
 *   php examples/homologacao/consulta.php [municipio] [tipo-consulta] [referencia]
 *
 * Exemplos:
 *   php examples/homologacao/consulta.php belem lote 123
 *   php examples/homologacao/consulta.php manaus dps ABC123
 */
$projectRoot = dirname(__DIR__, 2);
$municipio = strtolower($argv[1] ?? 'belem');
$tipoConsulta = strtolower($argv[2] ?? 'lote');
$referencia = $argv[3] ?? null;

$envOverrides = nfseMunicipalBuildEnvOverrides($municipio, 'homologacao', $projectRoot);
nfseMunicipalApplyEnvOverrides($envOverrides);

$referencia = $referencia ?? match ($tipoConsulta) {
    'lote' => nfseMunicipalRequiredEnvValue(sprintf('TEST_NFSE_%s_PROTOCOLO', strtoupper($municipio))),
    'dps' => nfseMunicipalRequiredEnvValue(sprintf('TEST_NFSE_%s_DPS', strtoupper($municipio))),
    'rps' => nfseMunicipalRequiredEnvValue(sprintf('TEST_NFSE_%s_RPS', strtoupper($municipio))),
    'nfse' => nfseMunicipalRequiredEnvValue(sprintf('TEST_NFSE_%s_NUMERO', strtoupper($municipio))),
    default => throw new RuntimeException(sprintf('Tipo de consulta não suportado: %s', $tipoConsulta)),
};

$fiscalFacade = new FiscalFacade;
$nfse = $fiscalFacade->nfse($municipio);
$providerInfo = $nfse->getProviderInfo();

$resultado = match ($tipoConsulta) {
    'lote' => $nfse->consultarLote($referencia),
    'dps' => method_exists($nfse, 'consultarDps')
        ? $nfse->consultarDps($referencia)
        : throw new RuntimeException(sprintf('O provider de %s não implementa consultarDps().', $municipio)),
    'rps' => method_exists($nfse, 'consultarRps')
        ? $nfse->consultarRps($referencia)
        : throw new RuntimeException(sprintf('O provider de %s não implementa consultarRps().', $municipio)),
    'nfse' => method_exists($nfse, 'consultarNfse')
        ? $nfse->consultarNfse($referencia)
        : throw new RuntimeException(sprintf('O provider de %s não implementa consultarNfse().', $municipio)),
    default => throw new RuntimeException(sprintf('Tipo de consulta não suportado: %s', $tipoConsulta)),
};

echo 'Provider pronto: '.($providerInfo->isSuccess() ? 'sim' : 'nao').PHP_EOL;
echo 'Municipio: '.$municipio.PHP_EOL;
echo 'Tipo de consulta: '.$tipoConsulta.PHP_EOL;
echo 'Referencia: '.$referencia.PHP_EOL;
echo 'Consulta '.ucfirst($tipoConsulta).' '.ucfirst($municipio).':'.PHP_EOL;
echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
