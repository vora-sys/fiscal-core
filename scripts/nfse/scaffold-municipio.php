<?php

declare(strict_types=1);

$options = getopt('', [
    'ibge:',
    'dry-run',
]);

$ibge = preg_replace('/\D+/', '', (string) ($options['ibge'] ?? '')) ?? '';
if ($ibge === '') {
    fwrite(STDERR, "Informe --ibge.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$catalogPath = $root.'/config/nfse/nfse-catalog-manifest.json';
$catalog = is_file($catalogPath)
    ? json_decode((string) file_get_contents($catalogPath), true)
    : [];
$municipio = (array) (($catalog['municipios'][$ibge] ?? null) ?: ($catalog['municipio_overrides'][$ibge] ?? []));

if ($municipio === []) {
    fwrite(STDERR, "Município {$ibge} não encontrado no catálogo NFSe.\n");
    exit(1);
}

$familyKey = (string) ($municipio['provider_family'] ?? $municipio['provider_family_key'] ?? '');
$slug = (string) ($municipio['slug'] ?? $ibge);
$overrides = (array) ($municipio['provider_config_overrides'] ?? []);
$dryRun = array_key_exists('dry-run', $options);
$basePath = "build/nfse-scaffold/municipios/{$slug}";

echo json_encode([
    'mode' => 'municipio',
    'dry_run' => $dryRun,
    'context' => [
        'ibge' => $ibge,
        'slug' => $slug,
        'family_key' => $familyKey,
        'source' => $overrides === [] ? 'catalog' : 'custom_override',
        'provider_config_overrides' => $overrides,
    ],
    'generated_files' => [
        ['path' => "{$basePath}/examples/homologacao/{$slug}.php"],
        ['path' => "{$basePath}/docs/NFSE-".strtoupper(str_replace('-', '_', $slug)).'.md'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
