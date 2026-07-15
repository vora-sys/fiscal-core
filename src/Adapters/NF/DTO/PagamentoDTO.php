<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para forma de pagamento
 * Corresponde à tag <pag> do XML
 */
class PagamentoDTO
{
    public function __construct(
        public string $tPag,        // Forma: 01=Dinheiro, 03=Cartão Crédito, 04=Cartão Débito, etc
        public float $vPag,         // Valor do pagamento
        public ?string $tpIntegra = null,  // Tipo integração: 1=TEF, 2=POS
        public ?string $cnpj = null,        // CNPJ credenciadora (para cartão)
        public ?string $tBand = null,       // Bandeira do cartão (01=Visa, 02=Master, etc)
        public ?string $cAut = null,        // Autorização da operação
    ) {}

    /**
     * Pagamento em dinheiro
     */
    public static function dinheiro(float $valor): self
    {
        return new self(tPag: '01', vPag: $valor);
    }

    /**
     * Pagamento com cartão de crédito
     */
    public static function cartaoCredito(
        float $valor,
        ?string $cnpjCredenciadora = null,
        ?string $bandeira = null,
        ?string $autorizacao = null
    ): self {
        return new self(
            tPag: '03',
            vPag: $valor,
            tpIntegra: '1', // TEF
            cnpj: $cnpjCredenciadora,
            tBand: $bandeira,
            cAut: $autorizacao
        );
    }

    /**
     * Pagamento com cartão de débito
     */
    public static function cartaoDebito(
        float $valor,
        ?string $cnpjCredenciadora = null,
        ?string $bandeira = null,
        ?string $autorizacao = null
    ): self {
        return new self(
            tPag: '04',
            vPag: $valor,
            tpIntegra: '1', // TEF
            cnpj: $cnpjCredenciadora,
            tBand: $bandeira,
            cAut: $autorizacao
        );
    }

    /**
     * Pagamento via PIX
     */
    public static function pix(float $valor): self
    {
        return new self(tPag: '17', vPag: $valor);
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->tPag = $this->tPag;
        $obj->vPag = $this->vPag;
        if ($this->tpIntegra !== null) {
            $obj->tpIntegra = $this->tpIntegra;
        }
        if ($this->cnpj !== null) {
            $obj->cnpj = $this->cnpj;
        }
        if ($this->tBand !== null) {
            $obj->tBand = $this->tBand;
        }
        if ($this->cAut !== null) {
            $obj->cAut = $this->cAut;
        }

        return $obj;
    }
}
