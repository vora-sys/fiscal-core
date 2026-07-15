<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

final class ServiceClassificationResolution
{
    /**
     * @param  array<string, ResolvedField>  $fields
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<string>  $missingInformation
     */
    public function __construct(
        public readonly ResolutionStatus $status,
        public readonly ResolutionConfidence $confidence,
        public readonly ?ServiceClassificationCandidate $selected,
        public readonly array $fields,
        public readonly array $candidates,
        public readonly array $evidence,
        public readonly array $warnings,
        public readonly array $missingInformation,
        public readonly string $resolutionId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'confidence' => $this->confidence->value,
            'resolution_id' => $this->resolutionId,
            'selected' => $this->selected?->toArray(),
            'fields' => array_map(static fn (ResolvedField $field): array => $field->toArray(), $this->fields),
            'candidates' => $this->candidates,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'missing_information' => $this->missingInformation,
        ];
    }
}
