<?php

namespace sabbajohn\FiscalCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\CobrancaDTO;

class CobrancaDTOTest extends TestCase
{
    public function test_criar_cobranca_a_vista()
    {
        $cobranca = CobrancaDTO::aVista('FAT001', 100.00);

        $this->assertEquals('FAT001', $cobranca->numeroFatura);
        $this->assertEquals(100.00, $cobranca->valorOriginal);
        $this->assertEquals(100.00, $cobranca->valorLiquido);
        $this->assertEmpty($cobranca->duplicatas);
    }

    public function test_criar_cobranca_parcelada()
    {
        $duplicatas = [
            ['nDup' => '001', 'dVenc' => '2024-12-31', 'vDup' => 50.00],
            ['nDup' => '002', 'dVenc' => '2025-01-31', 'vDup' => 50.00],
        ];

        $cobranca = CobrancaDTO::parcelada('FAT001', 100.00, $duplicatas);

        $this->assertEquals('FAT001', $cobranca->numeroFatura);
        $this->assertEquals(100.00, $cobranca->valorOriginal);
        $this->assertCount(2, $cobranca->duplicatas);
    }

    public function test_criar_cobranca_em_n_vezes()
    {
        $vencimento = new \DateTime('2024-12-31');
        $cobranca = CobrancaDTO::parceladaEmNVezes('FAT001', 300.00, 3, $vencimento);

        $this->assertEquals('FAT001', $cobranca->numeroFatura);
        $this->assertEquals(300.00, $cobranca->valorOriginal);
        $this->assertCount(3, $cobranca->duplicatas);

        // Verificar valores das parcelas
        $this->assertEquals(100.00, $cobranca->duplicatas[0]['vDup']);
        $this->assertEquals(100.00, $cobranca->duplicatas[1]['vDup']);
        $this->assertEquals(100.00, $cobranca->duplicatas[2]['vDup']);

        // Verificar numeração sequencial
        $this->assertEquals('001', $cobranca->duplicatas[0]['nDup']);
        $this->assertEquals('002', $cobranca->duplicatas[1]['nDup']);
        $this->assertEquals('003', $cobranca->duplicatas[2]['nDup']);
    }

    public function test_adicionar_desconto()
    {
        $cobranca = CobrancaDTO::aVista('FAT001', 100.00)
            ->comDesconto(10.00);

        $this->assertEquals(100.00, $cobranca->valorOriginal);
        $this->assertEquals(10.00, $cobranca->valorDesconto);
        $this->assertEquals(90.00, $cobranca->valorLiquido);
    }

    public function test_validacao_soma_duplicatas()
    {
        $duplicatas = [
            ['nDup' => '001', 'dVenc' => '2024-12-31', 'vDup' => 40.00],
            ['nDup' => '002', 'dVenc' => '2025-01-31', 'vDup' => 50.00],
        ];

        $cobranca = CobrancaDTO::parcelada('FAT001', 100.00, $duplicatas);
        $errors = $cobranca->validate();

        // Deve ter erro pois soma (90) difere do total (100)
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('difere do valor da fatura', $errors[0]);
    }

    public function test_validacao_com_soma_correta()
    {
        $duplicatas = [
            ['nDup' => '001', 'dVenc' => '2024-12-31', 'vDup' => 50.00],
            ['nDup' => '002', 'dVenc' => '2025-01-31', 'vDup' => 50.00],
        ];

        $cobranca = CobrancaDTO::parcelada('FAT001', 100.00, $duplicatas);
        $errors = $cobranca->validate();

        $this->assertEmpty($errors);
    }
}
