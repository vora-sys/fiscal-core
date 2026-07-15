<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use JsonException;
use RuntimeException;

final class NFSeScaffoldGenerator
{
    public function __construct(
        private readonly string $projectRoot = __DIR__.'/../../'
    ) {}

    public function scaffoldFamily(array $input): array
    {
        $familyKey = strtoupper(trim((string) ($input['family'] ?? '')));
        if ($familyKey === '') {
            throw new RuntimeException('O parâmetro family é obrigatório para scaffold-family.');
        }

        $providerShort = trim((string) ($input['provider_class'] ?? ''));
        if ($providerShort === '') {
            $providerShort = $this->studly($familyKey).'Provider';
        }

        if (! str_ends_with($providerShort, 'Provider')) {
            $providerShort .= 'Provider';
        }

        $layoutFamily = trim((string) ($input['layout_family'] ?? $familyKey));
        $schemaPackage = trim((string) ($input['schema_package'] ?? $familyKey));
        $transport = strtolower(trim((string) ($input['transport'] ?? 'soap')));
        $outputDir = rtrim((string) ($input['output_dir'] ?? ($this->projectRoot.'/build/nfse-scaffold/families/'.$familyKey)), '/');

        $context = [
            'family_key' => $familyKey,
            'provider_short_class' => $providerShort,
            'provider_fqcn' => 'sabbajohn\\FiscalCore\\Providers\\NFSe\\Municipal\\'.$providerShort,
            'layout_family' => $layoutFamily,
            'schema_package' => $schemaPackage,
            'transport' => $transport,
            'provider_slug' => strtolower(str_replace('_', '-', $familyKey)),
            'provider_doc_file' => $familyKey.'.md',
        ];

        $files = [
            [
                'path' => $outputDir.'/src/Providers/NFSe/Municipal/'.$providerShort.'.php',
                'content' => $this->renderTemplate('provider-class.stub.php', $context),
            ],
            [
                'path' => $outputDir.'/tests/Unit/NFSe/'.$providerShort.'Test.php',
                'content' => $this->renderTemplate('provider-test.stub.php', $context),
            ],
            [
                'path' => $outputDir.'/examples/homologacao/'.strtolower($familyKey).'-stub.php',
                'content' => $this->renderTemplate('homologacao-example.stub.php', $context),
            ],
            [
                'path' => $outputDir.'/docs/nfse-providers/'.$familyKey.'.md',
                'content' => $this->renderTemplate('family-doc.stub.md', $context),
            ],
            [
                'path' => $outputDir.'/snippets/nfse-provider-family.json',
                'content' => $this->prettyJson([
                    $familyKey => [
                        'provider_class' => $context['provider_fqcn'],
                        'layout_family' => $layoutFamily,
                        'schema_root' => 'resources/nfse/schemas/'.$schemaPackage,
                        'xsd_entrypoints' => [
                            'emitir' => 'definir-entrypoint.xsd',
                        ],
                        'transport' => $transport,
                        'versao' => 'definir',
                        'timeout' => 30,
                        'signature_mode' => 'optional',
                        'supported_operations' => ['emitir'],
                    ],
                ]),
            ],
            [
                'path' => $outputDir.'/requirements-checklist.md',
                'content' => $this->renderTemplate('requirements-checklist.stub.md', $context),
            ],
        ];

        return $this->finalizeResult('family', $context, $files, (bool) ($input['dry_run'] ?? false));
    }

