<?php

namespace Tests\Unit\Tributacao;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\TributacaoFacade;

/**
 * Testes unitários para cálculos de ICMS
 * Valida regras tributárias específicas por UF
 */
class ICMSCalculationTest extends TestCase
{
    private TributacaoFacade $tributacao;

    protected function setUp(): void
    {
        $this->tributacao = new TributacaoFacade;
    }

    /** @test */
    public function deve_calcular_icms_operacao_interna_sp(): void
    {
        $dados = [
            'ncm' => '22071000',
            'origem' => 'SP',
            'destino' => 'SP',
            'valor' => 1000.00,
            'tipo_operacao' => 'venda',
        ];

        $resultado = $this->tributacao->calcularICMS($dados);

        $this->assertTrue($resultado->isSuccess());
        $this->assertEquals(18.0, $resultado->getData()['aliquota']);
        $this->assertEquals(180.00, $resultado->getData()['valor_icms']);
    }

    /** @test */
    public function deve_calcular_icms_operacao_interestadual(): void
    {
        $dados = [
            'ncm' => '84715010',
            'origem' => 'SP',
            'destino' => 'RJ',
            'valor' => 1000.00,
            'tipo_operacao' => 'venda',
        ];

        $resultado = $this->tributacao->calcularICMS($dados);

        $this->assertTrue($resultado->isSuccess());
        $this->assertEquals(12.0, $resultado->getData()['aliquota']);
        $this->assertEquals(120.00, $resultado->getData()['valor_icms']);
    }

    /** @test */
    public function deve_aplicar_substituicao_tributaria_quando_aplicavel(): void
    {
        $dados = [
            'ncm' => '22071000', // Bebidas alcoólicas - sujeito a ST
            'origem' => 'SP',
            'destino' => 'RJ',
            'valor' => 1000.00,
            'cst' => '60', // ST
        ];

        $resultado = $this->tributacao->calcularICMS($dados);

        $this->assertTrue($resultado->isSuccess());
        $this->assertTrue($resultado->getData()['substituicao_tributaria']);
        $this->assertGreaterThan(0, $resultado->getData()['valor_st']);
    }

    /** @test */
    public function deve_validar_ncm_obrigatorio(): void
    {
        $dados = [
            'origem' => 'SP',
            'destino' => 'RJ',
            'valor' => 1000.00,
        ];

        $resultado = $this->tributacao->calcularICMS($dados);

        $this->assertFalse($resultado->isSuccess());
        $this->assertStringContainsString('NCM é obrigatório', $resultado->getError());
    }

    /** @test */
    public function deve_validar_uf_origem_valida(): void
    {
        $dados = [
            'ncm' => '84715010',
            'origem' => 'XX',
            'destino' => 'SP',
            'valor' => 1000.00,
        ];

        $resultado = $this->tributacao->calcularICMS($dados);

        $this->assertFalse($resultado->isSuccess());
        $this->assertStringContainsString('UF de origem inválida', $resultado->getError());
    }
}
