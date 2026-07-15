<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

use DateTimeImmutable;

final class ResolveServiceClassificationInput
{
    /** @param array<string, mixed> $serviceAttributes */
    public function __construct(
        public readonly string $municipalityCode,
        public readonly DateTimeImmutable $competence,
        public readonly ?string $lc116Code = null,
        public readonly ?string $nationalTaxCode = null,
        public readonly ?string $municipalTaxCode = null,
        public readonly ?string $originalMunicipalCode = null,
        public readonly ?string $nbsCode = null,
        public readonly ?string $serviceDescription = null,
        public readonly ?string $cnae = null,
        public readonly ?string $taxRegime = null,
        public readonly ?string $specialTaxRegime = null,
        public readonly ?string $providerMunicipalityCode = null,
        public readonly ?string $customerMunicipalityCode = null,
        public readonly ?string $executionMunicipalityCode = null,
        public readonly ?string $propertyMunicipalityCode = null,
        public readonly ?bool $issWithheld = null,
        public readonly ?float $issRate = null,
        public readonly array $serviceAttributes = [],
    ) {}
}
