<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de Produto/Item
 * Corresponde à tag <prod> dentro de <det> do XML
 */
class ProdutoDTO
{
    public function __construct(
        public int $item,                   // Número sequencial do item (1, 2, 3...)
        public string $codigo,              // Código do produto
        public string $cean,                // GTIN/EAN
        public string $descricao,           // Descrição do produto
        public string $ncm,                 // NCM
        public string $cfop,                // CFOP
        public string $unidadeComercial,    // Unidade (UN, KG, etc)
        public float $quantidadeComercial,  // Quantidade
        public float $valorUnitario,        // Valor unitário
        public float $valorTotal,           // Valor total do produto
        public string $ceanTributavel,      // GTIN tributável
        public string $unidadeTributavel,   // Unidade tributável
        public float $quantidadeTributavel, // Quantidade tributável
        public float $valorUnitarioTributavel, // Valor unitário tributável
        public int $indTot = 1,             // 1=Compõe total, 0=Não compõe
        public ?string $cest = null,        // CEST (opcional)
        public ?string $exTipi = null,      // EX TIPI (opcional)
    ) {}

    /**
     * Cria DTO simplificado para produto básico
     */
    public static function simple(
        int $item,
        string $codigo,
        string $descricao,
        string $ncm,
        string $cfop,
        float $quantidade,
        float $valorUnitario,
        string $unidade = 'UN',
        string $ean = 'SEM GTIN'
    ): self {
        $valorTotal = $quantidade * $valorUnitario;

        return new self(
            item: $item,
            codigo: $codigo,
            cean: $ean,
            descricao: $descricao,
            ncm: $ncm,
            cfop: $cfop,
            unidadeComercial: $unidade,
            quantidadeComercial: $quantidade,
            valorUnitario: $valorUnitario,
            valorTotal: $valorTotal,
            ceanTributavel: $ean,
            unidadeTributavel: $unidade,
            quantidadeTributavel: $quantidade,
            valorUnitarioTributavel: $valorUnitario
        );
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->item = $this->item;
        $obj->codigo = $this->codigo;
        $obj->cean = $this->cean;
        $obj->descricao = $this->descricao;
        $obj->ncm = $this->ncm;
        $obj->cfop = $this->cfop;
        $obj->unidadeComercial = $this->unidadeComercial;
        $obj->quantidadeComercial = $this->quantidadeComercial;
        $obj->valorUnitario = $this->valorUnitario;
        $obj->valorTotal = $this->valorTotal;
        $obj->ceanTributavel = $this->ceanTributavel;
        $obj->unidadeTributavel = $this->unidadeTributavel;
        $obj->quantidadeTributavel = $this->quantidadeTributavel;
        $obj->valorUnitarioTributavel = $this->valorUnitarioTributavel;
        $obj->indTot = $this->indTot;
        if ($this->cest !== null) {
            $obj->cest = $this->cest;
        }
        if ($this->exTipi !== null) {
            $obj->exTipi = $this->exTipi;
        }

        return $obj;
    }
}
