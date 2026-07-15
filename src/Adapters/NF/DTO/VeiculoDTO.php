<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados específicos de veículos (automotivo)
 * Corresponde à tag <veicProd> do XML
 * Usado quando NCM indica veículo novo (ex: 8703.XXXX)
 */
class VeiculoDTO
{
    public function __construct(
        // Dados obrigatórios
        public string $tpOp,                // Tipo operação: 1=Venda concessionária, 2=Faturamento direto, 3=Venda direta, 0=Outros
        public string $chassi,              // Chassi do veículo
        public string $cCor,                // Código da cor (DENATRAN)
        public string $xCor,                // Descrição da cor
        public string $pot,                 // Potência (cv)
        public string $cilin,               // Cilindrada (cm³)
        public string $pesoL,               // Peso líquido (kg)
        public string $pesoB,               // Peso bruto (kg)
        public string $nSerie,              // Número de série
        public string $tpComb,              // Tipo combustível (01-18)
        public string $nMotor,              // Número do motor
        public string $cmt,                 // CMT (Capacidade Máxima de Tração) em kg
        public string $dist,                // Distância entre eixos
        public int $anoMod,                 // Ano modelo fabricação
        public int $anoFab,                 // Ano fabricação
        public string $tpPint,              // Tipo pintura (M=Metálica, S=Sólida, P=Perolizada)
        public string $tpVeic,              // Tipo veículo (02-26 conforme RENAVAM)
        public string $espVeic,             // Espécie veículo (1=Passageiro, 2=Carga, etc)
        public string $vin,                 // VIN (Vehicle Identification Number) - OPCIONAL mas recomendado
        public string $condVeic,            // Condição veículo: 1=Acabado, 2=Inacabado, 3=Semi-acabado

        // Dados opcionais
        public ?string $cMod = null,        // Código Marca/Modelo (Tabela RENAVAM)
        public ?string $cCorDENATRAN = null, // Código cor DENATRAN
        public ?int $lota = null,           // Lotação máxima
        public ?int $tpRest = null,         // Restrição: 0=Não, 1=Alienação, 2=Outras
    ) {}

    /**
     * Cria DTO para veículo de passeio
     */
    public static function passeio(
        string $chassi,
        string $cor,
        string $codigoCor,
        string $anoModelo,
        string $anoFabricacao,
        string $motor,
        string $potencia,
        string $combustivel
    ): self {
        return new self(
            tpOp: '1',              // Venda concessionária
            chassi: $chassi,
            cCor: $codigoCor,
            xCor: $cor,
            pot: $potencia,
            cilin: '1000',          // Default
            pesoL: '1000',
            pesoB: '1200',
            nSerie: '',
            tpComb: $combustivel,
            nMotor: $motor,
            cmt: '0',
            dist: '2450',           // Distância típica entre eixos
            anoMod: (int) $anoModelo,
            anoFab: (int) $anoFabricacao,
            tpPint: 'M',            // Metálica
            tpVeic: '06',           // Automóvel
            espVeic: '1',           // Passageiro
            vin: '',
            condVeic: '1'           // Acabado
        );
    }

    /**
     * Códigos de tipo de combustível
     */
    public const COMBUSTIVEIS = [
        '01' => 'Álcool',
        '02' => 'Gasolina',
        '03' => 'Diesel',
        '04' => 'Gasogênio',
        '05' => 'Gás Metano',
        '06' => 'Elétrico/Fonte Interna',
        '07' => 'Elétrico/Fonte Externa',
        '08' => 'Gasol/Gás Natural Combustível',
        '09' => 'Álcool/Gás Natural Combustível',
        '10' => 'Diesel/Gás Natural Combustível',
        '11' => 'Vide/Diversas',
        '12' => 'Álcool/Gás Natural Veicular',
        '13' => 'Gasolina/Gás Natural Veicular',
        '14' => 'Diesel/Gás Natural Veicular',
        '15' => 'Gás Natural Veicular',
        '16' => 'Álcool/Gasolina',
        '17' => 'Gasolina/Álcool/Gás Natural',
        '18' => 'Gasolina/Elétrico',
    ];

    /**
     * Valida dados do veículo
     */
    public function validate(): array
    {
        $errors = [];

        // Validar chassi (deve ter 17 caracteres)
        if (strlen($this->chassi) !== 17) {
            $errors[] = 'Chassi deve ter 17 caracteres';
        }

        // Validar ano modelo/fabricação
        $anoAtual = (int) date('Y');
        if ($this->anoMod < 1900 || $this->anoMod > ($anoAtual + 1)) {
            $errors[] = "Ano modelo inválido: {$this->anoMod}";
        }

        if ($this->anoFab < 1900 || $this->anoFab > $anoAtual) {
            $errors[] = "Ano fabricação inválido: {$this->anoFab}";
        }

        // Validar tipo combustível
        if (! isset(self::COMBUSTIVEIS[$this->tpComb])) {
            $errors[] = "Tipo combustível inválido: {$this->tpComb}";
        }

        return $errors;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->tpOp = $this->tpOp;
        $obj->chassi = $this->chassi;
        $obj->cCor = $this->cCor;
        $obj->xCor = $this->xCor;
        $obj->pot = $this->pot;
        $obj->cilin = $this->cilin;
        $obj->pesoL = $this->pesoL;
        $obj->pesoB = $this->pesoB;
        $obj->nSerie = $this->nSerie;
        $obj->tpComb = $this->tpComb;
        $obj->nMotor = $this->nMotor;
        $obj->cmt = $this->cmt;
        $obj->dist = $this->dist;
        $obj->anoMod = $this->anoMod;
        $obj->anoFab = $this->anoFab;
        $obj->tpPint = $this->tpPint;
        $obj->tpVeic = $this->tpVeic;
        $obj->espVeic = $this->espVeic;
        $obj->vin = $this->vin;
        $obj->condVeic = $this->condVeic;
        if ($this->cMod !== null) {
            $obj->cMod = $this->cMod;
        }
        if ($this->cCorDENATRAN !== null) {
            $obj->cCorDENATRAN = $this->cCorDENATRAN;
        }
        if ($this->lota !== null) {
            $obj->lota = $this->lota;
        }
        if ($this->tpRest !== null) {
            $obj->tpRest = $this->tpRest;
        }

        return $obj;
    }
}
