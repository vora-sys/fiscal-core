<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\CofinsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IcmsDTO;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PisDTO;
use NFePHP\NFe\Make;

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

            if ($cst === '500') {
                $this->appendRetainedStFields($data);
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

            if ($cst === '60') {
                $this->appendRetainedStFields($data);
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

    /** @param array<string,mixed> $data */
    private function appendRetainedStFields(array &$data): void
    {
        $data['vBCSTRet'] = number_format($this->icms->vBCSTRet ?? 0.0, 2, '.', '');
        $data['pST'] = number_format($this->icms->pST ?? 0.0, 4, '.', '');
        $data['vICMSSubstituto'] = number_format($this->icms->vICMSSubstituto ?? 0.0, 2, '.', '');
        $data['vICMSSTRet'] = number_format($this->icms->vICMSSTRet ?? 0.0, 2, '.', '');

        if (
            $this->icms->vBCFCPSTRet !== null
            && $this->icms->pFCPSTRet !== null
            && $this->icms->vFCPSTRet !== null
        ) {
            $data['vBCFCPSTRet'] = number_format($this->icms->vBCFCPSTRet, 2, '.', '');
            $data['pFCPSTRet'] = number_format($this->icms->pFCPSTRet, 4, '.', '');
            $data['vFCPSTRet'] = number_format($this->icms->vFCPSTRet, 2, '.', '');
        }

        if (
            $this->icms->vBCEfet !== null
            && $this->icms->pICMSEfet !== null
            && $this->icms->vICMSEfet !== null
        ) {
            $data['pRedBCEfet'] = number_format($this->icms->pRedBCEfet ?? 0.0, 4, '.', '');
            $data['vBCEfet'] = number_format($this->icms->vBCEfet, 2, '.', '');
            $data['pICMSEfet'] = number_format($this->icms->pICMSEfet, 4, '.', '');
            $data['vICMSEfet'] = number_format($this->icms->vICMSEfet, 2, '.', '');
        }
    }

    public function getNodeType(): string
    {
        return 'imposto';
    }
}
