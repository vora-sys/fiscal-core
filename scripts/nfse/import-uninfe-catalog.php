<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);

$options = getopt('', [
    'source-csv::',
    'source-xml::',
    'output::',
    'base-manifest::',
    'generated-at::',
    'dry-run',
]);

$sourceCsv = trim((string) ($options['source-csv'] ?? ($root.'/Uninfe/source/NFe.Components.Wsdl/NFse/WSDL/provedores_municipios_por_estado.csv')));
$sourceXml = trim((string) ($options['source-xml'] ?? ($root.'/Uninfe/source/NFe.Components.Wsdl/NFse/WSDL/Webservice.xml')));
$outputPath = trim((string) ($options['output'] ?? ($root.'/config/nfse/nfse-catalog-manifest.json')));
$baseManifestPath = trim((string) ($options['base-manifest'] ?? $outputPath));
$generatedAt = trim((string) ($options['generated-at'] ?? '1970-01-01T00:00:00+00:00'));
$dryRun = array_key_exists('dry-run', $options);

$sourcePath = '';
$sourceKind = '';
$rows = [];

if ($sourceCsv !== '' && is_file($sourceCsv)) {
    $sourcePath = $sourceCsv;
    $sourceKind = 'csv';
    $rows = loadCsvRows($sourceCsv);
} elseif ($sourceXml !== '' && is_file($sourceXml)) {
    $sourcePath = $sourceXml;
    $sourceKind = 'xml';
    $rows = loadXmlRows($sourceXml);
} else {
    fwrite(STDERR, "Fonte UniNFe de catalogo nao encontrada: {$sourceCsv} ou {$sourceXml}\n");
    exit(1);
}

$baseManifest = is_file($baseManifestPath)
    ? json_decode((string) file_get_contents($baseManifestPath), true, 512, JSON_THROW_ON_ERROR)
    : [];
$baseOverrides = is_array($baseManifest['municipio_overrides'] ?? null) ? $baseManifest['municipio_overrides'] : [];

$municipios = [];
$skipped = 0;
$families = [];

foreach ($rows as $row) {
    $ibge = onlyDigits((string) ($row['ibge'] ?? ''));
    $provider = normalizeProvider((string) ($row['provider'] ?? ''));
    $municipio = normalizeText((string) ($row['municipio'] ?? ''));
    $uf = strtoupper(normalizeText((string) ($row['uf'] ?? '')));

    if ($ibge === '' || $provider === '' || $municipio === '' || $uf === '') {
        $skipped++;

        continue;
    }

    $families[$provider] = true;
    $base = [
        'provider_family' => $provider,
        'schema_package' => $provider,
        'slug' => slug($municipio),
        'nome' => $municipio,
        'uf' => $uf,
    ];

    $override = is_array($baseOverrides[$ibge] ?? null) ? $baseOverrides[$ibge] : [];
    $municipios[$ibge] = mergeRecursiveDistinct($base, $override);
}

ksort($municipios, SORT_STRING);
ksort($families, SORT_STRING);

$manifest = [
    'generated_at' => $generatedAt,
    $sourceKind === 'xml' ? 'source_xml' : 'source_csv' => relativePath($sourcePath, $root),
    'mode' => 'all',
    'processed_municipios' => count($municipios),
    'skipped_entries' => $skipped,
    'families_count' => count($families),
    'selected_ibges' => '[todos]',
    'municipio_overrides' => $municipios,
    'extra_provider_families' => is_array($baseManifest['extra_provider_families'] ?? null)
        ? $baseManifest['extra_provider_families']
        : [],
];

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

if (! $dryRun) {
    ensureDirectory(dirname($outputPath));
    file_put_contents($outputPath, $encoded);
}

echo json_encode([
    'dry_run' => $dryRun,
    'source_kind' => $sourceKind,
    'source' => $sourcePath,
    'output' => $outputPath,
    'processed_municipios' => count($municipios),
    'skipped_entries' => $skipped,
    'families_count' => count($families),
    'written' => $dryRun ? [] : [relativePath($outputPath, $root)],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

exit(0);

/**
 * @return list<array{provider:string,uf:string,municipio:string,ibge:string}>
 */
function loadCsvRows(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Falha ao abrir CSV UniNFe: {$path}");
    }

    $header = fgetcsv($fh, 0, ',', '"', '');
    $header = is_array($header) ? array_map(static fn ($v): string => strtolower(trim((string) $v)), $header) : [];

    $rows = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $mapped = [];
        foreach ($row as $index => $value) {
            $key = $header[$index] ?? (string) $index;
            $mapped[$key] = trim((string) $value);
        }

        $rows[] = [
            'provider' => (string) ($mapped['provider'] ?? $mapped['provedor'] ?? $row[0] ?? ''),
            'uf' => (string) ($mapped['uf'] ?? $row[1] ?? ''),
            'municipio' => (string) ($mapped['municipio'] ?? $mapped['município'] ?? $row[2] ?? ''),
            'ibge' => (string) ($mapped['id'] ?? $mapped['ibge'] ?? $row[3] ?? ''),
        ];
    }

    fclose($fh);

    return $rows;
}

/**
 * @return list<array{provider:string,uf:string,municipio:string,ibge:string}>
 */
function loadXmlRows(string $path): array
{
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = false;
    if (! $dom->load($path)) {
        throw new RuntimeException("Falha ao ler XML UniNFe: {$path}");
    }

    $rows = [];
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (! $element instanceof DOMElement) {
            continue;
        }

        $provider = firstNonEmptyAttribute($element, ['provider', 'provedor', 'padrao', 'servico', 'name', 'Nome']);
        $uf = firstNonEmptyAttribute($element, ['uf', 'UF', 'estado', 'Estado']);
        $municipio = firstNonEmptyAttribute($element, ['municipio', 'Município', 'Municipio', 'cidade', 'Cidade', 'nome', 'Nome']);
        $ibge = firstNonEmptyAttribute($element, ['ibge', 'IBGE', 'id', 'ID', 'codigo', 'Codigo']);

        if ($provider === '' || $uf === '' || $municipio === '' || $ibge === '') {
            continue;
        }

        $rows[] = [
            'provider' => $provider,
            'uf' => $uf,
            'municipio' => $municipio,
            'ibge' => $ibge,
        ];
    }

    return $rows;
}

/**
 * @param  list<string>  $names
 */
function firstNonEmptyAttribute(DOMElement $element, array $names): string
{
    foreach ($names as $name) {
        if ($element->hasAttribute($name)) {
            $value = trim($element->getAttribute($name));
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function normalizeProvider(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9_]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return $value;
}

function normalizeText(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function onlyDigits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function slug(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $base = strtolower($ascii !== false ? $ascii : $value);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');

    return $base !== '' ? $base : 'municipio';
}

/**
 * @param  array<string,mixed>  $base
 * @param  array<string,mixed>  $override
 * @return array<string,mixed>
 */
function mergeRecursiveDistinct(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = mergeRecursiveDistinct($base[$key], $value);

            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Falha ao criar diretorio: {$directory}");
    }
}

function relativePath(string $path, string $root): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/').'/';
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
