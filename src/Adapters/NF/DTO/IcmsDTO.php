<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de ICMS
 * Suporta os principais regimes (Simples Nacional, ICMS00, ICMS20, etc)
 */
class IcmsDTO
{
    public function __construct(
        public string $cst,                 // CST do ICMS (00, 20, 40, 41, 60, 90, 101, 102, etc)
        public int $orig,                   // Origem: 0=Nacional, 1=Estrangeira importação direta, etc
        public ?float $vBC = null,          // Base de cálculo
        public ?float $pICMS = null,        // Alíquota
        public ?float $vICMS = null,        // Valor do ICMS
        public ?float $pCredSN = null,      // Alíquota crédito Simples Nacional
        public ?float $vCredICMSSN = null,  // Valor crédito Simples Nacional
        public ?int $modBC = null,          // Modalidade BC: 0=Margem Valor Agregado, 3=Valor da operação
        public ?float $pRedBC = null,       // Percentual redução BC
        public ?int $motDesICMS = null,     // Motivo desoneração
    ) {}

    /**
     * ICMS para Simples Nacional (CSOSN 102 - Sem permissão de crédito)
     */
    public static function simplesNacionalSemCredito(int $orig = 0): self
    {
        return new self(
            cst: '102',
            orig: $orig
        );
    }

    /**
     * ICMS para Simples Nacional (CSOSN 101 - Com permissão de crédito)
     */
    public static function simplesNacionalComCredito(
        float $pCredSN,
        float $vCredICMSSN,
        int $orig = 0
    ): self {
        return new self(
            cst: '101',
            orig: $orig,
            pCredSN: $pCredSN,
            vCredICMSSN: $vCredICMSSN
        );
    }

    /**
     * ICMS00 - Tributado integralmente
     */
    public static function icms00(
        float $vBC,
        float $pICMS,
        float $vICMS,
        int $orig = 0,
        int $modBC = 3
    ): self {
        return new self(
            cst: '00',
            orig: $orig,
            vBC: $vBC,
            pICMS: $pICMS,
            vICMS: $vICMS,
            modBC: $modBC
        );
    }

    /**
     * ICMS40 - Isento / Não tributado
     */
    public static function icmsIsento(int $orig = 0): self
    {
        return new self(
            cst: '40',
            orig: $orig
        );
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cst = $this->cst;
        $obj->orig = $this->orig;
        if ($this->vBC !== null) {
            $obj->vBC = $this->vBC;
        }
        if ($this->pICMS !== null) {
            $obj->pICMS = $this->pICMS;
        }
        if ($this->vICMS !== null) {
            $obj->vICMS = $this->vICMS;
        }
        if ($this->pCredSN !== null) {
            $obj->pCredSN = $this->pCredSN;
        }
        if ($this->vCredICMSSN !== null) {
            $obj->vCredICMSSN = $this->vCredICMSSN;
        }
        if ($this->modBC !== null) {
            $obj->modBC = $this->modBC;
        }
        if ($this->pRedBC !== null) {
            $obj->pRedBC = $this->pRedBC;
        }
        if ($this->motDesICMS !== null) {
            $obj->motDesICMS = $this->motDesICMS;
        }

        return $obj;
    }
}
