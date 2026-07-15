<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ProdutoDTO;

class ProdutoDTOTest extends TestCase
{
    public function test_criar_produto()
    {
        $dto = new ProdutoDTO(
            item: 1,
            codigo: 'PROD001',
            cean: 'SEM GTIN',
            descricao: 'PRODUTO TESTE',
            ncm: '12345678',
            cfop: '5102',
            unidadeComercial: 'UN',
            quantidadeComercial: 10.0,
            valorUnitario: 25.50,
            valorTotal: 255.00,
            ceanTributavel: 'SEM GTIN',
            unidadeTributavel: 'UN',
            quantidadeTributavel: 10.0,
            valorUnitarioTributavel: 25.50
        );

        $this->assertEquals(1, $dto->item);
        $this->assertEquals('PROD001', $dto->codigo);
        $this->assertEquals('PRODUTO TESTE', $dto->descricao);
        $this->assertEquals(10.0, $dto->quantidadeComercial);
        $this->assertEquals(255.00, $dto->valorTotal);
    }

    public function test_factory_method_simple()
    {
        $dto = ProdutoDTO::simple(
            item: 1,
            codigo: 'PROD123',
            descricao: 'PRODUTO SIMPLES',
            ncm: '12345678',
            cfop: '5102',
            quantidade: 5,
            valorUnitario: 10.00
        );

        $this->assertEquals(1, $dto->item);
        $this->assertEquals('PROD123', $dto->codigo);
        $this->assertEquals(5.0, $dto->quantidadeComercial);
        $this->assertEquals(10.00, $dto->valorUnitario);
        $this->assertEquals(50.00, $dto->valorTotal);
        $this->assertEquals('UN', $dto->unidadeComercial);
        $this->assertEquals('SEM GTIN', $dto->cean);
    }

    public function test_calculo_valor_total()
    {
        $dto = ProdutoDTO::simple(
            1, 'PROD', 'DESC', '12345678', '5102', 3, 15.50
        );

        $this->assertEquals(46.50, $dto->valorTotal);
    }

    public function test_unidade_personalizada()
    {
        $dto = ProdutoDTO::simple(
            1, 'PROD', 'DESC', '12345678', '5102', 2.5, 20.00,
            unidade: 'KG'
        );

        $this->assertEquals('KG', $dto->unidadeComercial);
        $this->assertEquals('KG', $dto->unidadeTributavel);
    }

    public function test_ean_personalizado()
    {
        $dto = ProdutoDTO::simple(
            1, 'PROD', 'DESC', '12345678', '5102', 1, 10.00,
            ean: '7891234567890'
        );

        $this->assertEquals('7891234567890', $dto->cean);
        $this->assertEquals('7891234567890', $dto->ceanTributavel);
    }
}
