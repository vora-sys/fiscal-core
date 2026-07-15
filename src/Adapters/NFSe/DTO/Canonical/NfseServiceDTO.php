<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

final class NfseServiceDTO
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data) {}

    /** @param array<string,mixed> $payload */
    public static function fromPublicPayload(array $payload): self
    {
        return new self(array_filter([
            'codigo_servico_nacional' => self::digits($payload['codigo_servico_nacional'] ?? null),
            'codigo_servico_municipal' => self::string($payload['codigo_servico_municipal'] ?? null),
            'codigo_nbs' => self::digits($payload['codigo_nbs'] ?? null),
            'codigo_municipio_prestacao' => self::digits($payload['codigo_municipio_prestacao'] ?? null),
            'codigo_atividade_simples_nacional' => self::string($payload['codigo_atividade_simples_nacional'] ?? null),
            'descricao' => self::string($payload['descricao'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /** @return list<string> */
    public function validate(?bool $national): array
    {
        $errors = [];
        if (trim((string) ($this->data['descricao'] ?? '')) === '') {
            $errors[] = 'payload.servico.descricao é obrigatório.';
        }
        if (strlen((string) ($this->data['codigo_servico_nacional'] ?? '')) !== 6) {
            $errors[] = 'payload.servico.codigo_servico_nacional deve conter 6 dígitos.';
        }
        if (strlen((string) ($this->data['codigo_nbs'] ?? '')) !== 9) {
            $errors[] = 'payload.servico.codigo_nbs deve conter 9 dígitos.';
        }
        if (strlen((string) ($this->data['codigo_municipio_prestacao'] ?? '')) !== 7) {
            $errors[] = 'payload.servico.codigo_municipio_prestacao deve conter 7 dígitos.';
        }
        if ($national === false && trim((string) ($this->data['codigo_servico_municipal'] ?? '')) === '') {
            $errors[] = 'payload.servico.codigo_servico_municipal é obrigatório para o provedor municipal.';
        }

        return $errors;
    }

    public function hasSimpleNationalActivity(): bool
    {
        return isset($this->data['codigo_atividade_simples_nacional']);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';

        return $digits !== '' ? $digits : null;
    }
}
