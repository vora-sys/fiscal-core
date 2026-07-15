<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\ResponsavelTecnicoDTO;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para dados do responsável técnico
 * Encapsula ResponsavelTecnicoDTO e adiciona à tag <infRespTec>
 */
class ResponsavelTecnicoNode implements NotaNodeInterface
{
    public function __construct(
        private ResponsavelTecnicoDTO $responsavel
    ) {}

    public function getNodeType(): string
    {
        return 'responsavelTecnico';
    }

    public function validate(): bool
    {
        $errors = $this->responsavel->validate();

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        return true;
    }

    public function addToMake(Make $make): void
    {
        // Usar create() pois props() falha com indentação complexa
        $make->taginfRespTec(StdClassBuilder::create([
            'CNPJ' => $this->responsavel->cnpj,
            'xContato' => $this->responsavel->xContato,
            'email' => $this->responsavel->email,
            'fone' => $this->responsavel->fone,
            'idCSRT' => $this->responsavel->idCSRT,
            'hashCSRT' => $this->responsavel->hashCSRT,
        ]));
    }

    /**
     * Retorna o DTO encapsulado
     */
    public function getResponsavel(): ResponsavelTecnicoDTO
    {
        return $this->responsavel;
    }
}
