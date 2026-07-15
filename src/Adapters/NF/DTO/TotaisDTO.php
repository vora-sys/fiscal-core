<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para totais da NFe/NFCe
 * Corresponde à tag <total><ICMSTot> do XML
 */
class TotaisDTO
{
    public function __construct(
        // Valores base de cálculo
        public float $vBC = 0.00,           // Base de cálculo ICMS
        public float $vBCST = 0.00,         // Base de cálculo ICMS ST

        // Valores de ICMS
        public float $vICMS = 0.00,         // Valor total ICMS
        public float $vST = 0.00,           // Valor total ICMS ST
        public float $vICMSDeson = 0.00,    // Valor total ICMS desonerado

        // FCP (Fundo de Combate à Pobreza)
        public float $vFCP = 0.00,          // Valor FCP
        public float $vFCPST = 0.00,        // Valor FCP ST
        public float $vFCPSTRet = 0.00,     // Valor FCP ST Retido

        // Valores federais
        public float $vPIS = 0.00,          // Valor total PIS
        public float $vCOFINS = 0.00,       // Valor total COFINS
        public float $vII = 0.00,           // Valor total II (Importação)
        public float $vIPI = 0.00,          // Valor total IPI
        public float $vIPIDevol = 0.00,     // Valor IPI devolvido

        // Valores de produtos e serviços
        public float $vProd = 0.00,         // Valor total produtos
        public float $vServ = 0.00,         // Valor total serviços (NFS-e)

        // Outros valores
        public float $vFrete = 0.00,        // Valor total frete
        public float $vSeg = 0.00,          // Valor total seguro
        public float $vDesc = 0.00,         // Valor total desconto
        public float $vOutro = 0.00,        // Outras despesas acessórias

        // Valor total da nota
        public float $vNF = 0.00,           // Valor total da NF-e

        // Totais de tributos
        public float $vTotTrib = 0.00,      // Valor aproximado total de tributos

        // ICMS UF Destino (interestadual)
        public float $vFCPUFDest = 0.00,    // Valor FCP UF destino
        public float $vICMSUFDest = 0.00,   // Valor ICMS UF destino
        public float $vICMSUFRemet = 0.00,  // Valor ICMS UF remetente

        // Retenções
        public float $vRetPIS = 0.00,       // Valor retido PIS
        public float $vRetCOFINS = 0.00,    // Valor retido COFINS
        public float $vRetCSLL = 0.00,      // Valor retido CSLL
        public float $vBCIRRF = 0.00,       // Base cálculo IRRF
        public float $vIRRF = 0.00,         // Valor IRRF
        public float $vBCRetPrev = 0.00,    // Base cálculo retenção Previdência
        public float $vRetPrev = 0.00,      // Valor retenção Previdência
    ) {}

    /**
     * Calcula automaticamente os totais a partir dos itens
     */
    public static function fromItens(array $itens): self
    {
        $totais = new self;

        foreach ($itens as $item) {
            if (isset($item['produto'])) {
                $produto = $item['produto'];
                $totais->vProd += $produto['valorTotal'] ?? 0;
            }

            if (isset($item['impostos'])) {
                $impostos = $item['impostos'];

                // ICMS
                if (isset($impostos['icms'])) {
                    $icms = $impostos['icms'];
                    $totais->vBC += $icms['vBC'] ?? 0;
                    $totais->vICMS += $icms['vICMS'] ?? 0;
                    $totais->vBCST += $icms['vBCST'] ?? 0;
                    $totais->vST += $icms['vST'] ?? 0;
                    $totais->vICMSDeson += $icms['vICMSDeson'] ?? 0;
                    $totais->vFCP += $icms['vFCP'] ?? 0;
                }

                // IPI
                if (isset($impostos['ipi'])) {
                    $ipi = $impostos['ipi'];
                    $totais->vIPI += $ipi['vIPI'] ?? 0;
                }

                // PIS
                if (isset($impostos['pis'])) {
                    $pis = $impostos['pis'];
                    $totais->vPIS += $pis['vPIS'] ?? 0;
                }

                // COFINS
                if (isset($impostos['cofins'])) {
                    $cofins = $impostos['cofins'];
                    $totais->vCOFINS += $cofins['vCOFINS'] ?? 0;
                }
            }
        }

        // Calcular valor total da nota
        $totais->vNF = $totais->vProd
            + $totais->vST
            + $totais->vFrete
            + $totais->vSeg
            + $totais->vOutro
            + $totais->vII
            + $totais->vIPI
            - $totais->vDesc
            - $totais->vIPIDevol;

        return $totais;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->vBC = $this->vBC;
        $obj->vBCST = $this->vBCST;
        $obj->vICMS = $this->vICMS;
        $obj->vST = $this->vST;
        $obj->vICMSDeson = $this->vICMSDeson;
        $obj->vFCP = $this->vFCP;
        $obj->vFCPST = $this->vFCPST;
        $obj->vFCPSTRet = $this->vFCPSTRet;
        $obj->vPIS = $this->vPIS;
        $obj->vCOFINS = $this->vCOFINS;
        $obj->vII = $this->vII;
        $obj->vIPI = $this->vIPI;
        $obj->vIPIDevol = $this->vIPIDevol;
        $obj->vProd = $this->vProd;
        $obj->vServ = $this->vServ;
        $obj->vFrete = $this->vFrete;
        $obj->vSeg = $this->vSeg;
        $obj->vDesc = $this->vDesc;
        $obj->vOutro = $this->vOutro;
        $obj->vNF = $this->vNF;
        $obj->vTotTrib = $this->vTotTrib;
        $obj->vFCPUFDest = $this->vFCPUFDest;
        $obj->vICMSUFDest = $this->vICMSUFDest;
        $obj->vICMSUFRemet = $this->vICMSUFRemet;
        $obj->vRetPIS = $this->vRetPIS;
        $obj->vRetCOFINS = $this->vRetCOFINS;
        $obj->vRetCSLL = $this->vRetCSLL;
        $obj->vBCIRRF = $this->vBCIRRF;
        $obj->vIRRF = $this->vIRRF;
        $obj->vBCRetPrev = $this->vBCRetPrev;
        $obj->vRetPrev = $this->vRetPrev;

        return $obj;
    }
}
