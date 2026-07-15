<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

use stdClass;

/**
 * DTO para dados de cobrança da NFe
 * Corresponde à tag <cobr> do XML
 * Não utilizado em NFCe (apenas NFe)
 */
class CobrancaDTO
{
    public function __construct(
        // Dados da fatura
        public ?string $numeroFatura = null,
        public ?float $valorOriginal = null,
        public ?float $valorDesconto = null,
        public ?float $valorLiquido = null,

        // Duplicatas
        public array $duplicatas = []       // [['nDup' => '001', 'dVenc' => '2024-12-31', 'vDup' => 100.00]]
    ) {}

    /**
     * Cria cobrança à vista (sem duplicatas)
     */
    public static function aVista(
        string $numeroFatura,
        float $valorTotal
    ): self {
        return new self(
            numeroFatura: $numeroFatura,
            valorOriginal: $valorTotal,
            valorLiquido: $valorTotal,
            duplicatas: []
        );
    }

    /**
     * Cria cobrança parcelada com duplicatas
     */
    public static function parcelada(
        string $numeroFatura,
        float $valorTotal,
        array $duplicatas
    ): self {
        return new self(
            numeroFatura: $numeroFatura,
            valorOriginal: $valorTotal,
            valorLiquido: $valorTotal,
            duplicatas: $duplicatas
        );
    }

    /**
     * Cria cobrança parcelada dividindo valor igualmente
     */
    public static function parceladaEmNVezes(
        string $numeroFatura,
        float $valorTotal,
        int $numeroParcelas,
        \DateTime $primeiroVencimento
    ): self {
        $valorParcela = $valorTotal / $numeroParcelas;
        $duplicatas = [];

        for ($i = 1; $i <= $numeroParcelas; $i++) {
            $vencimento = clone $primeiroVencimento;
            $vencimento->modify("+{$i} month");

            $duplicatas[] = [
                'nDup' => str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'dVenc' => $vencimento->format('Y-m-d'),
                'vDup' => round($valorParcela, 2),
            ];
        }

        // Ajustar última parcela para bater com o total (evitar centavos de diferença)
        $somaParcelas = array_sum(array_column($duplicatas, 'vDup'));
        $diferenca = $valorTotal - $somaParcelas;

        if (abs($diferenca) > 0.01) {
            $duplicatas[$numeroParcelas - 1]['vDup'] += $diferenca;
        }

        return new self(
            numeroFatura: $numeroFatura,
            valorOriginal: $valorTotal,
            valorLiquido: $valorTotal,
            duplicatas: $duplicatas
        );
    }

    /**
     * Adiciona desconto à fatura
     */
    public function comDesconto(float $valorDesconto): self
    {
        $clone = clone $this;
        $clone->valorDesconto = $valorDesconto;
        $clone->valorLiquido = $this->valorOriginal - $valorDesconto;

        return $clone;
    }

    /**
     * Valida se a soma das duplicatas bate com o valor da fatura
     */
    public function validate(): array
    {
        $errors = [];

        if (! empty($this->duplicatas)) {
            $somaParc = array_sum(array_column($this->duplicatas, 'vDup'));
            $valorEsperado = $this->valorLiquido ?? $this->valorOriginal;

            // Tolerância maior para XMLs importados (pode haver arredondamentos)
            if (abs($somaParc - $valorEsperado) > 0.10) {
                $errors[] = sprintf(
                    'Soma das duplicatas (%.2f) difere do valor da fatura (%.2f)',
                    $somaParc,
                    $valorEsperado
                );
            }
        }

        return $errors;
    }

    public function toStdClass(): stdClass
    {
        $obj = new stdClass;
        if ($this->numeroFatura !== null) {
            $obj->numeroFatura = $this->numeroFatura;
        }
        if ($this->valorOriginal !== null) {
            $obj->valorOriginal = $this->valorOriginal;
        }
        if ($this->valorDesconto !== null) {
            $obj->valorDesconto = $this->valorDesconto;
        }
        if ($this->valorLiquido !== null) {
            $obj->valorLiquido = $this->valorLiquido;
        }
        $obj->duplicatas = $this->duplicatas;

        return $obj;
    }
}
