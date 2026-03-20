<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use freeline\FiscalCore\Facade\FiscalFacade;
use freeline\FiscalCore\Support\BelemMunicipalDocumentUrlBuilder;

function belemConsultaUsage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} --protocolo=059138577
  php {$scriptName} --rps-numero=164344 [--rps-serie=RPS] [--rps-tipo=1]
  php {$scriptName} --source-file=/caminho/emissao.json

Comportamento:
  --protocolo     Consulta disponibilidade da NFSe pelo protocolo/lote
  --rps-numero    Consulta disponibilidade por RPS
  --rps-serie     Serie do RPS, default RPS
  --rps-tipo      Tipo do RPS, default 1
  --source-file   Arquivo com o retorno da emissao/consulta para montar a URL oficial

Belém disponibiliza o DANFSe na URL oficial da prefeitura. Este script nao gera PDF local.
TXT;
}

function belemConsultaParseOptions(array $argv): array
{
    $options = [
        'rps_serie' => 'RPS',
        'rps_tipo' => '1',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--protocolo=')) {
            $options['protocolo'] = substr($arg, 12);
            continue;
        }

        if (str_starts_with($arg, '--rps-numero=')) {
            $options['rps_numero'] = substr($arg, 13);
            continue;
        }

        if (str_starts_with($arg, '--rps-serie=')) {
            $options['rps_serie'] = substr($arg, 12);
            continue;
        }

        if (str_starts_with($arg, '--rps-tipo=')) {
            $options['rps_tipo'] = substr($arg, 11);
            continue;
        }

        if (str_starts_with($arg, '--source-file=')) {
            $options['source_file'] = substr($arg, 14);
        }
    }

    return $options;
}

function belemExtractNfseXml(array $parsedResponse): ?string
{
    $rawXml = trim((string) ($parsedResponse['raw_xml'] ?? ''));
    if ($rawXml === '') {
        return null;
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($rawXml)) {
        return null;
    }

    $xpath = new DOMXPath($dom);
    foreach (['CompNfse', 'Nfse', 'InfNfse'] as $nodeName) {
        $node = $xpath->query("//*[local-name()='{$nodeName}']")->item(0);
        if ($node instanceof DOMNode) {
            return $dom->saveXML($node) ?: null;
        }
    }

    return null;
}

function belemExtractAvailabilityFromXml(string $content, string $cpfOuCnpj, string $inscricaoMunicipal): ?array
{
    $xml = belemExtractNfseXml(['raw_xml' => $content]);
    if ($xml === null) {
        return null;
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return null;
    }

    $xpath = new DOMXPath($dom);
    $numero = trim((string) $xpath->evaluate("string((//*[local-name()='Numero'])[1])"));
    $codigo = trim((string) $xpath->evaluate("string((//*[local-name()='CodigoVerificacao'])[1])"));
    $dataEmissao = trim((string) $xpath->evaluate("string((//*[local-name()='DataEmissao'])[1])"));

    if ($numero === '' || $codigo === '') {
        return null;
    }

    return [
        'authorization_status' => 'autorizada',
        'disponivel' => true,
        'source' => 'arquivo',
        'protocolo' => null,
        'nfse' => [
            'numero' => $numero,
            'codigo_verificacao' => $codigo,
            'data_emissao' => $dataEmissao !== '' ? $dataEmissao : null,
        ],
        'danfse_url' => BelemMunicipalDocumentUrlBuilder::build($cpfOuCnpj, $inscricaoMunicipal, $numero, $codigo),
        'consulta' => null,
        'warnings' => [],
    ];
}

$options = belemConsultaParseOptions($argv);
if (($options['help'] ?? false) === true) {
    echo belemConsultaUsage(basename((string) $argv[0])) . PHP_EOL;
    exit(0);
}

if (
    trim((string) ($options['protocolo'] ?? '')) === ''
    && trim((string) ($options['rps_numero'] ?? '')) === ''
    && trim((string) ($options['source_file'] ?? '')) === ''
) {
    fwrite(STDERR, "Erro: informe --protocolo, --rps-numero ou --source-file.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$envOverrides = [
    'FISCAL_ENVIRONMENT' => 'producao',
    'FISCAL_IM' => '4007197',
    'FISCAL_CERT_PATH' => $projectRoot . '/certs/cert_faives.p12',
    'FISCAL_CERT_PASSWORD' => '',
    'OPENSSL_CONF' => $projectRoot . '/openssl.cnf',
];

foreach ($envOverrides as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$fiscal = new FiscalFacade();
$nfse = $fiscal->nfse('belem');
$providerInfo = $nfse->getProviderInfo();

if (!$providerInfo->isSuccess()) {
    fwrite(STDERR, "Erro ao inicializar o provider de Belém.\n");
    fwrite(STDERR, $providerInfo->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$consulta = null;
$fonteConsulta = null;
$protocolo = trim((string) ($options['protocolo'] ?? ''));
$availability = null;
$prestadorCnpj = '41954766000192';
$prestadorIm = '4007197';

if (trim((string) ($options['source_file'] ?? '')) !== '') {
    $sourceFile = trim((string) $options['source_file']);
    if (!is_file($sourceFile)) {
        fwrite(STDERR, "Erro: arquivo informado em --source-file nao encontrado.\n");
        exit(1);
    }

    $sourceContents = (string) file_get_contents($sourceFile);
    $availability = belemExtractAvailabilityFromXml($sourceContents, $prestadorCnpj, $prestadorIm);
    $fonteConsulta = 'arquivo';
}

if ($availability === null) {
    $criterios = [];
    if ($protocolo !== '') {
        $criterios['protocolo'] = $protocolo;
        $fonteConsulta = 'lote';
    }

    if (trim((string) ($options['rps_numero'] ?? '')) !== '') {
        $criterios['rps'] = [
            'numero' => trim((string) $options['rps_numero']),
            'serie' => trim((string) $options['rps_serie']),
            'tipo' => trim((string) $options['rps_tipo']),
        ];
        if ($fonteConsulta === null) {
            $fonteConsulta = 'rps';
        }
    }

    $consulta = $nfse->consultarDisponibilidade($criterios);
    $availability = $consulta->isSuccess() ? $consulta->getData() : null;
}

echo 'Provider pronto: ' . ($providerInfo->isSuccess() ? 'sim' : 'nao') . PHP_EOL;
echo 'Fonte da consulta: ' . ($fonteConsulta ?? 'nenhuma') . PHP_EOL;
if ($consulta !== null) {
    echo "Consulta Belém:\n";
    echo $consulta->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if ($availability === null && (!$consulta instanceof \freeline\FiscalCore\Support\FiscalResponse || !$consulta->isSuccess())) {
    fwrite(STDERR, "Erro: a consulta nao retornou sucesso operacional.\n");
    exit(1);
}

if ($availability === null) {
    fwrite(STDERR, "Erro: nao foi possivel determinar a disponibilidade da NFSe.\n");
    exit(1);
}

echo "Disponibilidade Belém:\n";
echo json_encode($availability, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$danfseUrl = trim((string) ($availability['danfse_url'] ?? ''));
if ($danfseUrl === '') {
    fwrite(STDERR, "NFSe ainda nao esta disponivel na URL oficial da prefeitura.\n");
    exit(1);
}

echo 'URL oficial do DANFSe: ' . $danfseUrl . PHP_EOL;
