<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\NF\NFeAdapter;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\ManifestationType;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Support\ToolsFactory;
use NFePHP\NFe\Tools;
use NFePHP\NFe\Complements;
use sabbajohn\FiscalCore\Support\FiscalDocumentResultNormalizer;
use sabbajohn\FiscalCore\Support\FiscalResponseNormalizer;
use sabbajohn\FiscalCore\Support\SefazAdvancedMethodRegistry;
use sabbajohn\FiscalCore\Support\SefazResponseParser;

/**
 * Facade para NFe - Interface simplificada e com tratamento de erros
 * Evita que aplicações recebam erros 500 fornecendo responses padronizados
 */
class NFeFacade
{
    private ?NFeAdapter $nfe = null;
    private ?ImpressaoAdapter $impressao = null;
    private ResponseHandler $responseHandler;
    private FiscalDocumentResultNormalizer $resultNormalizer;
    private FiscalResponseNormalizer $publicNormalizer;
    private SefazResponseParser $sefazParser;
    private ?FiscalResponse $initializationError = null;

    public function __construct(
        ?NFeAdapter $nfe = null,
        ?ImpressaoAdapter $impressao = null
    ) {
        $this->responseHandler = new ResponseHandler();
        $this->resultNormalizer = new FiscalDocumentResultNormalizer();
        $this->publicNormalizer = new FiscalResponseNormalizer();
        $this->sefazParser = new SefazResponseParser();
        
        if ($nfe !== null) {
            $this->nfe = $nfe;
        } else {
            // Usa método safe que retorna FiscalResponse
            $toolsResponse = ToolsFactory::createNFeToolsSafe();
            if ($toolsResponse->isSuccess()) {
                $this->nfe = new NFeAdapter($toolsResponse->getData()['result']);
            } else {
                // Armazena o erro original em vez de sobrescrever
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
    private function checkNFeInitialization(): ?FiscalResponse
    {
        if ($this->nfe === null) {
            // Retorna o erro original se houver, senão cria um genérico
            return $this->initializationError ?? FiscalResponse::error(
                'NFe adapter não inicializado devido a erro de configuração',
                'INITIALIZATION_ERROR',
                'adapter_check',
                ['category' => 'configuration']
            );
        }
        return null;
    }

    /**
     * Emite uma NFe com tratamento completo de erros
     * 
     * @param array $dados Dados da nota fiscal
     * @return FiscalResponse Response padronizado com sucesso/erro
     */
    public function emitir(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro e retorna erro original se houver
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            // Garante que é modelo 55 (NFe)
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 55;
            }
            
            $result = $this->nfe->emitir($dados);

            $xmlAssinado = $this->nfe->getLastSignedXml();
            $xmlRetorno = $this->nfe->getLastResponseXml() ?? $result;
            $xmlAutorizado = $this->buildAuthorizedXml($xmlAssinado, $xmlRetorno);
            $sefazRetorno = \sabbajohn\FiscalCore\Support\XmlUtils::parseSefazRetorno($xmlRetorno);
            $protocolo = $sefazRetorno['protocolo']['nProt'] ?? null;
            $chaveAcesso = $this->extrairChaveAcesso($xmlAutorizado ?? $result);
            $situacao = $this->extrairSituacao($xmlAutorizado ?? $xmlRetorno);

            return $this->resultNormalizer->normalizeEmissao(
                'nfe',
                'emissao_nfe',
                $xmlRetorno,
                $xmlAssinado,
                $chaveAcesso,
                $situacao,
                [
                    'modelo' => 55,
                    'ambiente' => $dados['identificacao']['tpAmb'] ?? 2,
                ],
                $xmlAutorizado,
                is_string($protocolo) && trim($protocolo) !== '' ? $protocolo : null
            );
        }, 'emissao_nfe');
    }

