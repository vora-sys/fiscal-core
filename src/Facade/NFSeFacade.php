<?php

namespace freeline\FiscalCore\Facade;

use freeline\FiscalCore\Adapters\NF\NFSeAdapter;
use freeline\FiscalCore\Support\NFSeProviderResolver;
use freeline\FiscalCore\Support\ResponseHandler;
use freeline\FiscalCore\Support\FiscalResponse;
use freeline\FiscalCore\Support\BelemMunicipalDocumentUrlBuilder;
use freeline\FiscalCore\Support\MunicipalDanfseRendererResolver;
use freeline\FiscalCore\Support\NFSeRuntimeBootstrap;
use freeline\FiscalCore\Support\ProviderRegistry;

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
    private array $runtimeContext = [];

    public function __construct(string $municipio = 'curitiba', ?NFSeAdapter $nfse = null)
    {
        $this->municipio = $municipio;
        $this->responseHandler = new ResponseHandler();
        $resolver = new NFSeProviderResolver();
        $compat = $resolver->buildMetadata($municipio);
        $this->providerKey = $compat['provider_key'];
        $this->municipioIgnored = $compat['municipio_ignored'];
        $this->deprecationWarnings = $compat['warnings'];

        if ($nfse !== null) {
            $this->nfse = $nfse;
        } else {
            try {
                $registry = ProviderRegistry::getInstance();
                if (!$registry->has($this->providerKey)) {
                    $this->initializationError = FiscalResponse::error(
                        "Provider NFSe nacional '{$this->providerKey}' não encontrado",
                        'PROVIDER_NOT_FOUND',
                        'nfse_initialization',
                        [
                            'available_municipios' => $registry->listMunicipios(),
                            'provider_key' => $this->providerKey,
                            'municipio_input' => $municipio,
                            'municipio_ignored' => $this->municipioIgnored,
                            'warnings' => $this->deprecationWarnings,
                            'suggestions' => [
                                "Configure '{$this->providerKey}' em config/nfse-municipios.json",
                                "O parâmetro 'municipio' foi deprecado para NFSe e não define roteamento",
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

        try {
            $resultado = $this->nfse->emitir($dados);
            $lastEmission = method_exists($this->nfse, 'getLastEmissionInfo')
                ? $this->nfse->getLastEmissionInfo()
                : [];

            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_xml',
                'municipio' => $this->municipio,
                'emissao' => $lastEmission,
            ], 'nfse_emission', [
                'municipio' => $this->municipio,
                'provider_key' => $this->providerKey,
                'municipio_ignored' => $this->municipioIgnored,
                'warnings' => $this->deprecationWarnings,
                'provider_info' => $this->nfse->getProviderInfo()
            ]);
        } catch (\Exception $e) {
            return $this->responseHandler->handle($e, 'nfse_emission');
        }
    }

    public function emitirCompleto(array $dados, array $options = []): FiscalResponse
    {
        if ($check = $this->checkNFSeInitialization()) {
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

            if ($this->isBelemMunicipalFlow()) {
                return $this->emitirCompletoBelem($dados, $emissao, $emissaoData);
            }

            $warnings = [];
            $consultaData = null;
            $nfseXml = $this->extractNfseXmlFromParsedResponse($emissaoData['emissao']['parsed_response'] ?? []);

            if ($nfseXml === null) {
                $consulta = $this->resolvePostEmissionConsultation($dados, $emissaoData);
                if ($consulta !== null) {
                    $consultaData = $consulta->getData();
                    $nfseXml = $this->extractNfseXmlFromParsedResponse(
                        $consultaData['consulta']['parsed_response'] ?? []
                    );
                }
            }

            $flowStatus = 'parcial';
            $danfseData = null;
            if ($nfseXml !== null) {
                $danfse = $this->gerarDanfse($nfseXml);
                if ($danfse->isSuccess()) {
                    $danfseData = $danfse->getData();
                    $flowStatus = 'completo';
                } else {
                    $warnings[] = $danfse->getError();
                }
            } else {
                $warnings[] = 'Nao foi possivel resolver o XML final da NFSe apos a emissao.';
            }

            return FiscalResponse::success([
                'flow_status' => $flowStatus,
                'emissao' => [
                    'resultado' => $emissaoData['resultado'] ?? null,
                    'metadata' => $emissao->getMetadata(),
                    'introspection' => $emissaoData['emissao'] ?? null,
                ],
                'consulta' => $consultaData['consulta'] ?? null,
                'nfse' => $nfseXml !== null ? [
                    'xml' => $nfseXml,
                    'numero' => $this->extractNodeValue($nfseXml, 'Numero')
                        ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'numero'),
                    'codigo_verificacao' => $this->extractNodeValue($nfseXml, 'CodigoVerificacao')
                        ?? $this->resolveParsedResponseNfseValue($consultaData, $emissaoData, 'codigo_verificacao'),
                ] : null,
                'danfse' => $danfseData,
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
            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_consulta',
                'chave' => $chave,
                'municipio' => $this->municipio
            ], 'nfse_query', [
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
            return FiscalResponse::success([
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
            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_substituicao',
                'chave' => $chave,
                'municipio' => $this->municipio,
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
            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_consulta_rps',
                'municipio' => $this->municipio,
                'consulta' => $operation,
            ], 'nfse_query_by_rps', $this->buildCompatibilityMetadata());
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
            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_consulta_lote',
                'protocolo' => $protocolo,
                'municipio' => $this->municipio,
                'consulta' => $operation,
            ], 'nfse_query_lote', $this->buildCompatibilityMetadata());
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
            $resultado = $this->nfse->baixarXml($chave);
            return FiscalResponse::success([
                'resultado' => $resultado,
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
            return FiscalResponse::success([
                'resultado' => $resultado,
                'type' => 'nfse_danfse_download',
                'chave' => $chave,
                'municipio' => $this->municipio,
            ], 'nfse_download_danfse', $this->buildCompatibilityMetadata());
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
            if ($this->isBelemMunicipalFlow()) {
                throw new \RuntimeException(
                    'Belém nao utiliza geracao local de DANFSe neste fluxo. Use consultarDisponibilidade() e consuma a danfse_url oficial da prefeitura.'
                );
            }

            if (trim($xmlNfse) === '') {
                throw new \InvalidArgumentException('XML final da NFSe e obrigatorio para gerar o DANFSe.');
            }

            $renderer = (new MunicipalDanfseRendererResolver())->resolve($this->providerKey);
            $pdf = $renderer->render($xmlNfse);

            return FiscalResponse::success([
                'pdf_base64' => base64_encode($pdf),
                'content_type' => 'application/pdf',
                'filename' => 'danfse_' . strtolower($this->municipio) . '_' . date('Ymd_His') . '.pdf',
            ], 'nfse_generate_danfse', $this->buildCompatibilityMetadata());
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
                    "Provider NFSe nacional '{$this->providerKey}' não está configurado",
                    'MUNICIPALITY_NOT_CONFIGURED',
                    'nfse_municipality_validation',
                    [
                        'available_municipios' => $registry->listMunicipios(),
                        'provider_key' => $this->providerKey,
                        'municipio_input' => $municipioToValidate,
                        'municipio_ignored' => true,
                        'warnings' => $this->deprecationWarnings,
                        'suggestions' => [
                            "Configure '{$this->providerKey}' em config/nfse-municipios.json",
                            "Não é mais necessário configurar provider por município",
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

    private function isBelemMunicipalFlow(): bool
    {
        return $this->providerKey === 'BELEM_MUNICIPAL_2025';
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
        $parsedResponse = is_array($consultaData['consulta']['parsed_response'] ?? null)
            ? $consultaData['consulta']['parsed_response']
            : [];

        $warnings = array_merge($warnings, $source === 'rps' ? ['Consulta por RPS utilizada como fallback da disponibilidade em Belem.'] : []);

        return $this->buildAvailabilityPayloadFromParsedResponse(
            $parsedResponse,
            $source,
            $consultaData['consulta'] ?? null,
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
                $nfseXml = $this->extractNfseXmlFromParsedResponse($consultaLote->getData('consulta')['parsed_response'] ?? []);
                if ($nfseXml !== null) {
                    return $consultaLote;
                }
            }
        }

        $rps = is_array($dados['rps'] ?? null) ? $dados['rps'] : [];
        $consultaRpsPayload = array_filter([
            'numero' => $rps['numero'] ?? null,
            'serie' => $rps['serie'] ?? null,
            'tipo' => $rps['tipo'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if (count($consultaRpsPayload) === 3) {
            return $this->consultarPorRps($consultaRpsPayload);
        }

        return null;
    }

    private function extractNfseXmlFromParsedResponse(array $parsedResponse): ?string
    {
        $rawXml = trim((string) ($parsedResponse['raw_xml'] ?? ''));
        if ($rawXml === '') {
            return null;
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($rawXml)) {
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

    private function resolveParsedResponseNfseValue(?array $consultaData, array $emissaoData, string $field): ?string
    {
        $consultaParsed = $consultaData['consulta']['parsed_response']['nfse'][$field] ?? null;
        if (is_string($consultaParsed) && trim($consultaParsed) !== '') {
            return trim($consultaParsed);
        }

        $emissaoParsed = $emissaoData['emissao']['parsed_response']['nfse'][$field] ?? null;
        if (is_string($emissaoParsed) && trim($emissaoParsed) !== '') {
            return trim($emissaoParsed);
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

            $required = ['provider', 'api_base_url', 'timeout', 'endpoints'];
            $missing = [];
            foreach ($required as $key) {
                if (!array_key_exists($key, $config) || $config[$key] === '' || $config[$key] === []) {
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
