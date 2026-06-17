<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\ResponseHandler;

/**
 * Facade principal - Orquestra todos os outros facades
 * Interface unificada para todas as operações fiscais
 */
class FiscalFacade
{
    private NFeFacade $nfe;
    private NFCeFacade $nfce;
    private NFSeFacade $nfse;
    /** @var array<string,NFSeFacade> */
    private array $nfseByMunicipio = [];
    private ImpressaoFacade $impressao;
    private TributacaoFacade $tributacao;
    private ResponseHandler $responseHandler;

    public function __construct(
        ?NFeFacade $nfe = null,
        ?NFCeFacade $nfce = null,
        ?NFSeFacade $nfse = null,
        ?ImpressaoFacade $impressao = null,
        ?TributacaoFacade $tributacao = null
    ) {
        $this->responseHandler = new ResponseHandler();
        
        // Inicializa facades com fallback para instâncias padrão
        $this->nfe = $nfe ?? new NFeFacade();
        $this->nfce = $nfce ?? new NFCeFacade();
        $this->nfse = $nfse ?? new NFSeFacade();
        $this->nfseByMunicipio['__default__'] = $this->nfse;
        $this->impressao = $impressao ?? new ImpressaoFacade();
        $this->tributacao = $tributacao ?? new TributacaoFacade();
    }

    // ===== OPERAÇÕES NFe =====

    /**
     * Emite uma NFe
     */
    public function emitirNFe(array $dados): FiscalResponse
    {
        return $this->nfe->emitir($dados);
    }

    /**
     * Consulta status de uma NFe
     */
    public function consultarNFe(string $chave): FiscalResponse
    {
        return $this->nfe->consultar($chave);
    }

    public function baixarXmlNFe(string $chave): FiscalResponse
    {
        return $this->nfe->baixarXml($chave);
    }

    /**
     * Cancela uma NFe
     */
    public function cancelarNFe(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        return $this->nfe->cancelar($chave, $motivo, $protocolo);
    }

    public function cartaCorrecaoNFe(string $chave, string $correcao, int $sequencia = 1, array $opcoes = []): FiscalResponse
    {
        return $this->nfe->cartaCorrecao($chave, $correcao, $sequencia, $opcoes);
    }

    public function registrarEventoSefazNFe(
        string $uf,
        string $chave,
        int $tipoEvento,
        int $sequencia = 1,
        string $tagAdicional = '',
        array $opcoes = []
    ): FiscalResponse {
        return $this->nfe->registrarEventoSefaz($uf, $chave, $tipoEvento, $sequencia, $tagAdicional, $opcoes);
    }

    public function registrarEventoSefazLoteNFe(string $uf, array|\stdClass $eventos, array $opcoes = []): FiscalResponse
    {
        return $this->nfe->registrarEventoSefazLote($uf, $eventos, $opcoes);
    }

    // ===== OPERAÇÕES NFCe =====

    /**
     * Emite uma NFCe
     */
    public function emitirNFCe(array $dados): FiscalResponse
    {
        return $this->nfce->emitir($dados);
    }

    /**
     * Consulta status de uma NFCe
     */
    public function consultarNFCe(string $chave): FiscalResponse
    {
        return $this->nfce->consultar($chave);
    }

    public function consultarDocumentoPorChave(string $chave): FiscalResponse
    {
        $modelo = $this->detectDocumentModelFromChave($chave);

        return match ($modelo) {
            '55' => $this->consultarNFe($chave),
            '65' => $this->consultarNFCe($chave),
            default => FiscalResponse::error(
                "Modelo '{$modelo}' não suportado para consulta por chave",
                'UNSUPPORTED_DOCUMENT_MODEL',
                'consulta_documento_por_chave',
                ['chave_acesso' => $chave, 'modelo' => $modelo]
            ),
        };
    }

    public function baixarXmlNFCe(string $chave): FiscalResponse
    {
        return $this->nfce->baixarXml($chave);
    }

    public function baixarXmlDocumentoPorChave(string $chave): FiscalResponse
    {
        $modelo = $this->detectDocumentModelFromChave($chave);

        return match ($modelo) {
            '55' => $this->baixarXmlNFe($chave),
            '65' => $this->baixarXmlNFCe($chave),
            default => FiscalResponse::error(
                "Modelo '{$modelo}' não suportado para download de XML por chave",
                'UNSUPPORTED_DOCUMENT_MODEL',
                'download_xml_documento_por_chave',
                ['chave_acesso' => $chave, 'modelo' => $modelo]
            ),
        };
    }

    /**
     * Cancela uma NFCe
     */
    public function cancelarNFCe(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        return $this->nfce->cancelar($chave, $motivo, $protocolo);
    }

    public function cancelarPorSubstituicaoNFCe(
        string $chave,
        string $motivo,
        string $protocolo,
        string $chaveSubstituta,
        ?string $verAplic = null,
        array $opcoes = []
    ): FiscalResponse {
        return $this->nfce->cancelarPorSubstituicao($chave, $motivo, $protocolo, $chaveSubstituta, $verAplic, $opcoes);
    }