    /**
     * Consulta uma NFe pelo número da chave
     * 
     * @param string $chave Chave de acesso da NFe
     * @return FiscalResponse Response padronizado
     */
    public function consultar(string $chave): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }
            
            $result = $this->nfe->consultar($chave);

            return $this->resultNormalizer->normalizeConsulta(
                'nfe',
                'consulta_nfe',
                $result,
                $chave,
                $this->extrairSituacao($result)
            );
        }, 'consulta_nfe');
    }

    /**
     * Cancela uma NFe
     * 
     * @param string $chave Chave de acesso
     * @param string $motivo Motivo do cancelamento
     * @param string $protocolo Protocolo de autorização
     * @return FiscalResponse Response padronizado
     */
    public function cancelar(string $chave, string $motivo, string $protocolo): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($chave, $motivo, $protocolo) {
            if (strlen($motivo) < 15) {
                throw new \InvalidArgumentException('Motivo deve ter pelo menos 15 caracteres');
            }
            
            $xmlResponse = $this->nfe->cancelar($chave, $motivo, $protocolo);
            $parsed = $this->parseEventResponse($xmlResponse);
            $ok = $this->isSefazOperationSuccessful($xmlResponse);
            
            return $this->publicNormalizer->normalizeFiscalOperation('nfe', 'cancelamento_nfe', [
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
                'cancelado' => $this->isSefazOperationSuccessful($xmlResponse),
                'xml_response' => $xmlResponse,
                'cstat' => $parsed['cstat'] ?? null,
                'chave_acesso' => $chave,
                'motivo' => $motivo,
                'protocolo' => $protocolo
            ]);
        }, 'cancelamento_nfe');
    }

    /**
     * Inutiliza numeração da NFe
     */
    public function inutilizar(
        int $ano, 
        int $cnpj, 
        int $serie, 
        int $numeroInicial, 
        int $numeroFinal, 
        string $justificativa
    ): FiscalResponse {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use (
            $ano, $cnpj, $serie, $numeroInicial, $numeroFinal, $justificativa
        ) {
            if (strlen($justificativa) < 15) {
                throw new \InvalidArgumentException('Justificativa deve ter pelo menos 15 caracteres');
            }
            
            $xmlResponse = $this->nfe->inutilizar(
                $ano, $cnpj, 55, $serie, 
                $numeroInicial, $numeroFinal, $justificativa
            );
            $parsed = $this->parseEventResponse($xmlResponse);
            $ok = $this->isSefazOperationSuccessful($xmlResponse);
            
            return $this->publicNormalizer->normalizeFiscalOperation('nfe', 'inutilizacao_nfe', [
                'status' => $parsed['xmotivo'] ?? null,
                'ok' => $ok,
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? null,
            ], [
                'situacao' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? null,
            ], [], [
                'response_xml' => $xmlResponse,
                'parsed_response' => $parsed,
            ], [
                'inutilizado' => $this->isSefazOperationSuccessful($xmlResponse),
                'xml_response' => $xmlResponse,
                'cstat' => $parsed['cstat'] ?? null,
                'serie' => $serie,
                'numeros' => [
                    'inicial' => $numeroInicial,
                    'final' => $numeroFinal
                ],
                'justificativa' => $justificativa
            ]);
        }, 'inutilizacao_nfe');
    }

    /**
     * Verifica status do serviço SEFAZ
     * 
     * @param string $uf UF para consulta
     * @param int|null $ambiente Ambiente (1=produção, 2=homologação)
     * @return FiscalResponse Response com status do serviço
     */
    public function verificarStatusSefaz(string $uf = '', ?int $ambiente = null): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($uf, $ambiente) {
            $result = $this->nfe->sefazStatus($uf, $ambiente);
            $parsed = \sabbajohn\FiscalCore\Support\XmlUtils::parseSefazRetorno($result);
            $cstat = $parsed['lote']['cStat'] ?? $this->extractTagValue($result, ['cStat']);
            $xmotivo = $parsed['lote']['xMotivo'] ?? $this->extractTagValue($result, ['xMotivo']);
            
            return $this->publicNormalizer->normalizeFiscalOperation('nfe', 'status_sefaz', [
                'status' => $xmotivo,
                'ok' => in_array((string) $cstat, ['107', '108', '109'], true),
                'cstat' => $cstat,
                'xmotivo' => $xmotivo,
            ], [
                'situacao' => $xmotivo,
            ], [], [
                'response_xml' => $result,
                'parsed_response' => $parsed,
            ], [
                'xml_response' => $result,
                'uf' => $uf ?: 'SC',
                'ambiente' => $ambiente ?: 2,
                'status' => $this->extrairStatusSefaz($result)
            ]);
        }, 'status_sefaz');
    }

    public function consultarRecibo(string $recibo, ?int $ambiente = null): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($recibo, $ambiente) {
            $xmlResponse = $this->nfe->consultarRecibo($recibo, $ambiente);

            return $this->normalizeSefazCommonOperation('consulta_recibo_nfe', $xmlResponse, [
                'protocolo' => $recibo,
            ], [
                'recibo' => $recibo,
                'ambiente' => $ambiente,
            ]);
        }, 'consulta_recibo_nfe');
    }

    public function consultarCadastroContribuinte(
        string $uf,
        string $cnpj = '',
        string $iest = '',
        string $cpf = ''
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $cnpj, $iest, $cpf) {
            $xmlResponse = $this->nfe->consultarCadastroContribuinte($uf, $cnpj, $iest, $cpf);

            return $this->normalizeSefazCommonOperation('consulta_cadastro_contribuinte_nfe', $xmlResponse, [], [
                'uf' => $uf,
                'cnpj' => $cnpj,
                'iest' => $iest,
                'cpf' => $cpf,
            ]);
        }, 'consulta_cadastro_contribuinte_nfe');
    }

    public function cartaCorrecao(
        string $chave,
        string $correcao,
        int $sequencia = 1,
        array $opcoes = []
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave, $correcao, $sequencia, $opcoes) {
            $xmlResponse = $this->nfe->cartaCorrecao(
                $chave,
                $correcao,
                $sequencia,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('carta_correcao_nfe', $xmlResponse, [
                'chave_acesso' => $chave,
            ], [
                'chave_acesso' => $chave,
                'correcao' => $correcao,
                'sequencia' => $sequencia,
            ]);
        }, 'carta_correcao_nfe');
    }

    public function manifestarDestinatarioLote(array|\stdClass $eventos, array $opcoes = []): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($eventos, $opcoes) {
            $xmlResponse = $this->nfe->manifestarDestinatarioLote(
                $eventos,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('manifestacao_destinatario_lote_nfe', $xmlResponse, [], [
                'eventos_enviados' => $eventos,
            ]);
        }, 'manifestacao_destinatario_lote_nfe');
    }

    public function registrarEventoSefaz(
        string $uf,
        string $chave,
        int $tipoEvento,
        int $sequencia = 1,
        string $tagAdicional = '',
        array $opcoes = []
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $chave, $tipoEvento, $sequencia, $tagAdicional, $opcoes) {
            $this->sefazParser->validateXmlFragment($tagAdicional);

            $xmlResponse = $this->nfe->registrarEventoSefaz(
                $uf,
                $chave,
                $tipoEvento,
                $sequencia,
                $tagAdicional,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('registro_evento_sefaz_nfe', $xmlResponse, [
                'chave_acesso' => $chave,
            ], [
                'uf' => $uf,
                'tipo_evento' => $tipoEvento,
                'sequencia' => $sequencia,
            ]);
        }, 'registro_evento_sefaz_nfe');
    }

    public function registrarEventoSefazLote(string $uf, array|\stdClass $eventos, array $opcoes = []): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($uf, $eventos, $opcoes) {
            $this->validateEventBatchTags($eventos);
            $xmlResponse = $this->nfe->registrarEventoSefazLote(
                $uf,
                $eventos,
                $this->extractDhEventoOption($opcoes),
                $opcoes['lote'] ?? null
            );

            return $this->normalizeSefazEventOperation('registro_evento_sefaz_lote_nfe', $xmlResponse, [], [
                'uf' => $uf,
                'eventos_enviados' => $eventos,
            ]);
        }, 'registro_evento_sefaz_lote_nfe');
    }

    public function registrarEventoAvancado(string $metodo, array|\stdClass $dados, array $opcoes = []): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        if (!SefazAdvancedMethodRegistry::isAllowedForModel($metodo, 55)) {
            return FiscalResponse::error(
                "Método SEFAZ não suportado para NFe: {$metodo}",
                'UNSUPPORTED_SEFAZ_METHOD',
                'registro_evento_avancado_nfe',
                [
                    'method' => $metodo,
                    'allowed_methods' => SefazAdvancedMethodRegistry::allowedMethodsForModel(55),
                    'category' => 'validation',
                ]
            );
        }

        return $this->responseHandler->handle(function () use ($metodo, $dados, $opcoes) {
            $xmlResponse = $this->nfe->registrarEventoAvancado($metodo, $dados, $opcoes);

            return $this->normalizeSefazEventOperation('registro_evento_avancado_nfe', $xmlResponse, [], [
                'metodo' => $metodo,
                'payload' => $dados,
            ]);
        }, 'registro_evento_avancado_nfe');
    }

    public function registrarEpec(string $xml, ?string $verAplic = null): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($xml, $verAplic) {
            $result = $this->nfe->registrarEpec($xml, $verAplic);
            $xmlResponse = $result['response_xml'];

            return $this->normalizeSefazEventOperation('registro_epec_nfe', $xmlResponse, [
                'xml' => $result['xml'],
            ], [
                'xml_contingencia' => $result['xml'],
                'ver_aplic' => $verAplic,
            ]);
        }, 'registro_epec_nfe');
    }

    public function validarXmlSchemaSefaz(string $xml): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($xml) {
            return [
                'xml_valido_schema_sefaz' => $this->nfe->validarXmlSchemaSefaz($xml),
                'tamanho_xml' => strlen($xml),
            ];
        }, 'validacao_schema_sefaz_nfe');
    }

    public function consultarDistribuicaoDFe(
        int $ultNsu = 0,
        int $numNsu = 0,
        ?string $chave = null,
        string $fonte = 'AN'
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($ultNsu, $numNsu, $chave, $fonte) {
            $xmlResponse = $this->nfe->consultaNotasEmitidasParaEstabelecimento($ultNsu, $numNsu, $chave, $fonte);
            $parsed = $this->parseDistDFeResponse($xmlResponse);

            return $this->publicNormalizer->normalizeFiscalOperation('nfe', 'distdfe_nfe', [
                'status' => $parsed['xmotivo'] ?? null,
                'ok' => in_array((string) ($parsed['cstat'] ?? ''), ['137', '138'], true),
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
            ], [
                'chave_acesso' => $chave,
                'situacao' => $parsed['xmotivo'] ?? null,
            ], [], [
                'response_xml' => $xmlResponse,
                'parsed_response' => $parsed,
            ], array_merge(['xml_response' => $xmlResponse], $parsed));
        }, 'distdfe_nfe');
    }

    public function manifestarDestinatario(
        string $chave,
        ManifestationType|string $tipo,
        string $justificativa = '',
        int $sequencia = 1
    ): FiscalResponse {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave, $tipo, $justificativa, $sequencia) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }

            $manifestationType = is_string($tipo) ? ManifestationType::fromValue($tipo) : $tipo;
            $xmlResponse = $this->nfe->manifestarDestinatario($chave, $manifestationType, $justificativa, $sequencia);
            $parsed = $this->parseEventResponse($xmlResponse);

            return $this->publicNormalizer->normalizeFiscalOperation('nfe', 'manifestacao_destinatario_nfe', [
                'status' => $parsed['xmotivo'] ?? null,
                'ok' => $this->isSefazOperationSuccessful($xmlResponse),
                'cstat' => $parsed['cstat'] ?? null,
                'xmotivo' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? null,
            ], [
                'chave_acesso' => $chave,
                'situacao' => $parsed['xmotivo'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? null,
            ], [], [
                'response_xml' => $xmlResponse,
                'parsed_response' => $parsed,
            ], array_merge([
                    'chave_acesso' => $chave,
                    'manifestation_type' => $manifestationType->value,
                    'justificativa' => $justificativa,
                    'sequencia' => $sequencia,
                    'xml_response' => $xmlResponse,
                ],
                $parsed
            ));
        }, 'manifestacao_destinatario_nfe');
    }

    public function downloadNFe(string $chave): FiscalResponse
    {
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }

        return $this->responseHandler->handle(function () use ($chave) {
            if (strlen($chave) !== 44) {
                throw new \InvalidArgumentException('Chave de acesso deve ter 44 dígitos');
            }

            $xmlResponse = $this->nfe->downloadNFe($chave);
            $parsed = $this->parseDistDFeResponse($xmlResponse);
            $documentXml = $parsed['documents'][0]['xml'] ?? $xmlResponse;

            return $this->resultNormalizer->normalizeXmlRetrieval(
                'nfe',
                'download_nfe_destinataria',
                is_string($documentXml) ? $documentXml : null,
                $chave,
                $parsed['xmotivo'] ?? null,
                [
                    'response_xml' => $xmlResponse,
                    'parsed_response' => $parsed,
                ],
                [
                    'documents' => $parsed['documents'] ?? [],
                    'cstat' => $parsed['cstat'] ?? null,
                    'xmotivo' => $parsed['xmotivo'] ?? null,
                    'ult_nsu' => $parsed['ult_nsu'] ?? '0',
                    'max_nsu' => $parsed['max_nsu'] ?? '0',
                ]
            );
        }, 'download_nfe_destinataria');
    }

    public function baixarXml(string $chave): FiscalResponse
    {
        return $this->downloadNFe($chave);
    }

    /**
     * Gera DANFE da NFe
     * 
     * @param string $xmlAutorizado XML da NFe autorizada
     * @return FiscalResponse Response com o PDF ou erro
     */
    public function gerarDanfe(string $xmlAutorizado): FiscalResponse
    {
        return $this->responseHandler->handle(function() use ($xmlAutorizado) {
            if (!isset($this->impressao)) {
                throw new \RuntimeException('Impressao adapter não disponível.');
            }
            
            if (empty($xmlAutorizado)) {
                throw new \InvalidArgumentException('XML autorizado é obrigatório');
            }
            
            $pdf = $this->impressao->gerarDanfe($xmlAutorizado);

            return $this->resultNormalizer->normalizePdfBase64(
                'nfe',
                'geracao_danfe',
                $xmlAutorizado,
                base64_encode($pdf),
                'danfe_' . date('Ymd_His') . '.pdf'
            );
        }, 'geracao_danfe');
    }

    /**
     * Construtor fluente para NFe
     * 
     * @return NotaFiscalBuilder Builder para construção type-safe
     */
    public static function builder(): NotaFiscalBuilder
    {
        return new NotaFiscalBuilder();
    }

    /**
     * Cria NFe a partir de array (sem emitir)
     * Útil para validação prévia
     * 
     * @param array $dados Dados da nota
     * @return FiscalResponse Response com objeto NotaFiscal
     */
    public function criarNota(array $dados): FiscalResponse
    {
        // Verifica inicialização primeiro
        $initError = $this->checkNFeInitialization();
        if ($initError !== null) {
            return $initError;
        }
        
        return $this->responseHandler->handle(function() use ($dados) {
            if (!isset($dados['identificacao']['mod'])) {
                $dados['identificacao']['mod'] = 55;
            }
            
            $nota = $this->nfe->criarNota($dados);
            $nota->validate();
            
            return [
                'nota_fiscal' => $nota,
                'modelo' => 55,
                'validada' => true,
                'chave_simulada' => $this->simularChaveAcesso($dados)
            ];
        }, 'criacao_nota');
    }

    // Métodos auxiliares para extrair informações dos XMLs
    
    private function extrairChaveAcesso(string $xml): ?string
    {
        if ($xml === '') {
            return null;
        }

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query("//*[local-name()='chNFe']")->item(0);
            if (!$node instanceof \DOMNode) {
                $node = $xpath->query("//*[local-name()='infNFe']")->item(0);
                if ($node instanceof \DOMElement) {
                    $id = (string) $node->getAttribute('Id');
                    if (str_starts_with($id, 'NFe') && strlen($id) >= 47) {
                        return substr($id, 3, 44);
                    }
                }
            }
            return $node ? $node->nodeValue : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    private function extrairSituacao(string $xml): string
    {
        if ($xml === '') {
            return 'Status não identificado';
        }

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $queries = [
                "//*[local-name()='infProt']/*[local-name()='xMotivo']",
                "//*[local-name()='protNFe']//*[local-name()='xMotivo']",
                "//*[local-name()='xMotivo']",
            ];

            $node = null;
            foreach ($queries as $query) {
                $candidate = $xpath->query($query)->item(0);
                if ($candidate instanceof \DOMNode) {
                    $node = $candidate;
                    break;
                }
            }
            return $node ? $node->nodeValue : 'Status não identificado';
        } catch (\Exception $e) {
            return 'Erro ao extrair situação';
        }
    }

    private function buildAuthorizedXml(?string $xmlAssinado, string $xmlRetorno): ?string
    {
        if (!is_string($xmlAssinado) || trim($xmlAssinado) === '') {
            return null;
        }

        $parsed = \sabbajohn\FiscalCore\Support\XmlUtils::parseSefazRetorno($xmlRetorno);
        if (($parsed['autorizado'] ?? false) !== true) {
            return null;
        }

        try {
            return Complements::toAuthorize($xmlAssinado, $xmlRetorno);
        } catch (\Throwable) {
            return null;
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

        return $this->publicNormalizer->normalizeFiscalOperation('nfe', $operation, [
            'status' => $parsed['xmotivo'] ?? null,
            'ok' => $parsed['ok'] ?? null,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento, [], [
            'request_xml' => $this->nfe?->getLastRequestXml(),
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

        return $this->publicNormalizer->normalizeFiscalOperation('nfe', $operation, [
            'status' => $parsed['xmotivo'] ?? null,
            'ok' => $parsed['ok'] ?? null,
            'cstat' => $parsed['cstat'] ?? null,
            'xmotivo' => $parsed['xmotivo'] ?? null,
            'protocolo' => $parsed['protocolo'] ?? null,
        ], $documento, [], [
            'request_xml' => $this->nfe?->getLastRequestXml(),
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

    private function parseDistDFeResponse(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);

        $documentos = [];
        $docNodes = $xpath->query("//*[local-name()='docZip']");
        if ($docNodes !== false) {
            foreach ($docNodes as $docNode) {
                if (!$docNode instanceof \DOMElement) {
                    continue;
                }

                $decodedXml = $this->decodeDocZip((string) $docNode->textContent);
                $documentos[] = [
                    'nsu' => (string) $docNode->getAttribute('NSU'),
                    'schema' => (string) $docNode->getAttribute('schema'),
                    'xml' => $decodedXml,
                    'chave' => $this->extractTagValue($decodedXml, ['chNFe']) ?? $this->extractChaveByInfNFe($decodedXml),
                ];
            }
        }

        return [
            'cstat' => $this->extractTagValue($xml, ['cStat']),
            'xmotivo' => $this->extractTagValue($xml, ['xMotivo']),
            'ult_nsu' => $this->extractTagValue($xml, ['ultNSU']) ?? '0',
            'max_nsu' => $this->extractTagValue($xml, ['maxNSU']) ?? '0',
            'documents' => $documentos,
        ];
    }

    private function parseEventResponse(string $xml): array
    {
        return $this->sefazParser->parseEventResponse($xml);
    }

    private function decodeDocZip(string $encoded): ?string
    {
        $decoded = base64_decode(trim($encoded), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $xml = @gzdecode($decoded);
        if ($xml === false) {
            $xml = @gzinflate(substr($decoded, 10));
        }

        if (!is_string($xml) || trim($xml) === '') {
            return null;
        }

        return trim($xml);
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

    private function extractChaveByInfNFe(?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        try {
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $node = $xpath->query("//*[local-name()='infNFe']")->item(0);
            if (!$node instanceof \DOMElement) {
                return null;
            }

            $id = (string) $node->getAttribute('Id');

            return str_starts_with($id, 'NFe') ? substr($id, 3) : null;
        } catch (\Throwable) {
            return null;
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

    private function isSefazOperationSuccessful(string $xml): bool
    {
        return $this->sefazParser->isSuccessfulEventResponse($xml);
    }
    
    /**
     * Valida XML NFe
     */
    public function validarXML(string $xml): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($xml) {
            if (empty($xml)) {
                throw new \InvalidArgumentException("XML não pode estar vazio");
            }
            
            // Validação básica de XML
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            if (!$dom->loadXML($xml)) {
                $errors = libxml_get_errors();
                $errorMsg = "XML inválido: " . $errors[0]->message ?? 'Erro desconhecido';
                libxml_clear_errors();
                throw new \InvalidArgumentException($errorMsg);
            }
            
            // Valida se é uma NFe
            $infNFe = $dom->getElementsByTagName('infNFe');
            if ($infNFe->length === 0) {
                throw new \InvalidArgumentException("infNFe obrigatório");
            }
            
            $chave = $infNFe->item(0)->getAttribute('Id');
            $chave = str_replace('NFe', '', $chave);
            
            return [
                'xml_valido' => true,
                'chave_acesso' => $chave,
                'tipo_documento' => 'NFe',
                'tamanho_xml' => strlen($xml)
            ];
        }, 'validacao_xml_nfe');
    }
    
    /**
     * Valida chave de acesso NFe
     */
    public function validarChaveAcesso(string $chave): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($chave) {
            $chave = trim($chave);
            
            if (!preg_match('/^\d{44}$/', $chave)) {
                throw new \InvalidArgumentException("Chave de acesso deve ter 44 dígitos");
            }

            if ($chave === str_repeat('0', 44)) {
                throw new \InvalidArgumentException("Chave de acesso inválida");
            }
            
            // Extrai informações da chave
            $uf = substr($chave, 0, 2);
            $aamm = substr($chave, 2, 4);
            $cnpj = substr($chave, 6, 14);
            $modelo = substr($chave, 20, 2);
            $serie = substr($chave, 22, 3);
            $numero = substr($chave, 25, 9);
            $tipo_emissao = substr($chave, 34, 1);
            $codigo_numerico = substr($chave, 35, 8);
            $dv = substr($chave, 43, 1);
            
            $dv_calculado = $this->calcularDV(substr($chave, 0, 43));
            
            return [
                'chave_valida' => true,
                'uf' => $uf,
                'ano_mes' => $aamm,
                'cnpj_emitente' => $cnpj,
                'modelo' => $modelo,
                'serie' => intval($serie),
                'numero' => intval($numero),
                'tipo_emissao' => $tipo_emissao,
                'codigo_numerico' => $codigo_numerico,
                'digito_verificador' => $dv,
                'digito_verificador_calculado' => $dv_calculado,
                'digito_verificador_consistente' => $dv === $dv_calculado
            ];
        }, 'validacao_chave_nfe');
    }

    /**
     * Valida chave de acesso específica
     */
    public function validarChave(string $chave): FiscalResponse
    {
        return $this->validarChaveAcesso($chave);
    }

    /**
     * Valida dados do emitente
     */
    public function validarEmitente(array $emitente): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($emitente) {
            $erros = [];
            
            // Valida campos obrigatórios
            if (empty($emitente['CNPJ'])) {
                $erros[] = 'CNPJ obrigatório';
            } else {
                $cnpj = preg_replace('/\D/', '', $emitente['CNPJ']);
                if (strlen($cnpj) !== 14 || !$this->validarDigitosCNPJ($cnpj)) {
                    $erros[] = 'CNPJ inválido';
                }
            }
            
            if (empty($emitente['xNome'])) {
                $erros[] = 'Razão Social obrigatória';
            }
            
            if (empty($emitente['IE'])) {
                $erros[] = 'Inscrição Estadual obrigatória';
            }
            
            // Valida endereço
            if (empty($emitente['endereco'])) {
                $erros[] = 'Endereço obrigatório';
            } else {
                $endereco = $emitente['endereco'];
                if (empty($endereco['xLgr'])) $erros[] = 'Logradouro obrigatório';
                if (empty($endereco['nro'])) $erros[] = 'Número obrigatório';
                if (empty($endereco['xMun'])) $erros[] = 'Município obrigatório';
                if (empty($endereco['UF'])) $erros[] = 'UF obrigatória';
                if (empty($endereco['CEP'])) $erros[] = 'CEP obrigatório';
            }
            
            if (!empty($erros)) {
                throw new \InvalidArgumentException(implode(', ', $erros));
            }
            
            return [
                'emitente_valido' => true,
                'cnpj_formatado' => preg_replace('/\D/', '', $emitente['CNPJ']),
                'validacoes' => [
                    'cnpj' => !empty($emitente['CNPJ']),
                    'razao_social' => !empty($emitente['xNome']),
                    'ie' => !empty($emitente['IE']),
                    'endereco' => !empty($emitente['endereco'])
                ]
            ];
        }, 'validacao_emitente_nfe');
    }

    /**
     * Valida totais da NFe
     */
    public function validarTotais(array $totais): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($totais) {
            $erros = [];
            
            // Campos obrigatórios de totais
            $required = ['vBC', 'vICMS', 'vBCST', 'vST', 'vProd', 'vFrete', 'vSeg', 'vDesc', 'vII', 'vIPI', 'vPIS', 'vCOFINS', 'vOutro', 'vNF'];
            
            foreach ($required as $campo) {
                if (!isset($totais[$campo])) {
                    $totais[$campo] = 0;
                }
            }
            
            // Validação da soma
            $somaCalculada = 
                $totais['vProd'] + 
                $totais['vFrete'] + 
                $totais['vSeg'] + 
                $totais['vOutro'] + 
                $totais['vII'] + 
                $totais['vIPI'] - 
                $totais['vDesc'];
            
            $diferenca = abs($somaCalculada - $totais['vNF']);
            
            if ($diferenca > 0.02) { // Tolerância de 2 centavos
                $erros[] = 'Total inconsistente';
            }
            
            if (!empty($erros)) {
                throw new \InvalidArgumentException(implode(', ', $erros));
            }
            
            return [
                'totais_validos' => true,
                'valor_total_nfe' => $totais['vNF'],
                'valor_total_calculado' => round($somaCalculada, 2),
                'diferenca' => round($diferenca, 2),
                'componentes' => [
                    'produtos' => $totais['vProd'],
                    'frete' => $totais['vFrete'],
                    'seguro' => $totais['vSeg'],
                    'desconto' => $totais['vDesc'],
                    'outros' => $totais['vOutro'],
                    'ipi' => $totais['vIPI'],
                    'icms' => $totais['vICMS']
                ]
            ];
        }, 'validacao_totais_nfe');
    }

    /**
     * Valida código CST
     */
    public function validarCST(string $cst): FiscalResponse
    {
        return $this->responseHandler->execute(function() use ($cst) {
            // Remove caracteres não numéricos
            $cst = preg_replace('/\D/', '', $cst);
            
            // CSTs válidos para ICMS
            $cstsValidos = [
                '00', '10', '20', '30', '40', '41', '50', '51', '60', '70', '90',
                '101', '102', '103', '201', '202', '203', '300', '400', '500'
            ];
            
            if (!in_array($cst, $cstsValidos)) {
                throw new \InvalidArgumentException('CST inválido: ' . $cst);
            }
            
            // Determina regime tributário pelo CST
            $regime = strlen($cst) === 2 ? 'normal' : 'simples_nacional';
            
            // Determina se há tributação
            $semTributacao = in_array($cst, ['40', '41', '50', '51', '103', '203', '300', '400', '500']);
            
            return [
                'cst' => $cst,
                'cst_valido' => true,
                'regime_tributario' => $regime,
                'com_tributacao' => !$semTributacao,
                'descricao' => $this->getDescricaoCST($cst)
            ];
        }, 'validacao_cst');
    }

    /**
     * Retorna descrição do CST
     */
    private function getDescricaoCST(string $cst): string
    {
        $descricoes = [
            '00' => 'Tributada integralmente',
            '10' => 'Tributada e com cobrança do ICMS por substituição tributária',
            '20' => 'Com redução de base de cálculo',
            '30' => 'Isenta ou não tributada e com cobrança do ICMS por substituição tributária',
            '40' => 'Isenta',
            '41' => 'Não tributada',
            '50' => 'Suspensão',
            '51' => 'Diferimento',
            '60' => 'ICMS cobrado anteriormente por substituição tributária',
            '70' => 'Com redução de base de cálculo e cobrança do ICMS por substituição tributária',
            '90' => 'Outras',
            '101' => 'Tributada pelo Simples Nacional com permissão de crédito',
            '102' => 'Tributada pelo Simples Nacional sem permissão de crédito',
            '103' => 'Isenção do ICMS no Simples Nacional',
            '201' => 'Tributada pelo Simples Nacional com permissão de crédito e com cobrança do ICMS por substituição tributária',
            '202' => 'Tributada pelo Simples Nacional sem permissão de crédito e com cobrança do ICMS por substituição tributária',
            '203' => 'Isenção do ICMS no Simples Nacional e com cobrança do ICMS por substituição tributária',
            '300' => 'Imune',
            '400' => 'Não tributada pelo Simples Nacional',
            '500' => 'ICMS cobrado anteriormente por substituição tributária (substituído) ou por antecipação'
        ];
        
        return $descricoes[$cst] ?? 'CST não mapeado';
    }

    private function simularChaveAcesso(array $dados): string
    {
        // Simula chave baseada nos dados (para validação prévia)
        $uf = $dados['emitente']['endereco']['cUF'] ?? '42';
        $cnpj = preg_replace('/\D/', '', $dados['emitente']['CNPJ'] ?? '00000000000000');
        $modelo = '55';
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

    private function validarDigitosCNPJ(string $cnpj): bool
    {
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }

        $pesos = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += (int) $cnpj[$i] * $pesos[$i];
        }
        $digito1 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        $pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < 13; $i++) {
            $soma += (int) $cnpj[$i] * $pesos[$i];
        }
        $digito2 = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);

        return (int) $cnpj[12] === $digito1 && (int) $cnpj[13] === $digito2;
    }
}
