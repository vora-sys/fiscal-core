<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPayloadFactory;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPreviewSupport;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

function emitirMunicipioEmPreview(string $municipio): array
{
    $factory = new NFSeMunicipalPayloadFactory;
    $meta = $factory->providerMeta($municipio);
    $payload = $factory->demo($municipio);

    $config = ProviderRegistry::getInstance()->getConfig($meta['provider_key']);
    $config['certificate'] = NFSeMunicipalPreviewSupport::makeCertificate('Functional '.ucfirst($municipio));
    $config['prestador'] = $payload['prestador'];
    $config['soap_transport'] = NFSeMunicipalPreviewSupport::makeTransport($municipio);

    $providerClass = $config['provider_class'];
    $provider = new $providerClass($config);
    $provider->emitir($payload);

    if (! $provider instanceof NFSeOperationalIntrospectionInterface) {
        throw new RuntimeException("Provider {$providerClass} nao suporta introspeccao.");
    }

    return [
        'payload' => $payload,
        'request_xml' => (string) $provider->getLastRequestXml(),
        'soap_envelope' => (string) $provider->getLastSoapEnvelope(),
        'parsed_response' => $provider->getLastResponseData(),
    ];
}

echo "EMISSAO MUNICIPAL FUNCIONAL - PREVIEW LOCAL\n";
echo "==========================================\n\n";

foreach (['belem', 'joinville'] as $municipio) {
    try {
        $result = emitirMunicipioEmPreview($municipio);
    } catch (InvalidArgumentException $e) {
        echo strtoupper($municipio).PHP_EOL;
        echo str_repeat('=', strlen($municipio)).PHP_EOL;
        echo 'Preview municipal ignorado: '.$e->getMessage().PHP_EOL.PHP_EOL;

        continue;
    }

    $payload = $result['payload'];
    $parsed = $result['parsed_response'];

    echo strtoupper($municipio).PHP_EOL;
    echo str_repeat('=', strlen($municipio)).PHP_EOL;
    echo 'Tomador: '.$payload['tomador']['razao_social'].PHP_EOL;
    echo 'Servico: '.$payload['servico']['descricao'].PHP_EOL;
    echo 'Valor: R$ '.number_format((float) $payload['valor_servicos'], 2, ',', '.').PHP_EOL;
    echo 'Status parseado: '.($parsed['status'] ?? 'desconhecido').PHP_EOL;
    echo "XML da requisicao:\n";
    echo $result['request_xml'].PHP_EOL;
    echo "Resposta parseada:\n";
    echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL;
}

echo "Nenhuma prefeitura foi acionada neste exemplo.\n";
