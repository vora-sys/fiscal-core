<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\CofinsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IcmsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PisDTO;

/**
 * Node para tag <imposto> (Impostos do item)
 * Agrupa ICMS, PIS, COFINS
 */
class ImpostoNode implements NotaNodeInterface
{
    public function __construct(
        private int $item,
        private IcmsDTO $icms,
        private ?PisDTO $pis = null,
        private ?CofinsDTO $cofins = null,
        private ?float $vTotTrib = null,
    ) {}

    public function addToMake(Make $make): void
    {
        if ($this->vTotTrib !== null) {
            $make->tagimposto((object) [
                'item' => $this->item,
                'vTotTrib' => number_format(max(0, $this->vTotTrib), 2, '.', ''),
            ]);
        }

        // ICMS - detecta regime pelo CST
        $cst = $this->icms->cst;

        // Simples Nacional (CSOSN)
        if (in_array($cst, ['101', '102', '103', '201', '202', '203', '300', '400', '500', '900'])) {
            $data = [
                'item' => $this->item,
                'orig' => $this->icms->orig,
                'CSOSN' => $cst,
            ];

            if ($this->icms->pCredSN !== null) {
                $data['pCredSN'] = number_format($this->icms->pCredSN, 2, '.', '');
                $data['vCredICMSSN'] = number_format($this->icms->vCredICMSSN, 2, '.', '');
            }

            $make->tagICMSSN((object) $data);
        } else {
            // Regime normal
            $data = [
                'item' => $this->item,
                'orig' => $this->icms->orig,
                'CST' => $cst,
            ];

            if ($this->icms->modBC !== null) {
                $data['modBC'] = $this->icms->modBC;
            }

            if ($this->icms->vBC !== null) {
                $data['vBC'] = number_format($this->icms->vBC, 2, '.', '');
            }

            if ($this->icms->pICMS !== null) {
                $data['pICMS'] = number_format($this->icms->pICMS, 2, '.', '');
            }

            if ($this->icms->vICMS !== null) {
                $data['vICMS'] = number_format($this->icms->vICMS, 2, '.', '');
            }

            if ($this->icms->pRedBC !== null) {
                $data['pRedBC'] = number_format($this->icms->pRedBC, 2, '.', '');
            }

            $make->tagICMS((object) $data);
        }

        // PIS
        if ($this->pis) {
            $pisData = [
                'item' => $this->item,
                'CST' => $this->pis->cst,
            ];

            if ($this->pis->vBC !== null) {
                $pisData['vBC'] = number_format($this->pis->vBC, 2, '.', '');
            }

            if ($this->pis->pPIS !== null) {
                $pisData['pPIS'] = number_format($this->pis->pPIS, 4, '.', '');
            }

            if ($this->pis->vPIS !== null) {
                $pisData['vPIS'] = number_format($this->pis->vPIS, 2, '.', '');
            }

            $make->tagPIS((object) $pisData);
        }

        // COFINS
        if ($this->cofins) {
            $cofinsData = [
                'item' => $this->item,
                'CST' => $this->cofins->cst,
            ];

            if ($this->cofins->vBC !== null) {
                $cofinsData['vBC'] = number_format($this->cofins->vBC, 2, '.', '');
            }

            if ($this->cofins->pCOFINS !== null) {
                $cofinsData['pCOFINS'] = number_format($this->cofins->pCOFINS, 4, '.', '');
            }

            if ($this->cofins->vCOFINS !== null) {
                $cofinsData['vCOFINS'] = number_format($this->cofins->vCOFINS, 2, '.', '');
            }

            $make->tagCOFINS((object) $cofinsData);
        }
    }

    public function validate(): bool
    {
        if (empty($this->icms->cst)) {
            throw new \InvalidArgumentException('CST do ICMS é obrigatório');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'imposto';
    }
}
