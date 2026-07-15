<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados de identificação da NFe/NFCe
 * Corresponde à tag <ide> do XML
 */
class IdentificacaoDTO
{
    public function __construct(
        public int $cUF,                    // Código UF (ex: 41 = Paraná)
        public int $cNF,                    // Código numérico da NF (8 dígitos)
        public string $natOp,               // Natureza da operação
        public int $mod,                    // Modelo: 55=NFe, 65=NFCe
        public int $serie,                  // Série da nota
        public int $nNF,                    // Número da nota
        public string $dhEmi,               // Data/hora de emissão (formato ISO8601)
        public int $tpNF,                   // Tipo: 0=Entrada, 1=Saída
        public int $idDest,                 // Destino operação: 1=Interna, 2=Interestadual, 3=Exterior
        public int $cMunFG,                 // Código município do fato gerador
        public int $tpImp,                  // Formato impressão: 1=Retrato, 2=Paisagem, 4=DANFE NFC-e
        public int $tpEmis,                 // Forma emissão: 1=Normal
        public int $cDV,                    // Dígito verificador da chave
        public int $tpAmb,                  // Ambiente: 1=Produção, 2=Homologação
        public int $finNFe,                 // Finalidade: 1=Normal, 2=Complementar, 3=Ajuste, 4=Devolução
        public int $indFinal,               // Consumidor final: 0=Não, 1=Sim
        public int $indPres,                // Presença: 0=Não se aplica, 1=Presencial, 2=Internet, etc
        public int $procEmi = 0,            // Processo de emissão: 0=Próprio
        public string $verProc = '1.0.0',   // Versão do processo
        public ?string $dhSaiEnt = null,    // Data/hora saída/entrada (opcional)
        public ?int $indIntermed = null,    // Indicador intermediador (opcional)
    ) {}

    /**
     * Cria DTO para NFCe (modelo 65) com valores padrão
     */
    public static function forNFCe(
        int $cUF,
        string $natOp,
        int $nNF,
        int $cMunFG,
        int $serie = 1,
        int $tpAmb = 2,
        int $cNF = 0
    ): self {
        return new self(
            cUF: $cUF,
            cNF: $cNF ?: rand(10000000, 99999999),
            natOp: $natOp,
            mod: 65,              // NFCe
            serie: $serie,
            nNF: $nNF,
            dhEmi: date('Y-m-d\TH:i:sP'),
            tpNF: 1,              // Saída
            idDest: 1,            // Interna
            cMunFG: $cMunFG,
            tpImp: 4,             // DANFE NFC-e
            tpEmis: 1,            // Normal
            cDV: 0,               // Será calculado pelo NFePHP
            tpAmb: $tpAmb,
            finNFe: 1,            // Normal
            indFinal: 1,          // Consumidor final
            indPres: 1,           // Presencial
        );
    }

    /**
     * Cria DTO para NFe (modelo 55) com valores padrão
     */
    public static function forNFe(
        int $cUF,
        string $natOp,
        int $nNF,
        int $cMunFG,
        int $idDest,
        int $serie = 1,
        int $tpAmb = 2,
        int $cNF = 0
    ): self {
        return new self(
            cUF: $cUF,
            cNF: $cNF ?: rand(10000000, 99999999),
            natOp: $natOp,
            mod: 55,              // NFe
            serie: $serie,
            nNF: $nNF,
            dhEmi: date('Y-m-d\TH:i:sP'),
            tpNF: 1,              // Saída
            idDest: $idDest,
            cMunFG: $cMunFG,
            tpImp: 1,             // Retrato
            tpEmis: 1,            // Normal
            cDV: 0,               // Será calculado pelo NFePHP
            tpAmb: $tpAmb,
            finNFe: 1,            // Normal
            indFinal: 0,          // Não é consumidor final (padrão)
            indPres: 1,           // Presencial
        );
    }

    /**
     * Converte para stdClass (formato esperado pelo NFePHP Make)
     */
    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cUF = $this->cUF;
        $obj->cNF = $this->cNF;
        $obj->natOp = $this->natOp;
        $obj->mod = $this->mod;
        $obj->serie = $this->serie;
        $obj->nNF = $this->nNF;
        $obj->dhEmi = $this->dhEmi;
        $obj->tpNF = $this->tpNF;
        $obj->idDest = $this->idDest;
        $obj->cMunFG = $this->cMunFG;
        $obj->tpImp = $this->tpImp;
        $obj->tpEmis = $this->tpEmis;
        $obj->cDV = $this->cDV;
        $obj->tpAmb = $this->tpAmb;
        $obj->finNFe = $this->finNFe;
        $obj->indFinal = $this->indFinal;
        $obj->indPres = $this->indPres;
        $obj->procEmi = $this->procEmi;
        $obj->verProc = $this->verProc;
        if ($this->dhSaiEnt) {
            $obj->dhSaiEnt = $this->dhSaiEnt;
        }
        if ($this->indIntermed !== null) {
            $obj->indIntermed = $this->indIntermed;
        }

        return $obj;
    }
}
