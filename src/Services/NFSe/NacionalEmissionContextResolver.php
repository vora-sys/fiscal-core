<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Contracts\NfseNacionalCncInterface;
use sabbajohn\FiscalCore\Contracts\NfseNacionalParametrizacaoInterface;
use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;
use sabbajohn\FiscalCore\Exceptions\NfseNacionalPreflightException;

final class NacionalEmissionContextResolver
{
    public function __construct(
        private readonly NfseNacionalParametrizacaoInterface $parametrizacao,
        private readonly NfseNacionalCncInterface $cnc,
        private readonly NfseNacionalIssIncidenceResolver $incidence,
    ) {}

    /** @param array<string,mixed> $payload @return array{payload:array<string,mixed>,context:array<string,mixed>} */
    public function resolve(array $payload): array
    {
        unset($payload['_nfse_nacional_remote_context']);
        $inputPayloadHash = $this->payloadHash($payload);
        $service = is_array($payload['servico'] ?? null) ? $payload['servico'] : [];
        $issuer = is_array($payload['prestador'] ?? null) ? $payload['prestador'] : [];
        $taxation = is_array($payload['tributacao'] ?? null) ? $payload['tributacao'] : [];
        $municipal = is_array($taxation['municipal'] ?? null) ? $taxation['municipal'] : [];
        $competence = $this->competence((string) ($payload['dCompet'] ?? ''));
        $serviceCode = (string) ($service['cTribNac'] ?? '');
        $emissionCity = $this->digits($payload['cLocEmi'] ?? $issuer['codigoMunicipio'] ?? '');
        $incidence = $this->incidence->resolve($payload);
        $context = [
            'resolved_at' => gmdate(DATE_ATOM),
            'municipio_emissao' => $emissionCity,
            'incidencia_iss' => $incidence,
            'decisions' => [],
            'warnings' => [],
            'errors' => [],
        ];

        $agreement = $this->parametrizacao->consultarConvenio($emissionCity);
        $context['convenio'] = $agreement->toArray();
        if ($this->fresh($agreement) && $agreement->found() && $this->explicitFalse($agreement->data, ['aderenteEmissorNacional', 'aderente_emissor_nacional'])) {
            $this->fail('Município não aderente ao Emissor Nacional.', 'NFSE_NACIONAL_CONVENIO_INCOMPATIVEL', 'payload.identificacao.municipio_ocorrencia_codigo', $agreement);
        }
        if ($agreement->unavailable()) {
            $context['warnings'][] = 'Não foi possível confirmar o convênio municipal; a SEFIN fará a validação final.';
        }

        $rate = $this->parametrizacao->consultarAliquota($incidence['codigo_municipio'], $serviceCode, $competence);
        $context['aliquota'] = $rate->toArray();
        $officialRate = $this->extractRate($rate->data);
        $sendRate = filter_var($service['enviarPAliq'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $payloadRate = $this->number($municipal['pAliq'] ?? $service['aliquota'] ?? null);
        if ($this->fresh($rate) && $rate->found() && $officialRate !== null && $sendRate) {
            if ($payloadRate !== null && abs($payloadRate - $officialRate) > 0.0001) {
                $this->fail('Alíquota ISS informada diverge da parametrização vigente.', 'NFSE_NACIONAL_ALIQUOTA_DIVERGENTE', 'payload.tributacao.municipal.aliquota_iss', $rate);
            }
            if ($payloadRate === null || $payloadRate <= 0) {
                $payload['servico']['aliquota'] = $officialRate;
                $payload['tributacao']['municipal']['pAliq'] = $officialRate;
                $context['decisions'][] = ['field' => 'servico.aliquota', 'source' => 'parametrizacao_nacional', 'value' => $officialRate];
            }
        } elseif ($sendRate && ($payloadRate === null || $payloadRate <= 0)) {
            $this->fail('O perfil exige pAliq, mas não há alíquota informada ou parametrizada.', 'NFSE_NACIONAL_ALIQUOTA_OBRIGATORIA', 'payload.tributacao.municipal.aliquota_iss', $rate);
        }

        $benefitBlock = is_array($municipal['beneficio_municipal'] ?? null)
            ? $municipal['beneficio_municipal']
            : (is_array($municipal['BM'] ?? null) ? $municipal['BM'] : []);
        $benefitNumber = trim((string) ($benefitBlock['numero_beneficio'] ?? $benefitBlock['nBM'] ?? ''));
        if ($benefitNumber !== '') {
            $benefit = $this->parametrizacao->consultarBeneficio($incidence['codigo_municipio'], $benefitNumber, $competence);
            $context['beneficio'] = $benefit->toArray();
            if ($this->fresh($benefit) && ! $benefit->found()) {
                $this->fail('Benefício municipal declarado não foi localizado na competência.', 'NFSE_NACIONAL_BENEFICIO_INVALIDO', 'payload.tributacao.municipal.beneficio_municipal.numero_beneficio', $benefit);
            }
            if (! $this->fresh($benefit)) {
                $context['warnings'][] = 'Benefício municipal não pôde ser confirmado com dados atuais.';
            }
            if ($this->fresh($benefit) && $benefit->found()) {
                $officialService = $this->firstScalar($benefit->data, ['codigoServico', 'codigo_servico', 'cTribNac']);
                if ($officialService !== null && substr($this->digits($officialService), 0, 6) !== substr($this->digits($serviceCode), 0, 6)) {
                    $this->fail('Benefício municipal não se aplica ao serviço declarado.', 'NFSE_NACIONAL_BENEFICIO_SERVICO_INVALIDO', 'payload.tributacao.municipal.beneficio_municipal.numero_beneficio', $benefit);
                }
                $officialDocument = $this->firstScalar($benefit->data, ['inscricaoFederal', 'cpfCnpj', 'documentoContribuinte']);
                $issuerDocument = $this->document($issuer['cnpj'] ?? $issuer['cpf'] ?? '');
                if ($officialDocument !== null && $this->document($officialDocument) !== $this->document($issuerDocument)) {
                    $this->fail('Benefício municipal não se aplica ao contribuinte emitente.', 'NFSE_NACIONAL_BENEFICIO_CONTRIBUINTE_INVALIDO', 'payload.emitente.cpf_cnpj', $benefit);
                }
                $officialPercent = $this->number($this->firstScalar($benefit->data, ['pRedBCBM', 'percentualReducao', 'percentual_reducao']));
                $officialValue = $this->number($this->firstScalar($benefit->data, ['vRedBCBM', 'valorReducao', 'valor_reducao']));
                if (! isset($benefitBlock['pRedBCBM']) && ! isset($benefitBlock['percentual_reducao_bc']) && $officialPercent !== null) {
                    $payload['tributacao']['municipal']['BM']['pRedBCBM'] = $officialPercent;
                    $context['decisions'][] = ['field' => 'tributacao.municipal.BM.pRedBCBM', 'source' => 'parametrizacao_nacional', 'value' => $officialPercent];
                } elseif (! isset($benefitBlock['vRedBCBM']) && ! isset($benefitBlock['valor_reducao_bc']) && $officialValue !== null) {
                    $payload['tributacao']['municipal']['BM']['vRedBCBM'] = $officialValue;
                    $context['decisions'][] = ['field' => 'tributacao.municipal.BM.vRedBCBM', 'source' => 'parametrizacao_nacional', 'value' => $officialValue];
                }
            }
        }

        $specialRegime = trim((string) ($issuer['regEspTrib'] ?? '0'));
        if ($specialRegime !== '' && $specialRegime !== '0') {
            $regimes = $this->parametrizacao->consultarRegimesEspeciais($incidence['codigo_municipio'], $serviceCode, $competence);
            $context['regimes_especiais'] = $regimes->toArray();
            if ($this->fresh($regimes) && ! $regimes->found()) {
                $this->fail('Regime especial configurado não foi localizado na competência.', 'NFSE_NACIONAL_REGIME_ESPECIAL_INVALIDO', 'empresa.configuracao.regime_especial_tributacao', $regimes);
            }
            $officialRegimes = $this->scalarValues($regimes->data, ['regEspTrib', 'codigoRegimeEspecial', 'codigo_regime_especial']);
            if ($this->fresh($regimes) && $regimes->found() && $officialRegimes !== [] && ! in_array($specialRegime, $officialRegimes, true)) {
                $this->fail('Regime especial configurado não corresponde à parametrização vigente.', 'NFSE_NACIONAL_REGIME_ESPECIAL_DIVERGENTE', 'empresa.configuracao.regime_especial_tributacao', $regimes);
            }
            if (! $this->fresh($regimes)) {
                $context['warnings'][] = 'Regime especial não pôde ser confirmado com dados atuais.';
            }
        }

        $retentions = $this->parametrizacao->consultarRetencoes($incidence['codigo_municipio'], $competence);
        $context['retencoes'] = $retentions->toArray();
        if ($retentions->unavailable()) {
            $context['warnings'][] = 'Retenções municipais não puderam ser consultadas; o tipo informado foi preservado.';
        }
        $retentionType = trim((string) ($municipal['tpRetISSQN'] ?? $service['tpRetISSQN'] ?? '1'));
        $requiredRetention = $this->boolean($this->firstScalar($retentions->data, ['retencaoObrigatoria', 'retencao_obrigatoria']));
        $retentionAllowed = $this->boolean($this->firstScalar($retentions->data, ['permiteRetencao', 'retencaoPermitida', 'retencao_permitida']));
        if ($this->fresh($retentions) && $retentions->found() && $requiredRetention === true && $retentionType === '1') {
            $this->fail('Parametrização exige retenção do ISS, mas o payload declara ISS não retido.', 'NFSE_NACIONAL_RETENCAO_CONTRADITORIA', 'payload.tributacao.municipal.tipo_retencao_iss', $retentions);
        }
        if ($this->fresh($retentions) && $retentions->found() && $retentionAllowed === false && in_array($retentionType, ['2', '3'], true)) {
            $this->fail('Payload declara retenção do ISS não permitida pela parametrização.', 'NFSE_NACIONAL_RETENCAO_CONTRADITORIA', 'payload.tributacao.municipal.tipo_retencao_iss', $retentions);
        }

        $document = $this->document($issuer['cnpj'] ?? $issuer['cpf'] ?? '');
        if (strlen($emissionCity) === 7 && in_array(strlen($document), [11, 14], true)) {
            // A consulta oficial usa município + CNPJ/CPF. A IM local serve apenas
            // para selecionar a correspondência quando o CNC devolver mais de uma.
            $localIm = trim((string) ($issuer['inscricaoMunicipal'] ?? $issuer['IM'] ?? ''));
            $cnc = $this->cnc->consultarCadastroCnc($emissionCity, $document, $localIm !== '' ? $localIm : null);
            $context['cnc'] = $cnc->toArray();
            $record = is_array($cnc->data['correspondencia'] ?? null) ? $cnc->data['correspondencia'] : null;
            $unambiguous = ($cnc->data['correspondencia_inequivoca'] ?? false) === true;
            if ($this->fresh($cnc) && $cnc->found() && ($record === null || ! $unambiguous)) {
                $this->fail('O CNC não confirmou uma inscrição municipal única para o emitente.', 'NFSE_NACIONAL_CNC_IM_NAO_CONFIRMADA', 'payload.emitente.inscricao_municipal', $cnc);
            }
            if ($this->fresh($cnc) && $record !== null && $unambiguous && $this->cncExplicitlyDisabled($record)) {
                $this->fail('Emitente está inequivocamente desabilitado para emissão no CNC.', 'NFSE_NACIONAL_CNC_NAO_HABILITADO', 'payload.emitente.cpf_cnpj', $cnc);
            }
            $officialIm = $record !== null ? $this->cncMunicipalRegistration($record) : null;
            if ($this->fresh($cnc) && $unambiguous && $officialIm !== null) {
                $payload['prestador']['inscricaoMunicipal'] = $officialIm;
                $payload['prestador']['IM'] = $officialIm;
                $payload['prestador']['enviarIM'] = true;
                if ($officialIm !== $localIm) {
                    $context['decisions'][] = ['field' => 'prestador.inscricaoMunicipal', 'source' => 'cnc_nacional', 'value' => $officialIm];
                }
            } elseif ($this->fresh($cnc) && $cnc->status === 'nao_parametrizado' && $record === null) {
                $payload['prestador']['enviarIM'] = false;
                $context['decisions'][] = [
                    'field' => 'prestador.enviarIM',
                    'source' => 'cnc_nacional',
                    'value' => false,
                    'reason' => 'sem_informacoes_complementares',
                ];
                $context['warnings'][] = 'O CNC confirmou ausência de informações complementares; a IM será omitida da DPS.';
            } elseif ($cnc->unavailable() || $record === null) {
                $context['warnings'][] = $localIm === ''
                    ? 'A IM é obrigatória para consultar o CNC; informe-a no cadastro da empresa.'
                    : 'O CNC não confirmou a combinação CNPJ/CPF + município + IM; os dados locais foram preservados.';
            }
        }

        $context['hash'] = hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $context['payload_hash'] = $inputPayloadHash;
        $context['valid_until'] = gmdate(DATE_ATOM, time() + 21600);

        return ['payload' => $payload, 'context' => $context];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context @return array{payload:array<string,mixed>,context:array<string,mixed>}|null */
    public function reuse(array $payload, array $context): ?array
    {
        unset($payload['_nfse_nacional_remote_context']);
        $validUntil = strtotime((string) ($context['valid_until'] ?? ''));
        if ($validUntil === false || $validUntil <= time()) {
            return null;
        }
        if (! hash_equals((string) ($context['payload_hash'] ?? ''), $this->payloadHash($payload))) {
            return null;
        }
        $cncSnapshot = is_array($context['cnc'] ?? null) ? $context['cnc'] : [];
        $cncData = is_array($cncSnapshot['data'] ?? null) ? $cncSnapshot['data'] : [];
        if (($cncSnapshot['status'] ?? null) === 'encontrado') {
            $record = is_array($cncData['correspondencia'] ?? null) ? $cncData['correspondencia'] : null;
            if ($record === null || ($cncData['correspondencia_inequivoca'] ?? false) !== true) {
                return null;
            }
            $officialIm = $this->cncMunicipalRegistration($record);
            if ($officialIm !== null) {
                $payload['prestador']['inscricaoMunicipal'] = $officialIm;
                $payload['prestador']['IM'] = $officialIm;
                $payload['prestador']['enviarIM'] = true;
            }
        } elseif (($cncSnapshot['status'] ?? null) === 'nao_parametrizado'
            && ($cncSnapshot['metadata']['stale'] ?? false) !== true
            && ! is_array($cncData['correspondencia'] ?? null)) {
            $payload['prestador']['enviarIM'] = false;
        }
        foreach ((array) ($context['decisions'] ?? []) as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            if (($decision['field'] ?? null) === 'prestador.enviarIM') {
                $sendIm = filter_var($decision['value'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($sendIm !== null) {
                    $payload['prestador']['enviarIM'] = $sendIm;
                }

                continue;
            }
            if (($decision['field'] ?? null) === 'prestador.inscricaoMunicipal' && is_scalar($decision['value'] ?? null)) {
                $payload['prestador']['inscricaoMunicipal'] = (string) $decision['value'];
                $payload['prestador']['IM'] = (string) $decision['value'];

                continue;
            }
            if (! is_numeric($decision['value'] ?? null)) {
                continue;
            }
            if (($decision['field'] ?? null) === 'servico.aliquota') {
                $payload['servico']['aliquota'] = (float) $decision['value'];
                $payload['tributacao']['municipal']['pAliq'] = (float) $decision['value'];
            }
            if (($decision['field'] ?? null) === 'tributacao.municipal.BM.pRedBCBM') {
                $payload['tributacao']['municipal']['BM']['pRedBCBM'] = (float) $decision['value'];
            }
            if (($decision['field'] ?? null) === 'tributacao.municipal.BM.vRedBCBM') {
                $payload['tributacao']['municipal']['BM']['vRedBCBM'] = (float) $decision['value'];
            }
        }
        $context['reused'] = true;
        $context['reused_at'] = gmdate(DATE_ATOM);

        return ['payload' => $payload, 'context' => $context];
    }

    private function fail(string $message, string $code, string $path, NacionalApiResult $result): never
    {
        throw new NfseNacionalPreflightException($message, [
            'provider' => 'nfse_nacional',
            'stage' => 'remote_preflight',
            'code' => $code,
            'path' => $path,
            'request_id' => $result->metadata['request_id'] ?? null,
            'remote_status' => $result->status,
        ]);
    }

    private function explicitFalse(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;
            }
        }

        return false;
    }

    private function cncExplicitlyDisabled(array $record): bool
    {
        $info = is_array($record['InfCad'] ?? null)
            ? $record['InfCad']
            : (is_array($record['infCad'] ?? null) ? $record['infCad'] : []);
        foreach (['habilitadoEmissao', 'habilitado', 'podeEmitir'] as $key) {
            if (array_key_exists($key, $record) && filter_var($record[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
                return true;
            }
        }
        $emissionStatus = strtoupper(trim((string) ($info['SituacaoEmissaoNFSe'] ?? $info['situacaoEmissaoNFSe'] ?? '')));
        if ($emissionStatus !== '' && ! in_array($emissionStatus, ['HABILITADO', 'ATIVO', 'REGULAR'], true)) {
            return true;
        }
        $status = strtoupper(trim((string) ($info['SituacaoCadastral'] ?? $info['situacaoCadastral'] ?? $record['situacaoCadastral'] ?? $record['situacao'] ?? '')));

        return in_array($status, ['INATIVO', 'BAIXADO', 'SUSPENSO', 'NAO_HABILITADO'], true);
    }

    private function cncMunicipalRegistration(array $record): ?string
    {
        $value = $this->firstScalar($record, ['InscricaoMunicipal', 'inscricaoMunicipal', 'indicadorMunicipal', 'IM']);
        if (! is_scalar($value)) {
            return null;
        }

        $registration = (string) $value;

        return trim($registration) !== '' ? $registration : null;
    }

    private function extractRate(array $data): ?float
    {
        foreach (['aliquota', 'aliquotaIss', 'aliquotaISS', 'valorAliquota', 'pAliq'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && ($rate = $this->extractRate($value)) !== null) {
                return $rate;
            }
        }

        return null;
    }

    private function competence(string $value): string
    {
        $date = substr(trim($value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : gmdate('Y-m-d');
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    private function document(mixed $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', is_scalar($value) ? (string) $value : '') ?? '');
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function fresh(NacionalApiResult $result): bool
    {
        return ($result->metadata['stale'] ?? false) !== true;
    }

    private function boolean(mixed $value): ?bool
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function firstScalar(array $data, array $keys): string|int|float|bool|null
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && ($found = $this->firstScalar($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function scalarValues(array $data, array $keys): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $keys, true) && is_scalar($value)) {
                $values[] = trim((string) $value);
            }
            if (is_array($value)) {
                $values = [...$values, ...$this->scalarValues($value, $keys)];
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
