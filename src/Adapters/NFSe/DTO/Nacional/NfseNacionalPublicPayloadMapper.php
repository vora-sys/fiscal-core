<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

use sabbajohn\FiscalCore\Support\NfseNacionalIbscbsClassificationRules;

final class NfseNacionalPublicPayloadMapper
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function map(array $payload, array $context = []): array
    {
        $identificacao = $this->array($payload['identificacao'] ?? []);
        $emitente = $this->array($payload['emitente'] ?? []);
        $tomador = $this->array($payload['tomador'] ?? []);
        $servico = $this->array($payload['servico'] ?? []);
        $tributacao = $this->array($payload['tributacao'] ?? []);
        $tributacaoMunicipal = $this->array($tributacao['municipal'] ?? []);
        $emitenteEndereco = $this->address($emitente);
        $tomadorEndereco = $this->address($tomador);
        $empresaConfig = $this->array($context['empresa_config'] ?? []);

        $mei = NfseNacionalTaxRegimeResolver::mei($emitente, $empresaConfig);
        $simplesNacional = NfseNacionalTaxRegimeResolver::simplesNacional($emitente, $empresaConfig, null, null, $mei);
        $opSimpNac = NfseNacionalTaxRegimeResolver::opSimpNac($emitente, $empresaConfig, $mei, $simplesNacional);
        $regApTribSN = NfseNacionalTaxRegimeResolver::regApTribSN($emitente, $empresaConfig, $opSimpNac);

        $tpRetISSQN = (string) ($tributacaoMunicipal['tipo_retencao_iss'] ?? '1');
        $enviarPAliq = $tributacaoMunicipal['enviar_aliquota_iss']
            ?? (array_key_exists('aliquota_iss', $tributacaoMunicipal) ? true : null);
        if (NfseNacionalTaxRegimeResolver::shouldSuppressPAliq(
            $opSimpNac,
            $regApTribSN,
            $tpRetISSQN,
            $this->dataGet($tributacaoMunicipal, 'beneficio_municipal.tipo_beneficio')
                ?? $this->dataGet($tributacaoMunicipal, 'beneficio_municipal.tpBM')
                ?? $this->dataGet($tributacaoMunicipal, 'beneficio_municipal.tipo')
        )) {
            $enviarPAliq = false;
        }

        $totais = $this->array($payload['totais'] ?? []);
        $valorServicos = $this->firstNumeric([
            $totais['valor_servicos'] ?? null,
            $totais['valor_documento'] ?? null,
        ]) ?? 0.0;
        $valores = $this->mapValores($totais);
        $tributacaoCore = $this->mapTributacao($tributacao);
        $ibscbs = $this->mapIbscbs($tributacao);
        $regApIbscbsSn = $this->dataGet($tributacao, 'ibs_cbs.regime_apuracao_simples_nacional');

        return array_filter([
            'tpAmb' => ($context['fiscal_environment'] ?? null) === 'producao' ? '1' : '2',
            'dhEmi' => $identificacao['data_emissao'] ?? null,
            'verAplic' => (string) ($context['ver_aplic'] ?? 'fiscal-platform-api'),
            'serie' => (string) ($identificacao['serie'] ?? '1'),
            'nDPS' => isset($identificacao['numero']) ? (string) $identificacao['numero'] : null,
            'dCompet' => $identificacao['data_competencia']
                ?? (isset($identificacao['data_emissao']) ? substr((string) $identificacao['data_emissao'], 0, 10) : null),
            'tpEmit' => '1',
            'cLocEmi' => $identificacao['municipio_ocorrencia_codigo']
                ?? $emitenteEndereco['codigo_municipio']
                ?? $this->dataGet($empresaConfig, 'nfse.codigo_ibge'),
            'prestador' => array_filter([
                'cnpj' => $this->fiscalDocument((string) ($emitente['cpf_cnpj'] ?? '')),
                'inscricaoMunicipal' => (string) ($emitente['inscricao_municipal'] ?? ''),
                'razaoSocial' => (string) ($emitente['razao_social'] ?? ''),
                'opSimpNac' => $opSimpNac,
                'regApTribSN' => $regApTribSN,
                'regApIBSCBSSN' => $regApIbscbsSn,
                'regEspTrib' => (string) ($emitente['regime_especial_tributacao'] ?? '0'),
                'codigoMunicipio' => (string) ($emitenteEndereco['codigo_municipio'] ?? $this->dataGet($empresaConfig, 'nfse.codigo_ibge') ?? ''),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'tomador' => [
                'documento' => $this->fiscalDocument((string) ($tomador['cpf_cnpj'] ?? '')),
                'razaoSocial' => (string) ($tomador['razao_social'] ?? ''),
                'email' => $tomador['email'] ?? null,
                'telefone' => $tomador['telefone'] ?? null,
                'endereco' => array_filter([
                    'logradouro' => $tomadorEndereco['logradouro'] ?? null,
                    'numero' => $tomadorEndereco['numero'] ?? null,
                    'complemento' => $tomadorEndereco['complemento'] ?? null,
                    'bairro' => $tomadorEndereco['bairro'] ?? null,
                    'cep' => isset($tomadorEndereco['cep']) ? $this->onlyDigits((string) $tomadorEndereco['cep']) : null,
                    'codigoMunicipio' => $tomadorEndereco['codigo_municipio'] ?? null,
                    'uf' => $tomadorEndereco['uf'] ?? null,
                    'municipio' => $tomadorEndereco['municipio'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
            'servico' => array_filter([
                'cLocPrestacao' => $servico['municipio_prestacao_codigo'] ?? $servico['codigo_municipio_prestacao'] ?? $identificacao['municipio_ocorrencia_codigo'] ?? null,
                'cTribNac' => $servico['codigo_servico_nacional'] ?? null,
                'cTribMun' => $servico['codigo_servico_municipal'] ?? null,
                'cNBS' => $servico['codigo_nbs'] ?? null,
                'cAtvSN' => $servico['codigo_atividade_simples_nacional'] ?? null,
                'descricao' => $servico['descricao'] ?? null,
                'tribISSQN' => $tributacaoMunicipal['tributacao_iss'] ?? '1',
                'tpRetISSQN' => $tpRetISSQN,
                'aliquota' => $tributacaoMunicipal['aliquota_iss'] ?? null,
                'enviarPAliq' => $enviarPAliq,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'valor_servicos' => $valorServicos,
            'valores' => $valores !== [] ? $valores : null,
            'tributacao' => $tributacaoCore !== [] ? $tributacaoCore : null,
            'ibscbs' => $ibscbs !== [] ? $ibscbs : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,mixed>  $totais
     * @return array<string,mixed>
     */
    private function mapValores(array $totais): array
    {
        return $this->filterEmpty([
            'vReceb' => $totais['valor_recebido'] ?? null,
            'vDescIncond' => $totais['desconto_incondicionado'] ?? null,
            'vDescCond' => $totais['desconto_condicionado'] ?? null,
            'deducao_reducao' => $this->filterEmpty([
                'percentual' => $this->dataGet($totais, 'deducao_reducao.percentual'),
                'valor' => $this->dataGet($totais, 'deducao_reducao.valor'),
            ]),
            'vAjusteBC' => $this->mapBaseAdjustment($this->array($totais['ajuste_base_calculo'] ?? [])),
        ]);
    }

    /** @param array<string,mixed> $adjustment @return array<string,mixed> */
    private function mapBaseAdjustment(array $adjustment): array
    {
        $documents = array_map(function (array $document): array {
            return $this->filterEmpty([
                'tpAjusteBC' => $document['tipo'] ?? null,
                'xTpAjusteBC' => $document['descricao'] ?? null,
                'vTotDoc' => $document['valor_total_documento'] ?? null,
                'vAjuteAplic' => $document['valor_ajuste_aplicado'] ?? null,
                'dtEmiDoc' => $document['data_emissao'] ?? null,
                'dtCompDoc' => $document['data_competencia'] ?? null,
                'dFeNacional' => $this->filterEmpty([
                    'tipoChaveDFe' => $this->dataGet($document, 'dfe_nacional.tipo_chave'),
                    'xTipoChaveDFe' => $this->dataGet($document, 'dfe_nacional.descricao_tipo_chave'),
                    'chaveDFe' => $this->dataGet($document, 'dfe_nacional.chave'),
                ]),
                'docFiscalOutro' => $this->filterEmpty([
                    'cMunDocFiscal' => $this->dataGet($document, 'documento_fiscal_outro.codigo_municipio'),
                    'nDocFiscal' => $this->dataGet($document, 'documento_fiscal_outro.numero'),
                    'xDocFiscal' => $this->dataGet($document, 'documento_fiscal_outro.descricao'),
                ]),
                'docOutro' => $this->filterEmpty([
                    'nDoc' => $this->dataGet($document, 'documento_outro.numero'),
                    'xDoc' => $this->dataGet($document, 'documento_outro.descricao'),
                ]),
                'fornec' => $this->array($document['fornecedor'] ?? []),
            ]);
        }, $this->arrayList($adjustment['documentos'] ?? []));

        return $this->filterEmpty([
            'pAjusteBCISSQN' => $adjustment['percentual_issqn'] ?? null,
            'vAjusteBCISSQN' => $adjustment['valor_issqn'] ?? null,
            'documentos' => $documents === [] ? null : ['docAjusteBC' => $documents],
        ]);
    }

    /**
     * @param  array<string,mixed>  $tributacao
     * @return array<string,mixed>
     */
    private function mapTributacao(array $tributacao): array
    {
        $municipal = $this->array($tributacao['municipal'] ?? []);
        $federal = $this->array($tributacao['federal'] ?? []);
        $total = $this->array($tributacao['total'] ?? []);

        return $this->filterEmpty([
            'municipal' => $this->filterEmpty([
                'tribISSQN' => $municipal['tributacao_iss'] ?? null,
                'cPaisResult' => $municipal['pais_resultado'] ?? null,
                'tpImunidade' => $municipal['tipo_imunidade'] ?? null,
                'exigSusp' => $this->filterEmpty([
                    'tpSusp' => $this->dataGet($municipal, 'exigibilidade_suspensa.tipo_suspensao'),
                    'nProcesso' => $this->dataGet($municipal, 'exigibilidade_suspensa.numero_processo'),
                ]),
                'BM' => $this->filterEmpty([
                    'nBM' => $this->dataGet($municipal, 'beneficio_municipal.numero_beneficio'),
                    'pRedBCBM' => $this->dataGet($municipal, 'beneficio_municipal.percentual_reducao_bc'),
                    'vRedBCBM' => $this->dataGet($municipal, 'beneficio_municipal.valor_reducao_bc'),
                ]),
                'tpRetISSQN' => $municipal['tipo_retencao_iss'] ?? null,
                'pAliq' => $municipal['aliquota_iss'] ?? null,
                'enviarPAliq' => $municipal['enviar_aliquota_iss'] ?? null,
            ]),
            'federal' => $this->filterEmpty([
                'piscofins' => $this->filterEmpty([
                    'CST' => $this->dataGet($federal, 'pis_cofins.cst'),
                    'vBCPisCofins' => $this->dataGet($federal, 'pis_cofins.base_calculo'),
                    'pAliqPis' => $this->dataGet($federal, 'pis_cofins.aliquota_pis'),
                    'pAliqCofins' => $this->dataGet($federal, 'pis_cofins.aliquota_cofins'),
                    'vPis' => $this->dataGet($federal, 'pis_cofins.valor_pis'),
                    'vCofins' => $this->dataGet($federal, 'pis_cofins.valor_cofins'),
                    'tpRetPisCofins' => $this->dataGet($federal, 'pis_cofins.tipo_retencao'),
                ]),
                'vRetCP' => $federal['valor_retido_cp'] ?? null,
                'vRetIRRF' => $federal['valor_retido_irrf'] ?? null,
                'vRetCSLL' => $federal['valor_retido_csll'] ?? null,
            ]),
            'total' => $this->mapTotalTributos($total),
        ]);
    }

    /**
     * @param  array<string,mixed>  $total
     * @return array<string,mixed>
     */
    private function mapTotalTributos(array $total): array
    {
        return $this->filterEmpty([
            'indTotTrib' => $total['indicador_sem_total'] ?? null,
            'pTotTribSN' => $total['percentual_simples_nacional'] ?? null,
            'pTotTrib' => $this->filterEmpty([
                'pTotTribFed' => $this->dataGet($total, 'percentuais.federal'),
                'pTotTribEst' => $this->dataGet($total, 'percentuais.estadual'),
                'pTotTribMun' => $this->dataGet($total, 'percentuais.municipal'),
            ]),
            'vTotTrib' => $this->filterEmpty([
                'vTotTribFed' => $this->dataGet($total, 'valores.federal'),
                'vTotTribEst' => $this->dataGet($total, 'valores.estadual'),
                'vTotTribMun' => $this->dataGet($total, 'valores.municipal'),
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $tributacao
     * @return array<string,mixed>
     */
    private function mapIbscbs(array $tributacao): array
    {
        $adicionais = $this->array($tributacao['adicionais'] ?? []);
        $ibscbs = $this->array($tributacao['ibs_cbs'] ?? []);
        $gIbscbs = $this->mapIbscbsTributos($ibscbs);
        $hasIbscbsSignal = $adicionais !== [] || $ibscbs !== [];
        $purpose = (string) ($adicionais['finalidade_nfse'] ?? '0');
        $adjustment = $this->array($ibscbs['ajuste'] ?? []);
        $trib = $purpose === '0'
            ? ['gIBSCBS' => $gIbscbs]
            : ['gIBSCBSAjuste' => $this->filterEmpty([
                'vIBS' => $adjustment['valor_ibs'] ?? null,
                'vCBS' => $adjustment['valor_cbs'] ?? null,
            ])];

        return $this->filterEmpty([
            'finNFSe' => $purpose,
            'tpNFSeDebito' => $adicionais['tipo_nfse_debito'] ?? null,
            'tpNFSeCredito' => $adicionais['tipo_nfse_credito'] ?? null,
            'indFinal' => $hasIbscbsSignal ? ($adicionais['ind_final'] ?? $this->mapIbscbsIndFinal($adicionais)) : null,
            'cIndOp' => $adicionais['codigo_indicador_operacao'] ?? null,
            'tpOper' => $adicionais['tipo_operacao'] ?? null,
            'gRefNFSe' => $this->filterEmpty([
                'refNFSe' => $adicionais['referencias_nfse'] ?? null,
            ]),
            'tpEnteGov' => $adicionais['tipo_ente_governamental'] ?? null,
            'indDest' => $adicionais['indicador_destinatario'] ?? null,
            'dest' => $this->filterEmpty([
                'documento' => $this->fiscalDocument((string) ($this->dataGet($adicionais, 'destinatario.cpf_cnpj') ?? '')),
                'razaoSocial' => $this->dataGet($adicionais, 'destinatario.razao_social'),
            ]),
            'imovel' => $this->mapRealEstate($this->array($ibscbs['imovel'] ?? [])),
            'bensMoveis' => $this->arrayList($ibscbs['bens_moveis'] ?? []),
            'gPgtoVinc' => $this->mapLinkedPayments($this->arrayList($ibscbs['pagamentos_vinculados'] ?? [])),
            'valores' => [
                'trib' => $trib,
            ],
        ]);
    }

    /** @param array<string,mixed> $imovel @return array<string,mixed> */
    private function mapRealEstate(array $imovel): array
    {
        $locacao = $this->array($imovel['locacao'] ?? []);
        $units = array_map(function (array $unit): array {
            $adjustments = array_map(fn (array $adjustment): array => $this->filterEmpty([
                'tpAjusteBCLocImoveis' => $adjustment['tipo'] ?? null,
                'xTpAjusteBCLocImoveis' => $adjustment['descricao'] ?? null,
                'vAjusteBCLocImoveis' => $adjustment['valor'] ?? null,
            ]), $this->arrayList($unit['ajustes_base_calculo'] ?? []));

            return $this->filterEmpty([
                'inscImobFisc' => $unit['inscricao_imobiliaria'] ?? null,
                'cCIB' => $unit['cib'] ?? null,
                'end' => $this->array($unit['endereco'] ?? []),
                'gAjusteBCLocImoveis' => $adjustments,
            ]);
        }, $this->arrayList($imovel['unidades'] ?? []));

        return $this->filterEmpty([
            'cMun' => $imovel['codigo_municipio'] ?? null,
            'gLocacao' => $this->filterEmpty([
                'pCopropriedade' => $locacao['percentual_copropriedade'] ?? null,
                'vTotOper' => $locacao['valor_total_operacao'] ?? null,
                'vDescIncondTot' => $locacao['desconto_incondicionado_total'] ?? null,
                'vDescCondTot' => $locacao['desconto_condicionado_total'] ?? null,
                'dVencOrig' => $locacao['data_vencimento_original'] ?? null,
            ]),
            'gUnidImob' => $units,
        ]);
    }

    /** @param list<array<string,mixed>> $payments @return array<string,mixed> */
    private function mapLinkedPayments(array $payments): array
    {
        $mapped = array_map(fn (array $payment): array => $this->filterEmpty([
            'nPag' => $payment['numero'] ?? null,
            'idTransacao' => $payment['id_transacao'] ?? null,
            'tpMeioPgto' => $payment['tipo_meio_pagamento'] ?? null,
            'CNPJReceb' => $this->fiscalDocument((string) ($payment['cnpj_recebedor'] ?? '')),
            'CNPJBasePSP' => $this->fiscalDocument((string) ($payment['cnpj_base_psp'] ?? '')),
        ]), $payments);

        return $mapped === [] ? [] : ['pgto' => $mapped];
    }

    /**
     * @param  array<string,mixed>  $ibscbs
     * @return array<string,mixed>
     */
    private function mapIbscbsTributos(array $ibscbs): array
    {
        $cst = is_scalar($ibscbs['cst'] ?? null) ? (string) $ibscbs['cst'] : null;
        $class = is_scalar($ibscbs['classe'] ?? null) ? (string) $ibscbs['classe'] : null;
        $normalizedCst = NfseNacionalIbscbsClassificationRules::normalizeCst($cst);
        $normalizedClass = NfseNacionalIbscbsClassificationRules::normalizeClass($class);

        $gIbscbs = $this->filterEmpty([
            'CST' => $cst,
            'cClassTrib' => $class,
            'cCredPres' => $ibscbs['codigo_credito_presumido'] ?? null,
        ]);

        if (NfseNacionalIbscbsClassificationRules::allowsTribRegular($normalizedClass, $normalizedCst)) {
            $regular = $this->filterEmpty([
                'CSTReg' => $this->dataGet($ibscbs, 'regular.cst'),
                'cClassTribReg' => $this->dataGet($ibscbs, 'regular.classe'),
            ]);
            if ($regular !== []) {
                $gIbscbs['gTribRegular'] = $regular;
            }
        }

        if (NfseNacionalIbscbsClassificationRules::allowsDiferimento($normalizedClass, $normalizedCst)) {
            $dif = $this->mapIbscbsDiferimento($ibscbs);
            if ($dif !== []) {
                $gIbscbs['gDif'] = $dif;
            }
        }

        return $gIbscbs;
    }

    /**
     * @param  array<string,mixed>  $adicionais
     */
    private function mapIbscbsIndFinal(array $adicionais): string
    {
        foreach (['uso_consumo_pessoal', 'operacao_uso_consumo_pessoal'] as $key) {
            if (array_key_exists($key, $adicionais)) {
                return $this->booleanIndicator($adicionais[$key]) ?? '0';
            }
        }

        return '0';
    }

    private function booleanIndicator(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            '1', 'true', 'sim', 's', 'yes', 'y' => '1',
            '0', 'false', 'nao', 'n', 'no' => '0',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $ibscbs
     * @return array<string,mixed>
     */
    private function mapIbscbsDiferimento(array $ibscbs): array
    {
        $pDifUf = $this->dataGet($ibscbs, 'diferimento.percentual_ibs_uf');
        $pDifMun = $this->dataGet($ibscbs, 'diferimento.percentual_ibs_municipal');
        $pDifCbs = $this->dataGet($ibscbs, 'diferimento.percentual_cbs');
        if ($pDifUf === null || $pDifMun === null || $pDifCbs === null) {
            return [];
        }

        if (! NfseNacionalIbscbsClassificationRules::hasEffectiveDiferimento($pDifUf, $pDifMun, $pDifCbs)) {
            return [];
        }

        return [
            'pDifUF' => $pDifUf,
            'pDifMun' => $pDifMun,
            'pDifCBS' => $pDifCbs,
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private function address(array $source): array
    {
        $address = $this->array($source['endereco'] ?? []);

        return array_filter([
            'logradouro' => $address['logradouro'] ?? $source['logradouro'] ?? null,
            'numero' => $address['numero'] ?? $source['numero'] ?? null,
            'complemento' => $address['complemento'] ?? $source['complemento'] ?? null,
            'bairro' => $address['bairro'] ?? $source['bairro'] ?? null,
            'municipio' => $address['municipio'] ?? $source['municipio'] ?? null,
            'uf' => isset($address['uf']) || isset($source['uf']) ? strtoupper((string) ($address['uf'] ?? $source['uf'])) : null,
            'cep' => $address['cep'] ?? $source['cep'] ?? null,
            'codigo_municipio' => $address['codigo_municipio'] ?? $source['codigo_municipio'] ?? $source['codigo_ibge'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstNumeric(array $candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function sumItems(array $items): float
    {
        return array_reduce(
            $items,
            static fn (float $sum, array $item): float => $sum + (float) ($item['valorTotal'] ?? $item['valor_total'] ?? 0),
            0.0
        );
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function filterEmpty(array $values): array
    {
        $filtered = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = array_is_list($value) ? $value : $this->filterEmpty($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function fiscalDocument(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($value)) ?? '';
    }

    /**
     * @return array<string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function dataGet(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
