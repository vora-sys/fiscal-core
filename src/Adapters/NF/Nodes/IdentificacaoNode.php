<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\IdentificacaoDTO;

/**
 * Node para tag <ide> (Identificação da NFe/NFCe)
 */
class IdentificacaoNode implements NotaNodeInterface
{
    public function __construct(private IdentificacaoDTO $dto) {}

    public function addToMake(Make $make): void
    {
        $std = (object) [
            'cUF' => $this->dto->cUF,
            'natOp' => $this->dto->natOp,
            'mod' => $this->dto->mod,
            'serie' => $this->dto->serie,
            'nNF' => $this->dto->nNF,
            'dhEmi' => $this->dto->dhEmi,
            'dhSaiEnt' => $this->dto->dhSaiEnt,
            'tpNF' => $this->dto->tpNF,
            'idDest' => $this->dto->idDest,
            'cMunFG' => $this->dto->cMunFG,
            'tpImp' => $this->dto->tpImp,
            'tpEmis' => $this->dto->tpEmis,
            'tpAmb' => $this->dto->tpAmb,
            'finNFe' => $this->dto->finNFe,
            'indFinal' => $this->dto->indFinal,
            'indPres' => $this->dto->indPres,
            'procEmi' => $this->dto->procEmi,
            'verProc' => $this->dto->verProc,
        ];

        if (isset($this->dto->indIntermed)) {
            $std->indIntermed = $this->dto->indIntermed;
        }

        $make->tagide($std);
    }

    public function validate(): bool
    {
        // Validações básicas
        if (empty($this->dto->natOp)) {
            throw new \InvalidArgumentException('Natureza da operação é obrigatória');
        }

        if (! in_array($this->dto->mod, [55, 65])) {
            throw new \InvalidArgumentException('Modelo deve ser 55 (NFe) ou 65 (NFCe)');
        }

        if ($this->dto->nNF <= 0) {
            throw new \InvalidArgumentException('Número da nota deve ser maior que zero');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'identificacao';
    }

    public function getDto(): IdentificacaoDTO
    {
        return $this->dto;
    }
}
