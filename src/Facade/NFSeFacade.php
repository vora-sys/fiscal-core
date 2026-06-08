<?php

namespace sabbajohn\FiscalCore\Facade;

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Support\BelemMunicipalDocumentUrlBuilder;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\MunicipalDanfseRendererResolver;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use sabbajohn\FiscalCore\Support\NFSeRuntimeBootstrap;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Support\FiscalResponseNormalizer;
use sabbajohn\FiscalCore\Support\XmlUtils;

/**
 * Facade para NFSe - Interface simplificada e com tratamento de erros
 * Evita que aplicações recebam erros 500 fornecendo responses padronizados
 */
class NFSeFacade
{
    private ?NFSeAdapter $nfse = null;
    private ResponseHandler $responseHandler;
    private ?FiscalResponse $initializationError = null;
    private string $municipio;
    private string $providerKey;
    private bool $municipioIgnored = false;
    private array $deprecationWarnings = [];
    private ?array $municipioResolved = null;
    private array $runtimeContext = [];
    private FiscalResponseNormalizer $publicNormalizer;

    public function __construct(string $municipio = 'nacional', ?NFSeAdapter $nfse = null)
    {
        $this->municipio = $municipio;
        $this->responseHandler = new ResponseHandler();
        $this->publicNormalizer = new FiscalResponseNormalizer();
        $resolver = new NFSeProviderResolver();
        $compat = $resolver->buildMetadata($municipio);
        $this->providerKey = $compat['provider_key'];
        $this->municipioIgnored = $compat['municipio_ignored'];
        $this->deprecationWarnings = $compat['warnings'];
        $this->municipioResolved = is_array($compat['municipio_resolved'] ?? null)
            ? $compat['municipio_resolved']
            : null;

        if ($nfse !== null) {
            $this->nfse = $nfse;
        } else {
            try {
                $registry = ProviderRegistry::getInstance();
                if (!$registry->has($this->providerKey)) {
                    $this->initializationError = FiscalResponse::error(
                        "Provider NFSe '{$this->providerKey}' não encontrado",
                        'PROVIDER_NOT_FOUND',
                        'nfse_initialization',
                        [
                            'category' => 'configuration',
                            'available_municipios' => $registry->listMunicipios(),
                            'provider_key' => $this->providerKey,
                            'municipio_input' => $municipio,
                            'municipio_ignored' => $this->municipioIgnored,
                            'warnings' => $this->deprecationWarnings,
                            'suggestions' => [
                                "Configure '{$this->providerKey}' em config/nfse/nfse-provider-families.json",
                                "Revise o roteamento em config/nfse/providers-catalog.json",
                            ]
                        ]
                    );
                    return;
                }

                $bootstrap = (new NFSeRuntimeBootstrap())->makeProvider($municipio);
                $this->runtimeContext = $bootstrap;
                $this->nfse = new NFSeAdapter($municipio, $bootstrap['provider']);
            } catch (\Exception $e) {
                $this->initializationError = $this->responseHandler->handle($e, 'nfse_initialization');
            }
        }
    }

    /**
     * Verifica se o NFSe está inicializado corretamente
     */
    private function checkNFSeInitialization(): ?FiscalResponse
    {
        if ($this->initializationError !== null) {
            return $this->initializationError;
        }
        return null;
    }

    /**
     * Emite uma NFSe
     */
    public function emitir(array $dados): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        if ($check = $this->validateNationalMigrationEmissionWindow($dados)) {
            return $check;
        }