    public function scaffoldMunicipio(array $input): array
    {
        $ibge = trim((string) ($input['ibge'] ?? ''));
        if ($ibge === '') {
            throw new RuntimeException('O parâmetro ibge é obrigatório para scaffold-municipio.');
        }

        $catalog = new NFSeMunicipalCatalog($this->projectRoot.'/config/nfse/providers-catalog.json');
        $manifest = $this->loadJson($this->projectRoot.'/config/nfse/nfse-catalog-manifest.json');
        $resolved = $catalog->getByIbge($ibge);
        $manifestOverride = $manifest['municipio_overrides'][$ibge] ?? [];

        $slug = trim((string) ($input['slug'] ?? $resolved['slug'] ?? $manifestOverride['slug'] ?? ''));
        $nome = trim((string) ($input['nome'] ?? $resolved['nome'] ?? $manifestOverride['nome'] ?? ''));
        $uf = strtoupper(trim((string) ($input['uf'] ?? $resolved['uf'] ?? $manifestOverride['uf'] ?? '')));
        $family = trim((string) ($input['family'] ?? $resolved['provider_family_key'] ?? $manifestOverride['provider_family'] ?? ''));
        $schemaPackage = trim((string) ($input['schema_package'] ?? $resolved['schema_package'] ?? $manifestOverride['schema_package'] ?? $family));
        $source = trim((string) ($input['source'] ?? ($manifestOverride !== [] ? 'custom_override' : ($resolved !== null ? 'catalog' : 'uninfe_lookup_pending'))));

        if ($slug === '' || $nome === '' || $uf === '' || $family === '') {
            throw new RuntimeException('slug, nome, uf e family são obrigatórios quando o município não existe no catálogo atual.');
        }

        $outputDir = rtrim((string) ($input['output_dir'] ?? ($this->projectRoot.'/build/nfse-scaffold/municipios/'.$slug)), '/');

        $context = [
            'ibge' => $ibge,
            'slug' => $slug,
            'nome' => $nome,
            'uf' => $uf,
            'family_key' => $family,
            'schema_package' => $schemaPackage,
            'source' => $source,
            'provider_note' => (string) ($manifestOverride['provider_note'] ?? $resolved['provider_note'] ?? ''),
            'payload_defaults' => is_array($manifestOverride['payload_defaults'] ?? null)
                ? $manifestOverride['payload_defaults']
                : (is_array($resolved['payload_defaults'] ?? null) ? $resolved['payload_defaults'] : []),
            'provider_config_overrides' => is_array($manifestOverride['provider_config_overrides'] ?? null)
                ? $manifestOverride['provider_config_overrides']
                : (is_array($resolved['provider_config_overrides'] ?? null) ? $resolved['provider_config_overrides'] : []),
        ];

        $files = [
            [
                'path' => $outputDir.'/snippets/providers-catalog-entry.json',
                'content' => $this->prettyJson([
                    $ibge => [
                        'slug' => $slug,
                        'nome' => $nome,
                        'uf' => $uf,
                        'provider_family' => $family,
                        'schema_package' => $schemaPackage,
                        'ibge' => $ibge,
                        'homologado' => false,
                        'active' => true,
                        'provider_note' => $context['provider_note'],
                        'provider_config_overrides' => $context['provider_config_overrides'],
                        'payload_defaults' => $context['payload_defaults'],
                    ],
                ]),
            ],
            [
                'path' => $outputDir.'/snippets/nfse-catalog-manifest-entry.json',
                'content' => $this->prettyJson([
                    $ibge => [
                        'provider_family' => $family,
                        'schema_package' => $schemaPackage,
                        'slug' => $slug,
                        'nome' => $nome,
                        'uf' => $uf,
                        'provider_note' => $context['provider_note'],
                        'provider_config_overrides' => $context['provider_config_overrides'],
                        'payload_defaults' => $context['payload_defaults'],
                    ],
                ]),
            ],
            [
                'path' => $outputDir.'/requirements-checklist.md',
                'content' => $this->renderTemplate('municipio-checklist.stub.md', $context),
            ],
        ];

        return $this->finalizeResult('municipio', $context, $files, (bool) ($input['dry_run'] ?? false));
    }

    private function finalizeResult(string $mode, array $context, array $files, bool $dryRun): array
    {
        if (! $dryRun) {
            foreach ($files as $file) {
                $dir = dirname($file['path']);
                if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
                    throw new RuntimeException("Falha ao criar diretório de scaffold: {$dir}");
                }

                file_put_contents($file['path'], $file['content']);
            }
        }

        return [
            'mode' => $mode,
            'dry_run' => $dryRun,
            'context' => $context,
            'generated_files' => array_map(
                static fn (array $file): array => [
                    'path' => $file['path'],
                    'content' => $file['content'],
                ],
                $files
            ),
        ];
    }

    private function renderTemplate(string $templateName, array $context): string
    {
        $path = $this->projectRoot.'/scripts/nfse/templates/'.$templateName;
        if (! is_file($path)) {
            throw new RuntimeException("Template NFSe não encontrado: {$path}");
        }

        $template = file_get_contents($path);
        if ($template === false) {
            throw new RuntimeException("Falha ao ler template NFSe: {$path}");
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = $this->prettyJson($value);
            }

            $replacements['{{'.strtoupper($key).'}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    private function loadJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Falha ao ler JSON de scaffold: {$path}");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("JSON inválido em {$path}: {$e->getMessage()}", previous: $e);
        }

        return is_array($data) ? $data : [];
    }

    private function prettyJson(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function studly(string $value): string
    {
        $value = strtolower(str_replace(['-', '_'], ' ', $value));
        $value = ucwords($value);

        return str_replace(' ', '', $value);
    }
}
