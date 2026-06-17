<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NFSeProviderResolver
{
    public const NATIONAL_KEY = 'nfse_nacional';

    public function __construct(
        private ?NFSeMunicipalCatalog $catalog = null,
        private ?NFSeMunicipalProviderOverrides $providerOverrides = null
    ) {
        $this->catalog ??= new NFSeMunicipalCatalog();
        $this->providerOverrides ??= new NFSeMunicipalProviderOverrides();
    }

    public function resolveKey(?string $input): string
    {
        return (string) ($this->buildMetadata($input)['provider_key'] ?? self::NATIONAL_KEY);
    }

    public function buildMetadata(?string $input): array
    {
        if ($this->isBlank($input)) {
            return [
                'provider_key' => self::NATIONAL_KEY,
                'municipio_input' => $input,
                'municipio_ignored' => false,
                'municipio_resolved' => null,
                'municipio_provider_override' => null,
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
                'municipio_provider_override' => null,
                'routing_mode' => $providerFamilyKey === self::NATIONAL_KEY ? 'nacional' : 'provider_key',
                'warnings' => [],
            ];
        }

        $resolved = $this->catalog->resolveMunicipio($input);

        if ($resolved !== null) {
            $metadata = [
                'provider_key' => $resolved['provider_family_key'],
                'municipio_input' => $input,
                'municipio_ignored' => false,
                'municipio_resolved' => $resolved,
                'municipio_provider_override' => null,
                'routing_mode' => 'municipal',
                'warnings' => [],
            ];

            $override = $this->providerOverrides->resolveForMunicipio($resolved);
            if (!is_array($override)) {
                return $metadata;
            }

            $overrideProviderKeyRaw = (string) ($override['provider_key'] ?? '');
            $overrideProviderKey = $this->catalog->resolveProviderFamilyKey($overrideProviderKeyRaw);
            if ($overrideProviderKey === null) {
                $metadata['warnings'][] = sprintf(
                    "Override de provider ignorado para município '%s': provider '%s' não encontrado.",
                    (string) ($resolved['slug'] ?? $input),
                    $overrideProviderKeyRaw
                );
                $metadata['municipio_provider_override'] = [
                    'source_key' => (string) ($override['source_key'] ?? ''),
                    'provider_key' => $overrideProviderKeyRaw,
                    'reason' => (string) ($override['reason'] ?? ''),
                    'ticket' => (string) ($override['ticket'] ?? ''),
                    'updated_at' => (string) ($override['updated_at'] ?? ''),
                    'applied' => false,
                ];

                return $metadata;
            }

            $metadata['provider_key'] = $overrideProviderKey;
            $metadata['routing_mode'] = 'municipal_override';
            $metadata['municipio_provider_override'] = [
                'source_key' => (string) ($override['source_key'] ?? ''),
                'provider_key' => $overrideProviderKey,
                'reason' => (string) ($override['reason'] ?? ''),
                'ticket' => (string) ($override['ticket'] ?? ''),
                'updated_at' => (string) ($override['updated_at'] ?? ''),
                'applied' => true,
            ];

            $message = sprintf(
                "Override operacional ativo para município '%s': %s -> %s.",
                (string) ($resolved['slug'] ?? $input),
                (string) ($resolved['provider_family_key'] ?? self::NATIONAL_KEY),
                $overrideProviderKey
            );
            $reason = trim((string) ($override['reason'] ?? ''));
            $ticket = trim((string) ($override['ticket'] ?? ''));
            if ($reason !== '') {
                $message .= " Motivo: {$reason}.";
            }
            if ($ticket !== '') {
                $message .= " Ticket: {$ticket}.";
            }
            $metadata['warnings'][] = $message;

            return $metadata;
        }

        return [
            'provider_key' => self::NATIONAL_KEY,
            'municipio_input' => $input,
            'municipio_ignored' => true,
            'municipio_resolved' => null,
            'municipio_provider_override' => null,
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
