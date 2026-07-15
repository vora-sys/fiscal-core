<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PagamentoDTO;

class PagamentoDTOTest extends TestCase
{
    public function test_pagamento_dinheiro()
    {
        $dto = PagamentoDTO::dinheiro(100.00);

        $this->assertEquals('01', $dto->tPag);
        $this->assertEquals(100.00, $dto->vPag);
        $this->assertNull($dto->cnpj);
        $this->assertNull($dto->tBand);
    }

    public function test_pagamento_cartao_credito()
    {
        $dto = PagamentoDTO::cartaoCredito(
            valor: 250.00,
            cnpjCredenciadora: '12345678000190',
            bandeira: '01',
            autorizacao: 'ABC123'
        );

        $this->assertEquals('03', $dto->tPag);
        $this->assertEquals(250.00, $dto->vPag);
        $this->assertEquals('1', $dto->tpIntegra);
        $this->assertEquals('12345678000190', $dto->cnpj);
        $this->assertEquals('01', $dto->tBand);
        $this->assertEquals('ABC123', $dto->cAut);
    }

    public function test_pagamento_cartao_debito()
    {
        $dto = PagamentoDTO::cartaoDebito(
            valor: 150.00,
            cnpjCredenciadora: '98765432000111',
            bandeira: '02'
        );

        $this->assertEquals('04', $dto->tPag);
        $this->assertEquals(150.00, $dto->vPag);
        $this->assertEquals('1', $dto->tpIntegra);
        $this->assertEquals('98765432000111', $dto->cnpj);
        $this->assertEquals('02', $dto->tBand);
    }

    public function test_pagamento_pix()
    {
        $dto = PagamentoDTO::pix(75.50);

        $this->assertEquals('17', $dto->tPag);
        $this->assertEquals(75.50, $dto->vPag);
        $this->assertNull($dto->tpIntegra);
        $this->assertNull($dto->cnpj);
    }

    public function test_pagamento_cartao_sem_dados_opcionais()
    {
        $dto = PagamentoDTO::cartaoCredito(100.00);

        $this->assertEquals('03', $dto->tPag);
        $this->assertEquals(100.00, $dto->vPag);
        $this->assertNull($dto->cnpj);
        $this->assertNull($dto->tBand);
        $this->assertNull($dto->cAut);
    }
}
