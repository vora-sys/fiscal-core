<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de COFINS
 */
class CofinsDTO
{
    public function __construct(
        public string $cst,             // CST do COFINS (01, 04, 49, 99, etc)
        public ?float $vBC = null,      // Base de cálculo
        public ?float $pCOFINS = null,  // Alíquota
        public ?float $vCOFINS = null,  // Valor do COFINS
    ) {}

    /**
     * COFINS para regime de não cumulatividade (CST 01)
     */
    public static function naoCumulativo(float $vBC, float $pCOFINS, float $vCOFINS): self
    {
        return new self(
            cst: '01',
            vBC: $vBC,
            pCOFINS: $pCOFINS,
            vCOFINS: $vCOFINS
        );
    }

    /**
     * COFINS para operação tributável com alíquota zero (CST 04)
     */
    public static function aliquotaZero(): self
    {
        return new self(cst: '04');
    }

    /**
     * COFINS para outras operações (CST 49)
     */
    public static function outrasOperacoes(): self
    {
        return new self(cst: '49');
    }

    /**
     * COFINS para operação sem incidência (CST 07)
     */
    public static function semIncidencia(): self
    {
        return new self(cst: '07');
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cst = $this->cst;
        if ($this->vBC !== null) {
            $obj->vBC = $this->vBC;
        }
        if ($this->pCOFINS !== null) {
            $obj->pCOFINS = $this->pCOFINS;
        }
        if ($this->vCOFINS !== null) {
            $obj->vCOFINS = $this->vCOFINS;
        }

        return $obj;
    }
}
