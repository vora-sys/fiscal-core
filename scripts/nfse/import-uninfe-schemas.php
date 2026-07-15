<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);

$options = getopt('', [
    'source::',
    'output::',
    'manifest::',
    'generated-at::',
    'dry-run',
]);

$sourceDir = rtrim(trim((string) ($options['source'] ?? ($root.'/Uninfe/source/NFe.Components.Wsdl/NFse/schemas/NFSe'))), '/');
$outputDir = rtrim(trim((string) ($options['output'] ?? ($root.'/resources/nfse/schemas'))), '/');
$manifestPath = trim((string) ($options['manifest'] ?? ($outputDir.'/manifest.json')));
$generatedAt = trim((string) ($options['generated-at'] ?? '1970-01-01T00:00:00+00:00'));
$dryRun = array_key_exists('dry-run', $options);

if (! is_dir($sourceDir)) {
    fwrite(STDERR, "Diretorio de schemas UniNFe nao encontrado: {$sourceDir}\n");
    exit(1);
}

$families = [];
$written = [];

foreach (listFamilyDirectories($sourceDir) as $familyDir) {
    $family = basename($familyDir);
    $sourceFiles = listSchemaFiles($familyDir);
    $targetFamilyDir = $outputDir.'/'.$family;
    $fileManifests = [];

    foreach ($sourceFiles as $sourceFile) {
        $relative = relativePath($sourceFile, $familyDir);
        $targetFile = $targetFamilyDir.'/'.$relative;
        $fileManifests[] = [
            'path' => $relative,
            'sha256' => hash_file('sha256', $sourceFile),
            'bytes' => filesize($sourceFile),
        ];

        if (! $dryRun) {
            ensureDirectory(dirname($targetFile));
            copy($sourceFile, $targetFile);
            $written[] = relativePath($targetFile, $root);
        }
    }

    usort(
        $fileManifests,
        static fn (array $a, array $b): int => ((string) $a['path']) <=> ((string) $b['path'])
    );

    $families[$family] = [
        'status' => $sourceFiles === [] ? 'empty' : 'imported',
        'files' => count($sourceFiles),
        'source_kind' => 'uninfe',
        'source' => relativePath($familyDir, $root),
        'target' => relativePath($targetFamilyDir, $root),
        'file_manifest' => $fileManifests,
    ];
}

ksort($families, SORT_STRING);

$manifest = [
    'generated_at' => $generatedAt,
    'source_base' => relativePath($sourceDir, $root),
    'target_base' => relativePath($outputDir, $root),
    'mode' => 'all',
    'custom_schema_families' => [],
    'families' => $families,
    'warnings' => [],
];

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

if (! $dryRun) {
    ensureDirectory(dirname($manifestPath));
    file_put_contents($manifestPath, $encoded);
    $written[] = relativePath($manifestPath, $root);
}

echo json_encode([
    'dry_run' => $dryRun,
    'source' => $sourceDir,
    'output' => $outputDir,
    'manifest' => $manifestPath,
    'families_count' => count($families),
    'files_count' => array_sum(array_map(static fn (array $family): int => (int) $family['files'], $families)),
    'written' => $dryRun ? [] : $written,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

exit(0);

/**
 * @return list<string>
 */
function listFamilyDirectories(string $sourceDir): array
{
    $items = glob($sourceDir.'/*', GLOB_ONLYDIR) ?: [];
    sort($items, SORT_STRING);

    return array_values($items);
}

/**
 * @return list<string>
 */
function listSchemaFiles(string $familyDir): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($familyDir, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'xsd') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files, SORT_STRING);

    return $files;
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
