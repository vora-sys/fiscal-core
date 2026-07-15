<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class NfseNacionalCanonicalPayload
{
    /**
     * @var array<string,mixed>
     */
    private const DIRECT_ROOT_SCHEMA = [
        'id' => true,
        'tpAmb' => true,
        'dhEmi' => true,
        'verAplic' => true,
        'serie' => true,
        'nDPS' => true,
        'dCompet' => true,
        'tpEmit' => true,
        'cLocEmi' => true,
        'subst' => [
            'chSubstda' => true,
            'cMotivo' => true,
            'xMotivo' => true,
        ],
        'prestador' => [
            'cnpj' => true,
            'inscricaoMunicipal' => true,
            'enviarIM' => true,
            'razaoSocial' => true,
            'opSimpNac' => true,
            'regApTribSN' => true,
            'regEspTrib' => true,
            'codigoMunicipio' => true,
        ],
        'tomador' => [
            'documento' => true,
            'razaoSocial' => true,
            'email' => true,
            'telefone' => true,
            'endereco' => [
                'logradouro' => true,
                'numero' => true,
                'complemento' => true,
                'bairro' => true,
                'cep' => true,
                'codigoMunicipio' => true,
                'uf' => true,
                'municipio' => true,
            ],
        ],
        'servico' => [
            'cLocPrestacao' => true,
            'cTribNac' => true,
            'cTribMun' => true,
            'cNBS' => true,
            'descricao' => true,
            'tribISSQN' => true,
            'tpRetISSQN' => true,
            'aliquota' => true,
            'enviarPAliq' => true,
        ],
        'valor_servicos' => true,
        'valores' => [
            'vReceb' => true,
            'vDescIncond' => true,
            'vDescCond' => true,
            'deducao_reducao' => [
                'percentual' => true,
                'valor' => true,
            ],
        ],
        'tributacao' => [
            'municipal' => [
                'tribISSQN' => true,
                'cPaisResult' => true,
                'tpImunidade' => true,
                'exigSusp' => [
                    'tpSusp' => true,
                    'nProcesso' => true,
                ],
                'BM' => [
                    'nBM' => true,
                    'pRedBCBM' => true,
                    'vRedBCBM' => true,
                ],
                'tpRetISSQN' => true,
                'pAliq' => true,
                'enviarPAliq' => true,
            ],
            'federal' => [
                'piscofins' => [
                    'CST' => true,
                    'vBCPisCofins' => true,
                    'pAliqPis' => true,
                    'pAliqCofins' => true,
                    'vPis' => true,
                    'vCofins' => true,
                    'tpRetPisCofins' => true,
                ],
                'vRetCP' => true,
                'vRetIRRF' => true,
                'vRetCSLL' => true,
            ],
            'total' => [
                'indTotTrib' => true,
                'pTotTribSN' => true,
                'pTotTrib' => [
                    'pTotTribFed' => true,
                    'pTotTribEst' => true,
                    'pTotTribMun' => true,
                ],
                'vTotTrib' => [
                    'vTotTribFed' => true,
                    'vTotTribEst' => true,
                    'vTotTribMun' => true,
                ],
            ],
        ],
        'ibscbs' => [
            'finNFSe' => true,
            'indFinal' => true,
            'cIndOp' => true,
            'tpOper' => true,
            'gRefNFSe' => [
                'refNFSe' => true,
            ],
            'tpEnteGov' => true,
            'indDest' => true,
            'dest' => [
                'documento' => true,
                'razaoSocial' => true,
            ],
            'valores' => [
                'trib' => [
                    'gIBSCBS' => [
                        'CST' => true,
                        'cClassTrib' => true,
                        'cCredPres' => true,
                        'gTribRegular' => [
                            'CSTReg' => true,
                            'cClassTribReg' => true,
                        ],
                        'gDif' => [
                            'pDifUF' => true,
                            'pDifMun' => true,
                            'pDifCBS' => true,
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * @var array<string,list<string>>
     */
    private const LEGACY_PATH_HINTS = [
        'amount' => ['valor_servicos'],
        'valor' => ['valor_servicos'],
        'total' => ['valor_servicos'],
        'nota' => ['tpAmb', 'dhEmi', 'prestador', 'tomador', 'servico', 'valor_servicos'],
        'prestador.cpfCnpj' => ['prestador.cnpj'],
        'prestador.documento' => ['prestador.cnpj'],
        'prestador.nome' => ['prestador.razaoSocial'],
        'prestador.razao_social' => ['prestador.razaoSocial'],
        'prestador.omitirIM' => ['prestador.enviarIM'],
        'prestador.reg_ap_trib_sn' => ['prestador.regApTribSN'],
        'prestador.regime_apuracao_sn' => ['prestador.regApTribSN'],
        'tomador.cpfCnpj' => ['tomador.documento'],
        'tomador.document' => ['tomador.documento'],
        'tomador.nome' => ['tomador.razaoSocial'],
        'tomador.name' => ['tomador.razaoSocial'],
        'tomador.razao_social' => ['tomador.razaoSocial'],
        'tomador.logradouro' => ['tomador.endereco.logradouro'],
        'tomador.numero' => ['tomador.endereco.numero'],
        'tomador.complemento' => ['tomador.endereco.complemento'],
        'tomador.bairro' => ['tomador.endereco.bairro'],
        'tomador.cep' => ['tomador.endereco.cep'],
        'tomador.codigoMunicipio' => ['tomador.endereco.codigoMunicipio'],
        'tomador.codigo_municipio' => ['tomador.endereco.codigoMunicipio'],
        'tomador.uf' => ['tomador.endereco.uf'],
        'tomador.municipio' => ['tomador.endereco.municipio'],
        'tomador.address' => ['tomador.endereco'],
        'servico.servicoItemLista' => ['servico.cTribNac', 'servico.cTribMun'],
        'servico.itemListaServico' => ['servico.cTribNac', 'servico.cTribMun'],
        'servico.codigoServico' => ['servico.cTribMun'],
        'servico.codigoTributacaoMunicipal' => ['servico.cTribMun'],
        'servico.codigoServicoNacional' => ['servico.cTribNac'],
        'servico.codigoTributacaoNacional' => ['servico.cTribNac'],
        'servico.codigoNbs' => ['servico.cNBS'],
        'servico.codigoNBS' => ['servico.cNBS'],
        'servico.valor_irrf' => ['tributacao.federal.vRetIRRF'],
        'servico.valor_ir' => ['tributacao.federal.vRetIRRF'],
        'servico.iss_retido' => ['servico.tpRetISSQN'],
        'valores.desconto_incondicionado' => ['valores.vDescIncond'],
        'valores.desconto_condicionado' => ['valores.vDescCond'],
        'valores.deducao_reducao.pDR' => ['valores.deducao_reducao.percentual'],
        'valores.deducao_reducao.vDR' => ['valores.deducao_reducao.valor'],
        'tributacao.municipal.aliquota' => ['tributacao.municipal.pAliq'],
        'tributacao.federal.piscofins.cst' => ['tributacao.federal.piscofins.CST'],
    ];

    /**
     * @return list<string>
     */
    public static function expectedRootFields(): array
    {
        return [
            'id',
            'tpAmb',
            'dhEmi',
            'verAplic',
            'serie',
            'nDPS',
            'dCompet',
            'tpEmit',
            'cLocEmi',
            'subst.chSubstda',
            'subst.cMotivo',
            'subst.xMotivo',
            'prestador.cnpj',
            'prestador.inscricaoMunicipal',
            'prestador.enviarIM',
            'prestador.razaoSocial',
            'prestador.opSimpNac',
            'prestador.regApTribSN',
            'prestador.regEspTrib',
            'prestador.codigoMunicipio',
            'tomador.documento',
            'tomador.razaoSocial',
            'tomador.email',
            'tomador.telefone',
            'tomador.endereco.logradouro',
            'tomador.endereco.numero',
            'tomador.endereco.complemento',
            'tomador.endereco.bairro',
            'tomador.endereco.cep',
            'tomador.endereco.codigoMunicipio',
            'tomador.endereco.uf',
            'tomador.endereco.municipio',
            'servico.cLocPrestacao',
            'servico.cTribNac',
            'servico.cTribMun',
            'servico.cNBS',
            'servico.descricao',
            'servico.tribISSQN',
            'servico.tpRetISSQN',
            'servico.aliquota',
            'servico.enviarPAliq',
            'valor_servicos',
            'valores.vReceb',
            'valores.vDescIncond',
            'valores.vDescCond',
            'valores.deducao_reducao.percentual',
            'valores.deducao_reducao.valor',
            'tributacao.municipal.tribISSQN',
            'tributacao.municipal.cPaisResult',
            'tributacao.municipal.tpImunidade',
            'tributacao.municipal.exigSusp.tpSusp',
            'tributacao.municipal.exigSusp.nProcesso',
            'tributacao.municipal.BM.nBM',
            'tributacao.municipal.BM.pRedBCBM',
            'tributacao.municipal.BM.vRedBCBM',
            'tributacao.municipal.tpRetISSQN',
            'tributacao.municipal.pAliq',
            'tributacao.municipal.enviarPAliq',
            'tributacao.federal.piscofins.CST',
            'tributacao.federal.piscofins.vBCPisCofins',
            'tributacao.federal.piscofins.pAliqPis',
            'tributacao.federal.piscofins.pAliqCofins',
            'tributacao.federal.piscofins.vPis',
            'tributacao.federal.piscofins.vCofins',
            'tributacao.federal.piscofins.tpRetPisCofins',
            'tributacao.federal.vRetCP',
            'tributacao.federal.vRetIRRF',
            'tributacao.federal.vRetCSLL',
            'tributacao.total.indTotTrib',
            'tributacao.total.pTotTribSN',
            'tributacao.total.pTotTrib.pTotTribFed',
            'tributacao.total.pTotTrib.pTotTribEst',
            'tributacao.total.pTotTrib.pTotTribMun',
            'tributacao.total.vTotTrib.vTotTribFed',
            'tributacao.total.vTotTrib.vTotTribEst',
            'tributacao.total.vTotTrib.vTotTribMun',
            'ibscbs.finNFSe',
            'ibscbs.indFinal',
            'ibscbs.cIndOp',
            'ibscbs.tpOper',
            'ibscbs.gRefNFSe.refNFSe',
            'ibscbs.tpEnteGov',
            'ibscbs.indDest',
            'ibscbs.dest.documento',
            'ibscbs.dest.razaoSocial',
            'ibscbs.valores.trib.gIBSCBS.CST',
            'ibscbs.valores.trib.gIBSCBS.cClassTrib',
            'ibscbs.valores.trib.gIBSCBS.cCredPres',
            'ibscbs.valores.trib.gIBSCBS.gTribRegular.CSTReg',
            'ibscbs.valores.trib.gIBSCBS.gTribRegular.cClassTribReg',
            'ibscbs.valores.trib.gIBSCBS.gDif.pDifUF',
            'ibscbs.valores.trib.gIBSCBS.gDif.pDifMun',
            'ibscbs.valores.trib.gIBSCBS.gDif.pDifCBS',
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    public static function unexpectedPaths(array $payload): array
    {
        return array_column(self::validationIssues($payload), 'path');
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{
     *     path:string,
     *     reason:string,
     *     received:mixed,
     *     received_type:string,
     *     expected:list<string>,
     *     expected_type:string|null,
     *     message:string
     * }>
     */
    public static function validationIssues(array $payload): array
    {
        $issues = self::collectInvalidIssues($payload, self::DIRECT_ROOT_SCHEMA);
        ksort($issues);

        return array_values($issues);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $schema
     * @return array<string,array{
     *     path:string,
     *     reason:string,
     *     received:mixed,
     *     received_type:string,
     *     expected:list<string>,
     *     expected_type:string|null,
     *     message:string
     * }>
     */
    private static function collectInvalidIssues(array $payload, array $schema, string $prefix = ''): array
    {
        $invalid = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (!array_key_exists($key, $schema)) {
                $invalid[$path] = self::buildUnexpectedFieldIssue($path, $value, $schema, $prefix);
                continue;
            }

            $childSchema = $schema[$key];
            if ($childSchema === true) {
                continue;
            }

            if (!is_array($value) || array_is_list($value)) {
                $invalid[$path] = self::buildInvalidStructureIssue($path, $value, $childSchema);
                continue;
            }

            $invalid += self::collectInvalidIssues($value, $childSchema, $path);
        }

        return $invalid;
    }

    /**
     * @param array<string,mixed> $parentSchema
     * @return array{
     *     path:string,
     *     reason:string,
     *     received:mixed,
     *     received_type:string,
     *     expected:list<string>,
     *     expected_type:string|null,
     *     message:string
     * }
     */
    private static function buildUnexpectedFieldIssue(string $path, mixed $value, array $parentSchema, string $prefix): array
    {
        $expected = self::expectedPathsForUnexpectedField($path, $parentSchema, $prefix);

        return [
            'path' => $path,
            'reason' => 'unexpected_field',
            'received' => self::normalizeReceivedValue($value),
            'received_type' => self::valueType($value),
            'expected' => $expected,
            'expected_type' => null,
            'message' => self::buildIssueMessage($path, $value, $expected),
        ];
    }

    /**
     * @param array<string,mixed> $childSchema
     * @return array{
     *     path:string,
     *     reason:string,
     *     received:mixed,
     *     received_type:string,
     *     expected:list<string>,
     *     expected_type:string|null,
     *     message:string
     * }
     */
    private static function buildInvalidStructureIssue(string $path, mixed $value, array $childSchema): array
    {
        $expected = self::schemaPaths($childSchema, $path);

        return [
            'path' => $path,
            'reason' => 'invalid_structure',
            'received' => self::normalizeReceivedValue($value),
            'received_type' => self::valueType($value),
            'expected' => $expected,
            'expected_type' => 'object',
            'message' => sprintf(
                'Campo "%s" recebeu %s, mas o contrato espera um objeto com %s.',
                $path,
                self::valuePreview($value),
                self::joinExpectedFields($expected)
            ),
        ];
    }

    /**
     * @param array<string,mixed> $parentSchema
     * @return list<string>
     */
    private static function expectedPathsForUnexpectedField(string $path, array $parentSchema, string $prefix): array
    {
        if (isset(self::LEGACY_PATH_HINTS[$path])) {
            return self::LEGACY_PATH_HINTS[$path];
        }

        $paths = [];
        foreach (array_keys($parentSchema) as $field) {
            $paths[] = $prefix === '' ? (string) $field : $prefix . '.' . $field;
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param list<string> $expected
     */
    private static function buildIssueMessage(string $path, mixed $value, array $expected): string
    {
        if ($expected === []) {
            return sprintf(
                'Campo "%s" não é aceito no contrato canônico. Recebido: %s.',
                $path,
                self::valuePreview($value)
            );
        }

        return sprintf(
            'Campo "%s" não é aceito no contrato canônico. Recebido: %s. Use %s.',
            $path,
            self::valuePreview($value),
            self::joinExpectedFields($expected)
        );
    }

    /**
     * @param array<string,mixed> $schema
     * @return list<string>
     */
    private static function schemaPaths(array $schema, string $prefix): array
    {
        $paths = [];
        foreach (array_keys($schema) as $field) {
            $paths[] = $prefix . '.' . $field;
        }

        sort($paths);

        return $paths;
    }

    private static function normalizeReceivedValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 180 ? mb_substr($value, 0, 177) . '...' : $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['_type' => 'array', 'length' => count($value)];
            }

            return [
                '_type' => 'object',
                'keys' => array_slice(array_map('strval', array_keys($value)), 0, 12),
            ];
        }

        if (is_object($value)) {
            return ['_type' => 'object', 'class' => $value::class];
        }

        return $value;
    }

    private static function valueType(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            is_object($value) => 'object',
            default => gettype($value),
        };
    }

    private static function valuePreview(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => '"' . (mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '...' : $value) . '"',
            is_array($value) && array_is_list($value) => sprintf('lista com %d item(ns)', count($value)),
            is_array($value) => sprintf(
                'objeto com chaves [%s]',
                implode(', ', array_slice(array_map('strval', array_keys($value)), 0, 6))
            ),
            is_object($value) => 'objeto ' . $value::class,
            default => self::valueType($value),
        };
    }

    /**
     * @param list<string> $expected
     */
    private static function joinExpectedFields(array $expected): string
    {
        $quoted = array_map(static fn (string $field): string => '"' . $field . '"', $expected);
        $count = count($quoted);

        if ($count === 0) {
            return 'os campos canônicos permitidos';
        }

        if ($count === 1) {
            return $quoted[0];
        }

        if ($count === 2) {
            return $quoted[0] . ' ou ' . $quoted[1];
        }

        return implode(', ', array_slice($quoted, 0, -1)) . ' ou ' . $quoted[$count - 1];
    }
}
