<?php

namespace sabbajohn\FiscalCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TotaisDTO;

class TotaisDTOTest extends TestCase
{
    public function test_criar_totais_basico()
    {
        $totais = new TotaisDTO(
            vProd: 100.00,
            vNF: 100.00
        );

        $this->assertEquals(100.00, $totais->vProd);
        $this->assertEquals(100.00, $totais->vNF);
        $this->assertEquals(0.00, $totais->vICMS);
    }

    public function test_criar_totais_completo()
    {
        $totais = new TotaisDTO(
            vBC: 100.00,
            vICMS: 18.00,
            vPIS: 1.65,
            vCOFINS: 7.60,
            vProd: 100.00,
            vFrete: 10.00,
            vDesc: 5.00,
            vNF: 105.00
        );

        $this->assertEquals(100.00, $totais->vBC);
        $this->assertEquals(18.00, $totais->vICMS);
        $this->assertEquals(1.65, $totais->vPIS);
        $this->assertEquals(7.60, $totais->vCOFINS);
        $this->assertEquals(105.00, $totais->vNF);
    }

    public function test_from_itens_calcula_automaticamente()
    {
        $itens = [
            [
                'produto' => ['valorTotal' => 100.00],
                'impostos' => [
                    'icms' => ['vBC' => 100.00, 'vICMS' => 18.00],
                    'pis' => ['vPIS' => 1.65],
                    'cofins' => ['vCOFINS' => 7.60],
                ],
            ],
            [
                'produto' => ['valorTotal' => 50.00],
                'impostos' => [
                    'icms' => ['vBC' => 50.00, 'vICMS' => 9.00],
                    'pis' => ['vPIS' => 0.83],
                    'cofins' => ['vCOFINS' => 3.80],
                ],
            ],
        ];

        $totais = TotaisDTO::fromItens($itens);

        $this->assertEquals(150.00, $totais->vProd);
        $this->assertEquals(150.00, $totais->vBC);
        $this->assertEquals(27.00, $totais->vICMS);
        $this->assertEquals(2.48, $totais->vPIS);
        $this->assertEqualsWithDelta(11.40, $totais->vCOFINS, 0.01);
        $this->assertEquals(150.00, $totais->vNF);
    }
}
