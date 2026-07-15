<?php

namespace Tests\Unit\Nodes;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IdentificacaoDTO;
use sabbajohn\FiscalCore\Adapters\NF\Nodes\IdentificacaoNode;

class IdentificacaoNodeTest extends TestCase
{
    public function test_get_node_type()
    {
        $dto = IdentificacaoDTO::forNFCe(41, 'VENDA', 123, 4106902);
        $node = new IdentificacaoNode($dto);

        $this->assertEquals('identificacao', $node->getNodeType());
    }

    public function test_validate_com_dados_validos()
    {
        $dto = IdentificacaoDTO::forNFCe(41, 'VENDA', 123, 4106902);
        $node = new IdentificacaoNode($dto);

        $this->assertTrue($node->validate());
    }

    public function test_validate_natureza_operacao_vazia()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Natureza da operação é obrigatória');

        $dto = new IdentificacaoDTO(
            41, 12345678, '', 65, 1, 123,
            date('Y-m-d\TH:i:sP'), 1, 1, 4106902,
            4, 1, 0, 2, 1, 1, 1
        );
        $node = new IdentificacaoNode($dto);
        $node->validate();
    }

    public function test_validate_modelo_invalido()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Modelo deve ser 55 (NFe) ou 65 (NFCe)');

        $dto = new IdentificacaoDTO(
            41, 12345678, 'VENDA', 99, 1, 123,
            date('Y-m-d\TH:i:sP'), 1, 1, 4106902,
            4, 1, 0, 2, 1, 1, 1
        );
        $node = new IdentificacaoNode($dto);
        $node->validate();
    }

    public function test_validate_numero_nota_invalido()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Número da nota deve ser maior que zero');

        $dto = new IdentificacaoDTO(
            41, 12345678, 'VENDA', 65, 1, 0,
            date('Y-m-d\TH:i:sP'), 1, 1, 4106902,
            4, 1, 0, 2, 1, 1, 1
        );
        $node = new IdentificacaoNode($dto);
        $node->validate();
    }
}
