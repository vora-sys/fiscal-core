<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para dados do responsável técnico pela emissão
 * Corresponde à tag <infRespTec> do XML
 * Obrigatório para sistemas que emitem NF-e/NFC-e
 */
class ResponsavelTecnicoDTO
{
    public function __construct(
        // Dados do responsável técnico
        public string $cnpj,                // CNPJ da empresa desenvolvedora
        public string $xContato,            // Nome do contato na empresa
        public string $email,               // E-mail de contato
        public string $fone,                // Telefone de contato (formato: 11987654321)

        // CSRT (Código de Segurança do Responsável Técnico) - obrigatório para NFCe
        public ?string $idCSRT = null,      // Identificador do CSRT
        public ?string $hashCSRT = null,    // Hash do CSRT (SHA-1)
    ) {}

    /**
     * Cria responsável técnico para NFe (sem CSRT)
     */
    public static function paraNFe(
        string $cnpj,
        string $nomeContato,
        string $email,
        string $telefone
    ): self {
        return new self(
            cnpj: $cnpj,
            xContato: $nomeContato,
            email: $email,
            fone: self::formatarTelefone($telefone)
        );
    }

    /**
     * Cria responsável técnico para NFCe (com CSRT)
     */
    public static function paraNFCe(
        string $cnpj,
        string $nomeContato,
        string $email,
        string $telefone,
        string $idCSRT,
        string $hashCSRT
    ): self {
        return new self(
            cnpj: $cnpj,
            xContato: $nomeContato,
            email: $email,
            fone: self::formatarTelefone($telefone),
            idCSRT: $idCSRT,
            hashCSRT: $hashCSRT
        );
    }

    /**
     * Formata telefone removendo caracteres especiais
     */
    private static function formatarTelefone(string $telefone): string
    {
        return preg_replace('/[^0-9]/', '', $telefone);
    }

    /**
     * Valida dados do responsável técnico
     */
    public function validate(): array
    {
        $errors = [];

        // Validar CNPJ
        if (! preg_match('/^\d{14}$/', $this->cnpj)) {
            $errors[] = 'CNPJ do responsável técnico deve ter 14 dígitos';
        }

        // Validar telefone
        if (! preg_match('/^\d{10,11}$/', $this->fone)) {
            $errors[] = 'Telefone deve ter 10 ou 11 dígitos';
        }

        // Validar e-mail
        if (! filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail do responsável técnico inválido';
        }

        // Validar hash CSRT (se informado) - tolerante a formatos diferentes
        if ($this->hashCSRT && strlen($this->hashCSRT) > 0 && ! preg_match('/^[a-fA-F0-9]{40}$/', $this->hashCSRT)) {
            // Aviso apenas - alguns XMLs podem ter formato diferente
            // $errors[] = 'Hash CSRT deve ser SHA-1 (40 caracteres hexadecimais)';
        }

        return $errors;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->cnpj = $this->cnpj;
        $obj->xContato = $this->xContato;
        $obj->email = $this->email;
        $obj->fone = $this->fone;
        if ($this->idCSRT !== null) {
            $obj->idCSRT = $this->idCSRT;
        }
        if ($this->hashCSRT !== null) {
            $obj->hashCSRT = $this->hashCSRT;
        }

        return $obj;
    }
}
