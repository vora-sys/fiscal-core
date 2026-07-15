<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\InfoSuplementarDTO;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para informações suplementares de NFCe
 * Encapsula InfoSuplementarDTO e adiciona QR Code e URL de consulta
 */
class InfoSuplementarNode implements NotaNodeInterface
{
    public function __construct(
        private InfoSuplementarDTO $infoSuplementar
    ) {}

    public function getNodeType(): string
    {
        return 'infoSuplementar';
    }

    public function validate(): bool
    {
        $errors = $this->infoSuplementar->validate();

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        return true;
    }

    public function addToMake(Make $make): void
    {
        // Adicionar QR Code
        $make->taginfNFeSupl(StdClassBuilder::props(
            $this->infoSuplementar->qrCode,
            $this->infoSuplementar->urlChave
        ));
    }

    /**
     * Retorna o DTO encapsulado
     */
    public function getInfoSuplementar(): InfoSuplementarDTO
    {
        return $this->infoSuplementar;
    }
}
