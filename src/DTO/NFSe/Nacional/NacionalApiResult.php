<?php

namespace sabbajohn\FiscalCore\DTO\NFSe\Nacional;

final class NacionalApiResult
{
    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $warnings
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly array $warnings = [],
        public readonly array $metadata = [],
    ) {}

    public function found(): bool
    {
        return $this->status === 'encontrado';
    }

    public function unavailable(): bool
    {
        return $this->status === 'indisponivel';
    }

    /** @return array{status:string,data:array<string,mixed>,warnings:list<string>,metadata:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'data' => $this->data,
            'warnings' => $this->warnings,
            'metadata' => $this->metadata,
        ];
    }
}
