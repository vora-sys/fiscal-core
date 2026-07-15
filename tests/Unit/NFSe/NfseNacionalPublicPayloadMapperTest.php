<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NfseNacionalPublicPayloadMapper;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;

class NfseNacionalPublicPayloadMapperTest extends TestCase
{
    public function test_maps_public_v2_payload_to_valid_national_dps_xml(): void
    {
        $payload = [
            'identificacao' => [
                'serie' => '1',
                'numero' => '42',
                'data_emissao' => '2026-06-20T10:00:00-03:00',
                'data_competencia' => '2026-06-20',
                'municipio_ocorrencia_codigo' => '1302603',
            ],
            'emitente' => [
                'cpf_cnpj' => '11222333000181',
                'razao_social' => 'Empresa Teste LTDA',
                'inscricao_municipal' => '12345',
                'opcao_simples_nacional' => false,
                'endereco' => [
                    'codigo_municipio' => '1302603',
                ],
            ],
            'tomador' => [
                'cpf_cnpj' => '33440118000147',
                'razao_social' => 'Cliente Teste LTDA',
                'email' => 'cliente@example.com',
                'endereco' => [
                    'logradouro' => 'Rua Teste',
                    'numero' => '100',
                    'bairro' => 'Centro',
                    'cep' => '69000000',
                    'codigo_municipio' => '1302603',
                    'uf' => 'AM',
                ],
            ],
            'itens' => [[
                'codigo' => 'SERV-001',
                'descricao' => 'Servico de suporte tecnico',
                'quantidade' => 1,
                'valor_unitario' => 1000,
                'valor_total' => 1000,
            ]],
            'servico' => [
                'codigo_servico_nacional' => '010701',
                'codigo_servico_municipal' => '0107',
                'codigo_municipio_prestacao' => '1302603',
                'descricao' => 'Servico de suporte tecnico',
            ],
            'totais' => [
                'valor_servicos' => 1000,
            ],
            'tributacao' => [
                'municipal' => [
                    'tributacao_iss' => '1',
                    'tipo_retencao_iss' => '1',
                    'aliquota_iss' => 2,
                    'enviar_aliquota_iss' => true,
                ],
                'federal' => [
                    'pis_cofins' => [
                        'cst' => '01',
                        'base_calculo' => 1000,
                        'aliquota_pis' => 1.65,
                        'aliquota_cofins' => 7.6,
                        'valor_pis' => 16.5,
                        'valor_cofins' => 76,
                        'tipo_retencao' => '3',
                    ],
                    'valor_retido_irrf' => 15,
                ],
                'adicionais' => [
                    'finalidade_nfse' => '0',
                    'indicador_final' => '0',
                    'codigo_indicador_operacao' => '1',
                    'indicador_destinatario' => '0',
                ],
                'ibs_cbs' => [
                    'cst' => '000',
                    'classe' => '000001',
                    'ibs_uf' => [
                        'dif' => ['percentual' => 0],
                    ],
                    'ibs_mun' => [
                        'dif' => ['percentual' => 0],
                    ],
                    'cbs' => [
                        'dif' => ['percentual' => 0],
                    ],
                    'regular' => [
                        'cst' => '000',
                        'classe' => '000001',
                    ],
                ],
            ],
        ];

        $mapped = (new NfseNacionalPublicPayloadMapper)->map($payload, [
            'provider_key' => 'nfse_nacional',
            'fiscal_environment' => 'homologacao',
            'empresa_config' => [
                'nfse' => ['codigo_ibge' => '1302603'],
            ],
        ]);

        $this->assertSame('2', $mapped['tpAmb']);
        $this->assertSame('1302603', $mapped['cLocEmi']);
        $this->assertSame('11222333000181', $mapped['prestador']['cnpj']);
        $this->assertSame('010701', $mapped['servico']['cTribNac']);
        $this->assertSame(1000.0, $mapped['valor_servicos']);
        $this->assertSame('0', $mapped['ibscbs']['indFinal']);
        $this->assertSame('1', $mapped['ibscbs']['cIndOp']);
        $this->assertSame('000', $mapped['ibscbs']['valores']['trib']['gIBSCBS']['CST']);
        $this->assertArrayNotHasKey('gTribRegular', $mapped['ibscbs']['valores']['trib']['gIBSCBS']);
        $this->assertArrayNotHasKey('gDif', $mapped['ibscbs']['valores']['trib']['gIBSCBS']);

        $provider = new NacionalProvider([
            'codigo_municipio' => '1302603',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ]);

        $xml = $provider->gerarXmlDpsPreview($mapped);
        $validation = $provider->validarDpsXml($xml);

        $this->assertStringContainsString('<IBSCBS>', $xml);
        $this->assertStringContainsString('<cIndOp>000001</cIndOp>', $xml);
        $this->assertStringNotContainsString('<gTribRegular>', $xml);
        $this->assertStringNotContainsString('<gDif>', $xml);
        $this->assertTrue(
            $validation['ok'],
            implode(PHP_EOL, array_column($validation['errors'], 'message'))
        );
    }

    public function test_maps_indicador_final_as_not_personal_use_unless_explicit_flag_is_sent(): void
    {
        $mapper = new NfseNacionalPublicPayloadMapper;

        $mapped = $mapper->map([
            'tributacao' => [
                'adicionais' => [
                    'finalidade_nfse' => '0',
                    'uso_consumo_pessoal' => false,
                    'codigo_indicador_operacao' => '050101',
                    'indicador_destinatario' => '0',
                ],
                'ibs_cbs' => [
                    'cst' => '000',
                    'classe' => '000001',
                ],
            ],
        ]);

        $this->assertSame('0', $mapped['ibscbs']['indFinal']);

        $mappedPersonalUse = $mapper->map([
            'tributacao' => [
                'adicionais' => [
                    'finalidade_nfse' => '0',
                    'uso_consumo_pessoal' => true,
                    'codigo_indicador_operacao' => '050101',
                    'indicador_destinatario' => '0',
                ],
                'ibs_cbs' => [
                    'cst' => '000',
                    'classe' => '000001',
                ],
            ],
        ]);

        $this->assertSame('1', $mappedPersonalUse['ibscbs']['indFinal']);
    }

    public function test_maps_public_v2_payload_preserves_ibscbs_groups_when_cst_allows(): void
    {
        $mapped = (new NfseNacionalPublicPayloadMapper)->map([
            'tributacao' => [
                'adicionais' => [
                    'finalidade_nfse' => '0',
                    'uso_consumo_pessoal' => false,
                    'codigo_indicador_operacao' => '050101',
                    'indicador_destinatario' => '0',
                ],
                'ibs_cbs' => [
                    'cst' => '101',
                    'classe' => '000001',
                    'diferimento' => [
                        'percentual_ibs_uf' => 1,
                        'percentual_ibs_municipal' => 2,
                        'percentual_cbs' => 3,
                    ],
                    'regular' => [
                        'cst' => '000',
                        'classe' => '000001',
                    ],
                ],
            ],
        ]);

        $gIbscbs = $mapped['ibscbs']['valores']['trib']['gIBSCBS'];

        $this->assertSame('101', $gIbscbs['CST']);
        $this->assertSame('000001', $gIbscbs['cClassTrib']);
        $this->assertSame('000', $gIbscbs['gTribRegular']['CSTReg']);
        $this->assertSame('000001', $gIbscbs['gTribRegular']['cClassTribReg']);
        $this->assertSame(3, $gIbscbs['gDif']['pDifCBS']);
    }
}
