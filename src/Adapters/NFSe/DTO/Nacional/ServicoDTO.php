<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class ServicoDTO
{
    /**
     * @param  array<string,mixed>  $data
     */
    private function __construct(private array $data) {}

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    public static function fromArray(array $payload, array $context = []): self
    {
        $data = $payload;
        $cLocEmi = DpsPayloadHelper::onlyDigits((string) ($context['cLocEmi'] ?? $context['codigo_municipio'] ?? ''));
        $cLocPrest = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['cLocPrestacao'] ?? null,
            $payload['codigo_municipio'] ?? null,
            $payload['codigoMunicipio'] ?? null,
            $cLocEmi,
        ]) ?? '');
        if ($cLocPrest !== '') {
            $data['cLocPrestacao'] = str_pad(substr($cLocPrest, 0, 7), 7, '0', STR_PAD_LEFT);
        }

        $cTribNac = DpsPayloadHelper::normalizeCTribNac(DpsPayloadHelper::firstString([
            $payload['cTribNac'] ?? null,
            $payload['codigoServicoNacional'] ?? null,
            $payload['codigo_servico_nacional'] ?? null,
            $payload['codigo'] ?? null,
        ]) ?? '');
        if ($cTribNac !== '') {
            $data['cTribNac'] = $cTribNac;
            $data['codigo'] = $cTribNac;
        }

        $cTribMun = DpsPayloadHelper::normalizeCTribMun(DpsPayloadHelper::firstString([
            $payload['cTribMun'] ?? null,
            $payload['codigoMunicipal'] ?? null,
            $payload['codigo_municipal'] ?? null,
        ]) ?? '');
        if ($cTribMun !== '') {
            $data['cTribMun'] = $cTribMun;
        }

        $descricao = DpsPayloadHelper::firstString([
            $payload['descricao'] ?? null,
            $payload['discriminacao'] ?? null,
            $payload['xDescServ'] ?? null,
        ]);
        if ($descricao !== null) {
            $data['descricao'] = $descricao;
            $data['discriminacao'] = $descricao;
        }

        $cNbs = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['cNBS'] ?? null,
            $payload['nbs'] ?? null,
            $payload['codigo_nbs'] ?? null,
            $payload['codigoNbs'] ?? null,
            $payload['codigoNBS'] ?? null,
        ]) ?? '');
        if ($cNbs !== '') {
            $data['cNBS'] = $cNbs;
        }

        $cAtvSn = DpsPayloadHelper::firstString([
            $payload['cAtvSN'] ?? null,
            $payload['codigo_atividade_simples_nacional'] ?? null,
        ]);
        if ($cAtvSn !== null) {
            $data['cAtvSN'] = $cAtvSn;
        }

        $aliquota = DpsPayloadHelper::firstDecimal([
            $payload['pAliq'] ?? null,
            $payload['aliquota'] ?? null,
        ]);
        if ($aliquota !== null) {
            $data['pAliq'] = DpsPayloadHelper::normalizePercent($aliquota);
            $data['aliquota'] = $payload['aliquota'] ?? $data['pAliq'];
        }

        $tpRetIssqn = DpsPayloadHelper::normalizeIssRetentionCode($payload);
        $data['tpRetISSQN'] = $tpRetIssqn;

        return new self($data);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        if (strlen(DpsPayloadHelper::onlyDigits((string) ($this->data['cLocPrestacao'] ?? ''))) !== 7) {
            $errors[] = 'servico.cLocPrestacao deve conter 7 dígitos.';
        }

        if (strlen(DpsPayloadHelper::onlyDigits((string) ($this->data['cTribNac'] ?? ''))) !== 6) {
            $errors[] = 'servico.cTribNac deve conter 6 dígitos.';
        }

        $descricao = trim((string) ($this->data['descricao'] ?? $this->data['discriminacao'] ?? ''));
        if ($descricao === '') {
            $errors[] = 'servico.descricao é obrigatório.';
        } elseif (strlen($descricao) > 2000) {
            $errors[] = 'servico.descricao deve ter no máximo 2000 caracteres.';
        }

        if (! in_array((string) ($this->data['tpRetISSQN'] ?? ''), ['1', '2', '3'], true)) {
            $errors[] = 'servico.tpRetISSQN deve ser 1, 2 ou 3.';
        }
        $cNbs = DpsPayloadHelper::onlyDigits((string) ($this->data['cNBS'] ?? ''));
        if ($cNbs !== '' && strlen($cNbs) !== 9) {
            $errors[] = 'servico.cNBS deve conter exatamente 9 dígitos.';
        }
        $cAtvSn = trim((string) ($this->data['cAtvSN'] ?? ''));
        if ($cAtvSn !== '' && ! in_array($cAtvSn, ['7', '8', '9', '10', '11', '12', '13', '14', '90'], true)) {
            $errors[] = 'servico.cAtvSN deve ser 7, 8, 9, 10, 11, 12, 13, 14 ou 90.';
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
