<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

final class NfseFiscalContextDTO
{
    /** @param array<string,mixed> $companyConfig */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $environment,
        public readonly ?string $municipality,
        public readonly array $companyConfig = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'fiscal_environment' => $this->environment,
            'municipio' => $this->municipality,
            'empresa_config' => $this->companyConfig,
        ];
    }
}
