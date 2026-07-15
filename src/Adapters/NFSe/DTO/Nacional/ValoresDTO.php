<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class ValoresDTO
{
    /**
     * @param  array<string,mixed>  $data
     */
    private function __construct(
        private array $data,
        private float $valorServicos,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $valores = DpsPayloadHelper::firstArray([$payload['valores'] ?? null]);
        $servico = DpsPayloadHelper::firstArray([$payload['servico'] ?? null]);
        $valorServicos = DpsPayloadHelper::firstDecimal([
            $payload['valor_servicos'] ?? null,
            $valores['vServ'] ?? null,
            $valores['valor_servicos'] ?? null,
        ]) ?? 0.0;

        $data = $valores;
        $data['vServ'] = $valorServicos;

        $vReceb = DpsPayloadHelper::firstDecimal([
            $valores['vReceb'] ?? null,
            $valores['valor_recebido'] ?? null,
        ]);
        if ($vReceb !== null) {
            $data['vReceb'] = $vReceb;
        }

        $vDescIncond = DpsPayloadHelper::firstDecimal([
            $valores['vDescIncond'] ?? null,
            $valores['desconto_incondicionado'] ?? null,
            $servico['desconto_incondicionado'] ?? null,
            $servico['vDescIncond'] ?? null,
        ]);
        $vDescCond = DpsPayloadHelper::firstDecimal([
            $valores['vDescCond'] ?? null,
            $valores['desconto_condicionado'] ?? null,
            $servico['desconto_condicionado'] ?? null,
            $servico['vDescCond'] ?? null,
        ]);
        if ($vDescIncond !== null) {
            $data['vDescIncond'] = $vDescIncond;
        }
        if ($vDescCond !== null) {
            $data['vDescCond'] = $vDescCond;
        }

        $deducao = DpsPayloadHelper::firstArray([
            $valores['deducao_reducao'] ?? null,
            $valores['vDedRed'] ?? null,
        ]);
        $pDr = DpsPayloadHelper::firstDecimal([$deducao['pDR'] ?? null, $deducao['percentual'] ?? null, $servico['pDR'] ?? null]);
        $vDr = DpsPayloadHelper::firstDecimal([
            $deducao['vDR'] ?? null,
            $deducao['valor'] ?? null,
            $servico['vDR'] ?? null,
            $servico['valor_deducoes'] ?? null,
        ]);
        if ($pDr !== null || $vDr !== null) {
            $data['deducao_reducao'] = $pDr !== null ? ['pDR' => $pDr] : ['vDR' => $vDr];
            $data['vDedRed'] = $data['deducao_reducao'];
        }

        return new self($data, $valorServicos);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        if ($this->valorServicos <= 0) {
            return ['valor_servicos deve ser maior que zero.'];
        }

        return [];
    }

    public function valorServicos(): float
    {
        return $this->valorServicos;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
