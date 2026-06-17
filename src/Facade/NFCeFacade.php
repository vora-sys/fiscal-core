<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\NF\NFCe\NFCeAdapter;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Support\ToolsFactory;
use sabbajohn\FiscalCore\Support\FiscalDocumentResultNormalizer;
use sabbajohn\FiscalCore\Support\FiscalResponseNormalizer;
/**
 * Facade para NFCe - Interface simplificada com tratamento de erros
 * Evita que aplicações recebam erros 500 fornecendo responses padronizados
 */
class NFCeFacade
{
    private ?NFCeAdapter $nfce = null;
    private ?ImpressaoAdapter $impressao = null;
    private ResponseHandler $responseHandler;
    private FiscalDocumentResultNormalizer $resultNormalizer;
    private FiscalResponseNormalizer $publicNormalizer;
    private ?FiscalResponse $initializationError = null;

    public function __construct(
        ?NFCeAdapter $nfce = null,
        ?ImpressaoAdapter $impressao = null
    ) {
        $this->responseHandler = new ResponseHandler();
        $this->resultNormalizer = new FiscalDocumentResultNormalizer();
        $this->publicNormalizer = new FiscalResponseNormalizer();
        
        if ($nfce !== null) {
            $this->nfce = $nfce;
        } else {
            // Usa método safe que retorna FiscalResponse  
            $toolsResponse = ToolsFactory::createNFCeToolsSafe();
            if ($toolsResponse->isSuccess()) {
                $this->nfce = new NFCeAdapter($toolsResponse->getData()['result']);
            } else {
                // Armazena o erro original
                $this->initializationError = $toolsResponse;
            }
        }
        
        if ($impressao !== null) {
            $this->impressao = $impressao;
        } else {
            try {
                $this->impressao = new ImpressaoAdapter();
            } catch (\Exception $e) {
                // Se falhar, impressao fica null
            }
        }
    }

    /**
     * Verifica se o adapter está inicializado e retorna erro original se não estiver
     */
    private function checkNFCeInitialization(): ?FiscalResponse
    {
        if ($this->nfce === null) {
            // Retorna o erro original se houver
            return $this->initializationError ?? FiscalResponse::error(
                'NFCe adapter não inicializado devido a erro de configuração',
                'INITIALIZATION_ERROR',
                'adapter_check',
                ['category' => 'configuration']
            );
        }
        return null;
    }

    /**
     * Emite uma NFCe com tratamento completo de erros
     * 
     * @param array $dados Dados da nota fiscal de consumidor
     * @return FiscalResponse Response padronizado
     */
    public function emitir(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            // Garante que é modelo 65 (NFCe)
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 65;
            }
            
            $result = $this->nfce->emitir($dados);

            $xmlAssinado = $this->nfce->getLastSignedXml();
            $xmlRetorno = $this->nfce->getLastResponseXml() ?? $result;

