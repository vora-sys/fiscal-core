<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;

class NotaFiscalBuilderTest extends TestCase
{
    public function testFromArrayComDadosCompletos()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41,
                'cNF' => 12345678,
                'natOp' => 'VENDA',
                'mod' => 65,
                'serie' => 1,
                'nNF' => 123,
                'cMunFG' => 4106902,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190',
                'razaoSocial' => 'EMPRESA TESTE',
                'nomeFantasia' => 'TESTE',
                'inscricaoEstadual' => '123',
                'logradouro' => 'RUA',
                'numero' => '1',
                'bairro' => 'BAIRRO',
                'codigoMunicipio' => '123',
                'municipio' => 'CIDADE',
                'uf' => 'UF',
                'cep' => '12345',
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678901',
                'nome' => 'CONSUMIDOR',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'PROD001',
                        'descricao' => 'PRODUTO',
                        'ncm' => '12345678',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1.0,
                        'valorUnitario' => 10.00,
                        'valorTotal' => 10.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                        'pis' => ['cst' => '49'],
                        'cofins' => ['cst' => '49'],
                    ],
                ],
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 10.00],
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        $this->assertInstanceOf(NotaFiscal::class, $nota);
        $this->assertTrue($nota->hasNode('identificacao'));
        $this->assertTrue($nota->hasNode('emitente'));
        $this->assertTrue($nota->hasNode('destinatario'));
        $this->assertTrue($nota->hasNode('produto'));
        $this->assertTrue($nota->hasNode('imposto'));
        $this->assertTrue($nota->hasNode('pagamento'));
    }

    public function testFromArrayComMultiplosItens()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41, 'cNF' => 12345678, 'natOp' => 'VENDA',
                'mod' => 65, 'serie' => 1, 'nNF' => 123,
                'cMunFG' => 4106902, 'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190', 'razaoSocial' => 'EMPRESA',
                'nomeFantasia' => '', 'inscricaoEstadual' => '123',
                'logradouro' => 'RUA', 'numero' => '1', 'bairro' => 'B',
                'codigoMunicipio' => '123', 'municipio' => 'C',
                'uf' => 'UF', 'cep' => '12345',
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678901',
                'nome' => 'CONSUMIDOR',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'PROD001', 'descricao' => 'PRODUTO 1',
                        'ncm' => '12345678', 'cfop' => '5102', 'unidade' => 'UN',
                        'quantidade' => 2.0, 'valorUnitario' => 10.00, 'valorTotal' => 20.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                    ],
                ],
                [
                    'produto' => [
                        'codigo' => 'PROD002', 'descricao' => 'PRODUTO 2',
                        'ncm' => '87654321', 'cfop' => '5102', 'unidade' => 'UN',
                        'quantidade' => 1.0, 'valorUnitario' => 15.00, 'valorTotal' => 15.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                    ],
                ],
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 35.00],
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        $this->assertInstanceOf(NotaFiscal::class, $nota);
        // Os itens são adicionados sobrescrevendo (último item prevalece)
        $this->assertTrue($nota->hasNode('produto'));
    }

    public function testBuildRetornaNotaFiscal()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41, 'cNF' => 12345678, 'natOp' => 'VENDA',
                'mod' => 65, 'serie' => 1, 'nNF' => 123,
                'cMunFG' => 4106902, 'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190', 'razaoSocial' => 'EMPRESA',
                'nomeFantasia' => '', 'inscricaoEstadual' => '123',
                'logradouro' => 'RUA', 'numero' => '1', 'bairro' => 'B',
                'codigoMunicipio' => '123', 'municipio' => 'C',
                'uf' => 'UF', 'cep' => '12345',
            ],
        ];

        $builder = NotaFiscalBuilder::fromArray($dados);
        $nota = $builder->build();

        $this->assertInstanceOf(NotaFiscal::class, $nota);
    }

    public function testSerializaIbsCbsNoXmlDaNota()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 35,
                'cNF' => 12345678,
                'natOp' => 'VENDA',
                'mod' => 55,
                'serie' => 1,
                'nNF' => 123,
                'cMunFG' => 3550308,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190',
                'razaoSocial' => 'EMPRESA TESTE',
                'nomeFantasia' => 'TESTE',
                'inscricaoEstadual' => '123456789',
                'logradouro' => 'RUA TESTE',
                'numero' => '100',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '3550308',
                'municipio' => 'SAO PAULO',
                'uf' => 'SP',
                'cep' => '01001000',
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678901',
                'nome' => 'CONSUMIDOR TESTE',
                'logradouro' => 'RUA DESTINO',
                'numero' => '200',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '3550308',
                'municipio' => 'SAO PAULO',
                'uf' => 'SP',
                'cep' => '01001000',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'PROD001',
                        'descricao' => 'PRODUTO',
                        'ncm' => '12345678',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1.0,
                        'valorUnitario' => 100.00,
                        'valorTotal' => 100.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                        'pis' => ['cst' => '07'],
                        'cofins' => ['cst' => '07'],
                        'ibs_cbs' => [
                            'cst' => '000',
                            'classe' => '000001',
                            'base_calculo' => 100.00,
                            'ibs_uf' => [
                                'aliquota' => 0.1,
                                'valor' => 1.00,
                                'dif' => ['percentual' => 10.0, 'valor' => 0.10],
                            ],
                            'ibs_mun' => [
                                'aliquota' => 0.2,
                                'valor' => 2.00,
                            ],
                            'cbs' => [
                                'aliquota' => 0.9,
                                'valor' => 9.00,
                            ],
                            'regular' => [
                                'cst' => '000',
                                'classe' => '000001',
                                'ibs_uf' => ['aliquota' => 0.1, 'valor' => 1.00],
                                'ibs_mun' => ['aliquota' => 0.2, 'valor' => 2.00],
                                'cbs' => ['aliquota' => 0.9, 'valor' => 9.00],
                            ],
                        ],
                    ],
                ],
            ],
            'totais' => [
                'vBC' => 0.00,
                'vICMS' => 0.00,
                'vPIS' => 0.00,
                'vCOFINS' => 0.00,
                'vProd' => 100.00,
                'vNF' => 100.00,
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 100.00],
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        $this->assertTrue($nota->hasNode('ibs_cbs'));

        $xml = $nota->toXml();

        $this->assertStringContainsString('<IBSCBS>', $xml);
        $this->assertStringContainsString('<CST>000</CST>', $xml);
        $this->assertStringContainsString('<cClassTrib>000001</cClassTrib>', $xml);
        $this->assertStringContainsString('<vBC>100.00</vBC>', $xml);
        $this->assertStringContainsString('<pIBSUF>0.1000</pIBSUF>', $xml);
        $this->assertStringContainsString('<vIBSUF>1.00</vIBSUF>', $xml);
        $this->assertStringContainsString('<vIBSMun>2.00</vIBSMun>', $xml);
        $this->assertStringContainsString('<vCBS>9.00</vCBS>', $xml);
        $this->assertStringContainsString('<gTribRegular>', $xml);
        $this->assertStringContainsString('<IBSCBSTot>', $xml);
    }

    public function testSerializaImpostoSeletivoEGruposAvancadosRtc()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 35,
                'cNF' => 12345678,
                'natOp' => 'VENDA',
                'mod' => 55,
                'serie' => 1,
                'nNF' => 124,
                'cMunFG' => 3550308,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190',
                'razaoSocial' => 'EMPRESA TESTE',
                'nomeFantasia' => 'TESTE',
                'inscricaoEstadual' => '123456789',
                'logradouro' => 'RUA TESTE',
                'numero' => '100',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '3550308',
                'municipio' => 'SAO PAULO',
                'uf' => 'SP',
                'cep' => '01001000',
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678901',
                'nome' => 'CONSUMIDOR TESTE',
                'logradouro' => 'RUA DESTINO',
                'numero' => '200',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '3550308',
                'municipio' => 'SAO PAULO',
                'uf' => 'SP',
                'cep' => '01001000',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'PROD-RTC',
                        'descricao' => 'PRODUTO RTC',
                        'ncm' => '12345678',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1.0,
                        'valorUnitario' => 100.00,
                        'valorTotal' => 100.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                        'pis' => ['cst' => '07'],
                        'cofins' => ['cst' => '07'],
                        'is' => [
                            'cst' => '000',
                            'classe' => '000001',
                            'base_calculo' => 100.00,
                            'aliquota' => 5.00,
                            'valor' => 5.00,
                        ],
                        'ibs_cbs' => [
                            'cst' => '550',
                            'classe' => '550001',
                            'transferencia_credito' => [
                                'ibs' => ['valor' => 1.23],
                                'cbs' => ['valor' => 4.56],
                            ],
                            'estorno_credito' => [
                                'ibs' => ['valor' => 0.11],
                                'cbs' => ['valor' => 0.22],
                            ],
                            'credito_presumido_zfm' => [
                                'competencia' => '2026-01',
                                'tipo' => '1',
                                'valor' => 7.89,
                            ],
                            'dfe_referenciado' => [
                                'chave_acesso' => '35260112345678000190550010000001241000000010',
                                'item_referenciado' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            'totais' => [
                'vBC' => 0.00,
                'vICMS' => 0.00,
                'vPIS' => 0.00,
                'vCOFINS' => 0.00,
                'vProd' => 100.00,
                'vNF' => 100.00,
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 100.00],
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        $this->assertTrue($nota->hasNode('imposto_seletivo'));
        $this->assertTrue($nota->hasNode('ibs_cbs'));

        $xml = $nota->toXml();

        $this->assertStringContainsString('<IS>', $xml);
        $this->assertStringContainsString('<CSTIS>000</CSTIS>', $xml);
        $this->assertStringContainsString('<vIS>5.00</vIS>', $xml);
        $this->assertStringContainsString('<ISTot>', $xml);
        $this->assertStringContainsString('<gTransfCred>', $xml);
        $this->assertStringContainsString('<vIBS>1.23</vIBS>', $xml);
        $this->assertStringContainsString('<gEstornoCred>', $xml);
        $this->assertStringContainsString('<vIBSEstCred>0.11</vIBSEstCred>', $xml);
        $this->assertStringContainsString('<gCredPresIBSZFM>', $xml);
        $this->assertStringContainsString('<vCredPresIBSZFM>7.89</vCredPresIBSZFM>', $xml);
        $this->assertStringContainsString('<DFeReferenciado>', $xml);
    }
}
