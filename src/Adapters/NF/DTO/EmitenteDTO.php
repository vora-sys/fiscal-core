<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados do Emitente
 * Corresponde à tag <emit> do XML
 */
class EmitenteDTO
{
    public function __construct(
        public string $cnpj,
        public string $razaoSocial,
        public string $nomeFantasia,
        public string $inscricaoEstadual,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $codigoMunicipio,
        public string $nomeMunicipio,
        public string $uf,
        public string $cep,
        public string $codigoPais = '1058',           // Brasil
        public string $nomePais = 'BRASIL',
        public ?string $complemento = null,
        public ?string $telefone = null,
        public ?string $inscricaoMunicipal = null,
        public ?string $cnae = null,
        public ?int $crt = 1,                         // 1=Simples Nacional, 3=Normal
    ) {}

    /**
     * Converte DTO para stdClass com nomes de propriedades conforme NFePHP/XML
     */
    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->CNPJ = $this->cnpj;
        $obj->xNome = $this->razaoSocial;
        $obj->xFant = $this->nomeFantasia;
        $obj->IE = $this->inscricaoEstadual;
        $obj->xLgr = $this->logradouro;
        $obj->nro = $this->numero;
        $obj->xBairro = $this->bairro;
        $obj->cMun = $this->codigoMunicipio;
        $obj->xMun = $this->nomeMunicipio;
        $obj->UF = $this->uf;
        $obj->CEP = $this->cep;
        $obj->cPais = $this->codigoPais;
        $obj->xPais = $this->nomePais;
        $obj->CRT = $this->crt;
        if ($this->complemento !== null) {
            $obj->xCpl = $this->complemento;
        }
        if ($this->telefone !== null) {
            $obj->fone = $this->telefone;
        }
        if ($this->inscricaoMunicipal !== null) {
            $obj->IM = $this->inscricaoMunicipal;
        }
        if ($this->cnae !== null) {
            $obj->CNAE = $this->cnae;
        }

        return $obj;
    }
}
