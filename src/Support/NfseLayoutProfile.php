<?php

namespace sabbajohn\FiscalCore\Support;

final class NfseLayoutProfile
{
    /**
     * @param  array<string,bool>  $capabilities
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $family,
        public readonly string $version,
        public readonly ?string $xsdPath,
        public readonly string $environment,
        public readonly bool $enabled,
        private readonly array $capabilities,
        public readonly bool $transmissionEnabled = true,
        public readonly ?string $officialArtifactRevision = null,
    ) {}

    public static function nacional101(string $environment = 'homologacao'): self
    {
        return new self(
            'nfse_nacional',
            'NACIONAL',
            '1.01',
            'src/Providers/NFSe/Xsd/1.01/DPS_v1.01.xsd',
            $environment,
            true,
            [
                'declaracao_ibs_cbs' => true,
                'cnpj_alfanumerico' => false,
                'simples_nacional_rtc' => false,
            ],
            true,
            'NT004-ANEXOVI-1.01.03',
        );
    }

    public static function nacional104(
        string $environment = 'homologacao',
        bool $enabled = false,
        bool $transmissionEnabled = false,
        ?string $officialArtifactRevision = 'NT009-ANEXOVI-1.04.00',
    ): self {
        return new self(
            'nfse_nacional',
            'NACIONAL',
            '1.04',
            null,
            $environment,
            $enabled,
            [
                'declaracao_ibs_cbs' => true,
                'cnpj_alfanumerico' => true,
                'simples_nacional_rtc' => true,
                'notas_ajuste_ibs_cbs' => true,
                'ajuste_base_calculo' => true,
                'bens_imoveis_rtc' => true,
                'bens_moveis_rtc' => true,
                'pagamentos_vinculados' => true,
            ],
            $transmissionEnabled,
            $officialArtifactRevision,
        );
    }

    public static function legacy(string $providerKey, string $environment = 'homologacao'): self
    {
        return new self(
            $providerKey,
            'MUNICIPAL_LEGACY',
            'legacy',
            null,
            $environment,
            true,
            [
                'declaracao_ibs_cbs' => false,
                'cnpj_alfanumerico' => false,
                'simples_nacional_rtc' => false,
            ],
        );
    }

    public function supportsCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'family' => $this->family,
            'version' => $this->version,
            'xsd_path' => $this->xsdPath,
            'environment' => $this->environment,
            'enabled' => $this->enabled,
            'transmission_enabled' => $this->transmissionEnabled,
            'official_artifact_revision' => $this->officialArtifactRevision,
            'capabilities' => $this->capabilities,
        ];
    }
}
