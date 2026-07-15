<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados específicos de medicamentos
 * Corresponde à tag <med> do XML
 * Obrigatório para produtos farmacêuticos
 */
class MedicamentoDTO
{
    public function __construct(
        // Dados obrigatórios
        public string $cProdANVISA,         // Código do produto ANVISA

        // Dados do lote (opcional mas recomendado)
        public ?string $nLote = null,       // Número do lote
        public ?float $qLote = null,        // Quantidade no lote
        public ?\DateTime $dFab = null,     // Data de fabricação
        public ?\DateTime $dVal = null,     // Data de validade

        // Preço máximo consumidor
        public ?float $vPMC = null,         // Valor PMC (Preço Máximo ao Consumidor)

        // Rastreabilidade (SNGPC para controlados)
        public ?string $nBP = null,         // Número benefício fiscal
        public ?string $xMotivoIsencao = null, // Motivo isenção ICMS (medicamentos)
    ) {}

    /**
     * Cria DTO para medicamento controlado
     */
    public static function controlado(
        string $codigoANVISA,
        string $numeroLote,
        \DateTime $dataFabricacao,
        \DateTime $dataValidade,
        float $precoMaximo,
        float $quantidadeLote
    ): self {
        return new self(
            cProdANVISA: $codigoANVISA,
            nLote: $numeroLote,
            qLote: $quantidadeLote,
            dFab: $dataFabricacao,
            dVal: $dataValidade,
            vPMC: $precoMaximo
        );
    }

    /**
     * Cria DTO para medicamento genérico
     */
    public static function generico(
        string $codigoANVISA,
        float $precoMaximo
    ): self {
        return new self(
            cProdANVISA: $codigoANVISA,
            vPMC: $precoMaximo
        );
    }

    /**
     * Cria DTO para produto de higiene/cosmético (perfumaria)
     */
    public static function cosmetico(
        string $codigoANVISA,
        ?string $numeroLote = null,
        ?\DateTime $dataValidade = null
    ): self {
        return new self(
            cProdANVISA: $codigoANVISA,
            nLote: $numeroLote,
            dVal: $dataValidade
        );
    }

    /**
     * Adiciona dados de rastreabilidade
     */
    public function comRastreabilidade(string $numeroBeneficio): self
    {
        $clone = clone $this;
        $clone->nBP = $numeroBeneficio;

        return $clone;
    }

    /**
     * Adiciona motivo de isenção
     */
    public function comIsencao(string $motivo): self
    {
        $clone = clone $this;
        $clone->xMotivoIsencao = $motivo;

        return $clone;
    }

    /**
     * Valida dados do medicamento
     */
    public function validate(): array
    {
        $errors = [];

        // Validar código ANVISA (deve ter 13 dígitos)
        if (! preg_match('/^\d{13}$/', $this->cProdANVISA)) {
            $errors[] = 'Código ANVISA deve ter 13 dígitos';
        }

        // Validar datas
        if ($this->dFab && $this->dVal) {
            if ($this->dVal <= $this->dFab) {
                $errors[] = 'Data de validade deve ser posterior à data de fabricação';
            }
        }

        // Validar se medicamento não está vencido
        if ($this->dVal && $this->dVal < new \DateTime) {
            $errors[] = sprintf(
                'Medicamento vencido: validade %s',
                $this->dVal->format('d/m/Y')
            );
        }

        // Validar PMC
        if ($this->vPMC !== null && $this->vPMC <= 0) {
            $errors[] = 'Preço máximo ao consumidor (PMC) deve ser maior que zero';
        }

        // Validar quantidade do lote
        if ($this->qLote !== null && $this->qLote <= 0) {
            $errors[] = 'Quantidade do lote deve ser maior que zero';
        }

        return $errors;
    }

    /**
     * Verifica se o medicamento está próximo do vencimento (30 dias)
     */
    public function estaProximoVencimento(int $diasAlerta = 30): bool
    {
        if (! $this->dVal) {
            return false;
        }

        $hoje = new \DateTime;
        $diasRestantes = $hoje->diff($this->dVal)->days;

        return $diasRestantes <= $diasAlerta;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cProdANVISA = $this->cProdANVISA;
        if ($this->nLote !== null) {
            $obj->nLote = $this->nLote;
        }
        if ($this->qLote !== null) {
            $obj->qLote = $this->qLote;
        }
        if ($this->dFab !== null) {
            $obj->dFab = $this->dFab->format('Y-m-d');
        }
        if ($this->dVal !== null) {
            $obj->dVal = $this->dVal->format('Y-m-d');
        }
        if ($this->vPMC !== null) {
            $obj->vPMC = $this->vPMC;
        }
        if ($this->nBP !== null) {
            $obj->nBP = $this->nBP;
        }
        if ($this->xMotivoIsencao !== null) {
            $obj->xMotivoIsencao = $this->xMotivoIsencao;
        }

        return $obj;
    }
}
