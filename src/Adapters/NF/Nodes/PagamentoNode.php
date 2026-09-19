<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;
use sabbajohn\FiscalCore\Adapters\NF\DTO\PagamentosDTO;
use NFePHP\NFe\Make;

/**
 * Node para tag <pag> (Formas de Pagamento)
 * Usado principalmente em NFCe
 */
class PagamentoNode implements NotaNodeInterface
{
    public function __construct(private readonly PagamentosDTO $pagamentos) {}

    public function addToMake(Make $make): void
    {
        $make->tagpag((object) [
            'vTroco' => $this->pagamentos->troco === null
                ? null
                : number_format($this->pagamentos->troco, 2, '.', ''),
        ]);

        foreach ($this->pagamentos->formas as $pag) {
            $data = (object) [
                'indPag' => $pag->indPag ?? '0',
                'tPag' => $pag->tPag,
                'vPag' => number_format($pag->vPag, 2, '.', ''),
            ];

            if ($pag->xPag) {
                $data->xPag = $pag->xPag;
            }

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

            if ($pag->cAut !== null && $pag->cAut !== '') {
                $data->cAut = $pag->cAut;
            }

            $detPag = $make->tagdetPag($data);

            // NFePHP usa empty() para cAut e descarta a string válida "0".
            if ($pag->cAut === '0') {
                $card = $detPag->getElementsByTagName('card')->item(0);

                if ($card instanceof \DOMElement && $card->getElementsByTagName('cAut')->length === 0) {
                    $authorization = $detPag->ownerDocument->createElement('cAut');
                    $authorization->appendChild($detPag->ownerDocument->createTextNode($pag->cAut));
                    $card->appendChild($authorization);
                }
            }
        }
    }

    public function validate(): bool
    {
        if ($this->pagamentos->troco !== null && $this->pagamentos->troco < 0) {
            throw new \InvalidArgumentException('Valor do troco deve ser maior ou igual a zero');
        }

        if (empty($this->pagamentos->formas)) {
            throw new \InvalidArgumentException('Pelo menos uma forma de pagamento é obrigatória');
        }

        foreach ($this->pagamentos->formas as $pag) {
            if (empty($pag->tPag)) {
                throw new \InvalidArgumentException('Tipo de pagamento é obrigatório');
            }

            if ($pag->indPag !== null && ! in_array($pag->indPag, ['0', '1'], true)) {
                throw new \InvalidArgumentException('Indicador do pagamento deve ser 0 (à vista) ou 1 (a prazo)');
            }

            if (($pag->tPag === '90' && $pag->vPag < 0) || ($pag->tPag !== '90' && $pag->vPag <= 0)) {
                throw new \InvalidArgumentException('Valor do pagamento deve ser maior que zero');
            }

            if ($pag->tPag === '99' && ($pag->xPag === null || mb_strlen($pag->xPag) < 2 || mb_strlen($pag->xPag) > 60)) {
                throw new \InvalidArgumentException("Descrição do pagamento 'Outros' deve possuir entre 2 e 60 caracteres");
            }
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'pagamento';
    }
}
