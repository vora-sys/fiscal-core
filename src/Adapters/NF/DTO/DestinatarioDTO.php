<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados do Destinatário/Tomador
 * Corresponde à tag <dest> do XML
 */
class DestinatarioDTO
{
    public function __construct(
        public string $cpfCnpj,
        public string $nome,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $codigoMunicipio,
        public string $nomeMunicipio,
        public string $uf,
        public string $cep,
        public string $codigoPais = '1058',           // Brasil
        public string $nomePais = 'BRASIL',

        public ?string $inscricaoEstadual = null,
        public ?string $complemento = null,
        public ?string $telefone = null,
        public ?string $email = null,
        public int $indIEDest = 9,                    // 9=Não contribuinte
    ) {}

    /**
     * Cria DTO para consumidor final (CPF)
     */
    public static function consumidorFinal(
        string $cpf,
        string $nome,
        ?string $email = null
    ): self {
        return new self(
            cpfCnpj: $cpf,
            nome: $nome,
            logradouro: '',
            numero: '',
            bairro: '',
            codigoMunicipio: '',
            nomeMunicipio: '',
            uf: '',
            cep: '',
            email: $email,
            indIEDest: 9  // Não contribuinte
        );
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cpfCnpj = $this->cpfCnpj;
        $obj->nome = $this->nome;
        $obj->logradouro = $this->logradouro;
        $obj->numero = $this->numero;
        $obj->bairro = $this->bairro;
        $obj->codigoMunicipio = $this->codigoMunicipio;
        $obj->nomeMunicipio = $this->nomeMunicipio;
        $obj->uf = $this->uf;
        $obj->cep = $this->cep;
        $obj->codigoPais = $this->codigoPais;
        $obj->nomePais = $this->nomePais;
        if ($this->inscricaoEstadual !== null) {
            $obj->inscricaoEstadual = $this->inscricaoEstadual;
        }
        if ($this->complemento !== null) {
            $obj->complemento = $this->complemento;
        }
        if ($this->telefone !== null) {
            $obj->telefone = $this->telefone;
        }
        if ($this->email !== null) {
            $obj->email = $this->email;
        }
        $obj->indIEDest = $this->indIEDest;

        return $obj;
    }
}
