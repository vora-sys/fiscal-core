<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class PrestadorDTO
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
        $data = $payload;
        $documento = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['cnpj'] ?? null,
            $payload['cpf'] ?? null,
            $payload['documento'] ?? null,
        ]) ?? '');

        if (strlen($documento) === 14) {
            $data['cnpj'] = $documento;
            unset($data['cpf']);
        } elseif ($documento !== '') {
            $data['cpf'] = str_pad(substr($documento, 0, 11), 11, '0', STR_PAD_LEFT);
            unset($data['cnpj']);
        }
        if ($documento !== '') {
            $data['documento'] = $documento;
        }

        $im = DpsPayloadHelper::firstString([
            $payload['inscricaoMunicipal'] ?? null,
            $payload['inscricao_municipal'] ?? null,
            $payload['IM'] ?? null,
        ]);
        if ($im !== null) {
            $data['inscricaoMunicipal'] = $im;
            $data['IM'] = $im;
        }

        $nome = DpsPayloadHelper::firstString([
            $payload['razaoSocial'] ?? null,
            $payload['razao_social'] ?? null,
            $payload['nome'] ?? null,
            $payload['xNome'] ?? null,
        ]);
        if ($nome !== null) {
            $data['razaoSocial'] = $nome;
        }

        $telefone = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['telefone'] ?? null,
            $payload['fone'] ?? null,
        ]) ?? '');
        if ($telefone !== '') {
            $data['telefone'] = $telefone;
            $data['fone'] = $telefone;
        }

        $data['opSimpNac'] = (string) (DpsPayloadHelper::firstString([$payload['opSimpNac'] ?? null]) ?? '1');
        $regApTribSn = DpsPayloadHelper::firstString([
            $payload['regApTribSN'] ?? null,
            $payload['regime_apuracao_sn'] ?? null,
        ]);
        if ($regApTribSn !== null) {
            $data['regApTribSN'] = $regApTribSn;
        }
        $data['regEspTrib'] = (string) (DpsPayloadHelper::firstString([$payload['regEspTrib'] ?? null]) ?? '0');

        return new self($data);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $documento = DpsPayloadHelper::onlyDigits((string) ($this->data['documento'] ?? $this->data['cnpj'] ?? $this->data['cpf'] ?? ''));
        if (!in_array(strlen($documento), [11, 14], true)) {
            $errors[] = 'prestador.documento deve ser CPF (11) ou CNPJ (14).';
        }

        if (trim((string) ($this->data['inscricaoMunicipal'] ?? '')) === '') {
            $errors[] = 'prestador.inscricaoMunicipal é obrigatório.';
        }

        if (!in_array((string) ($this->data['opSimpNac'] ?? ''), ['1', '2', '3'], true)) {
            $errors[] = 'prestador.opSimpNac deve ser 1, 2 ou 3.';
        }

        if (!in_array((string) ($this->data['regEspTrib'] ?? ''), ['0', '1', '2', '3', '4', '5', '6', '9'], true)) {
            $errors[] = 'prestador.regEspTrib deve ser 0, 1, 2, 3, 4, 5, 6 ou 9.';
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
