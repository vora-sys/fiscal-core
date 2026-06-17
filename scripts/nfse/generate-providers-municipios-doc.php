<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = dirname(__DIR__, 2);

$options = getopt('', [
    'catalog::',
    'families::',
    'output::',
]);

$catalogPath = trim((string) ($options['catalog'] ?? ($root . '/config/nfse/providers-catalog.json')));
$familiesPath = trim((string) ($options['families'] ?? ($root . '/config/nfse/nfse-provider-families.json')));
$outputPath = trim((string) ($options['output'] ?? ($root . '/docs/NFSE-PROVIDERS-MUNICIPIOS.md')));

if (!is_file($catalogPath)) {
    fwrite(STDERR, "Catalogo NFSe nao encontrado: {$catalogPath}\n");
    exit(1);
}

if (!is_file($familiesPath)) {
    fwrite(STDERR, "Families NFSe nao encontrado: {$familiesPath}\n");
    exit(1);
}

$catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
$families = json_decode((string) file_get_contents($familiesPath), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($catalog) || !is_array($families)) {
    fwrite(STDERR, "JSON invalido em catalog/families.\n");
    exit(1);
}

$municipios = is_array($catalog['municipios'] ?? null) ? $catalog['municipios'] : [];
$byFamily = [];

foreach ($municipios as $ibge => $entry) {
    if (!is_array($entry)) {
        continue;
    }

    if (($entry['active'] ?? true) !== true) {
        continue;
    }

    $family = trim((string) ($entry['provider_family'] ?? ''));
    if ($family === '') {
        continue;
    }

    $uf = strtoupper(trim((string) ($entry['uf'] ?? '')));

    $byFamily[$family][] = [
        'ibge' => (string) $ibge,
        'nome' => trim((string) ($entry['nome'] ?? '')),
        'uf' => $uf,
        'is_technical' => in_array($uf, ['AN', 'XX'], true),
    ];
}

ksort($families);

$lines = [];
$lines[] = '# NFSe - Provedores Implementados e Municipios Atendidos';
$lines[] = '';
$lines[] = 'Base: `config/nfse/nfse-provider-families.json` + `config/nfse/providers-catalog.json`.';
$lines[] = 'Gerado em: `' . gmdate('Y-m-d H:i:s') . ' UTC`.';
$lines[] = '';
$lines[] = 'Legenda: considera somente municipios `active=true`; entradas tecnicas (`UF=AN/XX`) sao separadas na contagem.';
$lines[] = '';
$lines[] = '| Provider family | Provider class | Municipios reais | Entradas tecnicas | Municipios atendidos (reais) |';
$lines[] = '| --- | --- | ---: | ---: | --- |';

foreach ($families as $familyKey => $familyConfig) {
    if (!is_array($familyConfig)) {
        continue;
    }

    $providerClass = trim((string) ($familyConfig['provider_class'] ?? '-'));
    $items = $byFamily[(string) $familyKey] ?? [];

    usort(
        $items,
        static fn (array $a, array $b): int => [$a['uf'], $a['nome'], $a['ibge']] <=> [$b['uf'], $b['nome'], $b['ibge']]
    );

    $real = array_values(array_filter($items, static fn (array $m): bool => !($m['is_technical'] ?? false)));
    $technical = array_values(array_filter($items, static fn (array $m): bool => (bool) ($m['is_technical'] ?? false)));

    $municipiosLabel = '-';
    if ($real !== []) {
        $parts = [];
        foreach ($real as $m) {
            $parts[] = sprintf('%s/%s (`%s`)', (string) $m['nome'], (string) $m['uf'], (string) $m['ibge']);
        }

        $municipiosLabel = implode('<br>', $parts);
    }

    $lines[] = sprintf(
        '| `%s` | `%s` | %d | %d | %s |',
        (string) $familyKey,
        str_replace('|', '\\|', $providerClass),
        count($real),
        count($technical),
        $municipiosLabel
    );
}

$lines[] = '';
$lines[] = '## Observacoes';
$lines[] = '';
$lines[] = '- `DSF` permanece no catalogo de familias por compatibilidade legada, com migracao em andamento para `ABRASF_SHARED`/`BELEM_MUNICIPAL_2025`.';
$lines[] = '- `nfse_nacional` pode concentrar muitos municipios por adesao ao emissor nacional.';
$lines[] = '';
$lines[] = '## Como atualizar';
$lines[] = '';
$lines[] = '- `php scripts/nfse/generate-providers-municipios-doc.php`';
$lines[] = '- `php scripts/nfse/generate-providers-municipios-doc.php --output=docs/NFSE-PROVIDERS-MUNICIPIOS.md`';

file_put_contents($outputPath, implode(PHP_EOL, $lines) . PHP_EOL);

echo "Documento gerado: {$outputPath}\n";
exit(0);
