<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

final class NfseTaxationDTO
{
    /**
     * @param  array<string,mixed>  $municipal
     * @param  array<string,mixed>  $federal
     * @param  array<string,mixed>  $total
     */
    private function __construct(
        private readonly array $municipal,
        private readonly array $federal,
        private readonly array $total,
        private readonly NfseIbsCbsDeclarationDTO $ibsCbs,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromPublicPayload(array $payload): self
    {
        return new self(
            is_array($payload['municipal'] ?? null) ? $payload['municipal'] : [],
            is_array($payload['federal'] ?? null) ? $payload['federal'] : [],
            is_array($payload['total'] ?? null) ? $payload['total'] : [],
            NfseIbsCbsDeclarationDTO::fromPublicPayload(
                is_array($payload['adicionais'] ?? null) ? $payload['adicionais'] : [],
                is_array($payload['ibs_cbs'] ?? null) ? $payload['ibs_cbs'] : [],
            ),
        );
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = $this->ibsCbs->validate();
        $retention = $this->municipal['tipo_retencao_iss'] ?? null;
        if ($retention === null || ! in_array((string) $retention, ['1', '2', '3'], true)) {
            $errors[] = 'payload.tributacao.municipal.tipo_retencao_iss deve ser 1, 2 ou 3.';
        }

        return $errors;
    }

    public function ibsCbs(): NfseIbsCbsDeclarationDTO
    {
        return $this->ibsCbs;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'municipal' => $this->municipal,
            'federal' => $this->federal,
            'total' => $this->total,
            'adicionais' => $this->ibsCbs->additional(),
            'ibs_cbs' => $this->ibsCbs->declaration(),
        ], static fn (mixed $value): bool => $value !== []);
    }
}