    public function registrarEventoSefazNFCe(
        string $uf,
        string $chave,
        int $tipoEvento,
        int $sequencia = 1,
        string $tagAdicional = '',
        array $opcoes = []
    ): FiscalResponse {
        return $this->nfce->registrarEventoSefaz($uf, $chave, $tipoEvento, $sequencia, $tagAdicional, $opcoes);
    }

    public function registrarEventoSefazLoteNFCe(string $uf, array|\stdClass $eventos, array $opcoes = []): FiscalResponse
    {
        return $this->nfce->registrarEventoSefazLote($uf, $eventos, $opcoes);
    }

    // ===== OPERAÇÕES NFSe =====

    /**
     * Emite uma NFSe
     */
    public function emitirNFSe(array $dados, string $municipio = 'nacional'): FiscalResponse
    {
        return $this->nfse($municipio)->emitir($dados);
    }

    public function emitirNFSeCompleto(array $dados, string $municipio = 'nacional', array $options = []): FiscalResponse
    {
        return $this->nfse($municipio)->emitirCompleto($dados, $options);
    }

    public function consultarDisponibilidadeNFSe(array $criterios, string $municipio = 'nacional', array $options = []): FiscalResponse
    {
        return $this->nfse($municipio)->consultarDisponibilidade($criterios, $options);
    }

    /**
     * Consulta uma NFSe
     */
    public function consultarNFSe(string $chave, string $municipio = 'nacional'): FiscalResponse
    {
        return $this->nfse($municipio)->consultar($chave);
    }

    public function baixarXmlNFSe(string $chave, string $municipio = 'nacional'): FiscalResponse
    {
        return $this->nfse($municipio)->baixarXml($chave);
    }

    // ===== OPERAÇÕES DE IMPRESSÃO =====

    /**
     * Gera DANFE (PDF da NFe)
     */
    public function gerarDanfe(string $xmlNfe): FiscalResponse
    {
        return $this->impressao->gerarDanfe($xmlNfe);
    }

    /**
     * Gera DANFCE (PDF da NFCe)
     */
    public function gerarDanfce(string $xmlNfce, array $context = []): FiscalResponse
    {
        return $this->impressao->gerarDanfce($xmlNfce, $context);
    }

    // ===== OPERAÇÕES DE TRIBUTAÇÃO =====

    /**
     * Calcula tributos de um produto
     */
    public function calcularTributos(array $produto): FiscalResponse
    {
        return $this->tributacao->calcular($produto);
    }

    /**
     * Consulta NCM
     */
    public function consultarNCM(string $ncm): FiscalResponse
    {
        return $this->tributacao->consultarNCM($ncm);
    }

    // ===== OPERAÇÕES DE STATUS =====

    /**
     * Verifica status geral do sistema
     */
    public function verificarStatus(): FiscalResponse
    {
        try {
            $status = [
                'nfe' => $this->nfe->verificarStatusSefaz(),
                'nfce' => $this->nfce->verificarStatusSefaz(), 
                'nfse' => $this->nfse->getProviderInfo(),
                'impressao' => $this->impressao->verificarStatus(),
                'tributacao' => $this->tributacao->verificarStatus()
            ];

            $overall = 'ok';
            foreach ($status as $service => $result) {
                if ($result->isError()) {
                    $overall = 'partial_failure';
                    break;
                }
            }

            return FiscalResponse::success([
                'overall_status' => $overall,
                'services' => $status
            ], 'fiscal_system_status');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'fiscal_system_status');
        }
    }

    /**
     * Obtém informações de configuração
     */
    public function getConfigInfo(): FiscalResponse
    {
        try {
            $info = [
                'facades_loaded' => [
                    'nfe' => get_class($this->nfe),
                    'nfce' => get_class($this->nfce),
                    'nfse' => get_class($this->nfse),
                    'impressao' => get_class($this->impressao),
                    'tributacao' => get_class($this->tributacao)
                ],
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'loaded_extensions' => get_loaded_extensions(),
                    'memory_usage' => memory_get_usage(true),
                    'memory_limit' => ini_get('memory_limit')
                ]
            ];

            return FiscalResponse::success($info, 'fiscal_config_info');
            
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'fiscal_config_info');
        }
    }

    // ===== GETTERS PARA ACESSO DIRETO AOS FACADES =====

    public function nfe(): NFeFacade
    {
        return $this->nfe;
    }

    public function nfce(): NFCeFacade  
    {
        return $this->nfce;
    }

    public function nfse(?string $municipio = null): NFSeFacade
    {
        if ($municipio !== null) {
            $key = strtolower(trim($municipio));
            if (!isset($this->nfseByMunicipio[$key])) {
                $this->nfseByMunicipio[$key] = new NFSeFacade($municipio);
            }

            return $this->nfseByMunicipio[$key];
        }
        return $this->nfse;
    }

    public function impressao(): ImpressaoFacade
    {
        return $this->impressao;
    }

    public function tributacao(): TributacaoFacade
    {
        return $this->tributacao;
    }

    public function detectDocumentModelFromChave(string $chave): string
    {
        $chave = preg_replace('/\D/', '', $chave);

        if (strlen($chave) !== 44) {
            throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
        }

        return substr($chave, 20, 2);
    }
}
