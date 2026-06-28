<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class TributacaoFederalDTO
{
    /**
     * @param array<string,mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $tributacao = DpsPayloadHelper::firstArray([$payload['tributacao'] ?? null]);
        $federal = DpsPayloadHelper::firstArray([$tributacao['federal'] ?? null]);
        $servico = DpsPayloadHelper::firstArray([$payload['servico'] ?? null]);
        $data = $federal;
        foreach (['vRetCP', 'valor_cp', 'vRetIRRF', 'valor_irrf', 'valor_ir', 'vRetCSLL', 'valor_csll'] as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key]) && round((float) $data[$key], 2) <= 0.0) {
                unset($data[$key]);
            }
        }

        $piscofins = self::resolvePisCofins($payload, $federal, $servico);
        if ($piscofins !== []) {
            $data['piscofins'] = $piscofins;
        }

        foreach ([
            'vRetCP' => [
                $federal['vRetCP'] ?? null,
                $federal['valor_cp'] ?? null,
                $servico['vRetCP'] ?? null,
                $servico['valor_cp'] ?? null,
                $payload['vRetCP'] ?? null,
                $payload['valor_cp'] ?? null,
            ],
            'vRetIRRF' => [
                $federal['vRetIRRF'] ?? null,
                $federal['valor_irrf'] ?? null,
                $federal['valor_ir'] ?? null,
                $servico['vRetIRRF'] ?? null,
                $servico['valor_irrf'] ?? null,
                $servico['valor_ir'] ?? null,
                $payload['vRetIRRF'] ?? null,
                $payload['valor_irrf'] ?? null,
                $payload['valor_ir'] ?? null,
            ],
            'vRetCSLL' => [
                $federal['vRetCSLL'] ?? null,
                $federal['valor_csll'] ?? null,
                $servico['vRetCSLL'] ?? null,
                $servico['valor_csll'] ?? null,
                $payload['vRetCSLL'] ?? null,
                $payload['valor_csll'] ?? null,
            ],
        ] as $tag => $values) {
            $value = DpsPayloadHelper::firstPositiveDecimal($values);
            if ($value !== null) {
                $data[$tag] = $value;
            }
        }

        return new self($data);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $piscofins = DpsPayloadHelper::firstArray([$this->data['piscofins'] ?? null]);
        if ($piscofins !== [] && strlen(DpsPayloadHelper::onlyDigits((string) ($piscofins['CST'] ?? ''))) !== 2) {
            $errors[] = 'tributacao.federal.piscofins.CST deve conter 2 dígitos.';
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $federal
     * @param array<string,mixed> $servico
     * @return array<string,mixed>
     */
    private static function resolvePisCofins(array $payload, array $federal, array $servico): array
    {
        $piscofins = DpsPayloadHelper::firstArray([$federal['piscofins'] ?? null]);
        $resolved = [];
        $cst = DpsPayloadHelper::firstString([
            $piscofins['CST'] ?? null,
            $piscofins['cst'] ?? null,
            $federal['CST'] ?? null,
            $federal['cst_pis_cofins'] ?? null,
            $servico['CST'] ?? null,
            $servico['cst_pis_cofins'] ?? null,
            $payload['cst_pis_cofins'] ?? null,
        ]);
        if ($cst !== null && DpsPayloadHelper::onlyDigits($cst) !== '') {
            $resolved['CST'] = str_pad(substr(DpsPayloadHelper::onlyDigits($cst), 0, 2), 2, '0', STR_PAD_LEFT);
        }

        foreach ([
            'vBCPisCofins' => [$piscofins['vBCPisCofins'] ?? null, $piscofins['vBC'] ?? null, $federal['vBCPisCofins'] ?? null, $servico['vBCPisCofins'] ?? null],
            'pAliqPis' => [$piscofins['pAliqPis'] ?? null, $federal['pAliqPis'] ?? null, $servico['pAliqPis'] ?? null],
            'pAliqCofins' => [$piscofins['pAliqCofins'] ?? null, $federal['pAliqCofins'] ?? null, $servico['pAliqCofins'] ?? null],
            'vPis' => [$piscofins['vPis'] ?? null, $federal['vPis'] ?? null, $servico['vPis'] ?? null, $servico['valor_pis'] ?? null, $payload['valor_pis'] ?? null],
            'vCofins' => [$piscofins['vCofins'] ?? null, $federal['vCofins'] ?? null, $servico['vCofins'] ?? null, $servico['valor_cofins'] ?? null, $payload['valor_cofins'] ?? null],
        ] as $tag => $values) {
            $value = DpsPayloadHelper::firstDecimal($values);
            if ($value !== null) {
                $resolved[$tag] = $value;
            }
        }

        $tpRet = DpsPayloadHelper::firstString([
            $piscofins['tpRetPisCofins'] ?? null,
            $federal['tpRetPisCofins'] ?? null,
            $servico['tpRetPisCofins'] ?? null,
        ]);
        if ($tpRet !== null) {
            $resolved['tpRetPisCofins'] = $tpRet;
        }
        if ($resolved !== [] && !isset($resolved['CST'])) {
            $resolved['CST'] = '00';
        }

        return $resolved;
    }
}
