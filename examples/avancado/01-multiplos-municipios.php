<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Facade\FiscalFacade;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPayloadFactory;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPreviewSupport;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

function previewMunicipalProvider(string $municipio): array
{
    $factory = new NFSeMunicipalPayloadFactory();
    $meta = $factory->providerMeta($municipio);
    $payload = $factory->demo($municipio);

    $config = ProviderRegistry::getInstance()->getConfig($meta['provider_key']);
    $config['certificate'] = NFSeMunicipalPreviewSupport::makeCertificate('Preview ' . ucfirst($municipio));
    $config['prestador'] = $payload['prestador'];
    $config['soap_transport'] = NFSeMunicipalPreviewSupport::makeTransport($municipio);

    $providerClass = $config['provider_class'];
    $provider = new $providerClass($config);
    $provider->emitir($payload);

    if (!$provider instanceof NFSeOperationalIntrospectionInterface) {
        throw new RuntimeException("Provider {$providerClass} nao suporta introspeccao.");
    }

    return [
        'provider_class' => $providerClass,
        'payload' => $payload,
        'request_xml' => $provider->getLastRequestXml(),
        'parsed_response' => $provider->getLastResponseData(),
    ];
}

echo "NFSe MULTI-MUNICIPIO - EXEMPLO CONSISTENTE\n";
echo "=========================================\n\n";

$fiscal = new FiscalFacade();
$municipios = $fiscal->nfse()->listarMunicipios();

echo "1. Municipios configurados\n";
echo "--------------------------\n";

if (!$municipios->isSuccess()) {
    echo "Erro ao listar municipios: " . $municipios->getError() . PHP_EOL;
    exit(1);
}

$lista = $municipios->getData('municipios') ?? [];
echo "Municipios ativos: " . implode(', ', $lista) . PHP_EOL . PHP_EOL;

echo "2. Providers resolvidos\n";
echo "-----------------------\n";

foreach ($lista as $municipio) {
    $info = (new NFSeFacade($municipio))->getProviderInfo();
    if (!$info->isSuccess()) {
        echo "[ERRO] {$municipio}: " . $info->getError() . PHP_EOL;
        continue;
    }

    $data = $info->getData();
    echo sprintf(
        "[OK] %s -> %s (%s)\n",
        $municipio,
        basename(str_replace('\\', '/', (string) $data['provider_class'])),
        (string) $data['provider_key']
    );
}

echo PHP_EOL;
echo "3. Preview de emissao por municipio\n";
echo "----------------------------------\n";

$previewMunicipios = ['belem', 'joinville'];
foreach ($previewMunicipios as $municipio) {
    echo PHP_EOL . strtoupper($municipio) . PHP_EOL;
    echo str_repeat('-', strlen($municipio)) . PHP_EOL;

    try {
        $preview = previewMunicipalProvider($municipio);
    } catch (InvalidArgumentException $e) {
        echo "Preview municipal ignorado: " . $e->getMessage() . PHP_EOL;
        continue;
    }

    $payload = $preview['payload'];
    $response = $preview['parsed_response'];

    echo "Provider: " . $preview['provider_class'] . PHP_EOL;
    echo "Prestador: " . $payload['prestador']['cnpj'] . " / IM " . $payload['prestador']['inscricaoMunicipal'] . PHP_EOL;
    echo "Servico: " . $payload['servico']['descricao'] . PHP_EOL;
    echo "Valor: R$ " . number_format((float) $payload['valor_servicos'], 2, ',', '.') . PHP_EOL;
    echo "Status preview: " . ($response['status'] ?? 'desconhecido') . PHP_EOL;

    if (isset($response['nfse']['numero'])) {
        echo "Numero preview: " . $response['nfse']['numero'] . PHP_EOL;
    }

    if (isset($response['protocolo'])) {
        echo "Protocolo preview: " . $response['protocolo'] . PHP_EOL;
    }
}

echo PHP_EOL;
echo "4. Observacoes\n";
echo "--------------\n";
echo "- Este exemplo usa payloads validos por provider e transporte mockado.\n";
echo "- Emissao classificada como MEI segue automaticamente pelo provider nacional.\n";
echo "- Alguns municipios ainda exigem classificacao explicita de MEI no payload para evitar ambiguidade.\n";
echo "- Joinville exige codigo_municipio e aliquota coerentes no servico.\n";
echo "- Para envio real em homologacao, use os scripts em examples/homologacao/.\n";
