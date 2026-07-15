<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

final class NfseTotalsDTO
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string,mixed> $payload */
    public static function fromPublicPayload(array $payload): self
    {
        $deduction = is_array($payload['deducao_reducao'] ?? null) ? $payload['deducao_reducao'] : [];
        $baseAdjustment = is_array($payload['ajuste_base_calculo'] ?? null) ? $payload['ajuste_base_calculo'] : [];

        return new self(array_filter([
            'valor_servicos' => self::decimal($payload['valor_servicos'] ?? null),
            'valor_documento' => self::decimal($payload['valor_documento'] ?? null),
            'valor_recebido' => self::decimal($payload['valor_recebido'] ?? null),
            'desconto_incondicionado' => self::decimal($payload['desconto_incondicionado'] ?? null),
            'desconto_condicionado' => self::decimal($payload['desconto_condicionado'] ?? null),
            'deducao_reducao' => array_filter([
                'percentual' => self::decimal($deduction['percentual'] ?? null),
                'valor' => self::decimal($deduction['valor'] ?? null),
            ], static fn (mixed $value): bool => $value !== null),
            'ajuste_base_calculo' => self::filterArray([
                'percentual_issqn' => self::decimal($baseAdjustment['percentual_issqn'] ?? null),
                'valor_issqn' => self::decimal($baseAdjustment['valor_issqn'] ?? null),
                'documentos' => is_array($baseAdjustment['documentos'] ?? null) ? $baseAdjustment['documentos'] : null,
            ]),
        ], static fn (mixed $value): bool => $value !== null && $value !== []));
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = [];
        $gross = $this->value('valor_servicos');
        $document = $this->value('valor_documento');
        if ($gross === null || $gross <= 0) {
            $errors[] = 'payload.totais.valor_servicos deve ser maior que zero.';
        }
        if ($document === null || $document < 0) {
            $errors[] = 'payload.totais.valor_documento é obrigatório e não pode ser negativo.';
        }

        foreach (['valor_recebido', 'desconto_incondicionado', 'desconto_condicionado'] as $field) {
            if (($value = $this->value($field)) !== null && $value < 0) {
                $errors[] = "payload.totais.{$field} não pode ser negativo.";
            }
        }

        $percentage = $this->nestedValue('deducao_reducao.percentual');
        $deductionValue = $this->nestedValue('deducao_reducao.valor');
        if ($percentage !== null && $deductionValue !== null) {
            $errors[] = 'Informe apenas percentual ou valor em payload.totais.deducao_reducao.';
        }
        if ($percentage !== null && ($percentage < 0 || $percentage > 100)) {
            $errors[] = 'payload.totais.deducao_reducao.percentual deve estar entre 0 e 100.';
        }
        if ($deductionValue !== null && $deductionValue < 0) {
            $errors[] = 'payload.totais.deducao_reducao.valor não pode ser negativo.';
        }

        $baseAdjustment = is_array($this->data['ajuste_base_calculo'] ?? null)
            ? $this->data['ajuste_base_calculo']
            : [];
        if ($baseAdjustment !== []) {
            foreach (['percentual_issqn', 'valor_issqn'] as $field) {
                if (! is_numeric($baseAdjustment[$field] ?? null) || (float) $baseAdjustment[$field] < 0) {
                    $errors[] = "payload.totais.ajuste_base_calculo.{$field} é obrigatório e não pode ser negativo.";
                }
            }
            $documents = $baseAdjustment['documentos'] ?? null;
            if (! is_array($documents) || $documents === [] || count($documents) > 1000) {
                $errors[] = 'payload.totais.ajuste_base_calculo.documentos deve conter entre 1 e 1000 documentos.';
            } else {
                foreach ($documents as $index => $documentPayload) {
                    $documentPayload = is_array($documentPayload) ? $documentPayload : [];
                    foreach (['tipo', 'valor_total_documento', 'valor_ajuste_aplicado'] as $field) {
                        if (! array_key_exists($field, $documentPayload) || $documentPayload[$field] === '') {
                            $errors[] = "payload.totais.ajuste_base_calculo.documentos.{$index}.{$field} é obrigatório.";
                        }
                    }
                    $choices = array_filter([
                        $documentPayload['dfe_nacional'] ?? null,
                        $documentPayload['documento_fiscal_outro'] ?? null,
                        $documentPayload['documento_outro'] ?? null,
                    ], static fn (mixed $value): bool => is_array($value) && $value !== []);
                    if (count($choices) !== 1) {
                        $errors[] = "payload.totais.ajuste_base_calculo.documentos.{$index} deve informar exatamente um documento de referência.";
                    }
                }
            }
        }

        if ($gross !== null && $document !== null) {
            $deduction = $deductionValue ?? ($percentage !== null ? $gross * $percentage / 100 : 0.0);
            $expected = round($gross
                - ($this->value('desconto_incondicionado') ?? 0.0)
                - ($this->value('desconto_condicionado') ?? 0.0)
                - $deduction, 2);
            if ($expected < 0) {
                $errors[] = 'Descontos e deduções não podem superar payload.totais.valor_servicos.';
            } elseif (abs($document - $expected) > 0.01) {
                $errors[] = sprintf('payload.totais.valor_documento deve ser %.2f conforme os valores declarados.', $expected);
            }
        }

        return $errors;
    }

    public function serviceAmount(): float
    {
        return $this->value('valor_servicos') ?? 0.0;
    }

    public function hasBaseAdjustment(): bool
    {
        return ($this->data['ajuste_base_calculo'] ?? []) !== [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private function value(string $key): ?float
    {
        return isset($this->data[$key]) && is_numeric($this->data[$key]) ? (float) $this->data[$key] : null;
    }

    private function nestedValue(string $path): ?float
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function filterArray(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
