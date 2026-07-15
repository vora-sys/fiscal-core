<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

use DateTimeImmutable;

final class ServiceClassificationCandidate
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly ?string $lc116Code,
        public readonly ?string $nationalTaxCode,
        public readonly ?string $municipalTaxCode,
        public readonly ?string $originalMunicipalCode,
        public readonly ?string $nbsCode,
        public readonly ?string $operationIndicatorCode,
        public readonly ?string $taxClassificationCode,
        public readonly ?string $description,
        public readonly ?float $issRate,
        public readonly ?bool $issWithholding,
        public readonly ?string $issExigibility,
        public readonly string $source,
        public readonly ?string $sourceVersion = null,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validUntil = null,
        public readonly ?string $municipalityCode = null,
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lc116_code' => $this->lc116Code,
            'national_tax_code' => $this->nationalTaxCode,
            'municipal_tax_code' => $this->municipalTaxCode,
            'original_municipal_code' => $this->originalMunicipalCode,
            'nbs_code' => $this->nbsCode,
            'operation_indicator_code' => $this->operationIndicatorCode,
            'tax_classification_code' => $this->taxClassificationCode,
            'description' => $this->description,
            'iss_rate' => $this->issRate,
            'iss_withholding' => $this->issWithholding,
            'iss_exigibility' => $this->issExigibility,
            'source' => $this->source,
            'source_version' => $this->sourceVersion,
            'valid_from' => $this->validFrom?->format('Y-m-d'),
            'valid_until' => $this->validUntil?->format('Y-m-d'),
            'municipality_code' => $this->municipalityCode,
            'metadata' => $this->metadata,
        ];
    }
}
