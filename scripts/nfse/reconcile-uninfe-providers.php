<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);

$options = getopt('', [
    'csv::',
    'catalog::',
    'format::',
    'output::',
    'fail-on-unexpected',
]);

$csvPath = trim((string) ($options['csv'] ?? ($root . '/Uninfe/source/NFe.Components.Wsdl/NFse/WSDL/provedores_municipios_por_estado.csv')));
$catalogPath = trim((string) ($options['catalog'] ?? ($root . '/config/nfse/providers-catalog.json')));
$format = strtolower(trim((string) ($options['format'] ?? 'json')));
$outputPath = trim((string) ($options['output'] ?? ''));
$failOnUnexpected = array_key_exists('fail-on-unexpected', $options);

if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV do Uninfe nao encontrado: {$csvPath}\n");
    exit(1);
}

if (!is_file($catalogPath)) {
    fwrite(STDERR, "Catalogo NFSe nao encontrado: {$catalogPath}\n");
    exit(1);
}

$uninfeByIbge = loadUninfeByIbge($csvPath);
$catalogByIbge = loadCatalogByIbge($catalogPath);

$missing = [];
$divergences = [];
$matched = 0;

foreach ($uninfeByIbge as $ibge => $uninfe) {
    if (!isset($catalogByIbge[$ibge])) {
        $missing[$ibge] = $uninfe;
        continue;
    }

    $catalog = $catalogByIbge[$ibge];
    $catalogFamily = (string) ($catalog['provider_family'] ?? '');
    $uninfeProvider = (string) ($uninfe['provider'] ?? '');

    if ($catalogFamily === $uninfeProvider) {
        $matched++;
        continue;
    }

    $divergence = [
        'ibge' => $ibge,
        'slug' => (string) ($catalog['slug'] ?? ''),
        'nome' => (string) ($catalog['nome'] ?? ''),
        'uf' => (string) ($catalog['uf'] ?? ''),
        'uninfe_provider' => $uninfeProvider,
        'catalog_provider' => $catalogFamily,
    ];

    [$expected, $reason] = classifyDivergence($catalogFamily, $uninfeProvider);
    $divergence['expected'] = $expected;
    $divergence['reason'] = $reason;

    $divergences[] = $divergence;
}

usort(
    $divergences,
    static fn (array $a, array $b): int => [$a['expected'] ? 1 : 0, $a['uf'], $a['nome']] <=> [$b['expected'] ? 1 : 0, $b['uf'], $b['nome']]
);

ksort($missing);

$expectedDivergences = array_values(array_filter($divergences, static fn (array $d): bool => (bool) ($d['expected'] ?? false)));
$unexpectedDivergences = array_values(array_filter($divergences, static fn (array $d): bool => !($d['expected'] ?? false)));

$report = [
    'generated_at' => gmdate('c'),
    'input' => [
        'csv' => $csvPath,
        'catalog' => $catalogPath,
    ],
    'summary' => [
        'uninfe_rows' => count($uninfeByIbge),
        'catalog_active' => count($catalogByIbge),
        'matched' => $matched,
        'missing_in_catalog' => count($missing),
        'divergences_total' => count($divergences),
        'divergences_expected' => count($expectedDivergences),
        'divergences_unexpected' => count($unexpectedDivergences),
    ],
    'missing' => array_values(array_map(
        static fn (string $ibge, array $item): array => [
            'ibge' => $ibge,
            'provider' => (string) ($item['provider'] ?? ''),
            'uf' => (string) ($item['uf'] ?? ''),
            'municipio' => (string) ($item['municipio'] ?? ''),
        ],
        array_keys($missing),
        $missing
    )),
    'divergences_unexpected' => $unexpectedDivergences,
    'divergences_expected_sample' => array_slice($expectedDivergences, 0, 25),
];

$output = $format === 'md'
    ? renderMarkdownReport($report)
    : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($outputPath !== '') {
    file_put_contents($outputPath, $output);
} else {
    echo $output;
}

if ($failOnUnexpected && $report['summary']['divergences_unexpected'] > 0) {
    exit(2);
}

exit(0);

/**
 * @return array<string, array{provider:string,uf:string,municipio:string,id:string}>
 */
function loadUninfeByIbge(string $csvPath): array
{
    $fh = fopen($csvPath, 'r');
    if ($fh === false) {
        throw new RuntimeException("Falha ao abrir CSV: {$csvPath}");
    }

    fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($row) < 4) {
            continue;
        }

        [$provider, $uf, $municipio, $id] = $row;
        $ibge = preg_replace('/\D+/', '', (string) $id) ?? '';
        if ($ibge === '') {
            continue;
        }

        $rows[$ibge] = [
            'provider' => trim((string) $provider),
            'uf' => strtoupper(trim((string) $uf)),
            'municipio' => trim((string) $municipio),
            'id' => trim((string) $id),
        ];
    }

    fclose($fh);

    return $rows;
}

