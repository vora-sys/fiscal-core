<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de PIS
 */
class PisDTO
{
    public function __construct(
        public string $cst,          // CST do PIS (01, 04, 49, 99, etc)
        public ?float $vBC = null,   // Base de cálculo
        public ?float $pPIS = null,  // Alíquota
        public ?float $vPIS = null,  // Valor do PIS
    ) {}

    /**
     * PIS para regime de não cumulatividade (CST 01)
     */
    public static function naoCumulativo(float $vBC, float $pPIS, float $vPIS): self
    {
        return new self(
            cst: '01',
            vBC: $vBC,
            pPIS: $pPIS,
            vPIS: $vPIS
        );
    }

    /**
     * PIS para operação tributável com alíquota zero (CST 04)
     */
    public static function aliquotaZero(): self
    {
        return new self(cst: '04');
    }

    /**
     * PIS para outras operações (CST 49)
     */
    public static function outrasOperacoes(): self
    {
        return new self(cst: '49');
    }

    /**
     * PIS para operação sem incidência (CST 07)
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
        if ($this->pPIS !== null) {
            $obj->pPIS = $this->pPIS;
        }
        if ($this->vPIS !== null) {
            $obj->vPIS = $this->vPIS;
        }

        return $obj;
    }
}
