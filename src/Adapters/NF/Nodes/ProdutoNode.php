<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ProdutoDTO;

/**
 * Node para tag <prod> (Produto/Item)
 */
class ProdutoNode implements NotaNodeInterface
{
    public function __construct(private ProdutoDTO $dto) {}

    public function addToMake(Make $make): void
    {
        $std = (object) [
            'item' => $this->dto->item,
            'cProd' => $this->dto->codigo,
            'cEAN' => $this->dto->cean,
            'xProd' => $this->dto->descricao,
            'NCM' => $this->dto->ncm,
            'CFOP' => $this->dto->cfop,
            'uCom' => $this->dto->unidadeComercial,
            'qCom' => number_format($this->dto->quantidadeComercial, 4, '.', ''),
            'vUnCom' => number_format($this->dto->valorUnitario, 10, '.', ''),
            'vProd' => number_format($this->dto->valorTotal, 2, '.', ''),
            'cEANTrib' => $this->dto->ceanTributavel,
            'uTrib' => $this->dto->unidadeTributavel,
            'qTrib' => number_format($this->dto->quantidadeTributavel, 4, '.', ''),
            'vUnTrib' => number_format($this->dto->valorUnitarioTributavel, 10, '.', ''),
            'indTot' => $this->dto->indTot,
            'cest' => $this->dto->cest,
        ];

        $make->tagprod($std);
    }

    public function validate(): bool
    {
        if (empty($this->dto->codigo)) {
            throw new \InvalidArgumentException('Código do produto é obrigatório');
        }

        if (empty($this->dto->descricao)) {
            throw new \InvalidArgumentException('Descrição do produto é obrigatória');
        }

        if (empty($this->dto->ncm)) {
            throw new \InvalidArgumentException('NCM é obrigatório');
        }

        if (empty($this->dto->cfop)) {
            throw new \InvalidArgumentException('CFOP é obrigatório');
        }

        if ($this->dto->quantidadeComercial <= 0) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero');
        }

        if ($this->dto->valorUnitario <= 0) {
            throw new \InvalidArgumentException('Valor unitário deve ser maior que zero');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'produto';
    }
}
