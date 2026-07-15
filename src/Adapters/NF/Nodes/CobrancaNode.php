<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\CobrancaDTO;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

/**
 * Node para dados de cobrança
 * Encapsula CobrancaDTO e adiciona à tag <cobr>
 * Usado apenas em NFe (não em NFCe)
 */
class CobrancaNode implements NotaNodeInterface
{
    public function __construct(
        private CobrancaDTO $cobranca
    ) {}

    public function getNodeType(): string
    {
        return 'cobranca';
    }

    public function validate(): bool
    {
        $errors = $this->cobranca->validate();

        if (! empty($errors)) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }

        return true;
    }

    public function addToMake(Make $make): void
    {
        // Adicionar fatura
        if ($this->cobranca->numeroFatura) {
            $make->tagfat(StdClassBuilder::props(
                $this->cobranca->numeroFatura,
                $this->cobranca->valorOriginal,
                $this->cobranca->valorDesconto,
                $this->cobranca->valorLiquido
            ));
        }

        // Adicionar duplicatas
        if (! empty($this->cobranca->duplicatas)) {
            foreach ($this->cobranca->duplicatas as $duplicata) {
                $make->tagdup(StdClassBuilder::create([
                    'nDup' => $duplicata['nDup'],
                    'dVenc' => $duplicata['dVenc'] ?? null,
                    'vDup' => $duplicata['vDup'],
                ]));
            }
        }
    }

    /**
     * Retorna o DTO encapsulado
     */
    public function getCobranca(): CobrancaDTO
    {
        return $this->cobranca;
    }
}
