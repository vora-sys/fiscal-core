<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use freeline\FiscalCore\Facade\FiscalFacade;
use freeline\FiscalCore\Support\BelemMunicipalDocumentUrlBuilder;

function usage(string $scriptName): string
{
    return <<<TXT
Uso:
  php {$scriptName} --source-file=/caminho/retorno-emissao.json

O arquivo pode conter:
  - JSON do retorno da emissao/consulta
  - XML SOAP bruto
  - XML CompNfse/Nfse/InfNfse

Saida:
  - URL oficial do DANFSe de Belém
TXT;
}

function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($arg, '--source-file=')) {
            $options['source_file'] = substr($arg, 14);
            continue;
        }

    }

    return $options;
}

function extractNfseXml(string $content): ?string
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        $content = (string) (
            $decoded['data']['emissao']['introspection']['parsed_response']['raw_xml']
            ?? $decoded['data']['consulta']['parsed_response']['raw_xml']
            ?? $decoded['data']['resultado']
            ?? $decoded['raw_xml']
            ?? ''
        );
    }

    if ($content === '') {
        return null;
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($content)) {
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

$options = parseOptions($argv);
if (($options['help'] ?? false) === true) {
    echo usage(basename((string) $argv[0])) . PHP_EOL;
    exit(0);
}

$sourceFile = trim((string) ($options['source_file'] ?? ''));
if ($sourceFile === '' || !is_file($sourceFile)) {
    fwrite(STDERR, "Erro: informe um --source-file valido.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
foreach ([
    'FISCAL_ENVIRONMENT' => 'producao',
    'FISCAL_IM' => '4007197',
    'FISCAL_CERT_PATH' => $projectRoot . '/certs/cert_faives.p12',
    'FISCAL_CERT_PASSWORD' => '',
    'OPENSSL_CONF' => $projectRoot . '/openssl.cnf',
] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$xml = extractNfseXml((string) file_get_contents($sourceFile));
if ($xml === null) {
    fwrite(STDERR, "Erro: nao foi possivel extrair a NFSe do arquivo informado.\n");
    exit(1);
}

$dom = new DOMDocument();
if (!@$dom->loadXML($xml)) {
    fwrite(STDERR, "Erro: XML da NFSe invalido.\n");
    exit(1);
}

$xpath = new DOMXPath($dom);
$numero = trim((string) $xpath->evaluate("string((//*[local-name()='Numero'])[1])"));
$codigo = trim((string) $xpath->evaluate("string((//*[local-name()='CodigoVerificacao'])[1])"));

if ($numero === '' || $codigo === '') {
    fwrite(STDERR, "Erro: XML sem numero da NFSe ou codigo de verificacao.\n");
    exit(1);
}

$fiscal = new FiscalFacade();
$providerInfo = $fiscal->nfse('belem')->getProviderInfo();

if (!$providerInfo->isSuccess()) {
    fwrite(STDERR, "Erro ao inicializar provider de Belem.\n");
    exit(1);
}

$url = BelemMunicipalDocumentUrlBuilder::build('41954766000192', '4007197', $numero, $codigo);

echo 'URL oficial do DANFSe: ' . $url . PHP_EOL;
