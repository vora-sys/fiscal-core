<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

use sabbajohn\FiscalCore\Support\NfseNacionalIbscbsClassificationRules;

final class IbsCbsDTO
{
    /**
     * @param  array<string,mixed>  $data
     */
    private function __construct(private array $data) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $input = DpsPayloadHelper::firstArray([$payload['ibscbs'] ?? null, $payload['IBSCBS'] ?? null]);
        if ($input === []) {
            return new self([]);
        }

        $data = $input;
        foreach ([
            'finNFSe' => ['finNFSe', 'finalidade'],
            'indFinal' => ['indFinal', 'indicador_final'],
            'tpOper' => ['tpOper', 'tipo_operacao'],
            'tpEnteGov' => ['tpEnteGov', 'tipo_ente_governamental'],
            'indDest' => ['indDest', 'indicador_destinatario'],
        ] as $target => $keys) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $input[$key] ?? null;
            }
            $value = DpsPayloadHelper::firstString($values);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        $cIndOp = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $input['cIndOp'] ?? null,
            $input['codigo_indicador_operacao'] ?? null,
        ]) ?? '');
        if ($cIndOp !== '') {
            $data['cIndOp'] = str_pad(substr($cIndOp, 0, 6), 6, '0', STR_PAD_LEFT);
        }

        $refs = $input['gRefNFSe']['refNFSe'] ?? $input['refNFSe'] ?? null;
        if ($refs !== null) {
            $refList = [];
            foreach (is_array($refs) ? $refs : [$refs] as $ref) {
                if (is_scalar($ref) && trim((string) $ref) !== '') {
                    $refList[] = trim((string) $ref);
                }
            }
            if ($refList !== []) {
                $data['gRefNFSe'] = ['refNFSe' => $refList];
            }
        }

        $dest = DpsPayloadHelper::firstArray([$input['dest'] ?? null]);
        if ($dest !== []) {
            $data['dest'] = self::normalizePessoa($dest, true);
        }

        $imovel = DpsPayloadHelper::firstArray([$input['imovel'] ?? null, $input['bem_imovel'] ?? null]);
        if ($imovel !== []) {
            $data['imovel'] = self::normalizeImovel($imovel);
        }

        $valores = DpsPayloadHelper::firstArray([$input['valores'] ?? null]);
        $normalizedValores = $valores;
        $reeRepRes = DpsPayloadHelper::firstArray([
            $valores['gReeRepRes'] ?? null,
            $valores['reeRepRes'] ?? null,
            $valores['reembolso_repasse_ressarcimento'] ?? null,
            $input['gReeRepRes'] ?? null,
            $input['reeRepRes'] ?? null,
            $input['reembolso_repasse_ressarcimento'] ?? null,
        ]);
        if ($reeRepRes !== []) {
            $normalizedValores['gReeRepRes'] = self::normalizeReeRepRes($reeRepRes);
        }

        $gIbscbs = self::resolveTributosSitClas($input, $valores);
        if ($gIbscbs !== []) {
            $normalizedValores['trib'] = ['gIBSCBS' => $gIbscbs];
            $data['gIBSCBS'] = $gIbscbs;
        }
        if ($normalizedValores !== []) {
            $data['valores'] = $normalizedValores;
        }

        return new self($data);
    }

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        if ($this->data === []) {
            return [];
        }

        $errors = [];
        if (isset($this->data['cIndOp']) && strlen(DpsPayloadHelper::onlyDigits((string) $this->data['cIndOp'])) !== 6) {
            $errors[] = 'ibscbs.cIndOp deve conter 6 dígitos.';
        }

        $gIbscbs = DpsPayloadHelper::firstArray([
            $this->data['valores']['trib']['gIBSCBS'] ?? null,
            $this->data['gIBSCBS'] ?? null,
        ]);
        if ($gIbscbs !== []) {
            if (isset($gIbscbs['CST']) && strlen(DpsPayloadHelper::onlyDigits((string) $gIbscbs['CST'])) !== 3) {
                $errors[] = 'ibscbs.valores.trib.gIBSCBS.CST deve conter 3 dígitos.';
            }
            if (isset($gIbscbs['cClassTrib']) && strlen(DpsPayloadHelper::onlyDigits((string) $gIbscbs['cClassTrib'])) !== 6) {
                $errors[] = 'ibscbs.valores.trib.gIBSCBS.cClassTrib deve conter 6 dígitos.';
            }
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
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $valores
     * @return array<string,mixed>
     */
    private static function resolveTributosSitClas(array $input, array $valores): array
    {
        $trib = DpsPayloadHelper::firstArray([$valores['trib'] ?? null]);
        $gIbscbs = DpsPayloadHelper::firstArray([$trib['gIBSCBS'] ?? null, $input['gIBSCBS'] ?? null]);
        $cst = DpsPayloadHelper::firstString([$gIbscbs['CST'] ?? null, $gIbscbs['cst'] ?? null]);
        $cClassTrib = DpsPayloadHelper::firstString([
            $gIbscbs['cClassTrib'] ?? null,
            $gIbscbs['cClass'] ?? null,
            $gIbscbs['classificacao'] ?? null,
            $gIbscbs['codigo_classificacao'] ?? null,
        ]);
        if ($cst === null || $cClassTrib === null) {
            return [];
        }

        $data = $gIbscbs;
        unset($data['gTribRegular'], $data['gDif']);
        $data['CST'] = NfseNacionalIbscbsClassificationRules::normalizeCst($cst);
        $data['cClassTrib'] = NfseNacionalIbscbsClassificationRules::normalizeClass($cClassTrib);

        $cCredPres = DpsPayloadHelper::firstString([$gIbscbs['cCredPres'] ?? null, $gIbscbs['codigo_credito_presumido'] ?? null]);
        if ($cCredPres !== null && DpsPayloadHelper::onlyDigits($cCredPres) !== '') {
            $data['cCredPres'] = str_pad(substr(DpsPayloadHelper::onlyDigits($cCredPres), 0, 2), 2, '0', STR_PAD_LEFT);
        }

        $regular = DpsPayloadHelper::firstArray([$gIbscbs['gTribRegular'] ?? null]);
        if ($regular !== [] && NfseNacionalIbscbsClassificationRules::allowsTribRegular($data['cClassTrib'], $data['CST'])) {
            $cstReg = DpsPayloadHelper::firstString([$regular['CSTReg'] ?? null, $regular['cstReg'] ?? null, $regular['CST'] ?? null]);
            $cClassReg = DpsPayloadHelper::firstString([
                $regular['cClassTribReg'] ?? null,
                $regular['cClassReg'] ?? null,
                $regular['cClassTrib'] ?? null,
                $regular['classificacao'] ?? null,
            ]);
            if ($cstReg !== null && $cClassReg !== null) {
                $data['gTribRegular'] = [
                    'CSTReg' => str_pad(substr(DpsPayloadHelper::onlyDigits($cstReg), 0, 3), 3, '0', STR_PAD_LEFT),
                    'cClassTribReg' => str_pad(substr(DpsPayloadHelper::onlyDigits($cClassReg), 0, 6), 6, '0', STR_PAD_LEFT),
                ];
            }
        }

        $dif = DpsPayloadHelper::firstArray([$gIbscbs['gDif'] ?? null]);
        if ($dif !== [] && NfseNacionalIbscbsClassificationRules::allowsDiferimento($data['cClassTrib'], $data['CST'])) {
            $pDifUf = DpsPayloadHelper::firstDecimal([$dif['pDifUF'] ?? null]);
            $pDifMun = DpsPayloadHelper::firstDecimal([$dif['pDifMun'] ?? null]);
            $pDifCbs = DpsPayloadHelper::firstDecimal([$dif['pDifCBS'] ?? null]);
            if (
                $pDifUf !== null
                && $pDifMun !== null
                && $pDifCbs !== null
                && NfseNacionalIbscbsClassificationRules::hasEffectiveDiferimento($pDifUf, $pDifMun, $pDifCbs)
            ) {
                $data['gDif'] = [
                    'pDifUF' => $pDifUf,
                    'pDifMun' => $pDifMun,
                    'pDifCBS' => $pDifCbs,
                ];
            }
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $pessoa
     * @return array<string,mixed>
     */
    private static function normalizePessoa(array $pessoa, bool $withEndereco): array
    {
        $data = $pessoa;
        $documentoRaw = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', DpsPayloadHelper::firstString([
            $pessoa['CNPJ'] ?? null,
            $pessoa['cnpj'] ?? null,
            $pessoa['CPF'] ?? null,
            $pessoa['cpf'] ?? null,
            $pessoa['documento'] ?? null,
        ]) ?? '') ?? '');
        $documento = DpsPayloadHelper::onlyDigits($documentoRaw);
        if (strlen($documentoRaw) === 14) {
            $data['CNPJ'] = $documentoRaw;
        } elseif (strlen($documento) === 14) {
            $data['CNPJ'] = $documento;
        } elseif ($documento !== '') {
            $data['CPF'] = str_pad(substr($documento, 0, 11), 11, '0', STR_PAD_LEFT);
        }

        foreach ([
            'NIF' => ['NIF', 'nif'],
            'cNaoNIF' => ['cNaoNIF', 'codigo_nao_nif', 'motivo_nao_nif'],
            'xNome' => ['xNome', 'razaoSocial', 'razao_social', 'nome'],
        ] as $target => $keys) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $pessoa[$key] ?? null;
            }
            $value = DpsPayloadHelper::firstString($values);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        if ($withEndereco) {
            $endereco = DpsPayloadHelper::firstArray([$pessoa['end'] ?? null, $pessoa['endereco'] ?? null, $pessoa['address'] ?? null]);
            if ($endereco !== []) {
                $data['endereco'] = self::normalizeEndereco($endereco);
                $data['end'] = $data['endereco'];
            }
        }

        $fone = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([$pessoa['fone'] ?? null, $pessoa['telefone'] ?? null, $pessoa['phone'] ?? null]) ?? '');
        if ($fone !== '') {
            $data['fone'] = $fone;
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $endereco
     * @return array<string,mixed>
     */
    private static function normalizeEndereco(array $endereco): array
    {
        $data = $endereco;
        foreach ([
            'cPais' => ['cPais', 'codigo_pais'],
            'cEndPost' => ['cEndPost', 'codigo_postal'],
            'xCidade' => ['xCidade', 'cidade'],
            'xEstProvReg' => ['xEstProvReg', 'estado_provincia'],
            'xLgr' => ['xLgr', 'logradouro', 'street'],
            'nro' => ['nro', 'numero', 'number'],
            'xCpl' => ['xCpl', 'complemento', 'complement'],
            'xBairro' => ['xBairro', 'bairro', 'district'],
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

        $cMun = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $endereco['cMun'] ?? null,
            $endereco['codigo_municipio'] ?? null,
            $endereco['codigoMunicipio'] ?? null,
        ]) ?? '');
        if ($cMun !== '') {
            $data['cMun'] = str_pad(substr($cMun, 0, 7), 7, '0', STR_PAD_LEFT);
        }
        $cep = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([$endereco['CEP'] ?? null, $endereco['cep'] ?? null]) ?? '');
        if ($cep !== '') {
            $data['CEP'] = str_pad(substr($cep, 0, 8), 8, '0', STR_PAD_LEFT);
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $imovel
     * @return array<string,mixed>
     */
    private static function normalizeImovel(array $imovel): array
    {
        $data = $imovel;
        $insc = DpsPayloadHelper::firstString([$imovel['inscImobFisc'] ?? null, $imovel['inscricao_imobiliaria'] ?? null]);
        if ($insc !== null) {
            $data['inscImobFisc'] = $insc;
        }
        $cCib = DpsPayloadHelper::firstString([$imovel['cCIB'] ?? null, $imovel['cib'] ?? null]);
        if ($cCib !== null) {
            $data['cCIB'] = $cCib;
        }
        $endereco = DpsPayloadHelper::firstArray([$imovel['end'] ?? null, $imovel['endereco'] ?? null, $imovel['address'] ?? null]);
        if ($endereco !== []) {
            $data['endereco'] = self::normalizeEndereco($endereco);
            $data['end'] = $data['endereco'];
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $reeRepRes
     * @return array<string,mixed>
     */
    private static function normalizeReeRepRes(array $reeRepRes): array
    {
        $documentosPayload = $reeRepRes['documentos']
            ?? $reeRepRes['documentos_referenciados']
            ?? $reeRepRes['docs']
            ?? $reeRepRes;
        $documentos = [];
        foreach (DpsPayloadHelper::normalizeArrayList($documentosPayload) as $documento) {
            if (! is_array($documento)) {
                continue;
            }
            $documentos[] = self::normalizeDocumento($documento);
        }

        return ['documentos' => $documentos];
    }

    /**
     * @param  array<string,mixed>  $documento
     * @return array<string,mixed>
     */
    private static function normalizeDocumento(array $documento): array
    {
        $data = $documento;
        foreach ([
            'dtEmiDoc' => ['dtEmiDoc', 'data_emissao'],
            'dtCompDoc' => ['dtCompDoc', 'data_competencia'],
            'tpReeRepRes' => ['tpReeRepRes', 'tipo', 'tipo_reembolso_repasse_ressarcimento'],
            'xTpReeRepRes' => ['xTpReeRepRes', 'descricao_tipo'],
        ] as $target => $keys) {
            $values = [];
            foreach ($keys as $key) {
                $values[] = $documento[$key] ?? null;
            }
            $value = DpsPayloadHelper::firstString($values);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }
        $valor = DpsPayloadHelper::firstDecimal([
            $documento['vlrReeRepRes'] ?? null,
            $documento['valor'] ?? null,
            $documento['valor_reembolso_repasse_ressarcimento'] ?? null,
        ]);
        if ($valor !== null) {
            $data['vlrReeRepRes'] = $valor;
        }

        $fornecedor = DpsPayloadHelper::firstArray([$documento['fornec'] ?? null, $documento['fornecedor'] ?? null]);
        if ($fornecedor !== []) {
            $data['fornec'] = self::normalizePessoa($fornecedor, false);
        }

        return $data;
    }
}
