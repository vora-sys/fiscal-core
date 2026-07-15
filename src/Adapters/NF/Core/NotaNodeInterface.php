<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Core;

use NFePHP\NFe\Make;

/**
 * Interface base para nós da Nota Fiscal (Composite Pattern)
 *
 * Cada parte da NFe/NFCe (emitente, destinatário, produtos, etc.)
 * implementa esta interface e sabe como se adicionar ao Make do NFePHP.
 */
interface NotaNodeInterface
{
    /**
     * Adiciona este nó ao objeto Make do NFePHP
     *
     * @param  Make  $make  Objeto Make do NFePHP
     *
     * @throws \Exception Se houver erro ao adicionar o nó
     */
    public function addToMake(Make $make): void;

    /**
     * Valida se os dados do nó estão corretos
     *
     * @throws \InvalidArgumentException Se dados inválidos
     */
    public function validate(): bool;

    /**
     * Retorna o tipo do nó (para debug/logs)
     */
    public function getNodeType(): string;
}
