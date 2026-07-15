<?php

namespace Tests\Integration;

use NFePHP\NFe\Make;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;

/**
 * Teste de integração completo do sistema Composite + Builder
 * Simula criação de NFCe completa do início ao fim
 */
class NFCeCompletoTest extends TestCase
{
    public function test_criar_nf_ce_completa_via_builder()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41,
                'cNF' => 12345678,
                'natOp' => 'VENDA DE MERCADORIA',
                'mod' => 65,
                'serie' => 1,
                'nNF' => 123,
                'cMunFG' => 4106902,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '12345678000190',
                'razaoSocial' => 'LOJA TESTE LTDA',
                'nomeFantasia' => 'LOJA TESTE',
                'inscricaoEstadual' => '1234567890',
                'logradouro' => 'RUA COMERCIO',
                'numero' => '100',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '4106902',
                'municipio' => 'CURITIBA',
                'uf' => 'PR',
                'cep' => '80000000',
                'telefone' => '4133334444',
                'crt' => 1,
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678901',
                'nome' => 'CONSUMIDOR FINAL',
                'indIEDest' => 9,
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'CAFE001',
                        'descricao' => 'CAFE TORRADO 500G',
                        'ncm' => '09012190',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 2.0,
                        'valorUnitario' => 15.00,
                        'valorTotal' => 30.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                        'pis' => ['cst' => '49'],
                        'cofins' => ['cst' => '49'],
                    ],
                ],
                [
                    'produto' => [
                        'codigo' => 'ACUCAR001',
                        'descricao' => 'ACUCAR CRISTAL 1KG',
                        'ncm' => '17019900',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1.0,
                        'valorUnitario' => 5.00,
                        'valorTotal' => 5.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                        'pis' => ['cst' => '49'],
                        'cofins' => ['cst' => '49'],
                    ],
                ],
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 35.00],
            ],
        ];

        // Construir a nota
        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        // Verificações
        $this->assertInstanceOf(NotaFiscal::class, $nota);
        $this->assertTrue($nota->hasNode('identificacao'));
        $this->assertTrue($nota->hasNode('emitente'));
        $this->assertTrue($nota->hasNode('destinatario'));
        $this->assertTrue($nota->hasNode('produto'));
        $this->assertTrue($nota->hasNode('imposto'));
        $this->assertTrue($nota->hasNode('pagamento'));

        // Validar
        $this->assertTrue($nota->validate());

        // Obter Make
        $make = $nota->getMake();
        $this->assertInstanceOf(Make::class, $make);

        // Nota: Geração de XML completo requer tags adicionais (totais, etc)
        // Por enquanto validamos apenas a construção e integração com Make
    }

    public function test_criar_nf_ce_com_multiplos_pagamentos()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41,
                'cNF' => 87654321,
                'natOp' => 'VENDA',
                'mod' => 65,
                'serie' => 1,
                'nNF' => 456,
                'cMunFG' => 4106902,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '98765432000111',
                'razaoSocial' => 'COMERCIO EXEMPLO LTDA',
                'nomeFantasia' => 'EXEMPLO',
                'inscricaoEstadual' => '9876543210',
                'logradouro' => 'AV EXEMPLO',
                'numero' => '200',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '4106902',
                'municipio' => 'CURITIBA',
                'uf' => 'PR',
                'cep' => '80000001',
                'crt' => 1,
            ],
            'destinatario' => [
                'cpfCnpj' => '98765432100',
                'nome' => 'CLIENTE TESTE',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'PROD999',
                        'descricao' => 'PRODUTO EXEMPLO',
                        'ncm' => '12345678',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1.0,
                        'valorUnitario' => 100.00,
                        'valorTotal' => 100.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                    ],
                ],
            ],
            'pagamentos' => [
                ['tPag' => '01', 'vPag' => 50.00],  // Dinheiro
                ['tPag' => '03', 'vPag' => 50.00],  // Cartão Crédito
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        $this->assertInstanceOf(NotaFiscal::class, $nota);
        $this->assertTrue($nota->validate());

        // Verificar que pagamento foi adicionado
        $this->assertTrue($nota->hasNode('pagamento'));
    }

    public function test_criar_nf_ce_com_validacoes()
    {
        $dados = [
            'identificacao' => [
                'cUF' => 41,
                'cNF' => 11111111,
                'natOp' => 'VENDA SIMPLES',
                'mod' => 65,
                'serie' => 1,
                'nNF' => 789,
                'cMunFG' => 4106902,
                'tpAmb' => 2,
            ],
            'emitente' => [
                'cnpj' => '11111111000111',
                'razaoSocial' => 'EMPRESA VALIDACAO LTDA',
                'nomeFantasia' => 'VALIDACAO',
                'inscricaoEstadual' => '1111111111',
                'logradouro' => 'RUA VALIDACAO',
                'numero' => '300',
                'bairro' => 'VALIDACAO',
                'codigoMunicipio' => '4106902',
                'municipio' => 'CURITIBA',
                'uf' => 'PR',
                'cep' => '80000002',
                'crt' => 1,
            ],
            'destinatario' => [
                'cpfCnpj' => '11111111111',
                'nome' => 'CONSUMIDOR VALIDACAO',
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => 'VALID001',
                        'descricao' => 'PRODUTO VALIDACAO',
                        'ncm' => '87654321',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 5.0,
                        'valorUnitario' => 20.00,
                        'valorTotal' => 100.00,
                    ],
                    'impostos' => [
                        'icms' => ['cst' => '102', 'orig' => 0],
                    ],
                ],
            ],
            'pagamentos' => [
                ['tPag' => '17', 'vPag' => 100.00],  // PIX
            ],
        ];

        $nota = NotaFiscalBuilder::fromArray($dados)->build();

        // Deve validar com sucesso
        $resultado = $nota->validate();
        $this->assertTrue($resultado);

        // Deve ter todos os nodes
        $nodes = $nota->getNodes();
        $this->assertCount(6, $nodes);
    }
}
