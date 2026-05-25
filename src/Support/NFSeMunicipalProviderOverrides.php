<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use JsonException;
use RuntimeException;

final class NFSeMunicipalProviderOverrides
{
    /** @var array<string, array<string, mixed>> */
    private array $overrides;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/config/nfse/municipio-provider-overrides.json';

        $this->overrides = $this->loadOverrides($path);
    }

    public function resolveForMunicipio(array $municipio): ?array
    {
        $keys = array_values(
            array_unique(
                array_filter([
                    $this->normalizeLookupKey((string) ($municipio['ibge'] ?? '')),
                    $this->normalizeLookupKey((string) ($municipio['slug'] ?? '')),
                    $this->normalizeLookupKey((string) ($municipio['nome'] ?? '')),
                ])
            )
        );

        foreach ($keys as $lookupKey) {
            $entry = $this->overrides[$lookupKey] ?? null;
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['active'] ?? true) !== true) {
                continue;
            }

            $providerKey = trim((string) ($entry['provider_key'] ?? ''));
            if ($providerKey === '') {
                continue;
            }

            return [
                'provider_key' => $providerKey,
                'source_key' => $lookupKey,
                'reason' => trim((string) ($entry['reason'] ?? '')),
                'ticket' => trim((string) ($entry['ticket'] ?? '')),
                'updated_at' => trim((string) ($entry['updated_at'] ?? '')),
            ];
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadOverrides(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Falha ao ler overrides de providers NFSe: {$path}");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "JSON inválido em {$path}. Erro: {$e->getMessage()}",
                previous: $e
            );
        }

        if (!is_array($data)) {
            return [];
        }

        $rawOverrides = $data['overrides'] ?? $data;
        if (!is_array($rawOverrides)) {
            return [];
        }

        $normalized = [];
        foreach ($rawOverrides as $lookupKeyRaw => $rawEntry) {
            $lookupKey = (string) $lookupKeyRaw;
            $normalizedKey = $this->normalizeLookupKey($lookupKey);
            if ($normalizedKey === '') {
                continue;
            }

            $entry = $this->normalizeEntry($rawEntry);
            if ($entry === null) {
                continue;
            }

            $normalized[$normalizedKey] = $entry;
        }

        return $normalized;
    }

    private function normalizeEntry(mixed $rawEntry): ?array
    {
        if (is_string($rawEntry)) {
            $providerKey = trim($rawEntry);
            if ($providerKey === '') {
                return null;
            }

            return [
                'provider_key' => $providerKey,
                'active' => true,
            ];
        }

        if (!is_array($rawEntry)) {
            return null;
        }

        $providerKey = trim((string) (
            $rawEntry['provider_key']
            ?? $rawEntry['provider_family']
            ?? $rawEntry['provider']
            ?? ''
        ));
        if ($providerKey === '') {
            return null;
        }

        $active = $this->toBool($rawEntry['active'] ?? true);

        return [
            'provider_key' => $providerKey,
            'active' => $active,
            'reason' => trim((string) ($rawEntry['reason'] ?? '')),
            'ticket' => trim((string) ($rawEntry['ticket'] ?? '')),
            'updated_at' => trim((string) ($rawEntry['updated_at'] ?? '')),
        ];
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{7}$/', $value) === 1) {
            return $value;
        }

        $value = $this->removeAccents($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';

        return trim($value, '-');
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

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on', 'sim'],
            true
        );
    }
}
