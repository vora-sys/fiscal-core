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
use sabbajohn\FiscalCore\Support\SefazAdvancedMethodRegistry;
use sabbajohn\FiscalCore\Support\SefazResponseParser;
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
    private SefazResponseParser $sefazParser;
    private ?FiscalResponse $initializationError = null;

    public function __construct(
        ?NFCeAdapter $nfce = null,
        ?ImpressaoAdapter $impressao = null
    ) {
        $this->responseHandler = new ResponseHandler();
        $this->resultNormalizer = new FiscalDocumentResultNormalizer();
        $this->publicNormalizer = new FiscalResponseNormalizer();
        $this->sefazParser = new SefazResponseParser();
        
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
                'cstat' => $parsed['cstat'] ?? null,
                'chave_acesso' => $chave,
                'motivo' => $motivo,
                'protocolo' => $protocolo
            ]);
        }, 'cancelamento_nfce');
    }

    public function cancelarPorSubstituicao(
        string $chave,
        string $motivo,
        string $protocolo,
        string $chaveSubstituta,
        ?string $verAplic = null,
        array $opcoes = []
    ): FiscalResponse {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave, $motivo, $protocolo, $chaveSubstituta, $verAplic, $opcoes) {
            if (strlen($motivo) < 15) {
                throw new \InvalidArgumentException('Motivo deve ter pelo menos 15 caracteres');
            }

            $xmlResponse = $this->nfce->cancelarPorSubstituicao(
                $chave,
                $motivo,
                $protocolo,
                $chaveSubstituta,
                $verAplic,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('cancelamento_substituicao_nfce', $xmlResponse, [
                'chave_acesso' => $chave,
            ], [
                'cancelado' => $this->sefazParser->isSuccessfulEventResponse($xmlResponse),
                'chave_substituta' => $chaveSubstituta,
                'motivo' => $motivo,
                'protocolo_autorizacao' => $protocolo,
            ]);
        }, 'cancelamento_substituicao_nfce');
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

    public function registrarEventoSefaz(
        string $uf,
        string $chave,
        int $tipoEvento,
        int $sequencia = 1,
        string $tagAdicional = '',
        array $opcoes = []
    ): FiscalResponse {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $chave, $tipoEvento, $sequencia, $tagAdicional, $opcoes) {
            $this->sefazParser->validateXmlFragment($tagAdicional);
            $xmlResponse = $this->nfce->registrarEventoSefaz(
                $uf,
                $chave,
                $tipoEvento,
                $sequencia,
                $tagAdicional,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('registro_evento_sefaz_nfce', $xmlResponse, [
                'chave_acesso' => $chave,
            ], [
                'uf' => $uf,
                'tipo_evento' => $tipoEvento,
                'sequencia' => $sequencia,
            ]);
        }, 'registro_evento_sefaz_nfce');
    }

    public function registrarEventoSefazLote(string $uf, array|\stdClass $eventos, array $opcoes = []): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $eventos, $opcoes) {
            $this->validateEventBatchTags($eventos);
            $xmlResponse = $this->nfce->registrarEventoSefazLote(
                $uf,
                $eventos,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('registro_evento_sefaz_lote_nfce', $xmlResponse, [], [
                'uf' => $uf,
                'eventos_enviados' => $eventos,
            ]);
        }, 'registro_evento_sefaz_lote_nfce');
    }

    public function registrarEventoAvancado(string $metodo, array|\stdClass $dados, array $opcoes = []): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        if (!SefazAdvancedMethodRegistry::isAllowedForModel($metodo, 65)) {
            return FiscalResponse::error(
                "Método SEFAZ não suportado para NFCe: {$metodo}",
                'UNSUPPORTED_SEFAZ_METHOD',
                'registro_evento_avancado_nfce',
                [
                    'method' => $metodo,
                    'allowed_methods' => SefazAdvancedMethodRegistry::allowedMethodsForModel(65),
                    'category' => 'validation',
                ]
            );
        }

        return $this->responseHandler->handle(function () use ($metodo, $dados, $opcoes) {
            $xmlResponse = $this->nfce->registrarEventoAvancado($metodo, $dados, $opcoes);

            return $this->normalizeSefazEventOperation('registro_evento_avancado_nfce', $xmlResponse, [], [
                'metodo' => $metodo,
                'payload' => $dados,
            ]);
        }, 'registro_evento_avancado_nfce');
    }

    public function registrarEpec(string $xml, ?string $verAplic = null): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($xml, $verAplic) {
            $result = $this->nfce->registrarEpec($xml, $verAplic);
            $xmlResponse = $result['response_xml'];

            return $this->normalizeSefazEventOperation('registro_epec_nfce', $xmlResponse, [
                'xml' => $result['xml'],
            ], [
                'xml_contingencia' => $result['xml'],
                'ver_aplic' => $verAplic,
            ]);
        }, 'registro_epec_nfce');
    }

    public function verificarStatusEpec(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $ambiente, $ignorarContigencia) {
            $xmlResponse = $this->nfce->verificarStatusEpec($uf, $ambiente, $ignorarContigencia);
            $parsed = $this->sefazParser->parseCommonResponse($xmlResponse);
            $ok = in_array((string) ($parsed['cstat'] ?? ''), ['107', '108', '109'], true);

            return $this->publicNormalizer->normalizeFiscalOperation('nfce', 'status_epec_nfce', [
                'status' => $parsed['xmotivo'] ?? null,
                'ok' => $ok,
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
            ], [
                'situacao' => $parsed['xmotivo'] ?? null,
            ], [], [
                'request_xml' => $this->nfce?->getLastRequestXml(),
                'response_xml' => $xmlResponse,
                'parsed_response' => $parsed,
            ], [
                'xml_response' => $xmlResponse,
                'uf' => $uf,
                'ambiente' => $ambiente,
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
            ]);
        }, 'status_epec_nfce');
    }

    public function consultarCsc(int $indOperacao): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($indOperacao) {
            $xmlResponse = $this->nfce->consultarCsc($indOperacao);

            return $this->normalizeSefazCommonOperation('consulta_csc_nfce', $xmlResponse, [], [
                'ind_operacao' => $indOperacao,
            ]);
        }, 'consulta_csc_nfce');
    }

    public function validarXmlSchemaSefaz(string $xml): FiscalResponse
    {
        $initError = $this->checkNFCeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($xml) {
            return [
                'xml_valido_schema_sefaz' => $this->nfce->validarXmlSchemaSefaz($xml),
                'tamanho_xml' => strlen($xml),
            ];
        }, 'validacao_schema_sefaz_nfce');
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

    private function normalizeSefazEventOperation(
        string $operation,
        string $xmlResponse,
        array $documento = [],
        array $extra = []
    ): array {
        $parsed = $this->sefazParser->parseEventResponse($xmlResponse);

        $documento = array_merge([
            'chave_acesso' => $parsed['chave'] ?? null,
            'situacao' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento);

        if (($documento['chave_acesso'] ?? null) === null && ($parsed['chave'] ?? null) !== null) {
            $documento['chave_acesso'] = $parsed['chave'];
        }
        if (($documento['protocolo'] ?? null) === null && ($parsed['protocolo'] ?? null) !== null) {
            $documento['protocolo'] = $parsed['protocolo'];
        }

        return $this->publicNormalizer->normalizeFiscalOperation('nfce', $operation, [
            'status' => $parsed['xmotivo'] ?? null,
            'ok' => $parsed['ok'] ?? null,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento, [], [
            'request_xml' => $this->nfce?->getLastRequestXml(),
            'response_xml' => $xmlResponse,
            'parsed_response' => $parsed,
        ], array_merge([
            'xml_response' => $xmlResponse,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
            'chave_acesso' => $documento['chave_acesso'] ?? null,
            'eventos' => $parsed['eventos'] ?? [],
        ], $extra));
    }

    private function normalizeSefazCommonOperation(
        string $operation,
        string $xmlResponse,
        array $documento = [],
        array $extra = []
    ): array {
        $parsed = $this->sefazParser->parseCommonResponse($xmlResponse);

        $documento = array_merge([
            'chave_acesso' => $parsed['chave'] ?? null,
            'situacao' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento);

        return $this->publicNormalizer->normalizeFiscalOperation('nfce', $operation, [
            'status' => $parsed['xmotivo'] ?? null,
            'ok' => $parsed['ok'] ?? null,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento, [], [
            'request_xml' => $this->nfce?->getLastRequestXml(),
            'response_xml' => $xmlResponse,
            'parsed_response' => $parsed,
        ], array_merge([
            'xml_response' => $xmlResponse,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
            'chave_acesso' => $documento['chave_acesso'] ?? null,
        ], $extra));
    }

    private function extractDhEventoOption(array $opcoes): ?\DateTimeInterface
    {
        $value = $opcoes['dhEvento'] ?? $opcoes['dh_evento'] ?? null;
        if ($value === null || $value instanceof \DateTimeInterface) {
            return $value;
        }

        throw new \InvalidArgumentException('dhEvento deve implementar DateTimeInterface.');
    }

    private function validateEventBatchTags(array|\stdClass $eventos): void
    {
        $items = $eventos instanceof \stdClass ? ($eventos->evento ?? []) : $eventos;
        if (!is_iterable($items)) {
            throw new \InvalidArgumentException('Lote de eventos inválido.');
        }

        foreach ($items as $evento) {
            $tag = null;
            if (is_array($evento)) {
                $tag = $evento['tagAdic'] ?? $evento['tagAdicional'] ?? $evento['tag_adicional'] ?? null;
            } elseif ($evento instanceof \stdClass) {
                $tag = $evento->tagAdic ?? $evento->tagAdicional ?? $evento->tag_adicional ?? null;
            }

            if (is_string($tag)) {
                $this->sefazParser->validateXmlFragment($tag);
            }
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
        return $this->sefazParser->parseEventResponse($xml);
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
        return $this->sefazParser->isSuccessfulEventResponse($xml);
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
