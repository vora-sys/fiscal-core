<?php

namespace sabbajohn\FiscalCore\Support;

use Composer\InstalledVersions;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;

final class NFeCompatibility
{
    public const MONOPHASE_CONTRACT_LEGACY = 'legacy';

    public const MONOPHASE_CONTRACT_SPLIT = 'split';

    public const MONOPHASE_CONTRACT_UNSUPPORTED = 'unsupported';

    public const DEFAULT_XML_VERSION = '4.00';

    public const DEFAULT_SCHEMA = 'PL_009_V4';

    /** @var array<string,string> */
    private const SCHEMA_ALIASES = [
        'PL009' => 'PL_009',
        'PL009V4' => self::DEFAULT_SCHEMA,
        'PL010' => 'PL_010',
        'PL010V1' => 'PL_010_V1',
        'PL010V121' => 'PL_010_V1.21',
        'PL010V130' => 'PL_010_V1.30',
        'NT2025002' => 'PL_010',
        'NT2025002V130' => 'PL_010_V1.30',
        'NT2025002V150' => 'PL_010',
        'REFORMATRIBUTARIA' => 'PL_010',
        'RTC' => 'PL_010',
        'IBSCBS' => 'PL_010',
    ];

    public static function xmlVersion(?string $version): string
    {
        $version = trim((string) ($version ?: self::DEFAULT_XML_VERSION));

        if (! preg_match('/^\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException("Versao de layout NFe/NFCe invalida: {$version}");
        }

        return $version;
    }

    public static function xmlVersionForModel(int $model, ?string $nfeVersion, ?string $nfceVersion): string
    {
        return self::xmlVersion($model === 65 ? ($nfceVersion ?: $nfeVersion) : $nfeVersion);
    }

    public static function schema(?string $schema): string
    {
        $schema = trim((string) ($schema ?: self::DEFAULT_SCHEMA));
        $resolved = self::resolveAlias($schema);

        if (preg_match('/^PL_\d{3}$/', $resolved) === 1) {
            $latest = self::latestInstalledSchema($resolved);
            if ($latest !== null) {
                return $latest;
            }
        }

        if (self::schemaExists($resolved)) {
            return $resolved;
        }

        throw new \InvalidArgumentException(
            "Schema NFe/NFCe nao encontrado no nfephp-org/sped-nfe: {$schema}"
        );
    }

    public static function createMake(?string $schema = null): Make
    {
        return new Make(self::schema($schema));
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public static function normalizeToolsConfig(array $config): array
    {
        $config['versao'] = self::xmlVersion((string) ($config['versao'] ?? self::DEFAULT_XML_VERSION));
        $config['schemes'] = self::schema((string) ($config['schemes'] ?? $config['schemas'] ?? self::DEFAULT_SCHEMA));

        unset($config['schemas']);

        return $config;
    }

    /**
     * @return list<string>
     */
    public static function installedSchemas(): array
    {
        $basePath = self::schemaBasePath();
        if ($basePath === null || ! is_dir($basePath)) {
            return [];
        }

        $schemas = [];
        foreach (scandir($basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($basePath.DIRECTORY_SEPARATOR.$entry)) {
                $schemas[] = $entry;
            }
        }

        natsort($schemas);

        return array_values($schemas);
    }

    /**
     * @return array<string,mixed>
     */
    public static function runtimeCapabilities(): array
    {
        $schemas = self::installedSchemas();
        $monophaseContract = self::monophaseContract();

        return [
            'nfephp_sped_nfe_version' => self::packageVersion('nfephp-org/sped-nfe'),
            'nfephp_sped_da_version' => self::packageVersion('nfephp-org/sped-da'),
            'default_xml_version' => self::DEFAULT_XML_VERSION,
            'default_schema' => self::DEFAULT_SCHEMA,
            'installed_schemas' => $schemas,
            'supports_pl_010' => self::latestInstalledSchema('PL_010') !== null,
            'latest_pl_010_schema' => self::latestInstalledSchema('PL_010'),
            'supports_ibscbs_tags' => method_exists(Make::class, 'tagIBSCBS')
                && method_exists(Make::class, 'tagIBSCBSTot'),
            'monophase_contract' => $monophaseContract,
            'supports_split_monophase' => $monophaseContract === self::MONOPHASE_CONTRACT_SPLIT,
            'supports_is_tags' => method_exists(Make::class, 'tagIS')
                && method_exists(Make::class, 'tagISTot'),
            'supports_advanced_ibscbs_tags' => method_exists(Make::class, 'taggTransfCred')
                && method_exists(Make::class, 'taggCredPresIBSZFM')
                && method_exists(Make::class, 'taggAjusteCompet')
                && method_exists(Make::class, 'taggEstornoCred')
                && method_exists(Make::class, 'tagDFeReferenciado'),
            'supports_nfce_qrcode_v300' => method_exists(Tools::class, 'forceQRCodeVersion'),
            'supports_danfe_simplificado_tipo_2' => class_exists(
                'NFePHP\\DA\\NFe\\DanfeSimplificadoTipo2'
            ),
            'danfe_simplificado_tipo_2_renderer' => class_exists(
                'NFePHP\\DA\\NFe\\DanfeSimplificadoTipo2'
            ) ? 'NFePHP\\DA\\NFe\\DanfeSimplificadoTipo2' : null,
        ];
    }

    public static function monophaseContract(?Make $make = null): string
    {
        $target = $make ?? Make::class;
        if (! method_exists($target, 'tagIBSCBSMono')) {
            return self::MONOPHASE_CONTRACT_UNSUPPORTED;
        }

        $method = new \ReflectionMethod($target, 'tagIBSCBSMono');
        $file = $method->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            throw new \RuntimeException('Nao foi possivel identificar o contrato monofasico do NFePHP instalado.');
        }

        $lines = file($file);
        if ($lines === false) {
            throw new \RuntimeException('Nao foi possivel ler o contrato monofasico do NFePHP instalado.');
        }

        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        return str_contains($source, 'gIBSMonoAdRem_qBCMono')
            ? self::MONOPHASE_CONTRACT_SPLIT
            : self::MONOPHASE_CONTRACT_LEGACY;
    }

    private static function resolveAlias(string $schema): string
    {
        $key = preg_replace('/[^A-Za-z0-9]/', '', $schema);
        $key = strtoupper((string) $key);

        return self::SCHEMA_ALIASES[$key] ?? $schema;
    }

    private static function schemaExists(string $schema): bool
    {
        $basePath = self::schemaBasePath();

        return $basePath !== null && is_dir($basePath.DIRECTORY_SEPARATOR.$schema);
    }

    private static function latestInstalledSchema(string $prefix): ?string
    {
        $matches = array_values(array_filter(
            self::installedSchemas(),
            static fn (string $schema): bool => str_starts_with($schema, $prefix)
        ));

        if ($matches === []) {
            return null;
        }

        natsort($matches);

        return array_values($matches)[count($matches) - 1];
    }

    private static function schemaBasePath(): ?string
    {
        try {
            $reflection = new \ReflectionClass(Make::class);
            $fileName = $reflection->getFileName();
            if (! is_string($fileName)) {
                return null;
            }

            return dirname($fileName, 2).DIRECTORY_SEPARATOR.'schemes';
        } catch (\Throwable) {
            return null;
        }
    }

    private static function packageVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::getPrettyVersion($package) ?: InstalledVersions::getVersion($package);
        } catch (\Throwable) {
            return null;
        }
    }
}
