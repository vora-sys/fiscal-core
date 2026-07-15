<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class DpsDTO
{
    private PrestadorDTO $prestador;
    private TomadorDTO $tomador;
    private ServicoDTO $servico;
    private ValoresDTO $valores;
    private TributacaoMunicipalDTO $tributacaoMunicipal;
    private TributacaoFederalDTO $tributacaoFederal;
    private IbsCbsDTO $ibscbs;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    private function __construct(
        private array $data,
        private array $context,
    ) {
        $this->prestador = PrestadorDTO::fromArray(DpsPayloadHelper::firstArray([$data['prestador'] ?? null]));
        $cLocEmi = DpsPayloadHelper::onlyDigits((string) ($data['cLocEmi'] ?? $context['codigo_municipio'] ?? ''));
        $this->tomador = TomadorDTO::fromArray(DpsPayloadHelper::firstArray([$data['tomador'] ?? null]));
        $this->servico = ServicoDTO::fromArray(DpsPayloadHelper::firstArray([$data['servico'] ?? null]), [
            'cLocEmi' => $cLocEmi,
            'codigo_municipio' => $context['codigo_municipio'] ?? null,
        ]);
        $this->valores = ValoresDTO::fromArray($data);
        $this->tributacaoMunicipal = TributacaoMunicipalDTO::fromArray($data);
        $this->tributacaoFederal = TributacaoFederalDTO::fromArray($data);
        $this->ibscbs = IbsCbsDTO::fromArray($data);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     */
    public static function fromArray(array $payload, array $context = []): self
    {
        $data = $payload;
        $ambiente = (string) ($context['ambiente'] ?? 'homologacao');
        $codigoMunicipio = DpsPayloadHelper::onlyDigits((string) ($context['codigo_municipio'] ?? ''));

        $data['serie'] = NacionalDpsIdentityBuilder::normalizeSerie($payload['serie'] ?? $payload['serie_rps'] ?? '1');
        $data['nDPS'] = NacionalDpsIdentityBuilder::normalizeNumero($payload['nDPS'] ?? $payload['numero_rps'] ?? '1');
        $data['tpAmb'] = (string) (DpsPayloadHelper::firstString([$payload['tpAmb'] ?? null]) ?? ($ambiente === 'producao' ? '1' : '2'));
        $data['verAplic'] = DpsPayloadHelper::firstString([
            $payload['verAplic'] ?? null,
            $context['ver_aplic'] ?? null,
        ]) ?? 'invoiceflow-1.0';
        $data['tpEmit'] = (string) (DpsPayloadHelper::firstString([$payload['tpEmit'] ?? null]) ?? '1');
        $data = NacionalDpsTemporalNormalizer::normalizePayload($data, $context);

        $cLocEmi = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['cLocEmi'] ?? null,
            $payload['prestador']['codigoMunicipio'] ?? null,
            $payload['prestador']['codigo_municipio'] ?? null,
            $codigoMunicipio,
        ]) ?? '');
        if ($cLocEmi !== '') {
            $data['cLocEmi'] = str_pad(substr($cLocEmi, 0, 7), 7, '0', STR_PAD_LEFT);
        }

        $subst = DpsPayloadHelper::firstArray([$payload['subst'] ?? null]);
        if ($subst !== []) {
            $data['subst'] = [
                'chSubstda' => DpsPayloadHelper::onlyDigits((string) ($subst['chSubstda'] ?? '')),
                'cMotivo' => str_pad(DpsPayloadHelper::onlyDigits((string) ($subst['cMotivo'] ?? '')), 2, '0', STR_PAD_LEFT),
                'xMotivo' => trim(preg_replace('/\s+/', ' ', (string) ($subst['xMotivo'] ?? '')) ?? ''),
            ];
        }

        if (DpsPayloadHelper::firstString([$payload['id'] ?? null]) === null) {
            $dpsId = NacionalDpsIdentityBuilder::fromPayload($data, $context);
            if ($dpsId !== null) {
                $data['id'] = $dpsId;
            }
        }

        return new self($data, $context);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($this->data['dCompet'] ?? ''))) {
            $errors[] = 'dCompet deve estar no formato YYYY-MM-DD.';
        }
        if (!in_array((string) ($this->data['tpAmb'] ?? ''), ['1', '2'], true)) {
            $errors[] = 'tpAmb deve ser 1 (producao) ou 2 (homologacao).';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/', (string) ($this->data['dhEmi'] ?? ''))) {
            $errors[] = 'dhEmi deve estar no formato UTC (YYYY-MM-DDThh:mm:ssZ ou com timezone).';
        }
        $verAplic = trim((string) ($this->data['verAplic'] ?? ''));
        if ($verAplic === '' || strlen($verAplic) > 20) {
            $errors[] = 'verAplic deve ter entre 1 e 20 caracteres.';
        }
        if (strlen(DpsPayloadHelper::onlyDigits((string) ($this->data['serie'] ?? ''))) > 5) {
            $errors[] = 'serie deve conter de 1 a 5 dígitos numéricos.';
        }
        if (strlen(DpsPayloadHelper::onlyDigits((string) ($this->data['nDPS'] ?? ''))) > 15) {
            $errors[] = 'nDPS deve conter de 1 a 15 dígitos numéricos.';
        }
        if (!in_array((string) ($this->data['tpEmit'] ?? ''), ['1', '2', '3'], true)) {
            $errors[] = 'tpEmit deve ser 1, 2 ou 3.';
        }
        if (strlen(DpsPayloadHelper::onlyDigits((string) ($this->data['cLocEmi'] ?? ''))) !== 7) {
            $errors[] = 'cLocEmi deve conter 7 dígitos.';
        }

        $subst = DpsPayloadHelper::firstArray([$this->data['subst'] ?? null]);
        if ($subst !== []) {
            if (strlen(DpsPayloadHelper::onlyDigits((string) ($subst['chSubstda'] ?? ''))) !== 50) {
                $errors[] = 'subst.chSubstda deve conter 50 dígitos.';
            }
            if (!in_array((string) ($subst['cMotivo'] ?? ''), ['01', '02', '03', '04', '05', '99'], true)) {
                $errors[] = 'subst.cMotivo deve ser 01, 02, 03, 04, 05 ou 99.';
            }
            $xMotivo = trim((string) ($subst['xMotivo'] ?? ''));
            $length = function_exists('mb_strlen') ? mb_strlen($xMotivo) : strlen($xMotivo);
            if ($length < 15 || $length > 255) {
                $errors[] = 'subst.xMotivo deve conter entre 15 e 255 caracteres.';
            }
        }

        return array_values(array_unique(array_merge(
            $errors,
            $this->prestador->validate(),
            $this->tomador->validate(),
            $this->servico->validate(),
            $this->valores->validate(),
            $this->tributacaoMunicipal->validate(),
            $this->tributacaoFederal->validate(),
            $this->ibscbs->validate(),
        )));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = $this->data;
        $data['prestador'] = $this->prestador->toArray();
        $data['tomador'] = $this->tomador->toArray();
        $data['servico'] = $this->servico->toArray();
        $data['valor_servicos'] = $this->valores->valorServicos();
        $data['valores'] = $this->valores->toArray();

        $tributacao = DpsPayloadHelper::firstArray([$data['tributacao'] ?? null]);
        $tributacao['municipal'] = $this->tributacaoMunicipal->toArray();
        $federal = $this->tributacaoFederal->toArray();
        if ($federal !== []) {
            $tributacao['federal'] = $federal;
        } else {
            unset($tributacao['federal']);
        }
        $data['tributacao'] = $tributacao;

        $ibscbs = $this->ibscbs->toArray();
        if ($ibscbs !== []) {
            $data['ibscbs'] = $ibscbs;
            $data['IBSCBS'] = $ibscbs;
        }

        return $data;
    }
}
