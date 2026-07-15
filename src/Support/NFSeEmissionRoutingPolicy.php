<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use InvalidArgumentException;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;

final class NFSeEmissionRoutingPolicy
{
    public function __construct(
        private readonly ?ProviderRegistry $registry = null
    ) {}

    /**
     * @return array{0:string,1:NFSeProviderConfigInterface,2:string}
     */
    public function resolve(
        string $providerKey,
        NFSeProviderConfigInterface $configuredProvider,
        array $dados,
        bool $injectedProvider = false
    ): array {
        if ($injectedProvider || $providerKey === ProviderRegistry::NFSE_NATIONAL_KEY) {
            return [$providerKey, $configuredProvider, 'configured_provider'];
        }

        $mei = $this->resolveMeiClassification($dados);
        if ($mei === true) {
            $registry = $this->registry ?? ProviderRegistry::getInstance();

            return [
                ProviderRegistry::NFSE_NATIONAL_KEY,
                $registry->getNfseNacional(),
                'mei_nacional',
            ];
        }

        if ($mei === null && $this->requiresExplicitMeiClassification($configuredProvider)) {
            throw new InvalidArgumentException(
                'Este provider exige identificação explícita do emitente como MEI ou não MEI antes da emissão.'
            );
        }

        return [$providerKey, $configuredProvider, 'configured_provider'];
    }

    public function requiresExplicitMeiClassification(NFSeProviderConfigInterface $provider): bool
    {
        return (bool) ($provider->getConfig()['requires_explicit_mei_classification'] ?? false);
    }

    public function resolveMeiClassification(array $dados): ?bool
    {
        $prestador = $dados['prestador'] ?? null;
        if (! is_array($prestador)) {
            return null;
        }

        foreach (['mei', 'microempreendedor_individual'] as $boolKey) {
            if (array_key_exists($boolKey, $prestador)) {
                return filter_var($prestador[$boolKey], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? null;
            }
        }

        foreach (['regime_tributario', 'regime', 'tipo_empresa', 'enquadramento'] as $stringKey) {
            if (! isset($prestador[$stringKey])) {
                continue;
            }

            $normalized = strtolower(trim((string) $prestador[$stringKey]));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['mei', 'microempreendedor individual'], true)) {
                return true;
            }

            if (in_array($normalized, ['simples nacional', 'lucro presumido', 'lucro real', 'normal', 'nao mei', 'não mei'], true)) {
                return false;
            }
        }

        return null;
    }
}
