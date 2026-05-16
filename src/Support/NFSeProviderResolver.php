<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NFSeProviderResolver
{
    public const NATIONAL_KEY = 'nfse_nacional';

    public function __construct(
        private ?NFSeMunicipalCatalog $catalog = null
    ) {
        $this->catalog ??= new NFSeMunicipalCatalog();
    }

    public function resolveKey(?string $input): string
    {
        if ($this->isBlank($input)) {
            return self::NATIONAL_KEY;
        }

        $providerFamilyKey = $this->catalog->resolveProviderFamilyKey((string) $input);
        if ($providerFamilyKey !== null) {
            return $providerFamilyKey;
        }

        $resolved = $this->catalog->resolveMunicipio($input);

        return $resolved['provider_family_key'] ?? self::NATIONAL_KEY;
    }

    public function buildMetadata(?string $input): array
    {
        if ($this->isBlank($input)) {
            return [
                'provider_key' => self::NATIONAL_KEY,
                'municipio_input' => $input,
                'municipio_ignored' => false,
                'municipio_resolved' => null,
                'routing_mode' => 'nacional',
                'warnings' => [],
            ];
        }

        $providerFamilyKey = $this->catalog->resolveProviderFamilyKey((string) $input);
        if ($providerFamilyKey !== null) {
            return [
                'provider_key' => $providerFamilyKey,
                'municipio_input' => $input,
                'municipio_ignored' => false,
                'municipio_resolved' => null,
                'routing_mode' => $providerFamilyKey === self::NATIONAL_KEY ? 'nacional' : 'provider_key',
                'warnings' => [],
            ];
        }

        $resolved = $this->catalog->resolveMunicipio($input);

        if ($resolved !== null) {
            return [
                'provider_key' => $resolved['provider_family_key'],
                'municipio_input' => $input,
                'municipio_ignored' => false,
                'municipio_resolved' => $resolved,
                'routing_mode' => 'municipal',
                'warnings' => [],
            ];
        }

        return [
            'provider_key' => self::NATIONAL_KEY,
            'municipio_input' => $input,
            'municipio_ignored' => true,
            'municipio_resolved' => null,
            'routing_mode' => 'nacional_fallback',
            'warnings' => [
                "Município '{$input}' não encontrado no catálogo municipal. Aplicado fallback nacional.",
            ],
        ];
    }

    private function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