/**
 * @return array<string, array<string, mixed>>
 */
function loadCatalogByIbge(string $catalogPath): array
{
    $data = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
    $municipios = is_array($data['municipios'] ?? null) ? $data['municipios'] : [];

    $result = [];
    foreach ($municipios as $ibge => $municipio) {
        if (!is_array($municipio)) {
            continue;
        }

        if (!($municipio['active'] ?? true)) {
            continue;
        }

        $result[(string) $ibge] = $municipio;
    }

    return $result;
}

/**
 * @return array{0:bool,1:string}
 */
function classifyDivergence(string $catalogFamily, string $uninfeProvider): array
{
    if ($catalogFamily === 'nfse_nacional') {
        return [true, 'municipio_migrado_para_fluxo_nacional'];
    }

    if ($uninfeProvider === 'DSF' && in_array($catalogFamily, ['ABRASF_SHARED', 'BELEM_MUNICIPAL_2025'], true)) {
        return [true, 'alias_dsf_migrado_para_abrasf'];
    }

    return [false, 'divergencia_nao_classificada'];
}

/**
 * @param array<string, mixed> $report
 */
function renderMarkdownReport(array $report): string
{
    $summary = (array) ($report['summary'] ?? []);
    $missing = is_array($report['missing'] ?? null) ? $report['missing'] : [];
    $unexpected = is_array($report['divergences_unexpected'] ?? null) ? $report['divergences_unexpected'] : [];
    $expectedSample = is_array($report['divergences_expected_sample'] ?? null) ? $report['divergences_expected_sample'] : [];

    $lines = [];
    $lines[] = '# Reconciliacao Uninfe x Catalogo NFSe';
    $lines[] = '';
    $lines[] = '## Resumo';
    $lines[] = '';
    $lines[] = '- Gerado em: `' . (string) ($report['generated_at'] ?? '') . '`';
    $lines[] = '- Registros Uninfe (IBGE unico): `' . (string) ($summary['uninfe_rows'] ?? 0) . '`';
    $lines[] = '- Municipios ativos no catalogo: `' . (string) ($summary['catalog_active'] ?? 0) . '`';
    $lines[] = '- Match exato provider: `' . (string) ($summary['matched'] ?? 0) . '`';
    $lines[] = '- Ausentes no catalogo: `' . (string) ($summary['missing_in_catalog'] ?? 0) . '`';
    $lines[] = '- Divergencias totais: `' . (string) ($summary['divergences_total'] ?? 0) . '`';
    $lines[] = '- Divergencias esperadas: `' . (string) ($summary['divergences_expected'] ?? 0) . '`';
    $lines[] = '- Divergencias inesperadas: `' . (string) ($summary['divergences_unexpected'] ?? 0) . '`';
    $lines[] = '';

    $lines[] = '## Ausentes no Catalogo';
    $lines[] = '';
    if ($missing === []) {
        $lines[] = '- Nenhum municipio ausente.';
    } else {
        foreach ($missing as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = sprintf(
                '- `%s` %s/%s (`%s`)',
                (string) ($item['ibge'] ?? ''),
                (string) ($item['municipio'] ?? ''),
                (string) ($item['uf'] ?? ''),
                (string) ($item['provider'] ?? '')
            );
        }
    }
    $lines[] = '';

    $lines[] = '## Divergencias Inesperadas';
    $lines[] = '';
    if ($unexpected === []) {
        $lines[] = '- Nenhuma divergencia inesperada.';
    } else {
        foreach ($unexpected as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = sprintf(
                '- `%s` %s/%s: catalogo `%s`, uninfe `%s`',
                (string) ($item['ibge'] ?? ''),
                (string) ($item['nome'] ?? ''),
                (string) ($item['uf'] ?? ''),
                (string) ($item['catalog_provider'] ?? ''),
                (string) ($item['uninfe_provider'] ?? '')
            );
        }
    }
    $lines[] = '';

    $lines[] = '## Divergencias Esperadas (amostra)';
    $lines[] = '';
    if ($expectedSample === []) {
        $lines[] = '- Sem divergencias esperadas na amostra.';
    } else {
        foreach ($expectedSample as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = sprintf(
                '- `%s` %s/%s: catalogo `%s`, uninfe `%s` (`%s`)',
                (string) ($item['ibge'] ?? ''),
                (string) ($item['nome'] ?? ''),
                (string) ($item['uf'] ?? ''),
                (string) ($item['catalog_provider'] ?? ''),
                (string) ($item['uninfe_provider'] ?? ''),
                (string) ($item['reason'] ?? '')
            );
        }
    }
    $lines[] = '';

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
