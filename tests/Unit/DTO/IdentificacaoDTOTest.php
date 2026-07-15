<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IdentificacaoDTO;

class IdentificacaoDTOTest extends TestCase
{
    public function test_criar_identificacao_n_fe()
    {
        $dto = new IdentificacaoDTO(
            cUF: 41,
            cNF: 12345678,
            natOp: 'VENDA',
            mod: 55,
            serie: 1,
            nNF: 123,
            dhEmi: date('Y-m-d\TH:i:sP'),
            tpNF: 1,
            idDest: 1,
            cMunFG: 4106902,
            tpImp: 1,
            tpEmis: 1,
            cDV: 0,
            tpAmb: 2,
            finNFe: 1,
            indFinal: 0,
            indPres: 1
        );

        $this->assertEquals(41, $dto->cUF);
        $this->assertEquals(55, $dto->mod);
        $this->assertEquals('VENDA', $dto->natOp);
        $this->assertEquals(123, $dto->nNF);
    }

    public function test_factory_method_for_n_fe()
    {
        $dto = IdentificacaoDTO::forNFe(
            cUF: 41,
            natOp: 'VENDA',
            nNF: 456,
            cMunFG: 4106902,
            idDest: 1
        );

        $this->assertEquals(55, $dto->mod);
        $this->assertEquals(456, $dto->nNF);
        $this->assertEquals(1, $dto->tpNF);
        $this->assertEquals(2, $dto->tpAmb);
        $this->assertEquals(0, $dto->indFinal);
    }

    public function test_factory_method_for_nf_ce()
    {
        $dto = IdentificacaoDTO::forNFCe(
            cUF: 41,
            natOp: 'VENDA',
            nNF: 789,
            cMunFG: 4106902
        );

        $this->assertEquals(65, $dto->mod);
        $this->assertEquals(789, $dto->nNF);
        $this->assertEquals(4, $dto->tpImp);
        $this->assertEquals(1, $dto->indFinal);
        $this->assertEquals(1, $dto->indPres);
    }

    public function test_cnf_gerado_automaticamente()
    {
        $dto = IdentificacaoDTO::forNFCe(41, 'VENDA', 100, 4106902);

        $this->assertIsInt($dto->cNF);
        $this->assertGreaterThanOrEqual(10000000, $dto->cNF);
        $this->assertLessThanOrEqual(99999999, $dto->cNF);
    }
}
