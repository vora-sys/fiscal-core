<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use JsonException;
use InvalidArgumentException;
use RuntimeException;

final class NFSeMunicipalCatalog
{
    private array $catalog;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/config/nfse/providers-catalog.json';

        $this->catalog = $this->loadCatalog($path);
    }

    public function has(string $input): bool
    {
        return $this->resolveMunicipio($input) !== null;
    }

    public function resolveMunicipio(string $input): ?array
    {
        $input = $this->normalizeLookupKey($input);

        if ($input === '') {
            return null;
        }

        if (isset($this->catalog['municipios'][$input])) {
            return $this->normalizeMunicipio($input);
        }

        if (isset($this->catalog['aliases'][$input])) {
            return $this->normalizeMunicipio((string) $this->catalog['aliases'][$input]);
        }

        foreach ($this->catalog['municipios'] as $ibge => $municipio) {
            $slug = $this->normalizeLookupKey((string) ($municipio['slug'] ?? ''));
            $nome = $this->normalizeLookupKey((string) ($municipio['nome'] ?? ''));
            $uf = $this->normalizeLookupKey((string) ($municipio['uf'] ?? ''));

            $candidates = array_filter([
                $this->normalizeLookupKey((string) $ibge),
                $slug,
                $nome,
                $nome !== '' && $uf !== '' ? "{$nome}-{$uf}" : '',
                $nome !== '' && $uf !== '' ? "{$nome}/{$uf}" : '',
                $nome !== '' && $uf !== '' ? "{$nome} {$uf}" : '',
            ]);

            if (in_array($input, $candidates, true)) {
                return $this->normalizeMunicipio((string) $ibge);
            }
        }

        return null;
    }

    public function resolveProviderFamilyKey(string $input): ?string
    {
        $normalizedInput = $this->normalizeLookupKey($input);

        if ($normalizedInput === '') {
            return null;
        }

        foreach ($this->families(false) as $familyKey) {
            if ($this->normalizeLookupKey($familyKey) === $normalizedInput) {
                return $familyKey;
            }
        }

        return null;
    }

    public function resolveMunicipioOrFail(string $input): array
    {
        $resolved = $this->resolveMunicipio($input);

        if ($resolved === null) {
            throw new InvalidArgumentException("Município '{$input}' não encontrado no catálogo NFSe municipal.");
        }

        return $resolved;
    }

    public function getByIbge(string $ibge): ?array
    {
        $ibge = trim($ibge);

        if ($ibge === '' || !isset($this->catalog['municipios'][$ibge])) {
            return null;
        }

        return $this->normalizeMunicipio($ibge);
    }

    public function all(bool $onlyActive = false): array
    {
        $items = [];

        foreach (array_keys($this->catalog['municipios']) as $ibge) {
            $municipio = $this->normalizeMunicipio((string) $ibge);

            if ($onlyActive && $municipio['active'] !== true) {
                continue;
            }

            $items[] = $municipio;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => [$a['uf'], $a['nome']] <=> [$b['uf'], $b['nome']]
        );

        return $items;
    }

    public function allActive(): array
    {
        return $this->all(true);
    }

    public function families(bool $onlyActive = true): array
    {
        $families = [];

        foreach ($this->all($onlyActive) as $municipio) {
            $families[$municipio['provider_family_key']] = true;
        }

        $families = array_keys($families);
        sort($families);

        return $families;
    }

    public function raw(): array
    {
        return $this->catalog;
    }

    private function loadCatalog(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Catálogo NFSe municipal não encontrado em: {$path}");
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Falha ao ler catálogo NFSe municipal em: {$path}");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "JSON inválido no catálogo NFSe municipal em: {$path}. Erro: {$e->getMessage()}",
                previous: $e
            );
        }

        if (
            !is_array($data) ||
            !isset($data['municipios']) || !is_array($data['municipios']) ||
            !isset($data['aliases']) || !is_array($data['aliases'])
        ) {
            throw new RuntimeException("Estrutura inválida do catálogo NFSe municipal em: {$path}");
        }

        return $data;
    }

    private function normalizeMunicipio(string $ibge): array
    {
        if (!isset($this->catalog['municipios'][$ibge])) {
            throw new RuntimeException("Município IBGE '{$ibge}' não encontrado no catálogo NFSe municipal.");
        }

        $m = $this->catalog['municipios'][$ibge];

        return [
            'ibge' => $ibge,
            'slug' => (string) ($m['slug'] ?? ''),
            'nome' => (string) ($m['nome'] ?? ''),
            'uf' => strtoupper((string) ($m['uf'] ?? '')),
            'provider_family' => (string) ($m['provider_family'] ?? ''),
            'provider_family_key' => (string) ($m['provider_family'] ?? ''),
            'schema_package' => (string) ($m['schema_package'] ?? ($m['provider_family'] ?? '')),
            'homologado' => (bool) ($m['homologado'] ?? false),
            'active' => (bool) ($m['active'] ?? true),
            'provider_note' => (string) ($m['provider_note'] ?? ''),
            'provider_config_overrides' => is_array($m['provider_config_overrides'] ?? null)
                ? $m['provider_config_overrides']
                : [],
            'payload_defaults' => is_array($m['payload_defaults'] ?? null)
                ? $m['payload_defaults']
                : [],
            'display_name' => sprintf(
                '%s/%s',
                (string) ($m['nome'] ?? ''),
                strtoupper((string) ($m['uf'] ?? ''))
            ),
        ];
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = $this->removeAccents($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }

    private function removeAccents(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $result = transliterator_transliterate('Any-Latin; Latin-ASCII;', $value);

            if (is_string($result) && $result !== '') {
                return $result;
            }
        }

        $result = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if (is_string($result) && $result !== '') {
            return $result;
        }

        return $value;
    }
}
