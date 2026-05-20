<?php

declare(strict_types=1);

$options = getopt('', [
    'family:',
    'layout-family:',
    'schema-package:',
    'dry-run',
]);

$family = strtoupper(trim((string) ($options['family'] ?? '')));
if ($family === '') {
    fwrite(STDERR, "Informe --family.\n");
    exit(1);
}

$layoutFamily = strtoupper(trim((string) ($options['layout-family'] ?? $family)));
$schemaPackage = strtoupper(trim((string) ($options['schema-package'] ?? $family)));
$dryRun = array_key_exists('dry-run', $options);
$className = str_replace(' ', '', ucwords(strtolower(str_replace(['_', '-'], ' ', $family)))) . 'Provider';
$basePath = "build/nfse-scaffold/families/{$family}";

echo json_encode([
    'mode' => 'family',
    'dry_run' => $dryRun,
    'context' => [
        'family_key' => $family,
        'layout_family' => $layoutFamily,
        'schema_package' => $schemaPackage,
        'provider_class' => $className,
    ],
    'generated_files' => [
        ['path' => "{$basePath}/src/Providers/NFSe/Municipal/{$className}.php"],
        ['path' => "{$basePath}/snippets/nfse-provider-family.json"],
        ['path' => "{$basePath}/docs/nfse-providers/{$family}.md"],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
