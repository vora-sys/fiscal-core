<?php

namespace Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ProdutoDTO;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\ProdutoNode;

class ProdutoNodeTest extends TestCase
{
    public function test_get_node_type()
    {
        $dto = ProdutoDTO::simple(1, 'PROD001', 'PRODUTO', '12345678', '5102', 1, 10.00);
        $node = new ProdutoNode($dto);

        $this->assertEquals('produto', $node->getNodeType());
    }

    public function test_validate_com_dados_validos()
    {
        $dto = ProdutoDTO::simple(1, 'PROD001', 'PRODUTO', '12345678', '5102', 1, 10.00);
        $node = new ProdutoNode($dto);

        $this->assertTrue($node->validate());
    }

    public function test_validate_codigo_vazio()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Código do produto é obrigatório');

        $dto = new ProdutoDTO(
            1, '', 'SEM GTIN', 'DESC', '12345678', '5102',
            'UN', 1, 10, 10, 'SEM GTIN', 'UN', 1, 10
        );
        $node = new ProdutoNode($dto);
        $node->validate();
    }

    public function test_validate_descricao_vazia()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Descrição do produto é obrigatória');

        $dto = new ProdutoDTO(
            1, 'PROD', 'SEM GTIN', '', '12345678', '5102',
            'UN', 1, 10, 10, 'SEM GTIN', 'UN', 1, 10
        );
        $node = new ProdutoNode($dto);
        $node->validate();
    }

    public function test_validate_quantidade_zero()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantidade deve ser maior que zero');

        $dto = new ProdutoDTO(
            1, 'PROD', 'SEM GTIN', 'DESC', '12345678', '5102',
            'UN', 0, 10, 10, 'SEM GTIN', 'UN', 1, 10
        );
        $node = new ProdutoNode($dto);
        $node->validate();
    }

    public function test_validate_valor_unitario_zero()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Valor unitário deve ser maior que zero');

        $dto = new ProdutoDTO(
            1, 'PROD', 'SEM GTIN', 'DESC', '12345678', '5102',
            'UN', 1, 0, 10, 'SEM GTIN', 'UN', 1, 10
        );
        $node = new ProdutoNode($dto);
        $node->validate();
    }
}
