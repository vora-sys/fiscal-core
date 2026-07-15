<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de transporte da NFe/NFCe
 * Corresponde à tag <transp> do XML
 */
class TransporteDTO
{
    public function __construct(
        // Modal de frete
        public int $modFrete,               // 0=Emitente, 1=Destinatário, 2=Terceiros, 3=Próprio Remetente, 4=Próprio Destinatário, 9=Sem Frete

        // Dados da transportadora (opcional)
        public ?string $cnpjCpf = null,     // CNPJ ou CPF
        public ?string $nome = null,        // Razão social ou nome
        public ?string $inscricaoEstadual = null,
        public ?string $endereco = null,
        public ?string $nomeMunicipio = null,
        public ?string $uf = null,

        // Veículo (opcional)
        public ?string $placa = null,
        public ?string $ufVeiculo = null,
        public ?string $rntc = null,        // RNTC (Registro Nacional de Transportadores de Carga)

        // Reboque (opcional)
        public ?array $reboque = null,      // ['placa' => '...', 'uf' => '...', 'rntc' => '...']

        // Volumes transportados (opcional)
        public ?array $volumes = null,      // [['qVol' => 1, 'esp' => 'Caixa', 'marca' => '...', 'nVol' => '...', 'pesoL' => 10.5, 'pesoB' => 12.0]]

        // Lacres (opcional)
        public ?array $lacres = null,       // [['nLacre' => '123'], ['nLacre' => '456']]
    ) {}

    /**
     * Cria transporte sem frete (para NFCe presencial)
     */
    public static function semFrete(): self
    {
        return new self(modFrete: 9);
    }

    /**
     * Cria transporte por conta do emitente
     */
    public static function porContaEmitente(
        string $cnpjCpf,
        string $nome,
        string $inscricaoEstadual,
        ?string $endereco = null,
        ?string $nomeMunicipio = null,
        ?string $uf = null
    ): self {
        return new self(
            modFrete: 0,
            cnpjCpf: $cnpjCpf,
            nome: $nome,
            inscricaoEstadual: $inscricaoEstadual,
            endereco: $endereco,
            nomeMunicipio: $nomeMunicipio,
            uf: $uf
        );
    }

    /**
     * Cria transporte por conta do destinatário
     */
    public static function porContaDestinatario(): self
    {
        return new self(modFrete: 1);
    }

    /**
     * Cria transporte por terceiros
     */
    public static function porTerceiros(
        string $cnpjCpf,
        string $nome,
        string $inscricaoEstadual,
        string $endereco,
        string $nomeMunicipio,
        string $uf
    ): self {
        return new self(
            modFrete: 2,
            cnpjCpf: $cnpjCpf,
            nome: $nome,
            inscricaoEstadual: $inscricaoEstadual,
            endereco: $endereco,
            nomeMunicipio: $nomeMunicipio,
            uf: $uf
        );
    }

    /**
     * Adiciona dados do veículo transportador
     */
    public function comVeiculo(string $placa, string $uf, ?string $rntc = null): self
    {
        $clone = clone $this;
        $clone->placa = $placa;
        $clone->ufVeiculo = $uf;
        $clone->rntc = $rntc;

        return $clone;
    }

    /**
     * Adiciona volumes transportados
     */
    public function comVolumes(array $volumes): self
    {
        $clone = clone $this;
        $clone->volumes = $volumes;

        return $clone;
    }

    /**
     * Adiciona lacres
     */
    public function comLacres(array $lacres): self
    {
        $clone = clone $this;
        $clone->lacres = array_map(fn ($lacre) => ['nLacre' => $lacre], $lacres);

        return $clone;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->modFrete = $this->modFrete;
        if ($this->cnpjCpf !== null) {
            $obj->cnpjCpf = $this->cnpjCpf;
        }
        if ($this->nome !== null) {
            $obj->nome = $this->nome;
        }
        if ($this->inscricaoEstadual !== null) {
            $obj->inscricaoEstadual = $this->inscricaoEstadual;
        }
        if ($this->endereco !== null) {
            $obj->endereco = $this->endereco;
        }
        if ($this->nomeMunicipio !== null) {
            $obj->nomeMunicipio = $this->nomeMunicipio;
        }
        if ($this->uf !== null) {
            $obj->uf = $this->uf;
        }
        if ($this->placa !== null) {
            $obj->placa = $this->placa;
        }
        if ($this->ufVeiculo !== null) {
            $obj->ufVeiculo = $this->ufVeiculo;
        }
        if ($this->rntc !== null) {
            $obj->rntc = $this->rntc;
        }
        if ($this->reboque !== null) {
            $obj->reboque = $this->reboque;
        }
        if ($this->volumes !== null) {
            $obj->volumes = $this->volumes;
        }
        if ($this->lacres !== null) {
            $obj->lacres = $this->lacres;
        }

        return $obj;
    }
}
