<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados específicos de combustíveis
 * Corresponde à tag <comb> do XML
 * Usado para postos de combustível e distribuidoras
 */
class CombustivelDTO
{
    public function __construct(
        // Dados obrigatórios
        public string $cProdANP,            // Código do produto conforme ANP (ex: 210203001)
        public string $descANP,             // Descrição conforme ANP (ex: "GASOLINA COMUM")

        // Percentuais de composição (obrigatório para alguns)
        public ?float $pGLP = null,         // Percentual de gás liquefeito de petróleo (GLP)
        public ?float $pGNn = null,         // Percentual de gás natural nacional
        public ?float $pGNi = null,         // Percentual de gás natural importado
        public ?float $vPart = null,        // Valor partida (uso específico)

        // Informações adicionais
        public ?string $codif = null,       // Código de autorização CODIF (Mix)
        public ?float $qTemp = null,        // Quantidade temp. ambiente (litros a 20°C)

        // UF de consumo
        public ?string $ufCons = null,      // UF de consumo

        // CIDE (Contribuição de Intervenção no Domínio Econômico)
        public ?float $qBCProd = null,      // BC da CIDE (litros)
        public ?float $vAliqProd = null,    // Alíquota CIDE (R$/litro)
        public ?float $vCide = null,        // Valor CIDE

        // Dados do produtor (etanol/biodiesel)
        public ?string $nBico = null,       // Número do bico
        public ?string $nBomba = null,      // Número da bomba
        public ?string $nTanque = null,     // Número do tanque
        public ?float $vEncIni = null,      // Valor encerrante inicial
        public ?float $vEncFin = null,      // Valor encerrante final
    ) {}

    /**
     * Cria DTO para gasolina comum
     */
    public static function gasolinaComum(string $ufConsumo): self
    {
        return new self(
            cProdANP: '210203001',
            descANP: 'GASOLINA COMUM',
            ufCons: $ufConsumo
        );
    }

    /**
     * Cria DTO para gasolina aditivada
     */
    public static function gasolinaAditivada(string $ufConsumo): self
    {
        return new self(
            cProdANP: '210203002',
            descANP: 'GASOLINA ADITIVADA',
            ufCons: $ufConsumo
        );
    }

    /**
     * Cria DTO para etanol hidratado
     */
    public static function etanolHidratado(string $ufConsumo): self
    {
        return new self(
            cProdANP: '210202001',
            descANP: 'ETANOL HIDRATADO',
            ufCons: $ufConsumo
        );
    }

    /**
     * Cria DTO para diesel S10
     */
    public static function dieselS10(string $ufConsumo): self
    {
        return new self(
            cProdANP: '820101001',
            descANP: 'OLEO DIESEL S10',
            ufCons: $ufConsumo
        );
    }

    /**
     * Cria DTO para diesel S500
     */
    public static function dieselS500(string $ufConsumo): self
    {
        return new self(
            cProdANP: '820101002',
            descANP: 'OLEO DIESEL S500',
            ufCons: $ufConsumo
        );
    }

    /**
     * Cria DTO para gás natural veicular (GNV)
     */
    public static function gnv(string $ufConsumo): self
    {
        return new self(
            cProdANP: '210301001',
            descANP: 'GAS NATURAL VEICULAR',
            ufCons: $ufConsumo
        );
    }

    /**
     * Adiciona dados da bomba (para controle de abastecimento)
     */
    public function comDadosBomba(
        string $numeroBomba,
        string $numeroBico,
        ?string $numeroTanque = null
    ): self {
        $clone = clone $this;
        $clone->nBomba = $numeroBomba;
        $clone->nBico = $numeroBico;
        $clone->nTanque = $numeroTanque;

        return $clone;
    }

    /**
     * Adiciona encerrantes (bomba de combustível)
     */
    public function comEncerrantes(float $inicial, float $final): self
    {
        $clone = clone $this;
        $clone->vEncIni = $inicial;
        $clone->vEncFin = $final;

        return $clone;
    }

    /**
     * Adiciona CIDE
     */
    public function comCide(float $quantidadeBC, float $aliquota): self
    {
        $clone = clone $this;
        $clone->qBCProd = $quantidadeBC;
        $clone->vAliqProd = $aliquota;
        $clone->vCide = $quantidadeBC * $aliquota;

        return $clone;
    }

    /**
     * Códigos ANP mais comuns
     */
    public const PRODUTOS_ANP = [
        '210203001' => 'GASOLINA COMUM',
        '210203002' => 'GASOLINA ADITIVADA',
        '210202001' => 'ETANOL HIDRATADO',
        '820101001' => 'OLEO DIESEL S10',
        '820101002' => 'OLEO DIESEL S500',
        '210301001' => 'GAS NATURAL VEICULAR',
        '820201001' => 'BIODIESEL B100',
    ];

    /**
     * Valida dados do combustível
     */
    public function validate(): array
    {
        $errors = [];

        // Validar código ANP
        if (! isset(self::PRODUTOS_ANP[$this->cProdANP])) {
            $errors[] = "Código ANP não reconhecido: {$this->cProdANP}";
        }

        // Validar UF
        if ($this->ufCons && ! preg_match('/^[A-Z]{2}$/', $this->ufCons)) {
            $errors[] = "UF de consumo inválida: {$this->ufCons}";
        }

        // Validar encerrantes (se informados)
        if ($this->vEncIni !== null && $this->vEncFin !== null) {
            if ($this->vEncFin <= $this->vEncIni) {
                $errors[] = 'Encerrante final deve ser maior que inicial';
            }
        }

        return $errors;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cProdANP = $this->cProdANP;
        $obj->descANP = $this->descANP;
        if ($this->pGLP !== null) {
            $obj->pGLP = $this->pGLP;
        }
        if ($this->pGNn !== null) {
            $obj->pGNn = $this->pGNn;
        }
        if ($this->pGNi !== null) {
            $obj->pGNi = $this->pGNi;
        }
        if ($this->vPart !== null) {
            $obj->vPart = $this->vPart;
        }
        if ($this->codif !== null) {
            $obj->codif = $this->codif;
        }
        if ($this->qTemp !== null) {
            $obj->qTemp = $this->qTemp;
        }
        if ($this->ufCons !== null) {
            $obj->ufCons = $this->ufCons;
        }
        if ($this->qBCProd !== null) {
            $obj->qBCProd = $this->qBCProd;
        }
        if ($this->vAliqProd !== null) {
            $obj->vAliqProd = $this->vAliqProd;
        }
        if ($this->vCide !== null) {
            $obj->vCide = $this->vCide;
        }
        if ($this->nBico !== null) {
            $obj->nBico = $this->nBico;
        }
        if ($this->nBomba !== null) {
            $obj->nBomba = $this->nBomba;
        }
        if ($this->nTanque !== null) {
            $obj->nTanque = $this->nTanque;
        }
        if ($this->vEncIni !== null) {
            $obj->vEncIni = $this->vEncIni;
        }
        if ($this->vEncFin !== null) {
            $obj->vEncFin = $this->vEncFin;
        }

        return $obj;
    }
}
