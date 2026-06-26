<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class TributacaoMunicipalDTO
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
        $municipal = DpsPayloadHelper::firstArray([$tributacao['municipal'] ?? null]);
        $servico = DpsPayloadHelper::firstArray([$payload['servico'] ?? null]);
        $merged = $municipal + $servico;
        $data = $municipal;

        $data['tribISSQN'] = (string) (DpsPayloadHelper::firstString([
            $merged['tribISSQN'] ?? null,
            $merged['tributacao_issqn'] ?? null,
        ]) ?? '1');

        foreach ([
            'cPaisResult' => ['cPaisResult', 'codigo_pais_resultado'],
            'tpImunidade' => ['tpImunidade', 'tipo_imunidade'],
        ] as $target => $keys) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $merged[$key] ?? null;
            }
            $value = DpsPayloadHelper::firstString($values);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        $exigSusp = DpsPayloadHelper::firstArray([$merged['exigSusp'] ?? null]);
        $tpSusp = DpsPayloadHelper::firstString([$exigSusp['tpSusp'] ?? null, $merged['tpSusp'] ?? null]);
        $nProcesso = DpsPayloadHelper::firstString([$exigSusp['nProcesso'] ?? null, $merged['nProcesso'] ?? null]);
        if ($tpSusp !== null && $nProcesso !== null) {
            $data['exigSusp'] = ['tpSusp' => $tpSusp, 'nProcesso' => $nProcesso];
        }

        $bm = DpsPayloadHelper::firstArray([$merged['BM'] ?? null]);
        $nBm = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $bm['nBM'] ?? null,
            $merged['nBM'] ?? null,
            $merged['benefit_code'] ?? null,
            $merged['codigo_beneficio'] ?? null,
            $merged['codigoBeneficio'] ?? null,
        ]) ?? '');
        if (strlen($nBm) === 14) {
            $beneficio = ['nBM' => $nBm];
            $pRed = DpsPayloadHelper::firstDecimal([
                $bm['pRedBCBM'] ?? null,
                $merged['pRedBCBM'] ?? null,
                $merged['iss_reduction_percent'] ?? null,
                $merged['base_reduction_percent'] ?? null,
            ]);
            $vRed = DpsPayloadHelper::firstDecimal([
                $bm['vRedBCBM'] ?? null,
                $merged['vRedBCBM'] ?? null,
                $merged['valor_reducao_bc_bm'] ?? null,
            ]);
            if ($pRed !== null) {
                $beneficio['pRedBCBM'] = $pRed;
            } elseif ($vRed !== null) {
                $beneficio['vRedBCBM'] = $vRed;
            }
            $data['BM'] = $beneficio;
        }

        $data['tpRetISSQN'] = DpsPayloadHelper::normalizeIssRetentionCode($merged);

        $aliquota = DpsPayloadHelper::firstDecimal([
            $municipal['pAliq'] ?? null,
            $municipal['aliquota'] ?? null,
            $servico['pAliq'] ?? null,
            $servico['aliquota'] ?? null,
        ]);
        if ($aliquota !== null) {
            $data['pAliq'] = DpsPayloadHelper::normalizePercent($aliquota);
        }

        foreach (['enviarPAliq'] as $key) {
            if (array_key_exists($key, $municipal)) {
                $data[$key] = $municipal[$key];
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
        $tribIssqn = (string) ($this->data['tribISSQN'] ?? '');
        if (!in_array($tribIssqn, ['1', '2', '3', '4'], true)) {
            $errors[] = 'tributacao.municipal.tribISSQN deve ser 1, 2, 3 ou 4.';
        }

        $tpRet = (string) ($this->data['tpRetISSQN'] ?? '');
        if (!in_array($tpRet, ['1', '2', '3'], true)) {
            $errors[] = 'tributacao.municipal.tpRetISSQN deve ser 1, 2 ou 3.';
        }

        if (isset($this->data['pAliq']) && is_numeric($this->data['pAliq']) && (float) $this->data['pAliq'] > 5) {
            $errors[] = 'tributacao.municipal.pAliq não pode ser superior a 5%.';
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
}
