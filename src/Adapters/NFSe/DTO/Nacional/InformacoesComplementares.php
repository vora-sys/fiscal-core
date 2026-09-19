<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

class InformacoesComplementares
{
    private array $data;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public static function fromArray(array $payload, array $context = []): self
    {
        $data = [];
        foreach ($payload as $key => $infComplementar) {
            if (! empty($infComplementar) && is_scalar($infComplementar)) {
                $normalized = preg_replace('/\h+/u', ' ', (string) $infComplementar) ?? (string) $infComplementar;
                $normalized = preg_replace('/ *(\r\n|\r|\n) */', '$1', $normalized) ?? $normalized;
                $data[$key] = trim($normalized, " \t");
            }
        }

        return new self($data);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
