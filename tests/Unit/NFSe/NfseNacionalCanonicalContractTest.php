<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NfseNacionalCanonicalContract;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NfseNacionalContractException;

class NfseNacionalCanonicalContractTest extends TestCase
{
    public function test_accepts_canonical_payload_without_semantic_validation(): void
    {
        NfseNacionalCanonicalContract::assertCanonical([
            'tpAmb' => '9',
            'dhEmi' => 'invalid-date',
            'verAplic' => 'app',
            'serie' => 'ABC',
            'dCompet' => 'invalid',
            'tpEmit' => '9',
            'cLocEmi' => '1',
            'prestador' => [
                'cnpj' => '123',
                'inscricaoMunicipal' => '123',
                'enviarIM' => true,
                'opSimpNac' => '3',
                'regApTribSN' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '1302603',
            ],
            'tomador' => [
                'documento' => '123',
                'razaoSocial' => 'Cliente',
                'endereco' => [
                    'logradouro' => 'Rua A',
                    'numero' => '1',
                    'bairro' => 'Centro',
                    'cep' => '1',
                    'codigoMunicipio' => '1302603',
                    'uf' => 'AM',
                    'municipio' => 'Manaus',
                ],
            ],
            'servico' => [
                'cLocPrestacao' => '1',
                'cTribNac' => '12',
                'cTribMun' => '001',
                'cNBS' => '123456789',
                'descricao' => 'Servico',
                'tribISSQN' => '9',
                'tpRetISSQN' => '9',
                'aliquota' => 0,
                'enviarPAliq' => true,
            ],
            'valor_servicos' => 200,
            'valores' => [
                'vReceb' => 180,
                'vDescIncond' => 5,
                'deducao_reducao' => [
                    'valor' => 10,
                ],
            ],
            'tributacao' => [
                'municipal' => [
                    'tribISSQN' => '1',
                    'cPaisResult' => '1058',
                    'exigSusp' => [
                        'tpSusp' => '1',
                        'nProcesso' => 'PROC-123',
                    ],
                    'BM' => [
                        'nBM' => 'BM123',
                        'pRedBCBM' => 10,
                    ],
                    'tpRetISSQN' => '2',
                    'pAliq' => 5,
                ],
                'federal' => [
                    'piscofins' => [
                        'CST' => '01',
                        'vBCPisCofins' => 200,
                        'pAliqPis' => 1.65,
                        'pAliqCofins' => 7.6,
                        'vPis' => 3.3,
                        'vCofins' => 15.2,
                        'tpRetPisCofins' => '3',
                    ],
                    'vRetCP' => 2,
                    'vRetIRRF' => 3,
                    'vRetCSLL' => 4,
                ],
                'total' => [
                    'vTotTrib' => [
                        'vTotTribFed' => 20,
                        'vTotTribEst' => 0,
                        'vTotTribMun' => 10,
                    ],
                ],
            ],
            'ibscbs' => [
                'finNFSe' => '0',
                'cIndOp' => '1',
                'indDest' => '0',
                'valores' => [
                    'trib' => [
                        'gIBSCBS' => [
                            'CST' => '000',
                            'cClassTrib' => '000001',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue(true);
    }

    public function test_rejects_legacy_aliases_and_unexpected_paths(): void
    {
        $this->expectException(NfseNacionalContractException::class);
        $this->expectExceptionMessage(NfseNacionalCanonicalContract::INVALID_MESSAGE);

        try {
            NfseNacionalCanonicalContract::assertCanonical([
                'amount' => 200,
                'serie' => '1',
                'prestador' => [
                    'cnpj' => '01824852000166',
                    'cpfCnpj' => '01824852000166',
                ],
                'tomador' => [
                    'razaoSocial' => 'Cliente',
                    'address' => [
                        'zip_code' => '69000000',
                    ],
                ],
                'servico' => [
                    'cTribNac' => '140101',
                    'servicoItemLista' => '140101',
                ],
                'valor_servicos' => 200,
            ]);
        } catch (NfseNacionalContractException $e) {
            $this->assertSame([
                'amount',
                'prestador.cpfCnpj',
                'servico.servicoItemLista',
                'tomador.address',
            ], $e->details()['legacy_fields']);
            $this->assertSame('amount', $e->details()['invalid_fields'][0]['path']);
            $this->assertSame(200, $e->details()['invalid_fields'][0]['received']);
            $this->assertSame(['valor_servicos'], $e->details()['invalid_fields'][0]['expected']);
            $this->assertStringContainsString('Campo "amount"', $e->details()['summary']);

            throw $e;
        }
    }

    public function test_rejects_removed_internal_aliases_with_hints(): void
    {
        $this->expectException(NfseNacionalContractException::class);

        try {
            NfseNacionalCanonicalContract::assertCanonical([
                'prestador' => [
                    'cnpj' => '01824852000166',
                    'omitirIM' => false,
                    'reg_ap_trib_sn' => '1',
                ],
                'servico' => [
                    'cTribNac' => '140101',
                    'valor_irrf' => 10,
                    'iss_retido' => false,
                ],
                'valores' => [
                    'desconto_incondicionado' => 5,
                    'deducao_reducao' => [
                        'pDR' => 10,
                    ],
                ],
                'tributacao' => [
                    'municipal' => [
                        'aliquota' => 5,
                    ],
                    'federal' => [
                        'piscofins' => [
                            'cst' => '01',
                        ],
                    ],
                ],
            ]);
        } catch (NfseNacionalContractException $e) {
            $this->assertSame([
                'prestador.omitirIM',
                'prestador.reg_ap_trib_sn',
                'servico.iss_retido',
                'servico.valor_irrf',
                'tributacao.federal.piscofins.cst',
                'tributacao.municipal.aliquota',
                'valores.deducao_reducao.pDR',
                'valores.desconto_incondicionado',
            ], $e->details()['legacy_fields']);
            $this->assertSame(['prestador.enviarIM'], $e->details()['invalid_fields'][0]['expected']);
            $this->assertNotContains('prestador.omitirIM', $e->details()['expected_fields']);
            $this->assertNotContains('servico.valor_irrf', $e->details()['expected_fields']);
            $this->assertNotContains('tributacao.federal.piscofins.cst', $e->details()['expected_fields']);

            throw $e;
        }
    }

    public function test_provider_policy_keeps_only_exact_canonical_paths(): void
    {
        $policy = NfseNacionalCanonicalContract::canonicalizeProviderPolicy([
            'required_fields' => [
                'servico.cTribNac',
                'servico.cNBS',
                'prestador.opSimpNac',
                'service.cnae_code',
            ],
            'visible_fields' => [
                'servico.cTribMun',
                'servico.cTribNac',
                'servico.cNBS',
                'prestador.opSimpNac',
                'prestador.mei',
            ],
            'field_schema' => [
                'servico.cTribNac' => [
                    'label' => 'Codigo nacional',
                    'control' => 'text',
                    'payload_paths' => ['nota.itens.*.cTribNac'],
                ],
                'service.nbs' => [
                    'label' => 'NBS legado',
                ],
            ],
        ]);

        $this->assertSame(
            ['servico.cTribNac', 'servico.cNBS', 'prestador.opSimpNac'],
            $policy['required_fields']
        );
        $this->assertSame(
            ['servico.cTribMun', 'servico.cTribNac', 'servico.cNBS', 'prestador.opSimpNac'],
            $policy['visible_fields']
        );
        $this->assertSame(['servico.cTribNac'], $policy['field_schema']['servico.cTribNac']['payload_paths']);
        $this->assertSame(['servico.cTribMun'], $policy['field_schema']['servico.cTribMun']['payload_paths']);
        $this->assertSame(['servico.cNBS'], $policy['field_schema']['servico.cNBS']['payload_paths']);
        $this->assertSame(['prestador.opSimpNac'], $policy['field_schema']['prestador.opSimpNac']['payload_paths']);
    }
}