        try {
            $resultado = $this->nfse->emitir($dados);
            $lastEmission = method_exists($this->nfse, 'getLastEmissionInfo')
                ? $this->nfse->getLastEmissionInfo()
                : [];
            $data = [
                'resultado' => $resultado,
                'resultado_array' => XmlUtils::xmlToKeyValueArray($resultado),
                'type' => 'nfse_xml',
                'municipio' => $this->municipio,
                'emissao' => $lastEmission,
            ];
            $parsed = is_array($lastEmission['parsed_response'] ?? null) ? $lastEmission['parsed_response'] : [];
            $artifacts = is_array($lastEmission['artifacts'] ?? null) ? $lastEmission['artifacts'] : [];
            $documentoXml = $this->extractNfseXmlFromParsedResponse($parsed) ?? $this->extractFiscalXmlFromCandidate($resultado);
            $documento = [
                'modelo' => 'nfse',
                'xml' => $documentoXml,
                'chave_consulta' => $parsed['numero'] ?? $parsed['numero_nfse'] ?? null,
                'numero' => $parsed['numero'] ?? $parsed['numero_nfse'] ?? null,
                'protocolo' => $parsed['protocolo'] ?? $parsed['numero_lote'] ?? null,
                'situacao' => $parsed['status'] ?? null,
            ];
            $data += $this->publicNormalizer->normalizeFiscalOperation('nfse', 'nfse_emission', [
                'status' => $parsed['status'] ?? null,
                'ok' => !in_array((string) ($parsed['status'] ?? ''), ['error', 'invalid_xml', 'empty'], true),
                'mensagens' => is_array($parsed['mensagens'] ?? null) ? $parsed['mensagens'] : [],
                'protocolo' => $documento['protocolo'],
            ], $documento, [
                'provider_key' => $this->providerKey,
                'municipio' => $this->municipio,
                'provider_class' => $lastEmission['effective_provider_class'] ?? null,
            ], [
                'request_payload' => $artifacts['request_payload'] ?? null,
                'request_xml' => $artifacts['request_xml'] ?? null,
                'response_body' => $artifacts['response_raw'] ?? $resultado,
                'response_xml' => $artifacts['response_xml'] ?? (str_starts_with(ltrim($resultado), '<') ? $resultado : null),
                'parsed_response' => $parsed ?: null,
            ]);
            $metadata = [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
                'provider_info' => $this->nfse->getProviderInfo()
            ];

            if ($failure = $this->normalizeEmissionFailure($lastEmission, 'nfse_emission', $metadata, $data)) {
                return $failure;
            }

            return FiscalResponse::success($data, 'nfse_emission', $metadata);
        } catch (\Exception $e) {
            $lastEmission = method_exists($this->nfse, 'getLastEmissionInfo')
                ? $this->nfse->getLastEmissionInfo()
                : [];
            $metadata = [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
                'emissao' => $lastEmission,
            ];
            $normalizedError = $this->resolveEmissionExceptionMessage($lastEmission, $e->getMessage());

            return FiscalResponse::error(
                $normalizedError,
                $this->resolveEmissionExceptionCode($lastEmission),
                'nfse_emission',
                $metadata,
            );
        }
    }

    public function emitirCompleto(array $dados, array $options = []): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        if ($check = $this->validateNationalMigrationEmissionWindow($dados)) {
            return $check;
        }

        try {
            $emissao = $this->emitir($dados);
            if ($emissao->isError()) {
                return $emissao;
            }

            $emissaoData = $emissao->getData();
            $parsedEmission = $emissaoData['emissao']['parsed_response'] ?? [];
            if (is_array($parsedEmission)) {
                $emissionStatus = (string) ($parsedEmission['status'] ?? 'unknown');
                if (in_array($emissionStatus, ['error', 'invalid_xml', 'empty'], true)) {
                    $mensagens = is_array($parsedEmission['mensagens'] ?? null) ? $parsedEmission['mensagens'] : [];
                    return FiscalResponse::error(
                        $mensagens !== [] ? implode(' | ', $mensagens) : 'Falha na emissao da NFSe.',
                        'NFSE_EMISSION_FAILED',
                        'nfse_emission_complete',
                        $this->buildCompatibilityMetadata()
                    );
                }
            }

            $warnings = [];
            $consultaData = null;
            $nfseXml = $this->extractNfseXmlFromParsedResponse($emissaoData['emissao']['parsed_response'] ?? []);
            $documento = null;
            $impressao = null;

            if ($nfseXml === null) {
                $consulta = $this->resolvePostEmissionConsultation($dados, $emissaoData);
                if ($consulta !== null) {
                    $consultaData = $consulta->getData();
                    $documento = is_array($consultaData['documento'] ?? null) ? $consultaData['documento'] : null;
                    $impressao = is_array($consultaData['impressao'] ?? null) ? $consultaData['impressao'] : null;
                    $nfseXml = $documento['xml'] ?? null;
                }
            }

            if ($documento === null && $nfseXml !== null) {
                $documento = [
                    'xml' => $nfseXml,
                    'numero' => $this->extractNodeValue($nfseXml, 'Numero')
                        ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'numero'),
                    'codigo_verificacao' => $this->extractNodeValue($nfseXml, 'CodigoVerificacao')
                        ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'codigo_verificacao'),
                    'protocolo' => $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'protocolo'),
                    'status_autorizacao' => 'autorizada',
                    'data_emissao' => $this->extractNodeValue($nfseXml, 'DataEmissao'),
                    'chave_consulta' => $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'numero'),
                ];
            }

            if ($documento === null) {
                $documento = $this->buildDocumentFromParsedEmission($consultaData, $emissaoData);
            }

            $flowStatus = 'parcial';
            if (($impressao['disponivel'] ?? false) !== true && ($officialUrl = $this->resolveOfficialDocumentUrl($consultaData, $emissaoData)) !== null) {
                $impressao = [
                    'disponivel' => true,
                    'modo' => 'url',
                    'url' => $officialUrl,
                    'pdf_base64' => null,
                    'content_type' => 'text/uri-list',
                    'filename' => null,
                    'source' => 'official_url',
                ];
            }

            if (($impressao['disponivel'] ?? false) !== true && $nfseXml !== null) {
                $danfse = $this->gerarDanfse($nfseXml);
                if ($danfse->isSuccess()) {
                    $impressao = $danfse->getData('impressao');
                    $flowStatus = 'completo';
                } else {
                    $warnings[] = $danfse->getError();
                }
            } elseif (($impressao['disponivel'] ?? false) === true) {
                $flowStatus = 'completo';
            } else {
                $warnings[] = 'Nao foi possivel resolver o XML final da NFSe apos a emissao.';
            }

            return FiscalResponse::success([
                'flow_status' => $flowStatus,
                'authorization_status' => $documento['status_autorizacao'] ?? ($impressao['disponivel'] ?? false ? 'autorizada' : 'pendente'),
                'emissao' => [
                    'resultado' => $emissaoData['resultado'] ?? null,
                    'metadata' => $emissao->getMetadata(),
                    'introspection' => $emissaoData['emissao'] ?? null,
                ],
                'consulta' => $consultaData['consulta'] ?? null,
                'documento' => $documento,
                'impressao' => $impressao,
                'warnings' => array_values(array_filter($warnings)),
            ], 'nfse_emission_complete', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_emission_complete');
        }
    }

    public function consultarDisponibilidade(array $criterios, array $options = []): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            if ($this->isIsswebMunicipalFlow()) {
                return $this->consultarDisponibilidadeIssweb($criterios, $options);
            }

            if (!$this->isBelemMunicipalFlow()) {
                throw new \RuntimeException(
                    "consultarDisponibilidade() ainda nao foi implementado para o provider '{$this->providerKey}'."
                );
            }

            return $this->consultarDisponibilidadeBelem($criterios, $options);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_document_availability');
        }
    }

    /**
     * Consulta uma NFSe
     */
    public function consultar(string $chave): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->consultar($chave);
            $operation = method_exists($this->nfse, 'getLastOperationInfo')
                ? $this->nfse->getLastOperationInfo()
                : [];
            $data = $resultado->toArray();
            $data['operacao'] = [
                'tipo' => 'nfse_query',
                'status' => $data['consulta']['status'] ?? $data['documento']['status_autorizacao'] ?? null,
                'ok' => ($data['consulta']['disponivel'] ?? false) === true,
                'cstat' => null,
                'xmotivo' => null,
                'mensagens' => $data['consulta']['mensagens'] ?? [],
                'protocolo' => $data['documento']['protocolo'] ?? null,
            ];
            return FiscalResponse::success(array_merge($data, [
                'type' => 'nfse_consulta',
                'chave' => $chave,
                'municipio' => $this->municipio,
                'consulta_operacao' => $operation,
            ]), 'nfse_query', [
                'chave' => $chave,
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_query');
        }
    }

    /**
     * Cancela uma NFSe
     */
    public function cancelar(string $chave, string $motivo, string $protocolo = ''): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->cancelar($chave, $motivo, $protocolo);
            $operation = method_exists($this->nfse, 'getLastOperationInfo')
                ? $this->nfse->getLastOperationInfo()
                : [];
            if ($resultado !== true) {
                $mensagens = is_array($operation['parsed_response']['mensagens'] ?? null)
                    ? $operation['parsed_response']['mensagens']
                    : [];
                $mensagens = array_values(array_filter(array_map(static function ($mensagem): string {
                    if (is_array($mensagem)) {
                        $valor = $mensagem['mensagem']
                            ?? $mensagem['message']
                            ?? $mensagem['descricao']
                            ?? $mensagem['codigo']
                            ?? null;

                        if (is_scalar($valor)) {
                            return trim((string) $valor);
                        }

                        return trim(json_encode($mensagem, JSON_UNESCAPED_UNICODE) ?: '');
                    }

                    return is_scalar($mensagem) ? trim((string) $mensagem) : '';
                }, $mensagens), static fn (string $mensagem): bool => $mensagem !== ''));
                $message = $mensagens !== []
                    ? implode(' | ', $mensagens)
                    : 'Cancelamento da NFSe rejeitado pelo provedor.';

                return FiscalResponse::error(
                    $message,
                    'NFSE_CANCELLATION_REJECTED',
                    'nfse_cancellation',
                    array_merge($this->buildCompatibilityMetadata(), [
                        'chave' => $chave,
                        'motivo' => $motivo,
                        'municipio' => $this->municipio,
                        'provider_key' => $this->providerKey,
                        'municipio_ignored' => $this->municipioIgnored,
                        'warnings' => $this->deprecationWarnings,
                        'cancelamento' => $operation,
                    ])
                );
            }

            $normalized = $this->publicNormalizer->normalizeFiscalOperation('nfse', 'nfse_cancellation', [
                'status' => 'cancelada',
                'ok' => true,
                'mensagens' => [],
                'protocolo' => $protocolo ?: ($operation['normalized_result']['operacao']['protocolo'] ?? null),
            ], [
                'chave_consulta' => $chave,
                'situacao' => 'cancelada',
                'protocolo' => $protocolo ?: ($operation['normalized_result']['operacao']['protocolo'] ?? null),
            ], [
                'provider_key' => $this->providerKey,
                'municipio' => $this->municipio,
            ], [
                'parsed_response' => $operation['parsed_response'] ?? null,
                'response_body' => $operation,
            ]);

            return FiscalResponse::success($normalized + [
                'canceled' => $resultado,
                'type' => 'nfse_cancelamento',
                'chave' => $chave,
                'motivo' => $motivo,
                'municipio' => $this->municipio,
                'cancelamento' => $operation,
            ], 'nfse_cancellation', [
                'chave' => $chave,
                'motivo' => $motivo,
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_cancellation');
        }
    }

    /**
     * Substitui uma NFSe
     */
    public function substituir(string $chave, array $dados): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->substituir($chave, $dados);
            $operation = method_exists($this->nfse, 'getLastOperationInfo')
                ? $this->nfse->getLastOperationInfo()
                : [];
            $normalized = $this->publicNormalizer->normalizeFiscalOperation('nfse', 'nfse_substitution', [
                'status' => $operation['normalized_result']['operacao']['status'] ?? null,
                'ok' => $operation['normalized_result']['operacao']['ok'] ?? null,
                'mensagens' => $operation['normalized_result']['operacao']['mensagens'] ?? [],
                'protocolo' => $operation['normalized_result']['operacao']['protocolo'] ?? null,
            ], [
                'xml' => $this->extractFiscalXmlFromCandidate($resultado),
                'chave_consulta' => $chave,
                'protocolo' => $operation['normalized_result']['operacao']['protocolo'] ?? null,
            ], [
                'provider_key' => $this->providerKey,
                'municipio' => $this->municipio,
            ], [
                'response_body' => $resultado,
                'response_xml' => str_starts_with(ltrim($resultado), '<') ? $resultado : null,
                'parsed_response' => $operation['parsed_response'] ?? null,
            ]);
            return FiscalResponse::success($normalized + [
                'resultado' => $resultado,
                'type' => 'nfse_substituicao',
                'chave' => $chave,
                'municipio' => $this->municipio,
                'substituicao' => $operation,
            ], 'nfse_substitution', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_substitution');
        }
    }

    public function consultarPorRps(array $identificacaoRps): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->consultarPorRps($identificacaoRps);
            $operation = method_exists($this->nfse, 'getLastOperationInfo')
                ? $this->nfse->getLastOperationInfo()
                : [];
            return FiscalResponse::success(array_merge($resultado->toArray(), [
                'type' => 'nfse_consulta_rps',
                'municipio' => $this->municipio,
                'consulta_operacao' => $operation,
            ]), 'nfse_query_by_rps', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_query_by_rps');
        }
    }

    public function consultarLote(string $protocolo): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->consultarLote($protocolo);
            $operation = method_exists($this->nfse, 'getLastOperationInfo')
                ? $this->nfse->getLastOperationInfo()
                : [];
            return FiscalResponse::success(array_merge($resultado->toArray(), [
                'type' => 'nfse_consulta_lote',
                'protocolo' => $protocolo,
                'municipio' => $this->municipio,
                'consulta_operacao' => $operation,
            ]), 'nfse_query_lote', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_query_lote');
        }
    }

    public function baixarXml(string $chave): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $rawResult = $this->nfse->baixarXml($chave);
            $parsed = json_decode($rawResult, true);
            $documentoXml = is_array($parsed)
                ? $this->extractNfseXmlFromParsedResponse($parsed)
                : $this->extractFiscalXmlFromCandidate($rawResult);
            $responseXml = is_string($rawResult) && str_starts_with(ltrim($rawResult), '<')
                ? $rawResult
                : null;

            $parsedArray = is_array($parsed) ? $parsed : [];
            $normalized = $this->publicNormalizer->normalizeFiscalOperation('nfse', 'nfse_download_xml', [
                'status' => $parsedArray['status'] ?? null,
                'ok' => !in_array((string) ($parsedArray['status'] ?? ''), ['error', 'invalid_xml', 'empty'], true),
                'mensagens' => is_array($parsedArray['mensagens'] ?? null) ? $parsedArray['mensagens'] : [],
                'protocolo' => $parsedArray['protocolo'] ?? null,
            ], [
                'xml' => $documentoXml,
                'chave_consulta' => $chave,
                'situacao' => $parsedArray['status'] ?? null,
                'protocolo' => $parsedArray['protocolo'] ?? null,
                'numero' => $parsedArray['numero'] ?? $parsedArray['numero_nfse'] ?? null,
            ], [
                'operation' => 'baixar_xml',
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
            ], [
                'parsed_response' => is_array($parsed) ? $parsed : null,
                'request_payload' => null,
                'request_xml' => null,
                'response_body' => $rawResult,
                'response_xml' => $responseXml,
            ]);

            return FiscalResponse::success($normalized + [
                'type' => 'nfse_xml_download',
                'chave' => $chave,
                'municipio' => $this->municipio,
            ], 'nfse_download_xml', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_download_xml');
        }
    }

    public function baixarDanfse(string $chave): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->baixarDanfse($chave);
            $data = $resultado->toArray();
            $data['operacao'] = [
                'tipo' => 'nfse_download_danfse',
                'status' => ($data['impressao']['disponivel'] ?? false) ? 'disponivel' : 'indisponivel',
                'ok' => ($data['impressao']['disponivel'] ?? false) === true,
                'cstat' => null,
                'xmotivo' => null,
                'mensagens' => [],
                'protocolo' => null,
            ];
            $data['documento'] = [
                'modelo' => 'nfse',
                'xml' => null,
                'chave_acesso' => null,
                'chave_consulta' => $chave,
                'situacao' => $data['operacao']['status'],
                'protocolo' => null,
            ];
            return FiscalResponse::success(array_merge($data, [
                'type' => 'nfse_danfse_download',
                'chave' => $chave,
                'municipio' => $this->municipio,
            ]), 'nfse_generate_danfse', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_download_danfse');
        }
    }

    public function gerarDanfse(string $xmlNfse): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            if (trim($xmlNfse) === '') {
                throw new \InvalidArgumentException('XML final da NFSe e obrigatorio para gerar o DANFSe.');
            }

            $renderer = (new MunicipalDanfseRendererResolver())->resolve($this->providerKey);
            $pdf = $renderer->render($xmlNfse);

            $printResult = (new \sabbajohn\FiscalCore\Support\NFSeResultNormalizer())->normalizePdfBase64(
                base64_encode($pdf),
                [
                    'provider_key' => $this->providerKey,
                    'municipio' => $this->municipio,
                    'filename' => 'danfse_' . strtolower($this->municipio) . '_' . date('Ymd_His') . '.pdf',
                    'source' => 'render_local',
                ],
                [
                    'response_xml' => $xmlNfse,
                ]
            );

            $data = $printResult->toArray();
            $data['operacao'] = [
                'tipo' => 'nfse_generate_danfse',
                'status' => ($data['impressao']['disponivel'] ?? false) ? 'disponivel' : 'indisponivel',
                'ok' => ($data['impressao']['disponivel'] ?? false) === true,
                'cstat' => null,
                'xmotivo' => null,
                'mensagens' => [],
                'protocolo' => null,
            ];
            $data['documento'] = [
                'modelo' => 'nfse',
                'xml' => $xmlNfse,
                'chave_acesso' => null,
                'chave_consulta' => null,
                'situacao' => 'renderizada',
                'protocolo' => null,
            ];
            $data['xml'] = $xmlNfse;
            return FiscalResponse::success(array_merge($data, [
                'type' => 'nfse_generate_danfse',
            ]), 'nfse_generate_danfse', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_generate_danfse');
        }
    }

    public function listarMunicipiosNacionais(bool $forceRefresh = false): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->listarMunicipiosNacionais($forceRefresh);
            return FiscalResponse::success($resultado['data'] ?? [], 'nfse_nacional_municipios', [
                'source' => $resultado['metadata']['source'] ?? null,
                'stale' => $resultado['metadata']['stale'] ?? null,
                'force_refresh' => $forceRefresh,
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_municipios');
        }
    }

    public function consultarAliquotasMunicipio(
        string $codigoMunicipio,
        ?string $codigoServico = null,
        ?string $competencia = null,
        bool $forceRefresh = false
    ): FiscalResponse {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->consultarAliquotasMunicipio(
                $codigoMunicipio,
                $codigoServico,
                $competencia,
                $forceRefresh
            );
            return FiscalResponse::success($resultado['data'] ?? [], 'nfse_nacional_aliquotas', [
                'source' => $resultado['metadata']['source'] ?? null,
                'stale' => $resultado['metadata']['stale'] ?? null,
                'force_refresh' => $forceRefresh,
                'codigo_municipio' => $codigoMunicipio,
                'codigo_servico' => $codigoServico,
                'competencia' => $competencia,
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_aliquotas');
        }
    }

    public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->consultarConvenioMunicipio($codigoMunicipio, $forceRefresh);
            return FiscalResponse::success($resultado['data'] ?? [], 'nfse_nacional_convenio', [
                'source' => $resultado['metadata']['source'] ?? null,
                'stale' => $resultado['metadata']['stale'] ?? null,
                'force_refresh' => $forceRefresh,
                'codigo_municipio' => $codigoMunicipio,
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_convenio');
        }
    }

    public static function consultarContribuinteCnc(string $municipio, string $cnc): FiscalResponse
    {
        try {
            $adapter = new NFSeAdapter($municipio);
            $data = $adapter->consultarContribuinteCnc($cnc);

            if (!is_array($data)) {
                $data = ['resultado' => $data];
            }

            $data['codigoMunicipio'] = $municipio;

            return FiscalResponse::success($data, 'nfse_consultar_contribuinte_cnc');
        } catch (\Throwable $e) {
            return FiscalResponse::fromException($e, 'nfse_consultar_contribuinte_cnc');
        }
    }

    public static function verificarHabilitacaoCnc(string $municipio, string $cnc): FiscalResponse
    {
        try {
            $adapter = new NFSeAdapter($municipio);
            $habilitado = $adapter->verificarHabilitacaoCnc($cnc);

            return FiscalResponse::success([
                'habilitado' => $habilitado,
                'cnc' => $cnc,
                'codigoMunicipio' => $municipio,
            ], 'nfse_verificar_habilitacao_cnc');
        } catch (\Throwable $e) {
            return FiscalResponse::fromException($e, 'nfse_verificar_habilitacao_cnc');
        }
    }

    public function validarLayoutDps(array $payload, bool $checkCatalog = true): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->validarLayoutDps($payload, $checkCatalog);
            return FiscalResponse::success($resultado, 'nfse_nacional_layout_check', [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
                'check_catalog' => $checkCatalog,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_layout_check');
        }
    }

    public function gerarXmlDpsPreview(array $payload): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $xml = $this->nfse->gerarXmlDpsPreview($payload);
            return FiscalResponse::success([
                'xml' => $xml,
            ], 'nfse_nacional_layout_preview', [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_layout_preview');
        }
    }

    public function gerarXmlEnvioPreview(array $payload): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $xml = $this->nfse->gerarXmlEnvioPreview($payload);

            return FiscalResponse::success([
                'xml' => $xml,
                'metadata' => [
                    'artifacts' => [
                        'request_xml_preview' => $xml,
                    ],
                ],
            ], 'nfse_xml_envio_preview', [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
                'artifacts' => [
                    'request_xml_preview' => $xml,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_xml_envio_preview');
        }
    }

    public function validarXmlDps(array $payload): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $resultado = $this->nfse->validarXmlDps($payload);
            return FiscalResponse::success($resultado, 'nfse_nacional_xml_check', [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_nacional_xml_check');
        }
    }

    /**
     * Lista municípios disponíveis
     */
    public function listarMunicipios(): FiscalResponse
    {
        try {
            $registry = ProviderRegistry::getInstance();
            $municipios = $registry->listMunicipios();

            return FiscalResponse::success(['municipios' => $municipios], 'nfse_list_municipios', [
                'total' => count($municipios),
                'current_municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_list_municipios');
        }
    }

    /**
     * Obtém informações do provider atual
     */
    public function getProviderInfo(): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
            return $check;
        }

        try {
            $info = $this->nfse->getProviderInfo();
            $info['provider_key'] = $this->providerKey;
            $info['municipio_ignored'] = $this->municipioIgnored;
            $info['warnings'] = $this->deprecationWarnings;
            $info['runtime_bootstrapped'] = $this->runtimeContext !== [];
            return FiscalResponse::success($info, 'nfse_provider_info');
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_provider_info');
        }
    }

    /**
     * Valida XML NFSe
     */
    public function validarXML(string $xml): FiscalResponse
    {
        return $this->responseHandler->handle(function () use ($xml) {
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

            // Tenta identificar tipo de NFSe
            $tiposNFSe = ['CompNfse', 'Nfse', 'InfNfse', 'GerarNfseEnvio'];
            $tipoDetectado = null;

            foreach ($tiposNFSe as $tipo) {
                if ($dom->getElementsByTagName($tipo)->length > 0) {
                    $tipoDetectado = $tipo;
                    break;
                }
            }

            if (!$tipoDetectado) {
                throw new \InvalidArgumentException("XML não é uma NFSe válida");
            }

            return [
                'xml_valido' => true,
                'tipo_nfse' => $tipoDetectado,
                'municipio_esperado' => $this->municipio,
                'tamanho_xml' => strlen($xml)
            ];
        }, 'validacao_xml_nfse');
    }

    /**
     * Valida dados do prestador NFSe
     */
    public function validarPrestador(array $prestador): FiscalResponse
    {
        return $this->responseHandler->handle(function () use ($prestador) {
            $erros = [];

            // Valida campos obrigatórios
            if (empty($prestador['cnpj'])) {
                $erros[] = 'CNPJ do prestador é obrigatório';
            } else {
                // Valida formato CNPJ
                $cnpj = preg_replace('/\D/', '', $prestador['cnpj']);
                if (strlen($cnpj) !== 14) {
                    $erros[] = 'CNPJ deve ter 14 dígitos';
                }
            }

            if (empty($prestador['inscricaoMunicipal'])) {
                $erros[] = 'Inscrição Municipal é obrigatória';
            }

            if (empty($prestador['razaoSocial'])) {
                $erros[] = 'Razão Social é obrigatória';
            }

            if (!empty($erros)) {
                throw new \InvalidArgumentException("Prestador inválido: " . implode(', ', $erros));
            }

            return [
                'prestador_valido' => true,
                'cnpj_formatado' => isset($prestador['cnpj']) ? preg_replace('/\D/', '', $prestador['cnpj']) : null,
                'validacoes' => [
                    'cnpj' => !empty($prestador['cnpj']),
                    'inscricao_municipal' => !empty($prestador['inscricaoMunicipal']),
                    'razao_social' => !empty($prestador['razaoSocial'])
                ]
            ];
        }, 'validacao_prestador_nfse');
    }

    /**
     * Valida configuração do município
     */
    public function validarMunicipio(?string $municipio = null): FiscalResponse
    {
        $municipioToValidate = $municipio ?? $this->municipio;

        try {
            $registry = ProviderRegistry::getInstance();

            if (!$registry->has($this->providerKey)) {
                return FiscalResponse::error(
                    "Provider NFSe '{$this->providerKey}' não está configurado",
                    'MUNICIPALITY_NOT_CONFIGURED',
                    'nfse_municipality_validation',
                    [
                        'available_municipios' => $registry->listMunicipios(),
                        'provider_key' => $this->providerKey,
                        'municipio_input' => $municipioToValidate,
                        'municipio_ignored' => true,
                        'warnings' => $this->deprecationWarnings,
                        'suggestions' => [
                            "Configure '{$this->providerKey}' em config/nfse/nfse-provider-families.json",
                            "Revise o mapeamento do município em config/nfse/providers-catalog.json",
                        ]
                    ]
                );
            }

            $config = $registry->getConfig($this->providerKey);
            return FiscalResponse::success([
                'municipio' => $municipioToValidate,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => true,
                'configured' => true,
                'config_keys' => array_keys($config)
            ], 'nfse_municipality_validation', [
                'warnings' => $this->deprecationWarnings
            ]);

        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_municipality_validation');
        }
    }

    public static function nacional(?NFSeAdapter $nfse = null): self
    {
        return new self('nfse_nacional', $nfse);
    }

    private function buildCompatibilityMetadata(): array
    {
        return [
            'municipio' => $this->municipio,
            'provider_key' => $this->providerKey,
            'municipio_ignored' => $this->municipioIgnored,
            'warnings' => $this->deprecationWarnings,
        ];
    }

    private function normalizeEmissionFailure(array $lastEmission, string $operation, array $metadata, array $data): ?FiscalResponse
    {
        $parsedEmission = $lastEmission['parsed_response'] ?? [];
        if (!is_array($parsedEmission)) {
            return null;
        }

        $emissionStatus = (string) ($parsedEmission['status'] ?? 'unknown');
        if (!in_array($emissionStatus, ['error', 'invalid_xml', 'empty'], true)) {
            return null;
        }

        $mensagens = is_array($parsedEmission['mensagens'] ?? null) ? $parsedEmission['mensagens'] : [];
        $errorCode = $this->resolveProviderErrorCode($parsedEmission) ?? 'NFSE_EMISSION_FAILED';

        return FiscalResponse::error(
            $mensagens !== [] ? implode(' | ', $mensagens) : 'Falha na emissao da NFSe.',
            $errorCode,
            $operation,
            $metadata + [
                'emission_status' => $emissionStatus,
                'retryable' => (bool) ($parsedEmission['retryable'] ?? false),
                'transport_error' => $parsedEmission['transport_error'] ?? null,
                'redirect_location' => $parsedEmission['redirect_location'] ?? null,
                'http_status' => $parsedEmission['http_status'] ?? null,
                'emissao' => $lastEmission,
                'response' => $data,
            ]
        );
    }

    private function resolveEmissionExceptionCode(array $lastEmission): string
    {
        $parsedEmission = $lastEmission['parsed_response'] ?? [];
        if (!is_array($parsedEmission)) {
            return 'NFSE_EMISSION_ERROR';
        }

        $providerErrorCode = $this->resolveProviderErrorCode($parsedEmission);
        if ($providerErrorCode !== null) {
            return $providerErrorCode;
        }

        $status = (string) ($parsedEmission['status'] ?? '');
        if ($status === 'invalid_xml') {
            return 'XML_ERROR';
        }

        if ($status === 'error') {
            return 'NFSE_EMISSION_FAILED';
        }

        return 'NFSE_EMISSION_ERROR';
    }

    private function resolveEmissionExceptionMessage(array $lastEmission, string $fallback): string
    {
        $parsedEmission = $lastEmission['parsed_response'] ?? [];
        if (!is_array($parsedEmission)) {
            return $fallback;
        }

        $messages = array_values(array_filter((array) ($parsedEmission['mensagens'] ?? []), static fn ($value): bool => is_string($value) && trim($value) !== ''));
        if ($messages !== []) {
            return implode(' | ', $messages);
        }

        $errors = is_array($parsedEmission['errors'] ?? null) ? $parsedEmission['errors'] : [];
        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $code = trim((string) ($error['code'] ?? $error['codigo'] ?? ''));
                $description = trim((string) ($error['description'] ?? $error['descricao'] ?? $error['message'] ?? $error['mensagem'] ?? ''));
                if ($code === '' && $description === '') {
                    continue;
                }

                $messages[] = $code !== '' && $description !== '' ? "{$code}: {$description}" : ($description !== '' ? $description : $code);
            }

            if ($messages !== []) {
                return implode(' | ', array_values(array_unique($messages)));
            }
        }

        return $fallback;
    }

    private function resolveProviderErrorCode(array $parsedEmission): ?string
    {
        $errors = is_array($parsedEmission['errors'] ?? null) ? $parsedEmission['errors'] : [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = trim((string) ($error['code'] ?? $error['codigo'] ?? ''));
            if ($code !== '') {
                return $code;
            }
        }

        foreach ((array) ($parsedEmission['mensagens'] ?? []) as $message) {
            if (!is_string($message)) {
                continue;
            }

            if (preg_match('/^([A-Z]{1,6}\d{2,}|\d{3,}|[A-Z0-9_]+)\s*[:\-]/u', trim($message), $matches) === 1) {
                return trim((string) $matches[1]);
            }
        }

        return null;
    }

    private function isBelemMunicipalFlow(): bool
    {
        return $this->providerKey === 'BELEM_MUNICIPAL_2025';
    }

    private function isIsswebMunicipalFlow(): bool
    {
        return $this->providerKey === 'ISSWEB_AM';
    }

    private function validateNationalMigrationEmissionWindow(array $dados): ?FiscalResponse
    {
        if (!is_array($this->municipioResolved)) {
            return null;
        }

        $policy = is_array($this->municipioResolved['national_migration_policy'] ?? null)
            ? $this->municipioResolved['national_migration_policy']
            : [];
        if ($policy === [] || !($policy['enforce_emission_block_before_effective_date'] ?? false)) {
            return null;
        }

        $effectiveFrom = trim((string) ($policy['effective_from'] ?? ''));
        if ($effectiveFrom === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            return null;
        }

        $referenceDate = $this->extractNationalMigrationReferenceDate($dados);
        if ($referenceDate === null || $referenceDate >= $effectiveFrom) {
            return null;
        }

        $ibge = (string) ($this->municipioResolved['ibge'] ?? '');
        $legacySystem = trim((string) ($policy['legacy_system'] ?? 'sistema legado municipal'));
        $defaultMessage = sprintf(
            '%s utiliza exclusivamente o emissor nacional para fatos geradores a partir de %s. Competências até %s permanecem no sistema legado %s.',
            (string) ($this->municipioResolved['nome'] ?? $this->municipio),
            $effectiveFrom,
            (new \DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d'),
            $legacySystem
        );
        $message = trim((string) ($policy['message'] ?? '')) ?: $defaultMessage;
        $errorCode = trim((string) ($policy['error_code'] ?? '')) ?: 'NFSE_NATIONAL_MIGRATION_LEGACY_PERIOD';

        return FiscalResponse::error(
            $message,
            $errorCode,
            'nfse_emission',
            $this->buildCompatibilityMetadata() + [
                'municipio_ibge' => $ibge,
                'reference_date' => $referenceDate,
                'legacy_cutoff' => $effectiveFrom,
                'legacy_system' => $legacySystem,
            ]
        );
    }

    private function extractNationalMigrationReferenceDate(array $dados): ?string
    {
        $candidates = [
            (string) ($dados['dCompet'] ?? ''),
            substr((string) ($dados['dhEmi'] ?? ''), 0, 10),
            (string) ($dados['competencia'] ?? ''),
            substr((string) ($dados['rps']['data_emissao'] ?? ''), 0, 10),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function emitirCompletoBelem(array $dados, FiscalResponse $emissao, array $emissaoData): FiscalResponse
    {
        $warnings = [];
        $consultaData = null;
        $parsedEmission = is_array($emissaoData['emissao']['parsed_response'] ?? null)
            ? $emissaoData['emissao']['parsed_response']
            : [];

        $availability = $this->buildAvailabilityPayloadFromParsedResponse(
            $parsedEmission,
            'emissao',
            null,
            []
        );

        if (($availability['disponivel'] ?? false) !== true) {
            $consulta = $this->consultarDisponibilidade($this->buildAvailabilityCriteriaFromEmission($dados, $emissaoData));
            if ($consulta->isSuccess()) {
                $consultaData = $consulta->getData();
                $availability = $this->stripAvailabilityEnvelope($consultaData);
                if (($availability['source'] ?? null) === 'rps' && !empty($availability['warnings'])) {
                    $warnings = array_merge($warnings, $availability['warnings']);
                }
            } else {
                $warnings[] = $consulta->getError() ?? 'Nao foi possivel consultar a disponibilidade da NFSe em Belem.';
            }
        }

        $flowStatus = ($availability['disponivel'] ?? false) === true ? 'completo' : 'parcial';
        $warnings = array_values(array_unique(array_filter(array_merge($warnings, $availability['warnings'] ?? []))));

        return FiscalResponse::success([
            'flow_status' => $flowStatus,
            'authorization_status' => $availability['authorization_status'] ?? 'erro',
            'disponivel' => (bool) ($availability['disponivel'] ?? false),
            'emissao' => [
                'resultado' => $emissaoData['resultado'] ?? null,
                'metadata' => $emissao->getMetadata(),
                'introspection' => $emissaoData['emissao'] ?? null,
            ],
            'consulta' => $consultaData['consulta'] ?? null,
            'nfse' => $availability['nfse'] ?? null,
            'danfse_url' => $availability['danfse_url'] ?? null,
            'warnings' => $warnings,
        ], 'nfse_emission_complete', $this->buildCompatibilityMetadata());
    }

    private function consultarDisponibilidadeBelem(array $criterios, array $options = []): FiscalResponse
    {
        unset($options);

        $normalized = $this->normalizeAvailabilityCriteria($criterios);
        $warnings = [];
        $consultasExecutadas = [];

        $availability = null;
        if ($normalized['protocolo'] !== '') {
            $consultaLote = $this->consultarLote($normalized['protocolo']);
            $consultasExecutadas[] = 'lote';
            $availability = $this->buildAvailabilityPayloadFromConsultResponse($consultaLote, 'lote', $warnings);

            if (($availability['disponivel'] ?? false) === true) {
                return FiscalResponse::success($availability, 'nfse_document_availability', $this->buildCompatibilityMetadata());
            }
        }

        if ($normalized['rps'] !== null) {
            $consultaRps = $this->consultarPorRps($normalized['rps']);
            $consultasExecutadas[] = 'rps';
            $availability = $this->buildAvailabilityPayloadFromConsultResponse($consultaRps, 'rps', $warnings);

            return FiscalResponse::success($availability, 'nfse_document_availability', $this->buildCompatibilityMetadata());
        }

        if ($availability === null) {
            throw new \InvalidArgumentException(
                'Informe protocolo ou os dados completos do RPS para consultar a disponibilidade da NFSe.'
            );
        }

        $availability['warnings'] = array_values(array_unique(array_merge(
            $availability['warnings'] ?? [],
            $warnings,
            $consultasExecutadas === ['lote'] && ($availability['disponivel'] ?? false) !== true
                ? ['Consulta por lote realizada sem NFSe autorizada disponivel.']
                : []
        )));

        return FiscalResponse::success($availability, 'nfse_document_availability', $this->buildCompatibilityMetadata());
    }

    private function consultarDisponibilidadeIssweb(array $criterios, array $options = []): FiscalResponse
    {
        unset($options);

        $numero = trim((string) (
            $criterios['numero_nfse']
            ?? $criterios['numero']
            ?? $criterios['chave']
            ?? ''
        ));
        if ($numero === '') {
            throw new \InvalidArgumentException('Informe numero_nfse para consultar disponibilidade ISSWEB.');
        }

        $consulta = $this->consultar($numero);
        if ($consulta->isError()) {
            return $consulta;
        }

        $consultaData = $consulta->getData();
        $documento = is_array($consultaData['documento'] ?? null) ? $consultaData['documento'] : [];
        $impressao = is_array($consultaData['impressao'] ?? null) ? $consultaData['impressao'] : [];
        $parsedResponse = is_array($consultaData['raw']['parsed_response'] ?? null)
            ? $consultaData['raw']['parsed_response']
            : [];
        $mensagens = is_array($consultaData['consulta']['mensagens'] ?? null) ? $consultaData['consulta']['mensagens'] : [];

        $disponivel = ($documento['numero'] ?? null) !== null;
        $authorizationStatus = (string) (
            $documento['status_autorizacao']
            ?? (in_array((string) ($parsedResponse['status'] ?? 'unknown'), ['error', 'invalid_xml', 'empty'], true) ? 'erro' : 'nao_encontrada')
        );

        return FiscalResponse::success([
            'authorization_status' => $authorizationStatus,
            'disponivel' => $disponivel,
            'source' => 'consulta',
            'protocolo' => $documento['protocolo'] ?? ($parsedResponse['lote'] ?? null),
            'nfse' => $disponivel ? [
                'numero' => $documento['numero'] ?? null,
                'codigo_verificacao' => $documento['codigo_verificacao'] ?? null,
                'chave_validacao' => $parsedResponse['chave_validacao'] ?? ($documento['codigo_verificacao'] ?? null),
                'status_autorizacao' => $authorizationStatus,
            ] : null,
            'danfse_url' => ($impressao['disponivel'] ?? false) === true ? ($impressao['url'] ?? null) : null,
            'consulta' => $consultaData['consulta'] ?? null,
            'warnings' => array_values(array_filter($mensagens)),
        ], 'nfse_document_availability', $this->buildCompatibilityMetadata());
    }

    private function normalizeAvailabilityCriteria(array $criterios): array
    {
        $protocolo = trim((string) ($criterios['protocolo'] ?? ''));
        $rps = is_array($criterios['rps'] ?? null) ? $criterios['rps'] : [];
        $numeroRps = trim((string) ($rps['numero'] ?? ''));

        return [
            'protocolo' => $protocolo,
            'numero_nfse' => trim((string) ($criterios['numero_nfse'] ?? '')),
            'rps' => $numeroRps !== '' ? [
                'numero' => $numeroRps,
                'serie' => trim((string) ($rps['serie'] ?? 'RPS')) ?: 'RPS',
                'tipo' => trim((string) ($rps['tipo'] ?? '1')) ?: '1',
            ] : null,
        ];
    }

    private function buildAvailabilityCriteriaFromEmission(array $dados, array $emissaoData): array
    {
        $parsedResponse = is_array($emissaoData['emissao']['parsed_response'] ?? null)
            ? $emissaoData['emissao']['parsed_response']
            : [];
        $rps = is_array($dados['rps'] ?? null) ? $dados['rps'] : [];

        $criteria = [];
        $protocolo = trim((string) ($parsedResponse['protocolo'] ?? $parsedResponse['numero_lote'] ?? ''));
        if ($protocolo !== '') {
            $criteria['protocolo'] = $protocolo;
        }

        if (
            trim((string) ($rps['numero'] ?? '')) !== ''
            || trim((string) ($rps['serie'] ?? '')) !== ''
            || trim((string) ($rps['tipo'] ?? '')) !== ''
        ) {
            $criteria['rps'] = [
                'numero' => trim((string) ($rps['numero'] ?? '')),
                'serie' => trim((string) ($rps['serie'] ?? 'RPS')) ?: 'RPS',
                'tipo' => trim((string) ($rps['tipo'] ?? '1')) ?: '1',
            ];
        }

        return $criteria;
    }

    private function buildAvailabilityPayloadFromConsultResponse(
        FiscalResponse $consulta,
        string $source,
        array &$warnings
    ): array {
        $consultaData = $consulta->getData();
        $parsedResponse = is_array($consultaData['raw']['parsed_response'] ?? null)
            ? $consultaData['raw']['parsed_response']
            : [];

        $warnings = array_merge($warnings, $source === 'rps' ? ['Consulta por RPS utilizada como fallback da disponibilidade em Belem.'] : []);

        return $this->buildAvailabilityPayloadFromParsedResponse(
            $parsedResponse,
            $source,
            $consultaData['consulta_operacao'] ?? null,
            $warnings
        );
    }

    private function buildAvailabilityPayloadFromParsedResponse(
        array $parsedResponse,
        string $source,
        ?array $consultaData,
        array $warnings = []
    ): array {
        $nfseData = $this->extractAvailabilityNfseData($parsedResponse);
        $disponivel = ($nfseData['numero'] ?? null) !== null && ($nfseData['codigo_verificacao'] ?? null) !== null;
        $status = (string) ($parsedResponse['status'] ?? 'unknown');

        $authorizationStatus = match (true) {
            $disponivel => 'autorizada',
            in_array($status, ['error', 'invalid_xml', 'empty'], true) => 'erro',
            $status === 'success' => 'pendente',
            default => 'nao_encontrada',
        };

        return [
            'authorization_status' => $authorizationStatus,
            'disponivel' => $disponivel,
            'source' => $source,
            'protocolo' => $this->resolveAvailabilityProtocol($parsedResponse, $consultaData),
            'nfse' => $nfseData,
            'danfse_url' => $disponivel ? $this->buildBelemOfficialDocumentUrl($nfseData, $parsedResponse, $consultaData) : null,
            'consulta' => $consultaData,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    private function extractAvailabilityNfseData(array $parsedResponse): ?array
    {
        $nfse = is_array($parsedResponse['nfse'] ?? null) ? $parsedResponse['nfse'] : null;
        if ($nfse === null) {
            $lista = is_array($parsedResponse['lista_nfse'] ?? null) ? $parsedResponse['lista_nfse'] : [];
            $nfse = is_array($lista[0] ?? null) ? $lista[0] : null;
        }

        if ($nfse === null) {
            $xml = $this->extractNfseXmlFromParsedResponse($parsedResponse);
            if ($xml !== null) {
                $nfse = [
                    'numero' => $this->extractNodeValue($xml, 'Numero'),
                    'codigo_verificacao' => $this->extractNodeValue($xml, 'CodigoVerificacao'),
                    'data_emissao' => $this->extractNodeValue($xml, 'DataEmissao'),
                ];
            }
        }

        if (!is_array($nfse)) {
            return null;
        }

        $numero = trim((string) ($nfse['numero'] ?? ''));
        $codigoVerificacao = trim((string) ($nfse['codigo_verificacao'] ?? ''));
        $dataEmissao = trim((string) ($nfse['data_emissao'] ?? ''));

        if ($numero === '' && $codigoVerificacao === '' && $dataEmissao === '') {
            return null;
        }

        return [
            'numero' => $numero !== '' ? $numero : null,
            'codigo_verificacao' => $codigoVerificacao !== '' ? $codigoVerificacao : null,
            'data_emissao' => $dataEmissao !== '' ? $dataEmissao : null,
        ];
    }

    private function resolveAvailabilityProtocol(array $parsedResponse, ?array $consultaData): ?string
    {
        foreach ([
            $parsedResponse['protocolo'] ?? null,
            $consultaData['protocolo'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function buildBelemOfficialDocumentUrl(array $nfseData, array $parsedResponse, ?array $consultaData): string
    {
        $prestador = is_array($this->runtimeContext['config']['prestador'] ?? null)
            ? $this->runtimeContext['config']['prestador']
            : [];

        if ($prestador === []) {
            $prestador = $this->extractPrestadorContextFromAvailabilityContext($parsedResponse, $consultaData);
        }

        return BelemMunicipalDocumentUrlBuilder::build(
            (string) ($prestador['cnpj'] ?? ''),
            (string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal'] ?? ''),
            (string) ($nfseData['numero'] ?? ''),
            (string) ($nfseData['codigo_verificacao'] ?? '')
        );
    }

    private function extractPrestadorContextFromAvailabilityContext(array $parsedResponse, ?array $consultaData): array
    {
        $xmlCandidates = [
            $parsedResponse['raw_xml'] ?? null,
            $consultaData['artifacts']['request_xml'] ?? null,
            $consultaData['artifacts']['response_xml'] ?? null,
        ];

        foreach ($xmlCandidates as $xml) {
            if (!is_string($xml) || trim($xml) === '') {
                continue;
            }

            $dom = new \DOMDocument();
            if (!@$dom->loadXML($xml)) {
                continue;
            }

            $xpath = new \DOMXPath($dom);
            $cnpj = trim((string) $xpath->evaluate("string((//*[local-name()='PrestadorServico']//*[local-name()='Cnpj'])[1] | (//*[local-name()='Prestador']//*[local-name()='Cnpj'])[1])"));
            $inscricaoMunicipal = trim((string) $xpath->evaluate("string((//*[local-name()='PrestadorServico']//*[local-name()='InscricaoMunicipal'])[1] | (//*[local-name()='Prestador']//*[local-name()='InscricaoMunicipal'])[1])"));

            if ($cnpj !== '' && $inscricaoMunicipal !== '') {
                return [
                    'cnpj' => $cnpj,
                    'inscricaoMunicipal' => $inscricaoMunicipal,
                ];
            }
        }

        return [];
    }

    private function stripAvailabilityEnvelope(array $consultaData): array
    {
        return [
            'authorization_status' => $consultaData['authorization_status'] ?? 'erro',
            'disponivel' => (bool) ($consultaData['disponivel'] ?? false),
            'source' => $consultaData['source'] ?? null,
            'protocolo' => $consultaData['protocolo'] ?? null,
            'nfse' => $consultaData['nfse'] ?? null,
            'danfse_url' => $consultaData['danfse_url'] ?? null,
            'consulta' => $consultaData['consulta'] ?? null,
            'warnings' => is_array($consultaData['warnings'] ?? null) ? $consultaData['warnings'] : [],
        ];
    }

    private function resolvePostEmissionConsultation(array $dados, array $emissaoData): ?FiscalResponse
    {
        $parsedResponse = $emissaoData['emissao']['parsed_response'] ?? [];
        if (!is_array($parsedResponse)) {
            $parsedResponse = [];
        }

        $protocolo = trim((string) ($parsedResponse['protocolo'] ?? $parsedResponse['numero_lote'] ?? ''));
        if ($protocolo !== '') {
            $consultaLote = $this->consultarLote($protocolo);
            if ($consultaLote->isSuccess()) {
                $documento = is_array($consultaLote->getData('documento') ?? null) ? $consultaLote->getData('documento') : [];
                $statusAutorizacao = (string) ($consultaLote->getData('consulta')['status_autorizacao'] ?? '');
                if (($documento['numero'] ?? null) !== null || $statusAutorizacao === 'autorizada') {
                    return $consultaLote;
                }
            }
        }

        $rps = is_array($dados['rps'] ?? null) ? $dados['rps'] : [];
        $consultaRpsPayload = array_filter([
            'numero' => $rps['numero'] ?? null,
            'serie' => $rps['serie'] ?? null,
            'tipo' => $rps['tipo'] ?? null,
            'id' => $dados['id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if (
            isset($consultaRpsPayload['numero'], $consultaRpsPayload['serie'], $consultaRpsPayload['tipo'])
        ) {
            return $this->consultarPorRps($consultaRpsPayload);
        }

        return null;
    }

    private function extractNfseXmlFromParsedResponse(array $parsedResponse): ?string
    {
        $candidates = [
            $parsedResponse['raw_xml'] ?? null,
            $parsedResponse['xml'] ?? null,
            $parsedResponse['xml_retorno'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $xml = $this->extractFiscalXmlFromCandidate($candidate);
            if ($xml !== null) {
                return $xml;
            }
        }

        $gzipFields = [
            $parsedResponse['nfseXmlGZipB64'] ?? null,
            $parsedResponse['dados']['nfseXmlGZipB64'] ?? null,
        ];

        foreach ($gzipFields as $gzipField) {
            $xml = $this->decodeGZipBase64ToFiscalXml($gzipField);
            if ($xml !== null) {
                return $xml;
            }
        }

        return null;
    }

    private function extractFiscalXmlFromCandidate(mixed $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        if ($candidate === '' || !str_starts_with(ltrim($candidate), '<')) {
            return null;
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($candidate)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        foreach (['CompNfse', 'Nfse', 'InfNfse'] as $nodeName) {
            $node = $xpath->query("//*[local-name()='{$nodeName}']")->item(0);
            if ($node instanceof \DOMNode) {
                return $dom->saveXML($node) ?: null;
            }
        }

        return null;
    }

    private function decodeGZipBase64ToFiscalXml(mixed $candidate): ?string
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $decoded = base64_decode(trim($candidate), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $xml = @gzdecode($decoded);
        if ($xml === false) {
            $xml = @gzinflate(substr($decoded, 10));
        }

        return $this->extractFiscalXmlFromCandidate($xml);
    }

    private function extractNodeValue(string $xml, string $localName): ?string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $node = $xpath->query("//*[local-name()='{$localName}']")->item(0);
        if (!$node instanceof \DOMNode) {
            return null;
        }

        $value = trim((string) $node->textContent);

        return $value !== '' ? $value : null;
    }

    private function buildDocumentFromParsedEmission(?array $consultaData, array $emissaoData): ?array
    {
        $numero = $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'numero');
        if ($numero === null) {
            return null;
        }

        $parsedEmission = is_array($emissaoData['emissao']['parsed_response'] ?? null)
            ? $emissaoData['emissao']['parsed_response']
            : [];
        $parsedConsulta = is_array($consultaData['raw']['parsed_response'] ?? null)
            ? $consultaData['raw']['parsed_response']
            : [];
        $status = (string) (($parsedConsulta['status'] ?? null) ?: ($parsedEmission['status'] ?? 'unknown'));
        $codigoVerificacao = $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'codigo_verificacao')
            ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'chave_validacao');

        return [
            'xml' => null,
            'numero' => $numero,
            'codigo_verificacao' => $codigoVerificacao,
            'protocolo' => $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'protocolo')
                ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'lote'),
            'status_autorizacao' => match (true) {
                in_array($status, ['error', 'invalid_xml', 'empty'], true) => 'erro',
                $codigoVerificacao !== null || $this->resolveOfficialDocumentUrl($consultaData, $emissaoData) !== null => 'autorizada',
                $status === 'success' => 'pendente',
                default => 'nao_encontrada',
            },
            'data_emissao' => $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'data_emissao'),
            'chave_consulta' => $numero,
        ];
    }

    private function resolveParsedResponseNfseValue(?array $consultaData, array $emissaoData, string $field): ?string
    {
        $candidates = [
            $consultaData['raw']['parsed_response']['nfse'][$field] ?? null,
            $consultaData['raw']['parsed_response'][$field] ?? null,
            $emissaoData['emissao']['parsed_response']['nfse'][$field] ?? null,
            $emissaoData['emissao']['parsed_response'][$field] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function resolveOfficialDocumentUrl(?array $consultaData, array $emissaoData): ?string
    {
        $candidates = [
            $consultaData['raw']['parsed_response']['nfse_url'] ?? null,
            $emissaoData['emissao']['parsed_response']['nfse_url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        if ($this->isBelemMunicipalFlow()) {
            $parsedCandidates = [
                [
                    'parsed' => is_array($consultaData['raw']['parsed_response'] ?? null) ? $consultaData['raw']['parsed_response'] : null,
                    'consulta' => is_array($consultaData['consulta_operacao'] ?? null) ? $consultaData['consulta_operacao'] : null,
                ],
                [
                    'parsed' => is_array($emissaoData['emissao']['parsed_response'] ?? null) ? $emissaoData['emissao']['parsed_response'] : null,
                    'consulta' => is_array($emissaoData['emissao'] ?? null) ? $emissaoData['emissao'] : null,
                ],
            ];

            foreach ($parsedCandidates as $candidateData) {
                $parsed = $candidateData['parsed'] ?? null;
                if (!is_array($parsed)) {
                    continue;
                }

                $nfseData = $this->extractAvailabilityNfseData($parsed);
                if (($nfseData['numero'] ?? null) !== null && ($nfseData['codigo_verificacao'] ?? null) !== null) {
                    return $this->buildBelemOfficialDocumentUrl($nfseData, $parsed, $candidateData['consulta'] ?? null);
                }
            }
        }

        return null;
    }

    /**
     * Verifica prontidão para homologação NFSe Nacional.
     */
    public function verificarProntidaoHomologacao(): FiscalResponse
    {
        try {
            $registry = ProviderRegistry::getInstance();
            $config = $registry->getConfig($this->providerKey);

            $missing = [];
            foreach (['provider_class', 'api_base_url', 'param_api_base_url', 'timeout'] as $key) {
                if (!array_key_exists($key, $config) || trim((string) $config[$key]) === '') {
                    $missing[] = $key;
                }
            }

            foreach (['services', 'endpoints', 'operation_methods', 'catalog_endpoints', 'cnc_endpoints'] as $key) {
                if (!is_array($config[$key] ?? null) || $config[$key] === []) {
                    $missing[] = $key;
                }
            }

            foreach ([
                'services.adn.homologacao' => $config['services']['adn']['homologacao'] ?? null,
                'services.parametrizacao.homologacao' => $config['services']['parametrizacao']['homologacao'] ?? null,
                'services.danfse.homologacao' => $config['services']['danfse']['homologacao'] ?? null,
                'endpoints.emitir' => $config['endpoints']['emitir'] ?? null,
                'endpoints.consultar' => $config['endpoints']['consultar'] ?? null,
                'endpoints.cancelar' => $config['endpoints']['cancelar'] ?? null,
                'endpoints.consultar_rps' => $config['endpoints']['consultar_rps'] ?? null,
                'endpoints.consultar_lote' => $config['endpoints']['consultar_lote'] ?? null,
                'endpoints.baixar_xml' => $config['endpoints']['baixar_xml'] ?? null,
                'endpoints.baixar_danfse' => $config['endpoints']['baixar_danfse'] ?? null,
                'catalog_endpoints.municipios' => $config['catalog_endpoints']['municipios'] ?? null,
                'catalog_endpoints.aliquotas_municipio' => $config['catalog_endpoints']['aliquotas_municipio'] ?? null,
                'catalog_endpoints.convenio_municipio' => $config['catalog_endpoints']['convenio_municipio'] ?? null,
                'cnc_endpoints.contribuinte' => $config['cnc_endpoints']['contribuinte'] ?? null,
                'cnc_endpoints.habilitacao' => $config['cnc_endpoints']['habilitacao'] ?? null,
            ] as $key => $value) {
                if (!is_string($value) || trim($value) === '') {
                    $missing[] = $key;
                }
            }

            $signatureMode = (string) ($config['signature_mode'] ?? 'optional');
            $certManager = CertificateManager::getInstance();
            $certLoaded = $certManager->getCertificate() !== null;
            $certValid = $certManager->isValid();

            if ($signatureMode === 'required' && (!$certLoaded || !$certValid)) {
                $missing[] = 'certificado_digital_valido';
            }

            return FiscalResponse::success([
                'ready' => empty($missing),
                'provider_key' => $this->providerKey,
                'signature_mode' => $signatureMode,
                'certificado_carregado' => $certLoaded,
                'certificado_valido' => $certValid,
                'missing_requirements' => $missing,
            ], 'nfse_homologation_readiness', $this->buildCompatibilityMetadata());
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_homologation_readiness');
        }
    }
}
