<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

final class ResolvedField
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
        public readonly ResolutionConfidence $confidence,
        public readonly string $source,
        public readonly ?string $sourceVersion,
        public readonly string $method,
        public readonly bool $automatic,
        public readonly array $evidence = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'confidence' => $this->confidence->value,
            'source' => $this->source,
            'source_version' => $this->sourceVersion,
            'method' => $this->method,
            'automatic' => $this->automatic,
            'evidence' => $this->evidence,
        ];
    }
}
