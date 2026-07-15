<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use JsonException;
use RuntimeException;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;

class ProviderRegistry
{
    public const NFSE_NATIONAL_KEY = 'nfse_nacional';

    private static ?self $instance = null;

    private array $config = [];

    private array $providers = [];

    private string $catalogRevision = '';

    private NFSeProviderResolver $resolver;

    private NFSeMunicipalCatalog $catalog;

    private function __construct(?NFSeProviderResolver $resolver = null)
    {
        $this->catalog = new NFSeMunicipalCatalog;
        $this->resolver = $resolver ?? new NFSeProviderResolver($this->catalog);
        $this->loadConfig();
    }

    public static function getInstance(?NFSeProviderResolver $resolver = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($resolver);
        } elseif (self::$instance->catalogRevision !== NFSeCatalogRuntime::revision()) {
            self::$instance->reload();
        }

        return self::$instance;
    }

    public function get(string $providerKey): NFSeProviderConfigInterface
    {
        if (isset($this->providers[$providerKey])) {
            return $this->providers[$providerKey];
        }

        $config = $this->getConfig($providerKey);
        $providerClass = $config['provider_class'] ?? null;

        if (! is_string($providerClass) || trim($providerClass) === '') {
            throw new RuntimeException("Provider class não especificado para chave '{$providerKey}'.");
        }

        $providerClass = $this->resolveProviderClass($providerClass);

        if (! class_exists($providerClass)) {
            throw new RuntimeException("Provider class não encontrado: {$providerClass}");
        }

        $provider = new $providerClass($config);

        if (! $provider instanceof NFSeProviderConfigInterface) {
            throw new RuntimeException(
                "Provider '{$providerClass}' deve implementar NFSeProviderConfigInterface."
            );
        }

        $this->providers[$providerKey] = $provider;

        return $provider;
    }

    public function getNfseNacional(): NFSeProviderConfigInterface
    {
        return $this->get(self::NFSE_NATIONAL_KEY);
    }

    public function getByMunicipio(?string $municipio): NFSeProviderConfigInterface
    {
        $metadata = $this->resolver->buildMetadata($municipio);
        $providerKey = $metadata['provider_key'] ?? self::NFSE_NATIONAL_KEY;

        if (! $this->has($providerKey)) {
            return $this->getNfseNacional();
        }

        if (! is_array($metadata['municipio_resolved'] ?? null)) {
            return $this->get($providerKey);
        }

        $config = $this->applyMunicipioConfig($this->getConfig($providerKey), $metadata['municipio_resolved']);
        $providerClass = $config['provider_class'] ?? null;

        if (! is_string($providerClass) || trim($providerClass) === '') {
            throw new RuntimeException("Provider class não especificado para chave '{$providerKey}'.");
        }

        $providerClass = $this->resolveProviderClass($providerClass);

        if (! class_exists($providerClass)) {
            throw new RuntimeException("Provider class não encontrado: {$providerClass}");
        }

        $provider = new $providerClass($config);

        if (! $provider instanceof NFSeProviderConfigInterface) {
            throw new RuntimeException(
                "Provider '{$providerClass}' deve implementar NFSeProviderConfigInterface."
            );
        }

        return $provider;
    }

    public function has(string $providerKey): bool
    {
        return isset($this->config[$providerKey]);
    }

    public function getConfig(string $providerKey): array
    {
        if (! $this->has($providerKey)) {
            throw new RuntimeException("Provider '{$providerKey}' não configurado.");
        }

        return $this->config[$providerKey];
    }

    public function getConfigForMunicipio(?string $municipio): array
    {
        $metadata = $this->resolver->buildMetadata($municipio);
        $providerKey = $metadata['provider_key'] ?? self::NFSE_NATIONAL_KEY;

        if (! $this->has($providerKey)) {
            return $this->getConfig(self::NFSE_NATIONAL_KEY);
        }

        return $this->applyMunicipioConfig(
            $this->getConfig($providerKey),
            is_array($metadata['municipio_resolved'] ?? null) ? $metadata['municipio_resolved'] : null
        );
    }

    public function listProviders(): array
    {
        return array_keys($this->config);
    }

    public function listMunicipios(): array
    {
        $municipios = array_map(
            static fn (array $municipio): string => (string) $municipio['slug'],
            $this->catalog->allActive()
        );

        sort($municipios);

        return $municipios;
    }

    public function register(string $providerKey, array $config): void
    {
        $this->config[$providerKey] = $config;
        unset($this->providers[$providerKey]);
    }

    public function reload(): void
    {
        $this->config = [];
        $this->providers = [];
        $this->catalog = new NFSeMunicipalCatalog;
        $this->resolver = new NFSeProviderResolver($this->catalog);
        $this->loadConfig();
    }

    public function determinarAmbiente(?string $ambiente = null): string
    {
        if ($ambiente !== null) {
            return strtolower($ambiente) === 'producao' ? 'producao' : 'homologacao';
        }

        $env = $_ENV['NFSE_AMBIENTE'] ?? $_ENV['APP_ENV'] ?? 'homologacao';

        return in_array(strtolower((string) $env), ['prod', 'production', 'producao'], true)
            ? 'producao'
            : 'homologacao';
    }

    public function obterRegrasEspecificas(string $providerKey): array
    {
        if (! $this->has($providerKey)) {
            return [];
        }

        $config = $this->getConfig($providerKey);

        return is_array($config['regras_especificas'] ?? null) ? $config['regras_especificas'] : [];
    }

    public function buscarFallback(string $providerKey): ?string
    {
        if (! $this->has($providerKey)) {
            return null;
        }

        $fallback = $this->getConfig($providerKey)['fallback_provider'] ?? null;

        return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    public function obterVersaoSchema(string $providerKey): string
    {
        if (! $this->has($providerKey)) {
            return '1.0';
        }

        $version = $this->getConfig($providerKey)['versao_schema'] ?? '1.0';

        return is_string($version) && trim($version) !== '' ? $version : '1.0';
    }

    private function loadConfig(): void
    {
        $configFile = dirname(__DIR__, 2).'/config/nfse/nfse-provider-families.json';

        if (! is_file($configFile)) {
            throw new RuntimeException("Arquivo de configuração não encontrado: {$configFile}");
        }

        $json = file_get_contents($configFile);

        if ($json === false) {
            throw new RuntimeException("Falha ao ler configuração de providers: {$configFile}");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "JSON inválido em {$configFile}. Erro: {$e->getMessage()}",
                previous: $e
            );
        }

        if (! is_array($data)) {
            throw new RuntimeException("Estrutura inválida em {$configFile}");
        }

        if (! isset($data[self::NFSE_NATIONAL_KEY]) || ! is_array($data[self::NFSE_NATIONAL_KEY])) {
            throw new RuntimeException(
                "Configuração inválida: chave obrigatória '".self::NFSE_NATIONAL_KEY."' ausente."
            );
        }

        $this->config = NFSeCatalogRuntime::resolve('provider_families', $data);
        $this->catalogRevision = NFSeCatalogRuntime::revision();
    }

    private function resolveProviderClass(string $providerName): string
    {
        if (str_contains($providerName, '\\')) {
            return $providerName;
        }

        return "sabbajohn\\FiscalCore\\Providers\\NFSe\\{$providerName}";
    }

    private function applyMunicipioConfig(array $config, ?array $municipio): array
    {
        if ($municipio === null) {
            return $config;
        }

        $config['codigo_municipio'] = (string) ($municipio['ibge'] ?? $config['codigo_municipio'] ?? '');
        $config['municipio_nome'] = (string) ($municipio['nome'] ?? $config['municipio_nome'] ?? '');
        $config['municipio_uf'] = (string) ($municipio['uf'] ?? $config['municipio_uf'] ?? '');
        $config['municipio_slug'] = (string) ($municipio['slug'] ?? $config['municipio_slug'] ?? '');
        $config['schema_package'] = (string) ($municipio['schema_package'] ?? $config['schema_package'] ?? '');

        if (trim((string) ($municipio['provider_note'] ?? '')) !== '') {
            $config['provider_note'] = (string) $municipio['provider_note'];
        }

        if (is_array($municipio['payload_defaults'] ?? null) && $municipio['payload_defaults'] !== []) {
            $config['payload_defaults'] = $this->mergeRecursiveDistinct(
                is_array($config['payload_defaults'] ?? null) ? $config['payload_defaults'] : [],
                $municipio['payload_defaults']
            );
        }

        if (is_array($municipio['provider_config_overrides'] ?? null) && $municipio['provider_config_overrides'] !== []) {
            $config = $this->mergeRecursiveDistinct($config, $municipio['provider_config_overrides']);
        }

        return $config;
    }

    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function __clone(): void {}

    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
