<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

use DateTimeImmutable;
use InvalidArgumentException;

final class NfseEmissionDTO
{
    /** @param array<string,mixed> $identification @param array<string,mixed> $observations */
    private function __construct(
        private readonly array $identification,
        private readonly NfsePartyDTO $issuer,
        private readonly NfsePartyDTO $customer,
        private readonly NfseServiceDTO $service,
        private readonly NfseTotalsDTO $totals,
        private readonly NfseTaxationDTO $taxation,
        private readonly array $observations,
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromPublicPayload(array $payload): self
    {
        return new self(
            self::normalizeIdentification(is_array($payload['identificacao'] ?? null) ? $payload['identificacao'] : []),
            NfsePartyDTO::fromPublicPayload(is_array($payload['emitente'] ?? null) ? $payload['emitente'] : [], 'payload.emitente'),
            NfsePartyDTO::fromPublicPayload(is_array($payload['tomador'] ?? null) ? $payload['tomador'] : [], 'payload.tomador'),
            NfseServiceDTO::fromPublicPayload(is_array($payload['servico'] ?? null) ? $payload['servico'] : []),
            NfseTotalsDTO::fromPublicPayload(is_array($payload['totais'] ?? null) ? $payload['totais'] : []),
            NfseTaxationDTO::fromPublicPayload(is_array($payload['tributacao'] ?? null) ? $payload['tributacao'] : []),
            is_array($payload['observacoes'] ?? null) ? $payload['observacoes'] : [],
        );
    }

    /** @return list<string> */
    public function validate(?bool $national): array
    {
        $errors = array_merge(
            $this->issuer->validate(),
            $this->customer->validate(),
            $this->service->validate($national),
            $this->totals->validate(),
            $this->taxation->validate(),
        );

        foreach (['numero', 'data_emissao', 'data_competencia', 'municipio_ocorrencia_codigo'] as $field) {
            if (! isset($this->identification[$field]) || $this->identification[$field] === '') {
                $errors[] = "payload.identificacao.{$field} é obrigatório.";
            }
        }
        $municipality = (string) ($this->identification['municipio_ocorrencia_codigo'] ?? '');
        if ($municipality !== '' && strlen($municipality) !== 7) {
            $errors[] = 'payload.identificacao.municipio_ocorrencia_codigo deve conter 7 dígitos.';
        }
        $issueDate = (string) ($this->identification['data_emissao'] ?? '');
        if ($issueDate !== '') {
            try {
                new DateTimeImmutable($issueDate);
            } catch (\Throwable) {
                $errors[] = 'payload.identificacao.data_emissao deve ser uma data/hora ISO 8601 válida.';
            }
        }
        $competence = (string) ($this->identification['data_competencia'] ?? '');
        if ($competence !== '' && ! self::validDate($competence)) {
            $errors[] = 'payload.identificacao.data_competencia deve ser uma data válida no formato AAAA-MM-DD.';
        }
        $environment = (string) ($this->identification['ambiente'] ?? '');
        if ($environment !== '' && ! in_array($environment, ['homologacao', 'producao'], true)) {
            $errors[] = 'payload.identificacao.ambiente deve ser homologacao ou producao.';
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(bool $national): void
    {
        $errors = $this->validate($national);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    public function issuer(): NfsePartyDTO
    {
        return $this->issuer;
    }

    public function customer(): NfsePartyDTO
    {
        return $this->customer;
    }

    /** @return array<string,mixed> */
    public function identification(): array
    {
        return $this->identification;
    }

    /** @return array<string,mixed> */
    public function observations(): array
    {
        return $this->observations;
    }

    public function service(): NfseServiceDTO
    {
        return $this->service;
    }

    public function totals(): NfseTotalsDTO
    {
        return $this->totals;
    }

    public function taxation(): NfseTaxationDTO
    {
        return $this->taxation;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'identificacao' => $this->identification,
            'emitente' => $this->issuer->toArray(),
            'tomador' => $this->customer->toArray(),
            'servico' => $this->service->toArray(),
            'totais' => $this->totals->toArray(),
            'tributacao' => $this->taxation->toArray(),
            'observacoes' => $this->observations,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /** @param array<string,mixed> $identification @return array<string,mixed> */
    private static function normalizeIdentification(array $identification): array
    {
        $municipality = preg_replace('/\D+/', '', (string) ($identification['municipio_ocorrencia_codigo'] ?? '')) ?? '';

        return array_filter([
            'serie' => self::string($identification['serie'] ?? null) ?? '1',
            'numero' => self::string($identification['numero'] ?? null),
            'natureza_operacao' => self::string($identification['natureza_operacao'] ?? null),
            'data_emissao' => self::string($identification['data_emissao'] ?? null),
            'data_competencia' => self::string($identification['data_competencia'] ?? null),
            'ambiente' => self::string($identification['ambiente'] ?? null),
            'municipio_ocorrencia_codigo' => $municipality !== '' ? $municipality : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
