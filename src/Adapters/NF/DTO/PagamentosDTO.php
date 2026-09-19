<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO do grupo <pag>, incluindo formas de pagamento e troco.
 */
class PagamentosDTO
{
    /**
     * @param  list<PagamentoDTO>  $formas
     */
    public function __construct(
        public array $formas,
        public float $valorTotal,
        public ?float $troco = null,
    ) {}

    /**
     * @param  list<PagamentoDTO>  $formas
     */
    public static function fromFormas(array $formas, ?float $troco = null): self
    {
        return new self(
            formas: $formas,
            valorTotal: round(array_sum(array_map(
                static fn (PagamentoDTO $pagamento): float => $pagamento->vPag,
                $formas,
            )), 2),
            troco: $troco,
        );
    }

    /**
     * @return array{
     *     formas: list<array<string, mixed>>,
     *     valorTotal: float,
     *     troco: float|null
     * }
     */
    public function toArray(): array
    {
        return [
            'formas' => array_map(
                static fn (PagamentoDTO $pagamento): array => array_filter(
                    get_object_vars($pagamento),
                    static fn (mixed $value): bool => $value !== null,
                ),
                $this->formas,
            ),
            'valorTotal' => $this->valorTotal,
            'troco' => $this->troco,
        ];
    }
}
