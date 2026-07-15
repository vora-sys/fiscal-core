<?php

namespace sabbajohn\FiscalCore\Adapters\NF\DTO;

/**
 * DTO para informações suplementares de NFCe
 * Corresponde à tag <infNFeSupl> do XML
 * Contém QR Code e URL de consulta (obrigatório para NFCe)
 */
class InfoSuplementarDTO
{
    public function __construct(
        // Dados obrigatórios para NFCe
        public string $qrCode,              // Texto do QR Code para consulta
        public ?string $urlChave = null,    // URL de consulta da chave de acesso (opcional)
    ) {}

    /**
     * Cria info suplementar completa
     */
    public static function criar(string $qrCode, ?string $urlChave = null): self
    {
        return new self($qrCode, $urlChave);
    }

    /**
     * Gera QR Code para NFCe (homologação)
     *
     * Formato do QR Code:
     * <URL>?chNFe=<chave>&nVersao=100&tpAmb=<ambiente>&cDest=<CPF/CNPJ>&dhEmi=<hex>&vNF=<valor>&vICMS=<icms>&digVal=<digest>&cIdToken=<token>&cHashQRCode=<hash>
     */
    public static function gerarParaNFCe(
        string $chaveAcesso,
        string $urlConsulta,
        int $ambiente,
        string $dataEmissao,
        float $valorNota,
        float $valorICMS,
        string $digestValue,
        string $idToken,
        string $csc,
        ?string $cpfCnpjDestinatario = null
    ): self {
        // Montar parâmetros do QR Code
        $params = [
            'chNFe' => $chaveAcesso,
            'nVersao' => '100',
            'tpAmb' => $ambiente,
        ];

        if ($cpfCnpjDestinatario) {
            $params['cDest'] = $cpfCnpjDestinatario;
        }

        // Data em hexadecimal
        $params['dhEmi'] = bin2hex($dataEmissao);

        // Valores
        $params['vNF'] = number_format($valorNota, 2, '.', '');
        $params['vICMS'] = number_format($valorICMS, 2, '.', '');

        // Digest
        $params['digVal'] = $digestValue;

        // Token
        $params['cIdToken'] = $idToken;

        // Calcular hash do QR Code
        $dadosParaHash = implode('|', [
            $chaveAcesso,
            $params['nVersao'],
            $ambiente,
            $cpfCnpjDestinatario ?? '',
            $params['dhEmi'],
            $params['vNF'],
            $params['vICMS'],
            $digestValue,
            $csc,
        ]);

        $params['cHashQRCode'] = strtoupper(sha1($dadosParaHash));

        // Montar URL do QR Code
        $qrCode = $urlConsulta.'?'.http_build_query($params);

        return new self($qrCode, $urlConsulta);
    }

    /**
     * Valida estrutura da info suplementar
     */
    public function validate(): array
    {
        $errors = [];

        // Validar QR Code não vazio
        if (empty($this->qrCode)) {
            $errors[] = 'QR Code é obrigatório para NFCe';
        }

        // Validar formato da URL (se informada)
        if ($this->urlChave && ! filter_var($this->urlChave, FILTER_VALIDATE_URL)) {
            $errors[] = 'URL de consulta inválida';
        }

        // Validar se QR Code contém chave de acesso (WARNING apenas, não bloqueia)
        if (! str_contains($this->qrCode, 'chNFe=')) {
            // Aviso apenas - alguns QR Codes podem estar em formato diferente
            // $errors[] = 'QR Code não contém chave de acesso (chNFe)';
        }

        return $errors;
    }

    /**
     * Extrai chave de acesso do QR Code
     */
    public function extrairChaveAcesso(): ?string
    {
        if (preg_match('/chNFe=(\d{44})/', $this->qrCode, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass;
        $obj->qrCode = $this->qrCode;
        if ($this->urlChave !== null) {
            $obj->urlChave = $this->urlChave;
        }

        return $obj;
    }
}