            return $this->resultNormalizer->normalizeEmissao(
                'nfce',
                'emissao_nfce',
                $xmlRetorno,
                $xmlAssinado,
                $this->extrairChaveAcesso($result),
                $this->extrairSituacao($xmlRetorno),
                [
                    'modelo' => 65,
                    'ambiente' => $dados['identificacao']['tpAmb'] ?? 2,
                ]
            );
        }, 'emissao_nfce');
    }

    /**
     * Consulta uma NFCe pelo número da chave
     * 
     * @param string $chave Chave de acesso da NFCe
     * @return FiscalResponse Response padronizado
     */
    public function consultar(string $chave): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }
            
            $result = $this->nfce->consultar($chave);

            return $this->resultNormalizer->normalizeConsulta(
                'nfce',
                'consulta_nfce',
                $result,
                $chave,
                $this->extrairSituacao($result)
            );
        }, 'consulta_nfce');
    }

    /**
     * Cancela uma NFCe
     * 
     * @param string $chave Chave de acesso
     * @param string $motivo Motivo do cancelamento
     * @param string $protocolo Protocolo de autorização
     * @return FiscalResponse Response padronizado
     */
    public function cancelar(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave, $motivo, $protocolo) {
            if (strlen($motivo) < 15) {
                throw new \InvalidArgumentException('Motivo deve ter pelo menos 15 caracteres');
            }
            
            $xmlResponse = $this->nfce->cancelar($chave, $motivo, $protocolo);
            $parsed = $this->parseEventResponse($xmlResponse);
            $ok = $this->isSefazOperationSuccessful($xmlResponse);
            
            return $this->publicNormalizer->normalizeFiscalOperation('nfce', 'cancelamento_nfce', [
                'status' => $parsed['xmotivo'] ?? null,
                'ok' => $ok,
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? $protocolo,
            ], [
                'chave_acesso' => $chave,
                'situacao' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? $protocolo,
            ], [], [
                'response_xml' => $xmlResponse,
                'parsed_response' => $parsed,
            ], [
                'cancelado' => $ok,
                'xml_response' => $xmlResponse,
                'cstat' => $this->extrairCStat($xmlResponse),
                'chave_acesso' => $chave,
                'motivo' => $motivo,
                'protocolo' => $protocolo
            ]);
        }, 'cancelamento_nfce');
    }

    /**
     * Gera DANFCe (Documento Auxiliar da NFCe)
     * 
     * @param string $xmlAutorizado XML da NFCe autorizada
     * @return FiscalResponse Response com o PDF ou erro
     */
    public function gerarDanfce(string $xmlAutorizado, array $context = []): FiscalResponse
    {
        return $this->responseHandler->handle(function() use ($xmlAutorizado, $context) {
            if (!isset($this->impressao)) {
                throw new \RuntimeException('Impressao adapter não disponível.');
            }
            
            if (empty($xmlAutorizado)) {
                throw new \InvalidArgumentException('XML autorizado é obrigatório');
            }
            
            $pdf = $this->impressao->gerarDanfce($xmlAutorizado, $context);

            return $this->resultNormalizer->normalizePdfBase64(
                'nfce',
                'geracao_danfce',
                $xmlAutorizado,
                base64_encode($pdf),
                'danfce_' . date('Ymd_His') . '.pdf',
                ['print_source' => 'custom_thermal_layout']
            );
        }, 'geracao_danfce');
    }

    public function baixarXml(string $chave): FiscalResponse
    {
        return $this->consultar($chave);
    }

    /**
     * Verifica status do serviço SEFAZ
     */
    public function verificarStatusSefaz(string $uf = '', ?int $ambiente = null): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($uf, $ambiente) {
            $result = $this->nfce->sefazStatus($uf, $ambiente);
            $cstat = $this->extractTagValue($result, ['cStat']);
            $xmotivo = $this->extractTagValue($result, ['xMotivo']);
            
            return $this->publicNormalizer->normalizeFiscalOperation('nfce', 'status_sefaz', [
                'status' => $xmotivo,
                'ok' => in_array((string) $cstat, ['107', '108', '109'], true),
                'cstat' => $cstat,
                'xmotivo' => $xmotivo,
            ], [
                'situacao' => $xmotivo,
            ], [], [
                'response_xml' => $result,
                'parsed_response' => ['cstat' => $cstat, 'xmotivo' => $xmotivo],
            ], [
                'xml_response' => $result,
                'uf' => $uf ?: 'SC',
                'ambiente' => $ambiente ?: 2,
                'status' => $this->extrairStatusSefaz($result)
            ]);
        }, 'status_sefaz');
    }

    /**
     * Construtor fluente para NFCe
     */
    public static function builder(): NotaFiscalBuilder
    {
        return new NotaFiscalBuilder();
    }

    /**
     * Cria NFCe a partir de array (sem emitir)
     */
    public function criarNota(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 65;
            }
            
            $nota = $this->nfce->criarNota($dados);
            $nota->validate();
            
            return [
                'nota_fiscal' => $nota,
                'modelo' => 65,
                'validada' => true,
                'chave_simulada' => $this->simularChaveAcesso($dados)
            ];
        }, 'criacao_nota');
    }

    // Métodos auxiliares (mesmo padrão do NFeFacade)
    
    private function extrairChaveAcesso(string $xml): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query('//chNFe')->item(0);
            return $node ? $node->nodeValue : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function extrairSituacao(string $xml): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query('//xMotivo')->item(0);
            return $node ? $node->nodeValue : 'Status não identificado';
        } catch (\Exception $e) {
            return 'Erro ao extrair situação';
        }
    }
    
    private function extrairStatusSefaz(string $xml): string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $cStat = $xpath->query('//cStat')->item(0);
            $xMotivo = $xpath->query('//xMotivo')->item(0);
            
            if ($cStat && $xMotivo) {
                return $cStat->nodeValue . ' - ' . $xMotivo->nodeValue;
            }
            return 'Status não identificado';
        } catch (\Exception $e) {
            return 'Erro ao extrair status';
        }
    }

    private function extrairCStat(string $xml): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $cStat = $xpath->query('//cStat')->item(0);
            return $cStat ? trim((string) $cStat->nodeValue) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseEventResponse(string $xml): array
    {
        return [
            'cstat' => $this->extractTagValue($xml, ['cStat']),
            'xmotivo' => $this->extractTagValue($xml, ['xMotivo']),
            'protocolo' => $this->extractTagValue($xml, ['nProt']),
            'chave' => $this->extractTagValue($xml, ['chNFe']),
        ];
    }

    private function extractTagValue(string $xml, array $tagNames): ?string
    {
        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            foreach ($tagNames as $tagName) {
                $node = $xpath->query("//*[local-name()='{$tagName}']")->item(0);
                if ($node instanceof \DOMNode) {
                    $value = trim((string) $node->textContent);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function isSefazOperationSuccessful(string $xml): bool
    {
        $cStat = $this->extrairCStat($xml);
        if ($cStat === null) {
            return false;
        }
        return in_array($cStat, ['100', '101', '102', '135', '136', '155'], true);
    }
    
    private function simularChaveAcesso(array $dados): string
    {
        $uf = $dados['emitente']['endereco']['cUF'] ?? '42';
        $cnpj = preg_replace('/\D/', '', $dados['emitente']['CNPJ'] ?? '00000000000000');
        $modelo = '65';
        $serie = str_pad($dados['identificacao']['serie'] ?? '1', 3, '0', STR_PAD_LEFT);
        $numero = str_pad($dados['identificacao']['nNF'] ?? '1', 9, '0', STR_PAD_LEFT);
        $codigo = str_pad(rand(10000000, 99999999), 8, '0');
        
        $chave = $uf . date('ym') . $cnpj . $modelo . $serie . $numero . '1' . $codigo;
        $dv = $this->calcularDV($chave);
        
        return $chave . $dv;
    }
    
    private function calcularDV(string $chave): string
    {
        $soma = 0;
        $peso = 2;
        
        for ($i = strlen($chave) - 1; $i >= 0; $i--) {
            $soma += (int)$chave[$i] * $peso;
            $peso = $peso == 9 ? 2 : $peso + 1;
        }
        
        $dv = 11 - ($soma % 11);
        return $dv >= 10 ? '0' : (string)$dv;
    }
}
