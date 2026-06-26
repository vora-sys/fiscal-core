<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class TomadorDTO
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
            $payload['documento'] ?? null,
            $payload['cnpj'] ?? null,
            $payload['cpf'] ?? null,
            $payload['CNPJ'] ?? null,
            $payload['CPF'] ?? null,
        ]) ?? '');
        if ($documento !== '') {
            $data['documento'] = $documento;
            if (strlen($documento) === 14) {
                $data['cnpj'] = $documento;
                unset($data['cpf']);
            } else {
                $data['cpf'] = str_pad(substr($documento, 0, 11), 11, '0', STR_PAD_LEFT);
                unset($data['cnpj']);
            }
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

        $endereco = DpsPayloadHelper::firstArray([
            $payload['endereco'] ?? null,
            $payload['end'] ?? null,
            $payload['address'] ?? null,
        ]);
        if ($endereco !== []) {
            $data['endereco'] = self::normalizeEndereco($endereco);
        }

        $telefone = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['telefone'] ?? null,
            $payload['fone'] ?? null,
            $payload['phone'] ?? null,
        ]) ?? '');
        if ($telefone !== '') {
            $data['telefone'] = $telefone;
            $data['fone'] = $telefone;
        }

        return new self($data);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $documento = DpsPayloadHelper::onlyDigits((string) ($this->data['documento'] ?? ''));
        if (!in_array(strlen($documento), [11, 14], true)) {
            $errors[] = 'tomador.documento deve ser CPF (11) ou CNPJ (14).';
        }

        if (trim((string) ($this->data['razaoSocial'] ?? '')) === '') {
            $errors[] = 'tomador.razaoSocial é obrigatório.';
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
     * @param array<string,mixed> $endereco
     * @return array<string,mixed>
     */
    private static function normalizeEndereco(array $endereco): array
    {
        $data = $endereco;
        $cMun = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $endereco['codigo_municipio'] ?? null,
            $endereco['codigoMunicipio'] ?? null,
            $endereco['codigo_ibge'] ?? null,
            $endereco['municipality_ibge'] ?? null,
            $endereco['cMun'] ?? null,
        ]) ?? '');
        if ($cMun !== '') {
            $data['codigo_municipio'] = str_pad(substr($cMun, 0, 7), 7, '0', STR_PAD_LEFT);
            $data['cMun'] = $data['codigo_municipio'];
        }

        $cep = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $endereco['cep'] ?? null,
            $endereco['CEP'] ?? null,
            $endereco['postal_code'] ?? null,
            $endereco['zip'] ?? null,
        ]) ?? '');
        if ($cep !== '') {
            $data['cep'] = str_pad(substr($cep, 0, 8), 8, '0', STR_PAD_LEFT);
            $data['CEP'] = $data['cep'];
        }

        foreach ([
            'logradouro' => ['logradouro', 'xLgr', 'street'],
            'numero' => ['numero', 'nro', 'number'],
            'complemento' => ['complemento', 'xCpl', 'complement'],
            'bairro' => ['bairro', 'xBairro', 'district'],
        ] as $target => $keys) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $endereco[$key] ?? null;
            }
            $value = DpsPayloadHelper::firstString($values);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        return $data;
    }
}
