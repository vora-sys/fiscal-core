<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Canonical;

final class NfsePartyDTO
{
    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data, private readonly string $path) {}

    /** @param array<string,mixed> $payload */
    public static function fromPublicPayload(array $payload, string $path): self
    {
        $document = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) ($payload['cpf_cnpj'] ?? '')));
        $type = strtolower(trim((string) ($payload['tipo_pessoa'] ?? '')));
        $address = is_array($payload['endereco'] ?? null) ? $payload['endereco'] : [];

        return new self(array_filter([
            'cpf_cnpj' => $document,
            'tipo_pessoa' => $type,
            'razao_social' => self::string($payload['razao_social'] ?? null),
            'nome_fantasia' => self::string($payload['nome_fantasia'] ?? null),
            'inscricao_estadual' => self::string($payload['inscricao_estadual'] ?? null),
            'inscricao_municipal' => self::string($payload['inscricao_municipal'] ?? null),
            'crt' => self::string($payload['crt'] ?? null),
            'email' => self::string($payload['email'] ?? null),
            'telefone' => self::digits($payload['telefone'] ?? null),
            'endereco' => array_filter([
                'logradouro' => self::string($address['logradouro'] ?? null),
                'numero' => self::string($address['numero'] ?? null),
                'complemento' => self::string($address['complemento'] ?? null),
                'bairro' => self::string($address['bairro'] ?? null),
                'municipio' => self::string($address['municipio'] ?? null),
                'uf' => isset($address['uf']) ? strtoupper(trim((string) $address['uf'])) : null,
                'cep' => self::digits($address['cep'] ?? null),
                'codigo_municipio' => self::digits($address['codigo_municipio'] ?? $address['codigo_ibge'] ?? null),
            ], self::present(...)),
        ], self::present(...)), $path);
    }

    /** @return list<string> */
    public function validate(): array
    {
        $errors = [];
        $document = (string) ($this->data['cpf_cnpj'] ?? '');
        $type = (string) ($this->data['tipo_pessoa'] ?? '');
        if (! in_array($type, ['fisica', 'juridica', 'estrangeiro'], true)) {
            $errors[] = "{$this->path}.tipo_pessoa deve ser fisica, juridica ou estrangeiro.";
        }
        if ($type === 'fisica' && preg_match('/^\d{11}$/', $document) !== 1) {
            $errors[] = "{$this->path}.cpf_cnpj deve conter um CPF numérico de 11 dígitos.";
        }
        if ($type === 'juridica' && preg_match('/^[A-Z0-9]{12}\d{2}$/', $document) !== 1) {
            $errors[] = "{$this->path}.cpf_cnpj deve conter um CNPJ de 14 posições, inclusive no formato alfanumérico.";
        }
        if ($type !== 'estrangeiro' && trim((string) ($this->data['razao_social'] ?? '')) === '') {
            $errors[] = "{$this->path}.razao_social é obrigatório.";
        }

        return $errors;
    }

    public function document(): string
    {
        return (string) ($this->data['cpf_cnpj'] ?? '');
    }

    public function type(): string
    {
        return (string) ($this->data['tipo_pessoa'] ?? '');
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

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
