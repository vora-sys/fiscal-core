<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\TotaisDTO;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para totais da NFe/NFCe
 * Encapsula TotaisDTO e adiciona à tag <total><ICMSTot>
 */
class TotaisNode implements NotaNodeInterface
{
    public function __construct(
        private TotaisDTO $totais
    ) {}

    public function getNodeType(): string
    {
        return 'totais';
    }

    public function validate(): bool
    {
        // Validar valores não negativos
        if ($this->totais->vNF < 0) {
            throw new \InvalidArgumentException('Valor total da nota não pode ser negativo');
        }

        if ($this->totais->vProd < 0) {
            throw new \InvalidArgumentException('Valor total de produtos não pode ser negativo');
        }

        // Validar coerência: vNF deve ser >= vProd - vDesc
        $valorMinimo = $this->totais->vProd - $this->totais->vDesc;
        if ($this->totais->vNF < $valorMinimo) {
            throw new \InvalidArgumentException(sprintf(
                'Valor total da nota (%.2f) menor que valor de produtos menos desconto (%.2f)',
                $this->totais->vNF,
                $valorMinimo
            ));
        }

        return true;
    }

    public function addToMake(Make $make): void
    {
        // Adicionar tag de totais do ICMS
        $make->tagICMSTot(StdClassBuilder::create([
            'vBC' => $this->totais->vBC,
            'vICMS' => $this->totais->vICMS,
            'vICMSDeson' => $this->totais->vICMSDeson,
            'vFCP' => $this->totais->vFCP ?? 0,
            'vBCST' => $this->totais->vBCST,
            'vST' => $this->totais->vST,
            'vFCPST' => $this->totais->vFCPST ?? 0,
            'vFCPSTRet' => $this->totais->vFCPSTRet ?? 0,
            'vProd' => $this->totais->vProd,
            'vFrete' => $this->totais->vFrete,
            'vSeg' => $this->totais->vSeg,
            'vDesc' => $this->totais->vDesc,
            'vII' => $this->totais->vII,
            'vIPI' => $this->totais->vIPI,
            'vIPIDevol' => $this->totais->vIPIDevol ?? 0,
            'vPIS' => $this->totais->vPIS,
            'vCOFINS' => $this->totais->vCOFINS,
            'vOutro' => $this->totais->vOutro,
            'vNF' => $this->totais->vNF,
            'vTotTrib' => $this->totais->vTotTrib ?? 0,
        ]));

        // Se houver valores de ICMS UF destino, adicionar tag específica
        if ($this->totais->vICMSUFDest > 0 || $this->totais->vICMSUFRemet > 0) {
            $make->tagICMSUFDest(StdClassBuilder::create([
                'vBCUFDest' => $this->totais->vBCUFDest ?? 0,
                'vBCFCPUFDest' => $this->totais->vBCFCPUFDest ?? 0,
                'vFCPUFDest' => $this->totais->vFCPUFDest,
                'pFCPUFDest' => $this->totais->pFCPUFDest ?? 0,
                'vICMSUFDest' => $this->totais->vICMSUFDest,
                'vICMSUFRemet' => $this->totais->vICMSUFRemet,
                'pRedBC' => $this->totais->pRedBC ?? 0,
            ]));
        }

        // Se houver retenções de tributos, adicionar tag retTrib
        if ($this->temRetencoes()) {
            // Exemplo usando props() - captura nomes automaticamente!
            $make->tagretTrib(StdClassBuilder::props(
                $this->totais->vRetPIS,
                $this->totais->vRetCOFINS,
                $this->totais->vRetCSLL,
                $this->totais->vBCIRRF,
                $this->totais->vIRRF,
                $this->totais->vBCRetPrev,
                $this->totais->vRetPrev
            ));
        }
    }

    /**
     * Verifica se há retenções de tributos
     */
    private function temRetencoes(): bool
    {
        return $this->totais->vRetPIS > 0
            || $this->totais->vRetCOFINS > 0
            || $this->totais->vRetCSLL > 0
            || $this->totais->vIRRF > 0
            || $this->totais->vRetPrev > 0;
    }

    /**
     * Retorna o DTO encapsulado
     */
    public function getTotais(): TotaisDTO
    {
        return $this->totais;
    }
}
