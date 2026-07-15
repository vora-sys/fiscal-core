<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PagamentoDTO;

/**
 * Node para tag <pag> (Formas de Pagamento)
 * Usado principalmente em NFCe
 */
class PagamentoNode implements NotaNodeInterface
{
    /** @var PagamentoDTO[] */
    private array $pagamentos = [];

    public function __construct(PagamentoDTO ...$pagamentos)
    {
        $this->pagamentos = $pagamentos;
    }

    public function addToMake(Make $make): void
    {
        $make->tagpag((object) [
            'vTroco' => null,
        ]);

        foreach ($this->pagamentos as $pag) {
            $data = (object) [
                'indPag' => '0',
                'tPag' => $pag->tPag,
                'vPag' => number_format($pag->vPag, 2, '.', ''),
            ];

            // Dados do cartão (se houver)
            if ($pag->tpIntegra) {
                $data->tpIntegra = $pag->tpIntegra;
            }

            if ($pag->cnpj) {
                $data->CNPJ = $pag->cnpj;
            }

            if ($pag->tBand) {
                $data->tBand = $pag->tBand;
            }

            if ($pag->cAut) {
                $data->cAut = $pag->cAut;
            }

            $make->tagdetPag($data);
        }
    }

    public function validate(): bool
    {
        if (empty($this->pagamentos)) {
            throw new \InvalidArgumentException('Pelo menos uma forma de pagamento é obrigatória');
        }

        foreach ($this->pagamentos as $pag) {
            if (empty($pag->tPag)) {
                throw new \InvalidArgumentException('Tipo de pagamento é obrigatório');
            }

            if ($pag->vPag <= 0) {
                throw new \InvalidArgumentException('Valor do pagamento deve ser maior que zero');
            }
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'pagamento';
    }
}
