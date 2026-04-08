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

    /**
     * Cancela uma NFe
     */
    public function cancelarNFe(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        return $this->nfe->cancelar($chave, $motivo, $protocolo);
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

    /**
     * Cancela uma NFCe
     */
    public function cancelarNFCe(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        return $this->nfce->cancelar($chave, $motivo, $protocolo);
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
    public function gerarDanfce(string $xmlNfce): FiscalResponse
    {
        return $this->impressao->gerarDanfce($xmlNfce);
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
}
