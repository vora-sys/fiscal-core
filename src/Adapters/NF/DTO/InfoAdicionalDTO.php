<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para informações adicionais da NFe/NFCe
 * Corresponde à tag <infAdic> do XML
 */
class InfoAdicionalDTO
{
    public function __construct(
        // Informações de interesse do Fisco
        public ?string $infAdFisco = null,

        // Informações complementares de interesse do Contribuinte
        public ?string $infCpl = null,

        // Observações de interesse do Contribuinte
        public array $obsCont = [],         // [['xCampo' => 'Campo', 'xTexto' => 'Texto']]

        // Observações de interesse do Fisco
        public array $obsFisco = []         // [['xCampo' => 'Campo', 'xTexto' => 'Texto']]
    ) {}

    /**
     * Cria info adicional apenas com texto complementar
     */
    public static function simples(string $textoComplementar): self
    {
        return new self(infCpl: $textoComplementar);
    }

    /**
     * Cria info adicional para uso do fisco
     */
    public static function paraFisco(string $infoFisco): self
    {
        return new self(infAdFisco: $infoFisco);
    }

    /**
     * Adiciona observação do contribuinte
     */
    public function adicionarObsContribuinte(string $campo, string $texto): self
    {
        $clone = clone $this;
        $clone->obsCont[] = [
            'xCampo' => $campo,
            'xTexto' => $texto,
        ];

        return $clone;
    }

    /**
     * Adiciona observação do fisco
     */
    public function adicionarObsFisco(string $campo, string $texto): self
    {
        $clone = clone $this;
        $clone->obsFisco[] = [
            'xCampo' => $campo,
            'xTexto' => $texto,
        ];

        return $clone;
    }

    /**
     * Adiciona texto complementar (concatena se já existir)
     */
    public function adicionarTextoComplementar(string $texto, string $separador = '; '): self
    {
        $clone = clone $this;

        if ($this->infCpl) {
            $clone->infCpl .= $separador.$texto;
        } else {
            $clone->infCpl = $texto;
        }

        return $clone;
    }

    /**
     * Helper para adicionar informações comuns de NFCe
     */
    public static function paraNCe(
        ?string $identificacaoVendedor = null,
        ?string $mensagemPromocional = null
    ): self {
        $info = new self;
        $textos = [];

        if ($identificacaoVendedor) {
            $textos[] = "Vendedor: {$identificacaoVendedor}";
        }

        if ($mensagemPromocional) {
            $textos[] = $mensagemPromocional;
        }

        if (! empty($textos)) {
            $info->infCpl = implode(' | ', $textos);
        }

        return $info;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        if ($this->infAdFisco !== null) {
            $obj->infAdFisco = $this->infAdFisco;
        }
        if ($this->infCpl !== null) {
            $obj->infCpl = $this->infCpl;
        }
        $obj->obsCont = $this->obsCont;
        $obj->obsFisco = $this->obsFisco;

        return $obj;
    }
}
