<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/manaus_nacional_common.php';

$projectRoot = dirname(__DIR__, 2);
$options = manausNacionalParseOptions($argv);
if (($options['help'] ?? false) === true) {
    echo manausNacionalUsage(basename((string) $argv[0])) . PHP_EOL;
    exit(0);
}

if (($options['listar_codigos'] ?? false) === true) {
    echo json_encode(
        manausNacionalListarCodigos($options, $projectRoot),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

manausNacionalApplyEnvOverrides($projectRoot);

$nfse = manausNacionalFacade();
$providerInfo = $nfse->getProviderInfo();
$response = null;

if (isset($options['consultar_chave'])) {
    $response = $nfse->consultar((string) $options['consultar_chave']);
} elseif (isset($options['consultar_rps_numero'])) {
    $response = $nfse->consultarPorRps([
        'numero' => (string) $options['consultar_rps_numero'],
        'serie' => (string) ($options['consultar_rps_serie'] ?? '1'),
        'tipo' => (string) ($options['consultar_rps_tipo'] ?? '1'),
    ]);
} elseif (isset($options['consultar_lote'])) {
    $response = $nfse->consultarLote((string) $options['consultar_lote']);
} elseif (isset($options['baixar_xml'])) {
    $response = $nfse->baixarXml((string) $options['baixar_xml']);
} elseif (isset($options['baixar_danfse'])) {
    $response = $nfse->baixarDanfse((string) $options['baixar_danfse']);
} elseif (isset($options['cancelar_chave'])) {
    $response = $nfse->cancelar(
        (string) $options['cancelar_chave'],
        (string) ($options['motivo'] ?? 'Cancelamento de teste em homologacao'),
        (string) ($options['protocolo'] ?? '')
    );
} elseif (($options['aliquotas'] ?? false) === true) {
    $response = $nfse->consultarAliquotasMunicipio(
        '1302603',
        (string) ($options['c_trib_nac'] ?? '010101'),
        (string) ($options['competencia'] ?? date('Y-m-d'))
    );
} elseif (($options['convenio'] ?? false) === true) {
    $response = $nfse->consultarConvenioMunicipio('1302603');
}

if ($response !== null) {
/* TODO: ajustar, funciona bem com os testes */
    
    file_put_contents($response->getData('chave').'.xml', $response->getData('resultado')['raw_xml']);

    file_put_contents($response->getData('filename'), $response->getData('resultado'));
    echo json_encode([
        'provider' => $providerInfo->toArray(),
        'response' => $response->toArray(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$payload = manausNacionalBuildPayload($options);

if (($options['send'] ?? false) !== true) {
    $layout = $nfse->validarLayoutDps($payload, false);
    $xml = $nfse->gerarXmlDpsPreview($payload);

    echo json_encode([
        'mode' => 'preview',
        'provider' => $providerInfo->toArray(),
        'layout' => $layout->toArray(),
        'payload' => $payload,
        'xml_preview' => $xml->getData('xml'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$resultado = $nfse->emitirCompleto($payload);
echo $resultado->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
