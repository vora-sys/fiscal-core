<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\Nacional;

use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical\NfseEmissionDTO;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NfseNacionalPublicPayloadMapper;
use sabbajohn\FiscalCore\Contracts\NfseProviderPayloadMapperInterface;
use sabbajohn\FiscalCore\Support\NfseLayoutCapabilityException;
use sabbajohn\FiscalCore\Support\NfseLayoutProfile;

final class NfseNacionalProviderPayloadMapper implements NfseProviderPayloadMapperInterface
{
    public function supports(NfseLayoutProfile $profile): bool
    {
        return $profile->family === 'NACIONAL';
    }

    public function map(NfseEmissionDTO $emission, NfseLayoutProfile $profile, array $context = []): array
    {
        if (! $this->supports($profile) || ! $profile->enabled) {
            throw $this->capabilityFailure($profile, 'layout_profile', 'O perfil de leiaute Nacional solicitado não está ativo.');
        }

        $emission->assertValid(true);
        $series = (string) ($emission->identification()['serie'] ?? '');
        if (preg_match('/^\d{1,5}$/', $series) !== 1 || (int) $series < 1 || (int) $series > 49999) {
            throw new NfseLayoutCapabilityException(
                'A série da DPS de aplicativo próprio deve estar entre 00001 e 49999.',
                [
                    'stage' => 'semantic_validation',
                    'code' => 'NFSE_DPS_SERIES_RANGE_INVALID',
                    'path' => 'payload.identificacao.serie',
                    'provider' => $profile->providerKey,
                    'layout_version' => $profile->version,
                ],
            );
        }
        foreach ([
            'payload.emitente.cpf_cnpj' => $emission->issuer()->document(),
            'payload.tomador.cpf_cnpj' => $emission->customer()->document(),
        ] as $path => $document) {
            if (preg_match('/[A-Z]/', $document) === 1 && ! $profile->supportsCapability('cnpj_alfanumerico')) {
                throw new NfseLayoutCapabilityException(
                    'CNPJ alfanumérico exige o leiaute NFS-e Nacional 1.04.',
                    [
                        'stage' => 'provider_capability',
                        'code' => 'NFSE_NACIONAL_LAYOUT_104_REQUIRED',
                        'path' => $path,
                        'provider' => $profile->providerKey,
                        'layout_version' => $profile->version,
                    ],
                );
            }
        }

        $hasSimpleRtc = $emission->service()->hasSimpleNationalActivity()
            || $emission->taxation()->ibsCbs()->hasSimpleNationalRegime();
        if ($hasSimpleRtc && ! $profile->supportsCapability('simples_nacional_rtc')) {
            throw $this->capabilityFailure(
                $profile,
                'payload.tributacao.ibs_cbs.regime_apuracao_simples_nacional',
                'Os campos RTC exclusivos do Simples Nacional exigem o leiaute 1.04 ativo.',
            );
        }
        if ($emission->taxation()->ibsCbs()->hasLayout104Fields() && $profile->version !== '1.04') {
            throw new NfseLayoutCapabilityException(
                'Os grupos informados exigem o leiaute NFS-e Nacional 1.04.',
                [
                    'stage' => 'provider_capability',
                    'code' => 'NFSE_NACIONAL_LAYOUT_104_REQUIRED',
                    'path' => 'payload.tributacao.ibs_cbs',
                    'provider' => $profile->providerKey,
                    'layout_version' => $profile->version,
                ],
            );
        }
        if ($emission->totals()->hasBaseAdjustment() && $profile->version !== '1.04') {
            throw new NfseLayoutCapabilityException(
                'O ajuste da base de cálculo exige o leiaute NFS-e Nacional 1.04.',
                [
                    'stage' => 'provider_capability',
                    'code' => 'NFSE_NACIONAL_LAYOUT_104_REQUIRED',
                    'path' => 'payload.totais.ajuste_base_calculo',
                    'provider' => $profile->providerKey,
                    'layout_version' => $profile->version,
                ],
            );
        }

        $context = array_replace($context, [
            'provider_key' => $profile->providerKey,
            'fiscal_environment' => $profile->environment,
            'nfse_layout_profile' => $profile->toArray(),
        ]);

        return (new NfseNacionalPublicPayloadMapper)->map($emission->toArray(), $context);
    }

    private function capabilityFailure(NfseLayoutProfile $profile, string $path, string $message): NfseLayoutCapabilityException
    {
        return new NfseLayoutCapabilityException($message, [
            'stage' => 'provider_capability',
            'code' => 'NFSE_LAYOUT_CAPABILITY_UNSUPPORTED',
            'path' => $path,
            'provider' => $profile->providerKey,
            'layout_version' => $profile->version,
        ]);
    }
}
