<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', [
    'list',
    'set',
    'remove',
    'municipio:',
    'provider:',
    'reason::',
    'ticket::',
    'file::',
    'dry-run',
]);

$operation = resolveOperation($options);
$dryRun = array_key_exists('dry-run', $options);
$root = dirname(__DIR__, 2);
$overridePath = trim((string) ($options['file'] ?? ($root . '/config/nfse/municipio-provider-overrides.json')));

$document = loadOverridesDocument($overridePath);

if ($operation === 'list') {
    echo json_encode([
        'operation' => 'list',
        'dry_run' => $dryRun,
        'file' => $overridePath,
        'count' => count($document['overrides']),
        'overrides' => $document['overrides'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$municipioInput = trim((string) ($options['municipio'] ?? ''));
if ($municipioInput === '') {
    fwrite(STDERR, "Informe --municipio.\n");
    exit(1);
}

$catalog = new NFSeMunicipalCatalog($root . '/config/nfse/providers-catalog.json');
$resolved = $catalog->resolveMunicipio($municipioInput);
if ($resolved === null) {
    fwrite(STDERR, "Município '{$municipioInput}' não encontrado no catálogo.\n");
    exit(1);
}

$ibge = (string) ($resolved['ibge'] ?? '');
$slug = (string) ($resolved['slug'] ?? '');
$currentFamily = (string) ($resolved['provider_family_key'] ?? '');
$before = $document['overrides'][$ibge] ?? null;

if ($operation === 'set') {
    $providerInput = trim((string) ($options['provider'] ?? ''));
    if ($providerInput === '') {
        fwrite(STDERR, "Informe --provider para operação --set.\n");
        exit(1);
    }

    $providerKey = resolveProviderKey($providerInput, $root . '/config/nfse/nfse-provider-families.json');
    $entry = [
        'provider_family' => $providerKey,
        'active' => true,
        'updated_at' => gmdate('c'),
    ];

    $reason = trim((string) ($options['reason'] ?? ''));
    if ($reason !== '') {
        $entry['reason'] = $reason;
    }

    $ticket = trim((string) ($options['ticket'] ?? ''));
    if ($ticket !== '') {
        $entry['ticket'] = $ticket;
    }

    $document['overrides'][$ibge] = $entry;
    $document['updated_at'] = gmdate('c');
    ksort($document['overrides']);

    if (!$dryRun) {
        saveOverridesDocument($overridePath, $document);
    }

    echo json_encode([
        'operation' => 'set',
        'dry_run' => $dryRun,
        'file' => $overridePath,
        'municipio' => [
            'input' => $municipioInput,
            'ibge' => $ibge,
            'slug' => $slug,
            'catalog_provider' => $currentFamily,
        ],
        'before' => $before,
        'after' => $entry,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(0);
}

$removed = false;
if (array_key_exists($ibge, $document['overrides'])) {
    unset($document['overrides'][$ibge]);
    $document['updated_at'] = gmdate('c');
    $removed = true;
}

if (!$dryRun) {
    saveOverridesDocument($overridePath, $document);
}

echo json_encode([
    'operation' => 'remove',
    'dry_run' => $dryRun,
    'file' => $overridePath,
    'municipio' => [
        'input' => $municipioInput,
        'ibge' => $ibge,
        'slug' => $slug,
        'catalog_provider' => $currentFamily,
    ],
    'removed' => $removed,
    'before' => $before,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(0);

/**
 * @param array<string, mixed> $options
 */
function resolveOperation(array $options): string
{
    $operations = [];
    foreach (['list', 'set', 'remove'] as $flag) {
        if (array_key_exists($flag, $options)) {
            $operations[] = $flag;
        }
    }

    if (count($operations) !== 1) {
        fwrite(STDERR, "Use exatamente uma operação: --list, --set ou --remove.\n");
        exit(1);
    }

    return $operations[0];
}

/**
 * @return array{version:int,updated_at:?string,overrides:array<string,mixed>}
 */
function loadOverridesDocument(string $path): array
{
    if (!is_file($path)) {
        return [
            'version' => 1,
            'updated_at' => null,
            'overrides' => [],
        ];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        fwrite(STDERR, "Falha ao ler arquivo de overrides: {$path}\n");
        exit(1);
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "JSON inválido em {$path}: {$e->getMessage()}\n");
        exit(1);
    }

    if (!is_array($data)) {
        return [
            'version' => 1,
            'updated_at' => null,
            'overrides' => [],
        ];
    }

    $overrides = is_array($data['overrides'] ?? null) ? $data['overrides'] : [];

    return [
        'version' => (int) ($data['version'] ?? 1),
        'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        'overrides' => $overrides,
    ];
}

/**
 * @param array{version:int,updated_at:?string,overrides:array<string,mixed>} $document
 */
function saveOverridesDocument(string $path, array $document): void
{
    $encoded = json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    file_put_contents($path, $encoded . PHP_EOL);
}

function resolveProviderKey(string $input, string $familiesPath): string
{
    $json = file_get_contents($familiesPath);
    if ($json === false) {
        fwrite(STDERR, "Falha ao ler famílias de providers: {$familiesPath}\n");
        exit(1);
    }

    try {
        $families = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "JSON inválido em {$familiesPath}: {$e->getMessage()}\n");
        exit(1);
    }

    if (!is_array($families)) {
        fwrite(STDERR, "Estrutura inválida de famílias em {$familiesPath}\n");
        exit(1);
    }

    if (isset($families[$input])) {
        return $input;
    }

    $normalized = strtolower(trim($input));
    foreach (array_keys($families) as $familyKey) {
        if (strtolower((string) $familyKey) === $normalized) {
            return (string) $familyKey;
        }
    }

    fwrite(STDERR, "Provider '{$input}' não encontrado em nfse-provider-families.json.\n");
    exit(1);
}
