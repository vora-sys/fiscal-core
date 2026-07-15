<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\InfoAdicionalDTO;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para informações adicionais
 * Encapsula InfoAdicionalDTO e adiciona à tag <infAdic>
 */
class InfoAdicionalNode implements NotaNodeInterface
{
    public function __construct(
        private InfoAdicionalDTO $infoAdicional
    ) {}

    public function getNodeType(): string
    {
        return 'infoAdicional';
    }

    public function validate(): bool
    {
        // Validar tamanho dos textos (limites da SEFAZ)
        if ($this->infoAdicional->infAdFisco && strlen($this->infoAdicional->infAdFisco) > 2000) {
            throw new \InvalidArgumentException('Informações fiscais não podem ter mais de 2000 caracteres');
        }

        if ($this->infoAdicional->infCpl && strlen($this->infoAdicional->infCpl) > 5000) {
            throw new \InvalidArgumentException('Informações complementares não podem ter mais de 5000 caracteres');
        }

        return true;
    }

    public function addToMake(Make $make): void
    {
        // Adicionar tag de informações adicionais - props() captura os nomes automaticamente!
        $make->taginfAdic(StdClassBuilder::props(
            $this->infoAdicional->infAdFisco,
            $this->infoAdicional->infCpl
        ));

        // Adicionar observações do contribuinte
        if (! empty($this->infoAdicional->obsCont)) {
            foreach ($this->infoAdicional->obsCont as $obs) {
                $make->tagobsCont(StdClassBuilder::props(
                    $obs['xCampo'],
                    $obs['xTexto']
                ));
            }
        }

        // Adicionar observações do fisco
        if (! empty($this->infoAdicional->obsFisco)) {
            foreach ($this->infoAdicional->obsFisco as $obs) {
                $make->tagobsFisco(StdClassBuilder::props(
                    $obs['xCampo'],
                    $obs['xTexto']
                ));
            }
        }
    }

    /**
     * Retorna o DTO encapsulado
     */
    public function getInfoAdicional(): InfoAdicionalDTO
    {
        return $this->infoAdicional;
    }
}
