<?php

namespace sabbajohn\FiscalCore\Support;

final class NfseLayoutProfileResolver
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function resolve(string $providerKey, array $context = []): NfseLayoutProfile
    {
        $environment = strtolower(trim((string) ($context['fiscal_environment'] ?? 'homologacao')));
        $environment = $environment === 'producao' ? 'producao' : 'homologacao';
        $normalizedProvider = $this->normalizeProvider($providerKey);
        if ($normalizedProvider !== 'nfse_nacional') {
            return NfseLayoutProfile::legacy($providerKey, $environment);
        }

        $version = trim((string) (
            $context['nfse_layout_version']
            ?? $context['layout_version']
            ?? $this->dataGet((array) ($context['empresa_config'] ?? []), 'nfse.layout_version')
            ?? '1.01'
        ));

        if ($version === '1.04') {
            $companyEnabled = filter_var(
                $context['nfse_layout_104_enabled']
                    ?? $this->dataGet((array) ($context['empresa_config'] ?? []), 'nfse.layout_104_enabled')
                    ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
            $previewEnabled = filter_var($context['nfse_layout_104_preview_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $killSwitch = filter_var($context['nfse_layout_104_kill_switch'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $transmissionEnabled = filter_var(
                $context['nfse_layout_104_transmission_enabled'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );

            return NfseLayoutProfile::nacional104(
                $environment,
                $companyEnabled && $previewEnabled,
                $companyEnabled && $transmissionEnabled && ! $killSwitch,
                is_scalar($context['nfse_layout_104_official_artifact_revision'] ?? null)
                    ? (string) $context['nfse_layout_104_official_artifact_revision']
                    : 'NT009-ANEXOVI-1.04.00',
            );
        }

        return NfseLayoutProfile::nacional101($environment);
    }

    private function normalizeProvider(string $providerKey): string
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($providerKey)));

        return in_array($normalized, ['manaus', 'nacional', 'nfse_nacional'], true)
            ? 'nfse_nacional'
            : $normalized;
    }

    /** @param array<string,mixed> $data */
    private function dataGet(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
