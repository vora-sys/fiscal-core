<?php

namespace sabbajohn\FiscalCore\Providers\NFSe;

use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\DpsDTO;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NacionalDpsIdentityBuilder;
use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Services\NFSe\NacionalCatalogService;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

class NacionalProvider extends AbstractNFSeProvider implements NFSeNacionalCapabilitiesInterface, NFSeOperationalIntrospectionInterface
{
    private NacionalCatalogService $catalogService;
    private $httpClient;
    protected array $config;
    private array $lastResponseData = [];
    private array $lastOperationArtifacts = [];
    private ?string $lastOperation = null;
    private bool $lastSignatureApplied = false;
    private array $lastCertificateContext = [];

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->httpClient = $config['http_client'] ?? null;

        $cacheDir = $config['cache_dir'] ?? null;
        $cacheTtl = (int) ($config['cache_ttl'] ?? 86400);
        $catalogBaseUrl = $this->resolveCatalogBaseUrl();
        $catalogUseCustomHttpClient = (bool)($config['catalog_use_custom_http_client'] ?? false);

        $this->catalogService = new NacionalCatalogService(
            $catalogBaseUrl,
            $this->getTimeout(),
            new FileCacheStore($cacheDir),
            $cacheTtl,
            ($catalogUseCustomHttpClient && is_callable($this->httpClient))
                ? function (string $path) {
                    return call_user_func($this->httpClient, 'GET', $path, null, []);
                }
                : null
        );
    }

    private function resolveCatalogBaseUrl(): string
    {
        $serviceBase = trim((string)($this->config['services']['parametrizacao'][$this->ambiente] ?? ''));
        if ($serviceBase !== '') {
            return $this->normalizeBaseUrl($serviceBase);
        }

        $paramApiBase = trim((string)($this->config['param_api_base_url'] ?? ''));
        if ($paramApiBase !== '') {
            return $this->normalizeBaseUrl($paramApiBase);
        }

        $fallback = $this->ambiente === 'producao'
            ? 'https://adn.nfse.gov.br/parametrizacao'
            : 'https://adn.producaorestrita.nfse.gov.br/parametrizacao';

        return $fallback;
    }

    public function emitir(array $dados): string
    {
        $this->assertDpsIdentityFieldsBeforeNormalization($dados);
        $dados = $this->normalizeDpsPayload($dados, false);
        $this->validarDados($dados);
        $this->validarDadosDpsNacional($dados);
        // $this->assertCatalogCompatibilityBeforeEmission($dados);
        $xml = $this->montarXmlDpsNacional($dados);
        $xml = $this->assinarXmlSeNecessario($xml);
        $xml = $this->ensureUtf8XmlForTransmission($xml);
        $this->assertDpsXmlSchemaValidBeforeEmission($xml);
        try {
            $response = $this->enviarOperacao('emitir', $xml);
            $parsed = $this->processarResposta($response);
            $this->storeOperationState('emitir', $xml, $response, $parsed);
        } catch (\Throwable $e) {
            $transportError = $this->parseTransportErrorDetails($e->getMessage());
            $this->storeOperationState('emitir', $xml, $e->getMessage(), [
                'status' => 'error',
                'http_status' => $transportError['status'] ?? null,
                'mensagens' => $transportError['messages'] !== [] ? $transportError['messages'] : [$e->getMessage()],
                'errors' => $transportError['errors'] ?? [],
                'transport_error' => $e->getMessage(),
                'request_id' => $transportError['request_id'] ?? null,
                'operation_path' => $transportError['path'] ?? null,
            ]);

            throw $e;
        }

        return $response;
    }

    private function assertCatalogCompatibilityBeforeEmission(array $dados): void
    {
        $validation = $this->validarLayoutDps($dados, true);
        $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
        if ($errors !== []) {
            throw new \RuntimeException('Pré-validação NFSe nacional falhou: ' . implode(' | ', $errors));
        }

        $warnings = is_array($validation['warnings'] ?? null) ? $validation['warnings'] : [];
        $catalog = is_array($validation['catalog'] ?? null) ? $validation['catalog'] : [];
        $cTribNac = $this->onlyDigits((string) ($catalog['cTribNac'] ?? $dados['servico']['cTribNac'] ?? ''));
        $codigoMunicipio = $this->onlyDigits((string) ($catalog['codigoMunicipio'] ?? $dados['cLocEmi'] ?? ''));

        foreach ($warnings as $warning) {
            if (!is_string($warning)) {
                continue;
            }

            if (str_contains($warning, 'Catálogo nacional não retornou registro para cTribNac')) {
                throw new \RuntimeException(
                    "Pré-validação NFSe nacional: o catálogo de parametrização municipal não retornou o serviço {$cTribNac} para o município {$codigoMunicipio}. Revise competência, código nacional e enquadramento do prestador antes de enviar para a SEFIN."
                );
            }

            if (str_contains($warning, 'Catálogo nacional não retornou alíquota para cTribNac')) {
                throw new \RuntimeException(
                    "Pré-validação NFSe nacional: o catálogo não retornou parametrização para cTribNac {$cTribNac} no município {$codigoMunicipio}. Revise o código nacional do serviço antes de enviar para a SEFIN."
                );
            }
        }
    }

    private function assertDpsXmlSchemaValidBeforeEmission(string $xml): void
    {
        $validation = $this->validarDpsXml($xml);
        if (($validation['ok'] ?? false) === true) {
            return;
        }

        $messages = array_values(array_filter(array_map(
            static fn (array $error): string => trim((string)($error['message'] ?? '')),
            is_array($validation['errors'] ?? null) ? $validation['errors'] : []
        )));
        if ($messages === []) {
            $messages[] = 'Falha de schema XML sem detalhe retornado pelo validador local.';
        }

        $this->storeOperationState('emitir', $xml, 'XML DPS nacional invalido para XSD antes do envio.', [
            'status' => 'error',
            'error_code' => 'DPS_XML_SCHEMA_INVALID',
            'mensagens' => $messages,
            'errors' => array_map(
                static fn (string $message): array => [
                    'code' => 'DPS_XML_SCHEMA_INVALID',
                    'message' => $message,
                ],
                $messages
            ),
            'schema_validation' => $validation,
        ]);

        throw new \RuntimeException('XML DPS nacional invalido para XSD antes do envio: ' . implode(' | ', $messages));
    }

    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave da NFSe é obrigatória');
        }

        $response = $this->enviarOperacao('consultar', null, ['id' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar', null, $response, $parsed, ['chave_acesso' => $chave]);

        return $this->normalizeConsultaResult('consultar', $parsed, ['chave_consulta' => $chave]);
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if ($chave === '' || $motivo === '') {
            throw new \InvalidArgumentException('Chave e motivo são obrigatórios para cancelamento');
        }

        $xml = $this->buildCancelamentoEventoNacionalXml($chave, $motivo, $protocolo);
        $xml = $this->assinarPedRegEventoSeNecessario($xml);
        $xml = $this->ensureUtf8XmlForTransmission($xml);
        $response = $this->enviarOperacao('cancelar', $xml, ['id' => $chave]);
        $parsed = $this->processarResposta($response);
        $parsed = $this->normalizeCancelamentoResponse($response, $parsed);
        $this->storeOperationState('cancelar', $xml, $response, $parsed, [
            'chave_acesso' => $chave,
            'motivo' => $motivo,
            'protocolo' => $protocolo,
            'layout' => 'pedRegEvento',
        ]);

        return (bool) ($parsed['sucesso'] ?? false);
    }

    public function substituir(string $nfseOriginal, array $dadosSubstituicao): string
    {
        if ($nfseOriginal === '') {
            throw new \InvalidArgumentException('NFSe original é obrigatória para substituição');
        }

        $this->validarDados($dadosSubstituicao);
        $xml = $this->buildSubstituicaoXml($nfseOriginal, $dadosSubstituicao);

        return $this->enviarOperacao('substituir', $xml);
    }

    public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
    {
        foreach (['numero', 'serie', 'tipo'] as $campo) {
            if (!isset($identificacaoRps[$campo])) {
                throw new \InvalidArgumentException("Identificação RPS inválida: campo {$campo} é obrigatório");
            }
        }

        $id = trim((string) ($identificacaoRps['id'] ?? $identificacaoRps['idDPS'] ?? $identificacaoRps['id_dps'] ?? ''));
        if (preg_match(NacionalDpsIdentityBuilder::ID_PATTERN, $id) !== 1) {
            $id = $this->buildDpsIdFromRpsIdentification($identificacaoRps) ?? '';
        }

        if (preg_match(NacionalDpsIdentityBuilder::ID_PATTERN, $id) === 1) {
            $response = $this->requestHttp(
                'GET',
                $this->resolveConfiguredEndpoint(
                    (string) ($this->config['endpoints']['consultar_dps'] ?? 'sefin:/dps/{id}'),
                    ['id' => $id]
                ),
                null,
                ['Accept: application/json']
            );
            $parsed = $this->processarResposta($response);
            $this->storeOperationState('consultar_dps', null, $response, $parsed, ['id_dps' => $id]);

            return $this->normalizeConsultaResult('consultar_dps', $parsed, [
                'chave_consulta' => $id,
                'source' => 'consultar_dps',
            ]);
        }

        $xml = $this->buildConsultaRpsXml($identificacaoRps);
        $response = $this->enviarOperacao('consultar_rps', $xml);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar_rps', $xml, $response, $parsed, ['rps' => $identificacaoRps]);

        return $this->normalizeConsultaResult('consultar_rps', $parsed, [
            'chave_consulta' => (string) $identificacaoRps['numero'],
            'source' => 'consultar_rps',
        ]);
    }

    /**
     * @param array<string,mixed> $identificacaoRps
     */
    private function buildDpsIdFromRpsIdentification(array $identificacaoRps): ?string
    {
        $prestadorInput = is_array($identificacaoRps['prestador'] ?? null)
            ? $identificacaoRps['prestador']
            : [];
        $prestador = [
            'cnpj' => $identificacaoRps['cnpj']
                ?? $identificacaoRps['prestador_cnpj']
                ?? $prestadorInput['cnpj']
                ?? $this->config['prestador']['cnpj']
                ?? $this->config['empresa']['cnpj']
                ?? null,
            'cpf' => $identificacaoRps['cpf']
                ?? $identificacaoRps['prestador_cpf']
                ?? $prestadorInput['cpf']
                ?? $this->config['prestador']['cpf']
                ?? null,
            'documento' => $identificacaoRps['documento']
                ?? $identificacaoRps['prestador_documento']
                ?? $prestadorInput['documento']
                ?? null,
            'codigoMunicipio' => $identificacaoRps['codigo_municipio']
                ?? $identificacaoRps['codigoMunicipio']
                ?? $identificacaoRps['cLocEmi']
                ?? $prestadorInput['codigoMunicipio']
                ?? $prestadorInput['codigo_municipio']
                ?? $this->getCodigoMunicipio(),
        ];

        return NacionalDpsIdentityBuilder::fromPayload([
            'cLocEmi' => $identificacaoRps['cLocEmi'] ?? $prestador['codigoMunicipio'],
            'serie' => $identificacaoRps['serie'] ?? '1',
            'nDPS' => $identificacaoRps['numero'] ?? $identificacaoRps['nDPS'] ?? $identificacaoRps['numero_dps'] ?? null,
            'prestador' => $prestador,
        ], [
            'codigo_municipio' => $this->getCodigoMunicipio(),
        ]);
    }

    public function consultarLote(string $protocolo): NFSeConsultaResultInterface
    {
        if ($protocolo === '') {
            throw new \InvalidArgumentException('Protocolo do lote é obrigatório');
        }

        $xml = $this->buildConsultaLoteXml($protocolo);
        $response = $this->enviarOperacao('consultar_lote', $xml);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar_lote', $xml, $response, $parsed, ['protocolo' => $protocolo]);

        return $this->normalizeConsultaResult('consultar_lote', $parsed, [
            'chave_consulta' => $protocolo,
            'source' => 'consultar_lote',
        ]);
    }

    public function baixarXml(string $chave): string
    {
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave é obrigatória');
        }

        $xml = $this->buildDownloadXmlPayload('xml', $chave);
        $response = $this->enviarOperacao('baixar_xml', $xml, ['id' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('baixar_xml', $xml, $response, $parsed, ['chave_acesso' => $chave]);

        return json_encode($parsed);
    }

    public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
    {
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave é obrigatória');
        }

        $xml = $this->buildDownloadXmlPayload('danfse', $chave);
        $response = $this->enviarOperacao('baixar_danfse', $xml, ['chave' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('baixar_danfse', $xml, $response, $parsed, ['chave_acesso' => $chave]);

        return $this->normalizePrintResult($parsed, [
            'chave_consulta' => $chave,
            'filename' => 'danfse_nfse_nacional_' . date('Ymd_His') . '.pdf',
            'print_source' => 'download_danfse',
        ]);
    }

    public function listarMunicipiosNacionais(bool $forceRefresh = false): array
    {
        if (isset($this->config['catalog_endpoints']['municipios'])) {
            return $this->requestCatalogEndpoint(
                'municipios',
                [],
                $forceRefresh
            );
        }

        return $this->catalogService->listarMunicipios($forceRefresh);
    }

    public function consultarAliquotasMunicipio(
        string $codigoMunicipio,
        ?string $codigoServico = null,
        ?string $competencia = null,
        bool $forceRefresh = false
    ): array
    {
        if (is_bool($codigoServico)) {
            $forceRefresh = $codigoServico;
            $codigoServico = null;
        }

        if (isset($this->config['catalog_endpoints']['aliquotas_municipio'])) {
            return $this->requestCatalogEndpoint(
                'aliquotas_municipio',
                [
                    'codigo_municipio' => $codigoMunicipio,
                    'codigoServico' => $codigoServico,
                    'codigo_servico' => $codigoServico,
                    'competencia' => $competencia,
                ],
                $forceRefresh
            );
        }

        return $this->catalogService->consultarAliquotasMunicipio(
            $codigoMunicipio,
            $codigoServico,
            $competencia,
            $forceRefresh
        );
    }

    public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
    {
        if (isset($this->config['catalog_endpoints']['convenio_municipio'])) {
            return $this->requestCatalogEndpoint(
                'convenio_municipio',
                ['codigo_municipio' => $codigoMunicipio],
                $forceRefresh
            );
        }

        return $this->catalogService->consultarConvenioMunicipio($codigoMunicipio, $forceRefresh);
    }

    public function validarLayoutDps(array $payload, bool $checkCatalog = true): array
    {
        $payload = $this->normalizeDpsPayload($payload, false);
        $errors = [];
        $warnings = [];

        $required = [
            'id' => (string)($payload['id'] ?? ''),
            'tpAmb' => (string)($payload['tpAmb'] ?? ''),
            'dhEmi' => (string)($payload['dhEmi'] ?? ''),
            'verAplic' => (string)($payload['verAplic'] ?? ''),
            'serie' => (string)($payload['serie'] ?? ''),
            'nDPS' => (string)($payload['nDPS'] ?? ''),
            'dCompet' => (string)($payload['dCompet'] ?? ''),
            'tpEmit' => (string)($payload['tpEmit'] ?? ''),
            'cLocEmi' => (string)($payload['cLocEmi'] ?? ''),
            'prestador.cnpj' => (string)($payload['prestador']['cnpj'] ?? ''),
            'prestador.inscricaoMunicipal' => (string)($payload['prestador']['inscricaoMunicipal'] ?? ''),
            'prestador.regTrib.opSimpNac' => (string)($payload['prestador']['opSimpNac'] ?? ''),
            'prestador.regTrib.regEspTrib' => (string)($payload['prestador']['regEspTrib'] ?? ''),
            'serv.cLocPrestacao' => (string)($payload['servico']['cLocPrestacao'] ?? ''),
            'serv.cTribNac' => (string)($payload['servico']['cTribNac'] ?? ''),
            'serv.xDescServ' => (string)($payload['servico']['descricao'] ?? ''),
            'valores.vServPrest.vServ' => (string)($payload['valor_servicos'] ?? ''),
            'valores.trib.tribMun.tribISSQN' => (string)($payload['tributacao']['municipal']['tribISSQN'] ?? $payload['servico']['tribISSQN'] ?? ''),
            'valores.trib.tribMun.tpRetISSQN' => (string)($payload['tributacao']['municipal']['tpRetISSQN'] ?? $payload['servico']['tpRetISSQN'] ?? ''),
        ];

        foreach ($required as $path => $value) {
            if (trim((string)$value) === '') {
                $errors[] = "Campo obrigatório ausente no layout DPS: {$path}";
            }
        }

        $id = (string)($payload['id'] ?? '');
        if ($id !== '' && !preg_match('/^DPS\d{42}$/', $id)) {
            $errors[] = "Formato inválido para DPS/infDPS/id: '{$id}' (esperado literal DPS + 42 dígitos).";
        }

        $cTribNac = $this->onlyDigits((string)($payload['servico']['cTribNac'] ?? ''));
        if (strlen($cTribNac) !== 6) {
            $errors[] = 'cTribNac deve conter 6 dígitos numéricos.';
        }

        $cLocEmi = $this->onlyDigits((string)($payload['cLocEmi'] ?? ''));
        if (strlen($cLocEmi) !== 7) {
            $errors[] = 'cLocEmi deve conter 7 dígitos (código IBGE).';
        }

        $aliqPayload = (float)($payload['tributacao']['municipal']['pAliq'] ?? $payload['servico']['aliquota'] ?? 0);
        $tribIssqnPayload = (string)($payload['tributacao']['municipal']['tribISSQN'] ?? $payload['servico']['tribISSQN'] ?? '');
        if ($tribIssqnPayload === '1' && $aliqPayload <= 0) {
            $errors[] = 'Para tribISSQN=1, informe alíquota ISS > 0 (campo servico.aliquota) para gerar pAliq.';
        }
        $catalogSummary = null;
        if ($checkCatalog && strlen($cLocEmi) === 7) {
            try {
                $competencia = $this->normalizeCompetenciaForParamApi((string)($payload['dCompet'] ?? ''));
                $aliqResponse = $this->catalogService->consultarAliquotasMunicipio(
                    $cLocEmi,
                    $cTribNac !== '' ? $cTribNac : null,
                    $competencia,
                    false
                );

                $catalogData = $aliqResponse['data'] ?? [];
                $catalogHasService = $this->catalogContainsServiceCode(
                    is_array($catalogData) ? $catalogData : [],
                    $cTribNac
                );
                $catalogAliq = $this->extractAliquotaMunicipalFromCatalog(
                    is_array($catalogData) ? $catalogData : [],
                    $cTribNac
                );

                $catalogSummary = [
                    'codigoMunicipio' => $cLocEmi,
                    'cTribNac' => $cTribNac,
                    'competencia' => $competencia,
                    'servicoEncontradoCatalogo' => $catalogHasService,
                    'aliquotaCatalogo' => $catalogAliq,
                    'source' => $aliqResponse['metadata']['source'] ?? null,
                    'stale' => $aliqResponse['metadata']['stale'] ?? null,
                ];

                if ($catalogHasService === false) {
                    $warnings[] = "Catálogo nacional não retornou registro para cTribNac {$cTribNac} no município {$cLocEmi}.";
                } elseif ($catalogAliq === null) {
                    $warnings[] = "Catálogo nacional não retornou alíquota para cTribNac {$cTribNac} no município {$cLocEmi}.";
                } elseif ($aliqPayload <= 0) {
                    $warnings[] = "Alíquota não informada no payload. Catálogo sugere {$catalogAliq}.";
                } elseif (abs($aliqPayload - $catalogAliq) > 0.0001) {
                    $warnings[] = "Alíquota do payload ({$aliqPayload}) difere do catálogo ({$catalogAliq}) para cTribNac {$cTribNac}.";
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Falha ao consultar parametrização nacional de alíquotas: ' . $e->getMessage();
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
            'catalog' => $catalogSummary,
        ];
    }

    public function gerarXmlDpsPreview(array $payload): ?string
    {
        try {
            return $this->montarXmlDpsNacional($payload);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function validarXmlDps(array $payload): array
    {
        $xml = $this->gerarXmlDpsPreview($payload);
        if ($xml === null || trim($xml) === '') {
            return [
                'valid' => false,
                'xml' => null,
                'errors' => ['Não foi possível gerar o XML DPS para validação pré-envio.'],
                'missingTags' => [],
            ];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml)) {
            $libErrors = libxml_get_errors();
            libxml_clear_errors();
            $first = $libErrors[0]->message ?? 'XML inválido';
            return [
                'valid' => false,
                'xml' => $xml,
                'errors' => ['XML inválido: ' . trim((string)$first)],
                'missingTags' => [],
            ];
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $wrapInNfse = $this->shouldWrapDpsInNfse();
        $dpsBasePath = $wrapInNfse
            ? '/*[local-name()="NFSe"]/*[local-name()="infNFSe"]/*[local-name()="DPS"]'
            : '/*[local-name()="DPS"]';
        $required = [
            ['tag' => 'DPS', 'xpath' => $dpsBasePath],
            ['tag' => 'infDPS', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]'],
            ['tag' => 'infDPS/@Id', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/@Id'],
            ['tag' => 'tpAmb', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="tpAmb"]'],
            ['tag' => 'dhEmi', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="dhEmi"]'],
            ['tag' => 'serie', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="serie"]'],
            ['tag' => 'nDPS', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="nDPS"]'],
            ['tag' => 'dCompet', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="dCompet"]'],
            ['tag' => 'cLocEmi', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="cLocEmi"]'],
            ['tag' => 'prest', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="prest"]'],
            ['tag' => 'serv', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="serv"]'],
            ['tag' => 'serv/locPrest/cLocPrestacao', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="serv"]/*[local-name()="locPrest"]/*[local-name()="cLocPrestacao"]'],
            ['tag' => 'serv/cServ/cTribNac', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="serv"]/*[local-name()="cServ"]/*[local-name()="cTribNac"]'],
            ['tag' => 'serv/cServ/xDescServ', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="serv"]/*[local-name()="cServ"]/*[local-name()="xDescServ"]'],
            ['tag' => 'valores/vServPrest/vServ', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="valores"]/*[local-name()="vServPrest"]/*[local-name()="vServ"]'],
            ['tag' => 'valores/trib/tribMun/tribISSQN', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="tribMun"]/*[local-name()="tribISSQN"]'],
            ['tag' => 'valores/trib/tribMun/tpRetISSQN', 'xpath' => $dpsBasePath . '/*[local-name()="infDPS"]/*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="tribMun"]/*[local-name()="tpRetISSQN"]'],
        ];

        $missingTags = [];
        foreach ($required as $rule) {
            $nodes = $xpath->query($rule['xpath']);
            if (!$nodes || $nodes->length === 0) {
                $missingTags[] = [
                    'tag' => $rule['tag'],
                    'xpath' => $rule['xpath'],
                ];
            }
        }

        $errors = [];
        if (!empty($missingTags)) {
            foreach ($missingTags as $missing) {
                $errors[] = "Tag obrigatória ausente: {$missing['tag']} ({$missing['xpath']})";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'xml' => $xml,
            'errors' => $errors,
            'missingTags' => $missingTags,
        ];
    }

    protected function montarXmlRps(array $dados): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $ns = $this->getIntegrationNamespace();

        $envio = $dom->createElementNS($ns, 'GerarNfseEnvio');
        $envio->setAttribute('versao', $this->getVersao());
        $dom->appendChild($envio);

        $rps = $this->appendNode($dom, $envio, 'Rps');
        $inf = $this->appendNode($dom, $rps, 'InfDeclaracaoPrestacaoServico');
        $inf->setAttribute('Id', (string) ($dados['id'] ?? ('RPS' . ($dados['rps_numero'] ?? '1'))));

        $rpsInterno = $this->appendNode($dom, $inf, 'Rps');
        $identificacao = $this->appendNode($dom, $rpsInterno, 'IdentificacaoRps');
        $this->appendNode($dom, $identificacao, 'Numero', (string) ($dados['rps_numero'] ?? '1'));
        $this->appendNode($dom, $identificacao, 'Serie', (string) ($dados['rps_serie'] ?? 'A1'));
        $this->appendNode($dom, $identificacao, 'Tipo', (string) ($dados['rps_tipo'] ?? '1'));
        $this->appendNode($dom, $rpsInterno, 'DataEmissao', (string) ($dados['data_emissao_rps'] ?? date('Y-m-d')));
        $this->appendNode($dom, $rpsInterno, 'Status', (string) ($dados['status_rps'] ?? '1'));

        $this->appendNode($dom, $inf, 'Competencia', (string) ($dados['competencia'] ?? date('Y-m')));
        $this->appendNode($dom, $inf, 'NaturezaOperacao', (string) ($dados['natureza_operacao'] ?? '1'));
        $this->appendNode($dom, $inf, 'OptanteSimplesNacional', (string) ($dados['optante_simples_nacional'] ?? '1'));
        $this->appendNode($dom, $inf, 'IncentivadorCultural', (string) ($dados['incentivador_cultural'] ?? '2'));

        $servico = $this->appendNode($dom, $inf, 'Servico');
        $valores = $this->appendNode($dom, $servico, 'Valores');
        $valorServicos = (float) $dados['valor_servicos'];
        $aliquota = (float) ($dados['servico']['aliquota'] ?? 0);
        $baseCalculo = (float) ($dados['servico']['base_calculo'] ?? $valorServicos);

        $this->appendNode($dom, $valores, 'ValorServicos', $this->formatDecimal($valorServicos, 5));
        $this->appendNode($dom, $valores, 'ValorDeducoes', $this->formatDecimal((float) ($dados['servico']['valor_deducoes'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'ValorPis', $this->formatDecimal((float) ($dados['servico']['valor_pis'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'ValorCofins', $this->formatDecimal((float) ($dados['servico']['valor_cofins'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'ValorInss', $this->formatDecimal((float) ($dados['servico']['valor_inss'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'ValorIr', $this->formatDecimal((float) ($dados['servico']['valor_ir'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'ValorCsll', $this->formatDecimal((float) ($dados['servico']['valor_csll'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'IssRetido', (string) ($dados['servico']['iss_retido'] ?? '2'));
        $this->appendNode($dom, $valores, 'BaseCalculo', $this->formatDecimal($baseCalculo, 5));
        $this->appendNode($dom, $valores, 'Aliquota', $this->formatDecimal((float) $this->formatarAliquota($aliquota), 5));
        $this->appendNode($dom, $valores, 'ValorLiquidoNfse', $this->formatDecimal((float) ($dados['servico']['valor_liquido_nfse'] ?? $valorServicos), 5));
        $this->appendNode($dom, $valores, 'DescontoIncondicionado', $this->formatDecimal((float) ($dados['servico']['desconto_incondicionado'] ?? 0), 5));
        $this->appendNode($dom, $valores, 'DescontoCondicionado', $this->formatDecimal((float) ($dados['servico']['desconto_condicionado'] ?? 0), 5));

        $this->appendNode($dom, $servico, 'ItemListaServico', (string) ($dados['servico']['item_lista_servico'] ?? $dados['servico']['codigo']));
        $this->appendNode($dom, $servico, 'Discriminacao', (string) ($dados['servico']['discriminacao'] ?? ''));
        $this->appendNode($dom, $servico, 'InformacoesComplementares', (string) ($dados['servico']['informacoes_complementares'] ?? ''));
        $this->appendNode($dom, $servico, 'CodigoMunicipio', (string) ($dados['servico']['codigo_municipio'] ?? $this->getCodigoMunicipio()));

        $prestador = $this->appendNode($dom, $inf, 'Prestador');
        $this->appendNode($dom, $prestador, 'Cnpj', $this->onlyDigits((string) ($dados['prestador']['cnpj'] ?? '')));
        $this->appendNode($dom, $prestador, 'InscricaoMunicipal', (string) ($dados['prestador']['inscricaoMunicipal'] ?? ''));

        $tomador = $this->appendNode($dom, $inf, 'Tomador');
        $identTomador = $this->appendNode($dom, $tomador, 'IdentificacaoTomador');
        $cpfCnpj = $this->appendNode($dom, $identTomador, 'CpfCnpj');
        $docTomador = $this->onlyDigits((string) ($dados['tomador']['documento'] ?? ''));
        if (strlen($docTomador) === 14) {
            $this->appendNode($dom, $cpfCnpj, 'Cnpj', $docTomador);
        } else {
            $this->appendNode($dom, $cpfCnpj, 'Cpf', $docTomador);
        }
        $this->appendNode($dom, $tomador, 'RazaoSocial', (string) ($dados['tomador']['razaoSocial'] ?? ''));

        if (!empty($dados['tomador']['email']) || !empty($dados['tomador']['telefone'])) {
            $contato = $this->appendNode($dom, $tomador, 'Contato');
            if (!empty($dados['tomador']['telefone'])) {
                $this->appendNode($dom, $contato, 'Telefone', $this->onlyDigits((string) $dados['tomador']['telefone']));
            }
            if (!empty($dados['tomador']['email'])) {
                $this->appendNode($dom, $contato, 'Email', (string) $dados['tomador']['email']);
            }
        }

        return $dom->saveXML() ?: '';
    }

    protected function montarXmlDpsNacional(array $dados): string
    {
        $dados = $this->normalizeDpsPayload($dados, true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $ns = $this->getDpsNamespace();

        $versao = $this->resolveDpsVersion();
        $serieId = $this->normalizeNumeric((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'), 5, '1');
        $serie = $this->normalizeDpsSerieForXml($serieId, $versao);
        $nDpsId = $this->normalizeNumeric((string)($dados['nDPS'] ?? $dados['numero_rps'] ?? '1'), 15, '1');
        $nDps = ltrim($nDpsId, '0');
        if ($nDps === '') {
            $nDps = '1';
        }
        $dCompet = $this->normalizeDpsDate((string)($dados['dCompet'] ?? ''));
        $tpAmb = (string)($dados['tpAmb'] ?? ($this->getAmbiente() === 'producao' ? '1' : '2'));
        $dhEmi = $this->normalizeDpsDateTime((string)($dados['dhEmi'] ?? ''));
        $verAplic = (string)($dados['verAplic'] ?? $this->config['ver_aplic'] ?? 'invoiceflow-1.0');
        $tpEmit = (string)($dados['tpEmit'] ?? '1');

        $dpsId = $this->buildDpsId($dados, $serieId, $nDpsId);
        $wrapInNfse = $this->shouldWrapDpsInNfse();

        if ($wrapInNfse) {
            $root = $dom->createElementNS($ns, 'NFSe');
            $root->setAttribute('versao', $versao);
            $dom->appendChild($root);

            $infNfse = $dom->createElementNS($ns, 'infNFSe');
            $nfseId = (string)($dados['nfse']['id'] ?? $dados['nfse_id'] ?? ('NFSe' . substr($dpsId, 3)));
            if ($nfseId !== '') {
                $infNfse->setAttribute('Id', $nfseId);
            }
            $root->appendChild($infNfse);

            $dps = $dom->createElementNS($ns, 'DPS');
            $dps->setAttribute('versao', $versao);
            $infNfse->appendChild($dps);
        } else {
            $dps = $dom->createElementNS($ns, 'DPS');
            $dps->setAttribute('versao', $versao);
            $dom->appendChild($dps);
        }

        $inf = $dom->createElementNS($ns, 'infDPS');
        $inf->setAttribute('Id', $dpsId);
        $dps->appendChild($inf);

        $this->appendNodeDps($dom, $inf, 'tpAmb', $tpAmb);
        $this->appendNodeDps($dom, $inf, 'dhEmi', $dhEmi);
        $this->appendNodeDps($dom, $inf, 'verAplic', $verAplic);
        $this->appendNodeDps($dom, $inf, 'serie', $serie);
        $this->appendNodeDps($dom, $inf, 'nDPS', $nDps);
        $this->appendNodeDps($dom, $inf, 'dCompet', $dCompet);
        $this->appendNodeDps($dom, $inf, 'tpEmit', $tpEmit);

        $cLocEmiInput = (string)($dados['cLocEmi'] ?? $dados['prestador']['codigoMunicipio'] ?? $this->getCodigoMunicipio());
        $cLocEmi = str_pad(substr($this->onlyDigits($cLocEmiInput), 0, 7), 7, '0', STR_PAD_LEFT);
        $this->appendNodeDps($dom, $inf, 'cLocEmi', $cLocEmi);

        $this->appendPrestadorDps($dom, $inf, (array) ($dados['prestador'] ?? []), $tpEmit, $cLocEmi);
        $this->appendTomadorDps($dom, $inf, (array) ($dados['tomador'] ?? []));
        $this->appendServicoDps($dom, $inf, $dados, $cLocEmi);
        $this->appendValoresDps($dom, $inf, $dados);
        $this->appendIbscbsDps($dom, $inf, $dados);

        return $dom->saveXML() ?: '';
    }

    /**
     * @param array<string,mixed> $dados
     * @return array<string,mixed>
     */
    private function normalizeDpsPayload(array $dados, bool $validate): array
    {
        $dto = DpsDTO::fromArray($dados, $this->buildDpsDtoContext());
        if ($validate) {
            $errors = $dto->validate();
            if ($errors !== []) {
                throw new \InvalidArgumentException('Layout DPS inválido: ' . implode(' | ', $errors));
            }
        }

        return $dto->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDpsDtoContext(): array
    {
        return [
            'codigo_municipio' => $this->getCodigoMunicipio(),
            'ambiente' => $this->getAmbiente(),
            'ver_aplic' => $this->config['ver_aplic'] ?? 'invoiceflow-1.0',
        ];
    }

    /**
     * @param array<string,mixed> $prestador
     */
    private function appendPrestadorDps(\DOMDocument $dom, \DOMElement $inf, array $prestador, string $tpEmit, string $cLocEmi): void
    {
        $ns = $this->getDpsNamespace();
        $prest = $dom->createElementNS($ns, 'prest');
        $inf->appendChild($prest);

        $prestDoc = $this->onlyDigits((string)($prestador['cnpj'] ?? $prestador['cpf'] ?? $prestador['documento'] ?? ''));
        if (strlen($prestDoc) === 14) {
            $this->appendNodeDps($dom, $prest, 'CNPJ', $prestDoc);
        } else {
            $this->appendNodeDps($dom, $prest, 'CPF', str_pad(substr($prestDoc, 0, 11), 11, '0', STR_PAD_LEFT));
        }

        $caepf = $this->onlyDigits((string)($prestador['CAEPF'] ?? $prestador['caepf'] ?? ''));
        if ($caepf !== '') {
            $this->appendNodeDps($dom, $prest, 'CAEPF', $caepf);
        }

        $rawPrestIm = trim((string)($prestador['inscricaoMunicipal'] ?? $prestador['IM'] ?? ''));
        $prestIm = $this->getAmbiente() === 'producao'
            ? $rawPrestIm
            : $this->normalizeMunicipalRegistration($rawPrestIm);
        if ($prestIm !== '' && $this->shouldSendPrestadorIm($prestador, $cLocEmi)) {
            $this->appendNodeDps($dom, $prest, 'IM', $prestIm);
        }

        $prestNome = trim((string)($prestador['razaoSocial'] ?? $prestador['razao_social'] ?? $prestador['nome'] ?? ''));
        if ($prestNome !== '' && $tpEmit !== '1') {
            $this->appendNodeDps($dom, $prest, 'xNome', $prestNome);
        }

        $telefone = $this->onlyDigits((string)($prestador['telefone'] ?? $prestador['fone'] ?? ''));
        if ($telefone !== '') {
            $this->appendNodeDps($dom, $prest, 'fone', $telefone);
        }
        $email = trim((string)($prestador['email'] ?? ''));
        if ($email !== '') {
            $this->appendNodeDps($dom, $prest, 'email', $email);
        }

        $regTrib = $dom->createElementNS($ns, 'regTrib');
        $prest->appendChild($regTrib);
        $opSimpNac = (string)($prestador['opSimpNac'] ?? '1');
        $this->appendNodeDps($dom, $regTrib, 'opSimpNac', $opSimpNac);
        $regApTribSn = trim((string)($prestador['regApTribSN'] ?? $prestador['regime_apuracao_sn'] ?? ''));
        if ($regApTribSn === '' && $opSimpNac === '3') {
            $regApTribSn = '1';
        }
        if ($regApTribSn !== '') {
            $this->appendNodeDps($dom, $regTrib, 'regApTribSN', $regApTribSn);
        }
        $this->appendNodeDps($dom, $regTrib, 'regEspTrib', (string)($prestador['regEspTrib'] ?? '0'));
    }

    /**
     * @param array<string,mixed> $prestador
     */
    private function shouldSendPrestadorIm(array $prestador, string $cLocEmi): bool
    {
        $explicitSend = $this->optionalBoolean([
            $prestador['enviarIM'] ?? null,
            $prestador['enviar_im'] ?? null,
            $prestador['informarIM'] ?? null,
            $prestador['informar_im'] ?? null,
        ]);
        if ($explicitSend !== null) {
            return $explicitSend;
        }

        $policy = strtolower(trim((string) (
            $prestador['imPolicy'] ?? $prestador['im_policy']
            ?? $this->config['prestador_im_policy']
            ?? $this->config['dps']['prestador_im_policy']
            ?? ''
        )));
        if (in_array($policy, ['omit', 'omitir', 'nao_informar', 'não_informar'], true)) {
            return false;
        }
        if (in_array($policy, ['send', 'enviar', 'informar'], true)) {
            return true;
        }

        $explicitOmit = $this->optionalBoolean([
            $prestador['omitirIM'] ?? null,
            $prestador['omitir_im'] ?? null,
            $this->config['omit_prestador_im'] ?? null,
            $this->config['dps']['omit_prestador_im'] ?? null,
        ]);
        if ($explicitOmit !== null) {
            return !$explicitOmit;
        }

        $omitMunicipios = array_merge(
            (array) ($this->config['dps']['omit_prestador_im_municipios'] ?? []),
            (array) ($this->config['omit_prestador_im_municipios'] ?? []),
        );
        foreach ((array) $omitMunicipios as $municipio) {
            if ($this->onlyDigits((string) $municipio) === $this->onlyDigits($cLocEmi)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function optionalBoolean(array $values): ?bool
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value !== 0;
            }

            $normalized = strtolower(trim((string) $value));
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($ascii) && $ascii !== '') {
                $normalized = $ascii;
            }

            if (in_array($normalized, ['1', 'true', 'sim', 'yes', 'on', 'enviar', 'informar', 'send'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'nao', 'no', 'off', 'omitir', 'omit'], true)) {
                return false;
            }

            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $tomador
     */
    private function appendTomadorDps(\DOMDocument $dom, \DOMElement $inf, array $tomador): void
    {
        $tomadorDoc = $this->onlyDigits((string)($tomador['documento'] ?? $tomador['cnpj'] ?? $tomador['cpf'] ?? ''));
        $tomadorNome = trim((string)($tomador['razaoSocial'] ?? $tomador['razao_social'] ?? $tomador['nome'] ?? ''));
        if ($tomadorDoc === '' || $tomadorNome === '') {
            return;
        }

        $ns = $this->getDpsNamespace();
        $toma = $dom->createElementNS($ns, 'toma');
        $inf->appendChild($toma);
        if (strlen($tomadorDoc) === 14) {
            $this->appendNodeDps($dom, $toma, 'CNPJ', $tomadorDoc);
        } else {
            $this->appendNodeDps($dom, $toma, 'CPF', str_pad(substr($tomadorDoc, 0, 11), 11, '0', STR_PAD_LEFT));
        }
        $this->appendNodeDps($dom, $toma, 'xNome', $tomadorNome);
        $this->appendTomadorEnderecoDps($dom, $toma, $tomador);

        $telefone = $this->onlyDigits((string)($tomador['telefone'] ?? $tomador['fone'] ?? ''));
        if ($telefone !== '') {
            $this->appendNodeDps($dom, $toma, 'fone', $telefone);
        }
        $email = trim((string)($tomador['email'] ?? ''));
        if ($email !== '') {
            $this->appendNodeDps($dom, $toma, 'email', $email);
        }
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendServicoDps(\DOMDocument $dom, \DOMElement $inf, array $dados, string $cLocEmi): void
    {
        $ns = $this->getDpsNamespace();
        $servico = (array)($dados['servico'] ?? []);
        $serv = $dom->createElementNS($ns, 'serv');
        $inf->appendChild($serv);

        $locPrest = $dom->createElementNS($ns, 'locPrest');
        $serv->appendChild($locPrest);
        $cLocPrestInput = (string)($servico['cLocPrestacao'] ?? $servico['codigo_municipio'] ?? $cLocEmi);
        $cLocPrest = str_pad(substr($this->onlyDigits($cLocPrestInput), 0, 7), 7, '0', STR_PAD_LEFT);
        $this->appendNodeDps($dom, $locPrest, 'cLocPrestacao', $cLocPrest);

        $cServ = $dom->createElementNS($ns, 'cServ');
        $serv->appendChild($cServ);
        $cTribNac = $this->normalizeCTribNac((string)($servico['cTribNac'] ?? $servico['codigoServicoNacional'] ?? $servico['codigo'] ?? ''));
        if ($cTribNac === '') {
            $cTribNac = '010101';
        }
        $cTribMun = $this->normalizeCTribMun((string)($servico['cTribMun'] ?? $servico['codigoMunicipal'] ?? ''));
        $cNbs = preg_replace('/\D+/', '', (string)($servico['cNBS'] ?? $servico['nbs'] ?? '')) ?? '';
        if ($cNbs !== '' && strlen($cNbs) !== 9) {
            throw new \InvalidArgumentException('servico.cNBS deve conter exatamente 9 dígitos.');
        }
        $this->appendNodeDps($dom, $cServ, 'cTribNac', $cTribNac);
        if ($cTribMun !== '') {
            $this->appendNodeDps($dom, $cServ, 'cTribMun', $cTribMun);
        }
        $this->appendNodeDps($dom, $cServ, 'xDescServ', (string)($servico['descricao'] ?? $servico['discriminacao'] ?? 'Servico'));
        if ($cNbs !== '') {
            $this->appendNodeDps($dom, $cServ, 'cNBS', $cNbs);
        }

        $this->appendObraGroup($dom, $serv, (array)($servico['obra'] ?? []));
        $this->appendInfoComplGroup($dom, $serv, $servico);
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendValoresDps(\DOMDocument $dom, \DOMElement $inf, array $dados): void
    {
        $ns = $this->getDpsNamespace();
        $valoresPayload = is_array($dados['valores'] ?? null) ? $dados['valores'] : [];
        $valores = $dom->createElementNS($ns, 'valores');
        $inf->appendChild($valores);

        $vServPrest = $dom->createElementNS($ns, 'vServPrest');
        $valores->appendChild($vServPrest);
        $vReceb = $this->firstDecimal([
            $valoresPayload['vReceb'] ?? null,
            $valoresPayload['valor_recebido'] ?? null,
        ]);
        if ($vReceb !== null) {
            $this->appendNodeDps($dom, $vServPrest, 'vReceb', $this->formatDecimal($vReceb, 2));
        }
        $valorServicos = (float)($dados['valor_servicos'] ?? $valoresPayload['vServ'] ?? 0);
        $this->appendNodeDps($dom, $vServPrest, 'vServ', $this->formatDecimal($valorServicos, 2));

        $this->appendDescontosDps($dom, $valores, $dados);
        $this->appendDeducaoReducaoDps($dom, $valores, $dados);

        $trib = $dom->createElementNS($ns, 'trib');
        $valores->appendChild($trib);
        $this->appendTributacaoMunicipalDps($dom, $trib, $dados);
        $this->appendTributacaoFederalDps($dom, $trib, $dados);
        $this->appendTotalTributosDps($dom, $trib, $dados);
    }

    /**
     * @param array<string,mixed> $servico
     */
    private function appendInfoComplGroup(\DOMDocument $dom, \DOMElement $serv, array $servico): void
    {
        $info = is_array($servico['infoCompl'] ?? null) ? $servico['infoCompl'] : [];
        $values = [
            'idDocTec' => $this->firstString([$info['idDocTec'] ?? null, $servico['idDocTec'] ?? null]),
            'docRef' => $this->firstString([$info['docRef'] ?? null, $servico['docRef'] ?? null]),
            'xPed' => $this->firstString([$info['xPed'] ?? null, $servico['xPed'] ?? null]),
            'xInfComp' => $this->firstString([
                $info['xInfComp'] ?? null,
                $servico['xInfComp'] ?? null,
                $servico['informacoes_complementares'] ?? null,
            ]),
        ];

        $items = $info['gItemPed']['xItemPed'] ?? $servico['xItemPed'] ?? null;
        $hasInfo = $items !== null || array_filter($values, static fn ($value) => $value !== null) !== [];
        if (!$hasInfo) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $infoCompl = $dom->createElementNS($ns, 'infoCompl');
        foreach (['idDocTec', 'docRef', 'xPed'] as $tag) {
            if ($values[$tag] !== null) {
                $this->appendNodeDps($dom, $infoCompl, $tag, $values[$tag]);
            }
        }

        $itemList = is_array($items) ? $items : ($items !== null ? [$items] : []);
        if ($itemList !== []) {
            $gItemPed = $dom->createElementNS($ns, 'gItemPed');
            foreach ($itemList as $item) {
                if (is_scalar($item) && trim((string)$item) !== '') {
                    $this->appendNodeDps($dom, $gItemPed, 'xItemPed', trim((string)$item));
                }
            }
            if ($gItemPed->childNodes->length > 0) {
                $infoCompl->appendChild($gItemPed);
            }
        }

        if ($values['xInfComp'] !== null) {
            $this->appendNodeDps($dom, $infoCompl, 'xInfComp', $values['xInfComp']);
        }

        if ($infoCompl->childNodes->length > 0) {
            $serv->appendChild($infoCompl);
        }
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendDescontosDps(\DOMDocument $dom, \DOMElement $valores, array $dados): void
    {
        $valoresPayload = is_array($dados['valores'] ?? null) ? $dados['valores'] : [];
        $servico = (array)($dados['servico'] ?? []);
        $vDescIncond = $this->firstDecimal([
            $valoresPayload['vDescIncond'] ?? null,
            $valoresPayload['desconto_incondicionado'] ?? null,
            $servico['desconto_incondicionado'] ?? null,
            $servico['vDescIncond'] ?? null,
        ]);
        $vDescCond = $this->firstDecimal([
            $valoresPayload['vDescCond'] ?? null,
            $valoresPayload['desconto_condicionado'] ?? null,
            $servico['desconto_condicionado'] ?? null,
            $servico['vDescCond'] ?? null,
        ]);
        if ($vDescIncond === null && $vDescCond === null) {
            return;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'vDescCondIncond');
        $valores->appendChild($node);
        if ($vDescIncond !== null) {
            $this->appendNodeDps($dom, $node, 'vDescIncond', $this->formatDecimal($vDescIncond, 2));
        }
        if ($vDescCond !== null) {
            $this->appendNodeDps($dom, $node, 'vDescCond', $this->formatDecimal($vDescCond, 2));
        }
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendDeducaoReducaoDps(\DOMDocument $dom, \DOMElement $valores, array $dados): void
    {
        $valoresPayload = is_array($dados['valores'] ?? null) ? $dados['valores'] : [];
        $deducao = is_array($valoresPayload['deducao_reducao'] ?? null)
            ? $valoresPayload['deducao_reducao']
            : (is_array($valoresPayload['vDedRed'] ?? null) ? $valoresPayload['vDedRed'] : []);
        $servico = (array)($dados['servico'] ?? []);

        $pDr = $this->firstDecimal([
            $deducao['pDR'] ?? null,
            $deducao['percentual'] ?? null,
            $servico['pDR'] ?? null,
        ]);
        $vDr = $this->firstDecimal([
            $deducao['vDR'] ?? null,
            $deducao['valor'] ?? null,
            $servico['vDR'] ?? null,
            $servico['valor_deducoes'] ?? null,
        ]);
        if ($pDr === null && $vDr === null) {
            return;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'vDedRed');
        $valores->appendChild($node);
        if ($pDr !== null) {
            $this->appendNodeDps($dom, $node, 'pDR', $this->formatDecimal($pDr, 2));
            return;
        }

        $this->appendNodeDps($dom, $node, 'vDR', $this->formatDecimal((float)$vDr, 2));
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendTributacaoMunicipalDps(\DOMDocument $dom, \DOMElement $trib, array $dados): void
    {
        $ns = $this->getDpsNamespace();
        $servico = (array)($dados['servico'] ?? []);
        $tributacao = is_array($dados['tributacao'] ?? null) ? $dados['tributacao'] : [];
        $municipal = is_array($tributacao['municipal'] ?? null) ? $tributacao['municipal'] : [];
        $merged = $municipal + $servico;

        $tribMun = $dom->createElementNS($ns, 'tribMun');
        $trib->appendChild($tribMun);
        $tribIssqn = (string)($merged['tribISSQN'] ?? '1');
        $this->appendNodeDps($dom, $tribMun, 'tribISSQN', $tribIssqn);

        $cPaisResult = $this->firstString([$merged['cPaisResult'] ?? null, $merged['codigo_pais_resultado'] ?? null]);
        if ($cPaisResult !== null) {
            $this->appendNodeDps($dom, $tribMun, 'cPaisResult', $cPaisResult);
        }
        $tpImunidade = $this->firstString([$merged['tpImunidade'] ?? null, $merged['tipo_imunidade'] ?? null]);
        if ($tpImunidade !== null) {
            $this->appendNodeDps($dom, $tribMun, 'tpImunidade', $tpImunidade);
        }
        $this->appendExigSuspDps($dom, $tribMun, $merged);
        $this->appendBeneficioMunicipalGroup($dom, $tribMun, $merged);

        $retentionPayload = $municipal + $servico;
        $tpRetIssqn = $this->resolveDpsIssRetentionCode($retentionPayload);
        $this->appendNodeDps($dom, $tribMun, 'tpRetISSQN', $tpRetIssqn);

        $aliquota = $this->firstDecimal([
            $municipal['pAliq'] ?? null,
            $municipal['aliquota'] ?? null,
            $servico['pAliq'] ?? null,
            $servico['aliquota'] ?? null,
        ]);
        $aliquotaPercent = $aliquota !== null ? $this->normalizeDpsAliquotaPercent((float)$aliquota) : 0.0;
        $sendPAliq = $this->optionalBoolean([$this->config['dps_send_paliq'] ?? null]) ?? ($tribIssqn === '1');
        $servicoSendPAliq = $this->optionalBoolean([$servico['enviarPAliq'] ?? null]);
        if ($servicoSendPAliq !== null) {
            $sendPAliq = $servicoSendPAliq;
        }
        $municipalSendPAliq = $this->optionalBoolean([$municipal['enviarPAliq'] ?? null]);
        if ($municipalSendPAliq !== null) {
            $sendPAliq = $municipalSendPAliq;
        }
        $sendPAliq = $this->shouldSendDpsMunicipalAliquota($dados, $merged, $tribIssqn, $tpRetIssqn, $sendPAliq);
        if ($sendPAliq && $aliquotaPercent > 0) {
            $this->appendNodeDps($dom, $tribMun, 'pAliq', $this->formatDecimal($aliquotaPercent, 2));
        }
    }

    /**
     * @param array<string,mixed> $dados
     * @param array<string,mixed> $merged
     */
    private function shouldSendDpsMunicipalAliquota(array $dados, array $merged, string $tribIssqn, string $tpRetIssqn, bool $requested): bool
    {
        if (!$requested) {
            return false;
        }

        $prestador = is_array($dados['prestador'] ?? null) ? $dados['prestador'] : [];
        $opSimpNac = trim((string)($prestador['opSimpNac'] ?? '1'));
        $regApTribSn = trim((string)($prestador['regApTribSN'] ?? $prestador['reg_ap_trib_sn'] ?? $prestador['regime_apuracao_sn'] ?? ''));
        if ($regApTribSn === '' && $opSimpNac === '3') {
            $regApTribSn = '1';
        }

        if ($tribIssqn === '1' && $tpRetIssqn === '1' && $opSimpNac === '3' && $regApTribSn === '1') {
            return $this->hasMunicipalBenefitAllowingDpsAliquota($merged);
        }

        return true;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function hasMunicipalBenefitAllowingDpsAliquota(array $data): bool
    {
        $beneficio = is_array($data['BM'] ?? null) ? $data['BM'] : [];
        $tpBm = $this->firstString([
            $beneficio['tpBM'] ?? null,
            $data['tpBM'] ?? null,
            $data['tipo_beneficio_municipal'] ?? null,
            $data['benefit_type'] ?? null,
        ]);
        if ($tpBm === null) {
            return false;
        }

        $normalized = strtolower(trim($tpBm));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($ascii) && $ascii !== '') {
            $normalized = $ascii;
        }
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return in_array($normalized, ['1', '4', 'isencao', 'isencao_iss', 'aliquota', 'aliquota_diferenciada'], true);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function appendExigSuspDps(\DOMDocument $dom, \DOMElement $tribMun, array $data): void
    {
        $exigSusp = is_array($data['exigSusp'] ?? null) ? $data['exigSusp'] : [];
        $tpSusp = $this->firstString([$exigSusp['tpSusp'] ?? null, $data['tpSusp'] ?? null]);
        $nProcesso = $this->firstString([$exigSusp['nProcesso'] ?? null, $data['nProcesso'] ?? null]);
        if ($tpSusp === null || $nProcesso === null) {
            return;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'exigSusp');
        $tribMun->appendChild($node);
        $this->appendNodeDps($dom, $node, 'tpSusp', $tpSusp);
        $this->appendNodeDps($dom, $node, 'nProcesso', $nProcesso);
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendTributacaoFederalDps(\DOMDocument $dom, \DOMElement $trib, array $dados): void
    {
        $tributacao = is_array($dados['tributacao'] ?? null) ? $dados['tributacao'] : [];
        $federal = is_array($tributacao['federal'] ?? null) ? $tributacao['federal'] : [];
        $servico = (array)($dados['servico'] ?? []);

        $piscofins = $this->resolvePisCofinsPayload($dados, $federal, $servico);
        $vRetCp = $this->firstPositiveDecimal([
            $federal['vRetCP'] ?? null,
            $federal['valor_cp'] ?? null,
            $servico['vRetCP'] ?? null,
            $servico['valor_cp'] ?? null,
            $dados['vRetCP'] ?? null,
            $dados['valor_cp'] ?? null,
        ]);
        $vRetIrrf = $this->firstPositiveDecimal([
            $federal['vRetIRRF'] ?? null,
            $federal['valor_irrf'] ?? null,
            $federal['valor_ir'] ?? null,
            $servico['vRetIRRF'] ?? null,
            $servico['valor_irrf'] ?? null,
            $servico['valor_ir'] ?? null,
            $dados['vRetIRRF'] ?? null,
            $dados['valor_irrf'] ?? null,
            $dados['valor_ir'] ?? null,
        ]);
        $vRetCsll = $this->firstPositiveDecimal([
            $federal['vRetCSLL'] ?? null,
            $federal['valor_csll'] ?? null,
            $servico['vRetCSLL'] ?? null,
            $servico['valor_csll'] ?? null,
            $dados['vRetCSLL'] ?? null,
            $dados['valor_csll'] ?? null,
        ]);

        if ($piscofins === [] && $vRetCp === null && $vRetIrrf === null && $vRetCsll === null) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $tribFed = $dom->createElementNS($ns, 'tribFed');
        $trib->appendChild($tribFed);
        if ($piscofins !== []) {
            $pisCofinsNode = $dom->createElementNS($ns, 'piscofins');
            $tribFed->appendChild($pisCofinsNode);
            $this->appendNodeDps($dom, $pisCofinsNode, 'CST', (string)($piscofins['CST'] ?? '00'));
            foreach (['vBCPisCofins', 'pAliqPis', 'pAliqCofins', 'vPis', 'vCofins'] as $tag) {
                if (array_key_exists($tag, $piscofins) && is_numeric($piscofins[$tag])) {
                    $this->appendNodeDps($dom, $pisCofinsNode, $tag, $this->formatDecimal((float)$piscofins[$tag], 2));
                }
            }
            if (isset($piscofins['tpRetPisCofins']) && is_scalar($piscofins['tpRetPisCofins'])) {
                $this->appendNodeDps($dom, $pisCofinsNode, 'tpRetPisCofins', trim((string)$piscofins['tpRetPisCofins']));
            }
        }
        if ($vRetCp !== null) {
            $this->appendNodeDps($dom, $tribFed, 'vRetCP', $this->formatDecimal($vRetCp, 2));
        }
        if ($vRetIrrf !== null) {
            $this->appendNodeDps($dom, $tribFed, 'vRetIRRF', $this->formatDecimal($vRetIrrf, 2));
        }
        if ($vRetCsll !== null) {
            $this->appendNodeDps($dom, $tribFed, 'vRetCSLL', $this->formatDecimal($vRetCsll, 2));
        }
    }

    /**
     * @param array<string,mixed> $dados
     * @param array<string,mixed> $federal
     * @param array<string,mixed> $servico
     * @return array<string,mixed>
     */
    private function resolvePisCofinsPayload(array $dados, array $federal, array $servico): array
    {
        $piscofins = is_array($federal['piscofins'] ?? null) ? $federal['piscofins'] : [];
        $cst = $this->firstString([
            $piscofins['CST'] ?? null,
            $piscofins['cst'] ?? null,
            $federal['CST'] ?? null,
            $federal['cst_pis_cofins'] ?? null,
            $servico['CST'] ?? null,
            $servico['cst_pis_cofins'] ?? null,
            $dados['cst_pis_cofins'] ?? null,
        ]);
        $resolved = [];
        if ($cst !== null) {
            $resolved['CST'] = str_pad(substr($this->onlyDigits($cst), 0, 2), 2, '0', STR_PAD_LEFT);
        }

        $fieldMap = [
            'vBCPisCofins' => [$piscofins['vBCPisCofins'] ?? null, $piscofins['vBC'] ?? null, $federal['vBCPisCofins'] ?? null, $servico['vBCPisCofins'] ?? null],
            'pAliqPis' => [$piscofins['pAliqPis'] ?? null, $federal['pAliqPis'] ?? null, $servico['pAliqPis'] ?? null],
            'pAliqCofins' => [$piscofins['pAliqCofins'] ?? null, $federal['pAliqCofins'] ?? null, $servico['pAliqCofins'] ?? null],
            'vPis' => [$piscofins['vPis'] ?? null, $federal['vPis'] ?? null, $servico['vPis'] ?? null, $servico['valor_pis'] ?? null, $dados['valor_pis'] ?? null],
            'vCofins' => [$piscofins['vCofins'] ?? null, $federal['vCofins'] ?? null, $servico['vCofins'] ?? null, $servico['valor_cofins'] ?? null, $dados['valor_cofins'] ?? null],
        ];
        foreach ($fieldMap as $tag => $values) {
            $value = $this->firstDecimal($values);
            if ($value !== null) {
                $resolved[$tag] = $value;
            }
        }

        $tpRet = $this->firstString([
            $piscofins['tpRetPisCofins'] ?? null,
            $federal['tpRetPisCofins'] ?? null,
            $servico['tpRetPisCofins'] ?? null,
        ]);
        if ($tpRet !== null) {
            $resolved['tpRetPisCofins'] = $tpRet;
        }
        if ($resolved !== [] && !isset($resolved['CST'])) {
            $resolved['CST'] = '00';
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendTotalTributosDps(\DOMDocument $dom, \DOMElement $trib, array $dados): void
    {
        $tributacao = is_array($dados['tributacao'] ?? null) ? $dados['tributacao'] : [];
        $total = is_array($tributacao['total'] ?? null)
            ? $tributacao['total']
            : (is_array($dados['totTrib'] ?? null) ? $dados['totTrib'] : []);

        $totTrib = $dom->createElementNS($this->getDpsNamespace(), 'totTrib');
        $trib->appendChild($totTrib);

        if (isset($total['indTotTrib'])) {
            $this->appendNodeDps($dom, $totTrib, 'indTotTrib', (string)$total['indTotTrib']);
            return;
        }
        if (isset($total['pTotTribSN']) && is_numeric($total['pTotTribSN'])) {
            $this->appendNodeDps($dom, $totTrib, 'pTotTribSN', $this->formatDecimal((float)$total['pTotTribSN'], 2));
            return;
        }
        if (isset($total['pTotTrib']) && is_array($total['pTotTrib'])) {
            $node = $dom->createElementNS($this->getDpsNamespace(), 'pTotTrib');
            $totTrib->appendChild($node);
            $this->appendNodeDps($dom, $node, 'pTotTribFed', $this->formatDecimal((float)($total['pTotTrib']['pTotTribFed'] ?? 0), 2));
            $this->appendNodeDps($dom, $node, 'pTotTribEst', $this->formatDecimal((float)($total['pTotTrib']['pTotTribEst'] ?? 0), 2));
            $this->appendNodeDps($dom, $node, 'pTotTribMun', $this->formatDecimal((float)($total['pTotTrib']['pTotTribMun'] ?? 0), 2));
            return;
        }

        $values = is_array($total['vTotTrib'] ?? null) ? $total['vTotTrib'] : $total;
        $node = $dom->createElementNS($this->getDpsNamespace(), 'vTotTrib');
        $totTrib->appendChild($node);
        $this->appendNodeDps($dom, $node, 'vTotTribFed', $this->formatDecimal((float)($values['vTotTribFed'] ?? 0), 2));
        $this->appendNodeDps($dom, $node, 'vTotTribEst', $this->formatDecimal((float)($values['vTotTribEst'] ?? 0), 2));
        $this->appendNodeDps($dom, $node, 'vTotTribMun', $this->formatDecimal((float)($values['vTotTribMun'] ?? 0), 2));
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function appendIbscbsDps(\DOMDocument $dom, \DOMElement $inf, array $dados): void
    {
        $ibscbs = is_array($dados['ibscbs'] ?? null)
            ? $dados['ibscbs']
            : (is_array($dados['IBSCBS'] ?? null) ? $dados['IBSCBS'] : []);
        if ($ibscbs === []) {
            return;
        }

        $gIbscbs = $this->resolveIbscbsTributosSitClas($ibscbs);
        $finNfse = $this->firstString([$ibscbs['finNFSe'] ?? null, $ibscbs['finalidade'] ?? null]);
        $cIndOpRaw = $this->firstString([$ibscbs['cIndOp'] ?? null, $ibscbs['codigo_indicador_operacao'] ?? null]);
        $cIndOpDigits = $cIndOpRaw !== null ? $this->onlyDigits($cIndOpRaw) : '';
        $cIndOp = $cIndOpDigits !== ''
            ? str_pad(substr($cIndOpDigits, 0, 6), 6, '0', STR_PAD_LEFT)
            : null;
        $indDest = $this->firstString([$ibscbs['indDest'] ?? null, $ibscbs['indicador_destinatario'] ?? null]);
        if ($gIbscbs === [] || $finNfse === null || $cIndOp === null || $indDest === null) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $node = $dom->createElementNS($ns, 'IBSCBS');
        $inf->appendChild($node);
        $this->appendNodeDps($dom, $node, 'finNFSe', $finNfse);
        $indFinal = $this->firstString([$ibscbs['indFinal'] ?? null, $ibscbs['indicador_final'] ?? null]);
        if ($indFinal !== null) {
            $this->appendNodeDps($dom, $node, 'indFinal', $indFinal);
        }
        $this->appendNodeDps($dom, $node, 'cIndOp', $cIndOp);
        $tpOper = $this->firstString([$ibscbs['tpOper'] ?? null, $ibscbs['tipo_operacao'] ?? null]);
        if ($tpOper !== null) {
            $this->appendNodeDps($dom, $node, 'tpOper', $tpOper);
        }
        $this->appendIbscbsRefNfseDps($dom, $node, $ibscbs);
        $tpEnteGov = $this->firstString([$ibscbs['tpEnteGov'] ?? null, $ibscbs['tipo_ente_governamental'] ?? null]);
        if ($tpEnteGov !== null) {
            $this->appendNodeDps($dom, $node, 'tpEnteGov', $tpEnteGov);
        }
        $this->appendNodeDps($dom, $node, 'indDest', $indDest);
        $this->appendIbscbsDestDps($dom, $node, $ibscbs);
        $this->appendIbscbsImovelDps($dom, $node, $ibscbs);

        $valores = $dom->createElementNS($ns, 'valores');
        $node->appendChild($valores);
        $this->appendIbscbsReeRepResDps($dom, $valores, $ibscbs);
        $trib = $dom->createElementNS($ns, 'trib');
        $valores->appendChild($trib);
        $this->appendIbscbsTributosSitClasDps($dom, $trib, $gIbscbs);
    }

    /**
     * @param array<string,mixed> $gIbscbs
     */
    private function appendIbscbsTributosSitClasDps(\DOMDocument $dom, \DOMElement $trib, array $gIbscbs): void
    {
        $ns = $this->getDpsNamespace();
        $gNode = $dom->createElementNS($ns, 'gIBSCBS');
        $trib->appendChild($gNode);
        $this->appendNodeDps($dom, $gNode, 'CST', (string)$gIbscbs['CST']);
        $this->appendNodeDps($dom, $gNode, 'cClassTrib', (string)$gIbscbs['cClassTrib']);
        if (isset($gIbscbs['cCredPres'])) {
            $this->appendNodeDps($dom, $gNode, 'cCredPres', (string)$gIbscbs['cCredPres']);
        }
        if (isset($gIbscbs['gTribRegular']) && is_array($gIbscbs['gTribRegular'])) {
            $regular = $gIbscbs['gTribRegular'];
            if (isset($regular['CSTReg'], $regular['cClassTribReg'])) {
                $regularNode = $dom->createElementNS($ns, 'gTribRegular');
                $gNode->appendChild($regularNode);
                $this->appendNodeDps($dom, $regularNode, 'CSTReg', (string)$regular['CSTReg']);
                $this->appendNodeDps($dom, $regularNode, 'cClassTribReg', (string)$regular['cClassTribReg']);
            }
        }
        if (isset($gIbscbs['gDif']) && is_array($gIbscbs['gDif'])) {
            $dif = $gIbscbs['gDif'];
            if (isset($dif['pDifUF'], $dif['pDifMun'], $dif['pDifCBS'])) {
                $difNode = $dom->createElementNS($ns, 'gDif');
                $gNode->appendChild($difNode);
                $this->appendNodeDps($dom, $difNode, 'pDifUF', $this->formatDecimal((float)$dif['pDifUF'], 2));
                $this->appendNodeDps($dom, $difNode, 'pDifMun', $this->formatDecimal((float)$dif['pDifMun'], 2));
                $this->appendNodeDps($dom, $difNode, 'pDifCBS', $this->formatDecimal((float)$dif['pDifCBS'], 2));
            }
        }
    }

    /**
     * @param array<string,mixed> $ibscbs
     * @return array<string,mixed>
     */
    private function resolveIbscbsTributosSitClas(array $ibscbs): array
    {
        $valores = is_array($ibscbs['valores'] ?? null) ? $ibscbs['valores'] : [];
        $trib = is_array($valores['trib'] ?? null) ? $valores['trib'] : [];
        $gIbscbs = is_array($trib['gIBSCBS'] ?? null)
            ? $trib['gIBSCBS']
            : (is_array($ibscbs['gIBSCBS'] ?? null) ? $ibscbs['gIBSCBS'] : []);

        $cst = $this->firstString([$gIbscbs['CST'] ?? null, $gIbscbs['cst'] ?? null]);
        $cClassTrib = $this->firstString([
            $gIbscbs['cClassTrib'] ?? null,
            $gIbscbs['cClass'] ?? null,
            $gIbscbs['classificacao'] ?? null,
            $gIbscbs['codigo_classificacao'] ?? null,
        ]);
        if ($cst === null || $cClassTrib === null) {
            return [];
        }

        $gIbscbs['CST'] = str_pad(substr($this->onlyDigits($cst), 0, 3), 3, '0', STR_PAD_LEFT);
        $gIbscbs['cClassTrib'] = str_pad(substr($this->onlyDigits($cClassTrib), 0, 6), 6, '0', STR_PAD_LEFT);
        $cCredPres = $this->firstString([$gIbscbs['cCredPres'] ?? null, $gIbscbs['codigo_credito_presumido'] ?? null]);
        if ($cCredPres !== null && $this->onlyDigits($cCredPres) !== '') {
            $gIbscbs['cCredPres'] = str_pad(substr($this->onlyDigits($cCredPres), 0, 2), 2, '0', STR_PAD_LEFT);
        }

        if (isset($gIbscbs['gTribRegular']) && is_array($gIbscbs['gTribRegular'])) {
            $regular = $gIbscbs['gTribRegular'];
            $cstReg = $this->firstString([$regular['CSTReg'] ?? null, $regular['cstReg'] ?? null, $regular['CST'] ?? null]);
            $cClassReg = $this->firstString([
                $regular['cClassTribReg'] ?? null,
                $regular['cClassReg'] ?? null,
                $regular['cClassTrib'] ?? null,
                $regular['classificacao'] ?? null,
            ]);
            if ($cstReg !== null && $cClassReg !== null) {
                $gIbscbs['gTribRegular']['CSTReg'] = str_pad(substr($this->onlyDigits($cstReg), 0, 3), 3, '0', STR_PAD_LEFT);
                $gIbscbs['gTribRegular']['cClassTribReg'] = str_pad(substr($this->onlyDigits($cClassReg), 0, 6), 6, '0', STR_PAD_LEFT);
            }
        }

        return $gIbscbs;
    }

    /**
     * @param array<string,mixed> $ibscbs
     */
    private function appendIbscbsRefNfseDps(\DOMDocument $dom, \DOMElement $ibscbsNode, array $ibscbs): void
    {
        $refs = $ibscbs['gRefNFSe']['refNFSe'] ?? $ibscbs['refNFSe'] ?? null;
        if ($refs === null) {
            return;
        }

        $refList = is_array($refs) ? $refs : [$refs];
        $node = $dom->createElementNS($this->getDpsNamespace(), 'gRefNFSe');
        foreach ($refList as $ref) {
            if (is_scalar($ref) && trim((string)$ref) !== '') {
                $this->appendNodeDps($dom, $node, 'refNFSe', trim((string)$ref));
            }
        }
        if ($node->childNodes->length > 0) {
            $ibscbsNode->appendChild($node);
        }
    }

    /**
     * @param array<string,mixed> $ibscbs
     */
    private function appendIbscbsDestDps(\DOMDocument $dom, \DOMElement $ibscbsNode, array $ibscbs): void
    {
        $dest = is_array($ibscbs['dest'] ?? null) ? $ibscbs['dest'] : [];
        $nome = trim((string)($dest['xNome'] ?? $dest['razaoSocial'] ?? $dest['razao_social'] ?? $dest['nome'] ?? ''));
        if ($dest === [] || $nome === '') {
            return;
        }

        $ns = $this->getDpsNamespace();
        $node = $dom->createElementNS($ns, 'dest');
        if (!$this->appendIbscbsIdentityChoiceDps($dom, $node, $dest, true)) {
            return;
        }
        $this->appendNodeDps($dom, $node, 'xNome', $nome);
        $endereco = $this->firstArray([$dest['end'] ?? null, $dest['endereco'] ?? null, $dest['address'] ?? null]);
        if ($endereco !== []) {
            $this->appendEnderecoDps($dom, $node, $endereco);
        }
        $fone = $this->firstString([$dest['fone'] ?? null, $dest['telefone'] ?? null, $dest['phone'] ?? null]);
        $foneDigits = $fone !== null ? $this->onlyDigits($fone) : '';
        if ($foneDigits !== '') {
            $this->appendNodeDps($dom, $node, 'fone', $foneDigits);
        }
        $email = $this->firstString([$dest['email'] ?? null]);
        if ($email !== null) {
            $this->appendNodeDps($dom, $node, 'email', $email);
        }

        $ibscbsNode->appendChild($node);
    }

    /**
     * @param array<string,mixed> $ibscbs
     */
    private function appendIbscbsImovelDps(\DOMDocument $dom, \DOMElement $ibscbsNode, array $ibscbs): void
    {
        $imovel = $this->firstArray([$ibscbs['imovel'] ?? null, $ibscbs['bem_imovel'] ?? null]);
        if ($imovel === []) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $node = $dom->createElementNS($ns, 'imovel');
        $inscImobFisc = $this->firstString([$imovel['inscImobFisc'] ?? null, $imovel['inscricao_imobiliaria'] ?? null]);
        if ($inscImobFisc !== null) {
            $this->appendNodeDps($dom, $node, 'inscImobFisc', $inscImobFisc);
        }

        $cCib = $this->firstString([$imovel['cCIB'] ?? null, $imovel['cib'] ?? null]);
        if ($cCib !== null) {
            $this->appendNodeDps($dom, $node, 'cCIB', $cCib);
        } else {
            $endereco = $this->firstArray([$imovel['end'] ?? null, $imovel['endereco'] ?? null, $imovel['address'] ?? null]);
            if ($endereco === [] || !$this->appendEnderecoObraEventoDps($dom, $node, $endereco)) {
                return;
            }
        }

        $ibscbsNode->appendChild($node);
    }

    /**
     * @param array<string,mixed> $ibscbs
     */
    private function appendIbscbsReeRepResDps(\DOMDocument $dom, \DOMElement $valores, array $ibscbs): void
    {
        $valoresPayload = is_array($ibscbs['valores'] ?? null) ? $ibscbs['valores'] : [];
        $reeRepRes = $this->firstArray([
            $valoresPayload['gReeRepRes'] ?? null,
            $valoresPayload['reeRepRes'] ?? null,
            $valoresPayload['reembolso_repasse_ressarcimento'] ?? null,
            $ibscbs['gReeRepRes'] ?? null,
            $ibscbs['reeRepRes'] ?? null,
            $ibscbs['reembolso_repasse_ressarcimento'] ?? null,
        ]);
        if ($reeRepRes === []) {
            return;
        }

        $documentosPayload = $reeRepRes['documentos']
            ?? $reeRepRes['documentos_referenciados']
            ?? $reeRepRes['docs']
            ?? null;
        if ($documentosPayload === null) {
            $documentosPayload = $reeRepRes;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'gReeRepRes');
        foreach ($this->normalizeArrayList($documentosPayload) as $documentoPayload) {
            if (!is_array($documentoPayload)) {
                continue;
            }
            $documento = $this->buildIbscbsReeRepResDocumentoDps($dom, $documentoPayload);
            if ($documento !== null) {
                $node->appendChild($documento);
            }
        }

        if ($node->childNodes->length > 0) {
            $valores->appendChild($node);
        }
    }

    /**
     * @param array<string,mixed> $documentoPayload
     */
    private function buildIbscbsReeRepResDocumentoDps(\DOMDocument $dom, array $documentoPayload): ?\DOMElement
    {
        $choice = $this->buildIbscbsDocumentoChoiceDps($dom, $documentoPayload);
        if ($choice === null) {
            return null;
        }

        $dtEmiDoc = $this->firstString([
            $documentoPayload['dtEmiDoc'] ?? null,
            $documentoPayload['data_emissao'] ?? null,
        ]);
        $dtCompDoc = $this->firstString([
            $documentoPayload['dtCompDoc'] ?? null,
            $documentoPayload['data_competencia'] ?? null,
        ]);
        $tpReeRepRes = $this->firstString([
            $documentoPayload['tpReeRepRes'] ?? null,
            $documentoPayload['tipo'] ?? null,
            $documentoPayload['tipo_reembolso_repasse_ressarcimento'] ?? null,
        ]);
        $vlrReeRepRes = $this->firstDecimal([
            $documentoPayload['vlrReeRepRes'] ?? null,
            $documentoPayload['valor'] ?? null,
            $documentoPayload['valor_reembolso_repasse_ressarcimento'] ?? null,
        ]);
        if ($dtEmiDoc === null || $dtCompDoc === null || $tpReeRepRes === null || $vlrReeRepRes === null) {
            return null;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'documentos');
        $node->appendChild($choice);

        $fornecedor = $this->firstArray([$documentoPayload['fornec'] ?? null, $documentoPayload['fornecedor'] ?? null]);
        if ($fornecedor !== []) {
            $this->appendIbscbsFornecedorDps($dom, $node, $fornecedor);
        }

        $this->appendNodeDps($dom, $node, 'dtEmiDoc', $this->normalizeDpsDate($dtEmiDoc));
        $this->appendNodeDps($dom, $node, 'dtCompDoc', $this->normalizeDpsDate($dtCompDoc));
        $this->appendNodeDps($dom, $node, 'tpReeRepRes', $tpReeRepRes);
        $xTpReeRepRes = $this->firstString([
            $documentoPayload['xTpReeRepRes'] ?? null,
            $documentoPayload['descricao_tipo'] ?? null,
        ]);
        if ($xTpReeRepRes !== null) {
            $this->appendNodeDps($dom, $node, 'xTpReeRepRes', $xTpReeRepRes);
        }
        $this->appendNodeDps($dom, $node, 'vlrReeRepRes', $this->formatDecimal($vlrReeRepRes, 2));

        return $node;
    }

    /**
     * @param array<string,mixed> $documentoPayload
     */
    private function buildIbscbsDocumentoChoiceDps(\DOMDocument $dom, array $documentoPayload): ?\DOMElement
    {
        $dfe = $this->firstArray([
            $documentoPayload['dFeNacional'] ?? null,
            $documentoPayload['dfeNacional'] ?? null,
            $documentoPayload['dfe_nacional'] ?? null,
        ]);
        if ($dfe === [] && ($documentoPayload['tipoChaveDFe'] ?? $documentoPayload['chaveDFe'] ?? null) !== null) {
            $dfe = $documentoPayload;
        }
        if ($dfe !== []) {
            $tipoChave = $this->firstString([$dfe['tipoChaveDFe'] ?? null, $dfe['tipo'] ?? null]);
            $chave = $this->firstString([$dfe['chaveDFe'] ?? null, $dfe['chave'] ?? null]);
            if ($tipoChave !== null && $chave !== null) {
                $node = $dom->createElementNS($this->getDpsNamespace(), 'dFeNacional');
                $this->appendNodeDps($dom, $node, 'tipoChaveDFe', $tipoChave);
                $xTipo = $this->firstString([$dfe['xTipoChaveDFe'] ?? null, $dfe['descricao_tipo'] ?? null]);
                if ($xTipo !== null) {
                    $this->appendNodeDps($dom, $node, 'xTipoChaveDFe', $xTipo);
                }
                $this->appendNodeDps($dom, $node, 'chaveDFe', $chave);
                return $node;
            }
        }

        $docFiscalOutro = $this->firstArray([
            $documentoPayload['docFiscalOutro'] ?? null,
            $documentoPayload['doc_fiscal_outro'] ?? null,
        ]);
        if ($docFiscalOutro === [] && ($documentoPayload['cMunDocFiscal'] ?? $documentoPayload['nDocFiscal'] ?? null) !== null) {
            $docFiscalOutro = $documentoPayload;
        }
        if ($docFiscalOutro !== []) {
            $cMun = $this->firstString([$docFiscalOutro['cMunDocFiscal'] ?? null, $docFiscalOutro['codigo_municipio'] ?? null]);
            $nDoc = $this->firstString([$docFiscalOutro['nDocFiscal'] ?? null, $docFiscalOutro['numero'] ?? null]);
            $xDoc = $this->firstString([$docFiscalOutro['xDocFiscal'] ?? null, $docFiscalOutro['descricao'] ?? null]);
            if ($cMun !== null && $nDoc !== null && $xDoc !== null) {
                $node = $dom->createElementNS($this->getDpsNamespace(), 'docFiscalOutro');
                $this->appendNodeDps($dom, $node, 'cMunDocFiscal', str_pad(substr($this->onlyDigits($cMun), 0, 7), 7, '0', STR_PAD_LEFT));
                $this->appendNodeDps($dom, $node, 'nDocFiscal', $nDoc);
                $this->appendNodeDps($dom, $node, 'xDocFiscal', $xDoc);
                return $node;
            }
        }

        $docOutro = $this->firstArray([
            $documentoPayload['docOutro'] ?? null,
            $documentoPayload['doc_outro'] ?? null,
        ]);
        if ($docOutro === [] && ($documentoPayload['nDoc'] ?? $documentoPayload['xDoc'] ?? null) !== null) {
            $docOutro = $documentoPayload;
        }
        if ($docOutro !== []) {
            $nDoc = $this->firstString([$docOutro['nDoc'] ?? null, $docOutro['numero'] ?? null]);
            $xDoc = $this->firstString([$docOutro['xDoc'] ?? null, $docOutro['descricao'] ?? null]);
            if ($nDoc !== null && $xDoc !== null) {
                $node = $dom->createElementNS($this->getDpsNamespace(), 'docOutro');
                $this->appendNodeDps($dom, $node, 'nDoc', $nDoc);
                $this->appendNodeDps($dom, $node, 'xDoc', $xDoc);
                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $fornecedor
     */
    private function appendIbscbsFornecedorDps(\DOMDocument $dom, \DOMElement $documentos, array $fornecedor): void
    {
        $nome = $this->firstString([
            $fornecedor['xNome'] ?? null,
            $fornecedor['razaoSocial'] ?? null,
            $fornecedor['razao_social'] ?? null,
            $fornecedor['nome'] ?? null,
        ]);
        if ($nome === null) {
            return;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'fornec');
        if (!$this->appendIbscbsIdentityChoiceDps($dom, $node, $fornecedor, true)) {
            return;
        }

        $this->appendNodeDps($dom, $node, 'xNome', $nome);
        $documentos->appendChild($node);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function appendIbscbsIdentityChoiceDps(\DOMDocument $dom, \DOMElement $parent, array $data, bool $padCpf): bool
    {
        $nif = $this->firstString([$data['NIF'] ?? null, $data['nif'] ?? null]);
        if ($nif !== null) {
            $this->appendNodeDps($dom, $parent, 'NIF', $nif);
            return true;
        }

        $cNaoNif = $this->firstString([
            $data['cNaoNIF'] ?? null,
            $data['codigo_nao_nif'] ?? null,
            $data['motivo_nao_nif'] ?? null,
        ]);
        if ($cNaoNif !== null) {
            $this->appendNodeDps($dom, $parent, 'cNaoNIF', $cNaoNif);
            return true;
        }

        $documento = $this->onlyDigits((string)($this->firstString([
            $data['CNPJ'] ?? null,
            $data['cnpj'] ?? null,
            $data['CPF'] ?? null,
            $data['cpf'] ?? null,
            $data['documento'] ?? null,
        ]) ?? ''));
        if (strlen($documento) === 14) {
            $this->appendNodeDps($dom, $parent, 'CNPJ', $documento);
            return true;
        }
        if ($documento !== '') {
            $cpf = $padCpf ? str_pad(substr($documento, 0, 11), 11, '0', STR_PAD_LEFT) : $documento;
            $this->appendNodeDps($dom, $parent, 'CPF', $cpf);
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $endereco
     */
    private function appendEnderecoDps(\DOMDocument $dom, \DOMElement $parent, array $endereco): bool
    {
        $logradouro = $this->firstString([$endereco['xLgr'] ?? null, $endereco['logradouro'] ?? null, $endereco['street'] ?? null]);
        $numero = $this->firstString([$endereco['nro'] ?? null, $endereco['numero'] ?? null, $endereco['number'] ?? null]);
        $bairro = $this->firstString([$endereco['xBairro'] ?? null, $endereco['bairro'] ?? null, $endereco['district'] ?? null]);
        if ($logradouro === null || $numero === null || $bairro === null) {
            return false;
        }

        $end = $dom->createElementNS($this->getDpsNamespace(), 'end');
        if (!$this->appendEnderecoChoiceDps($dom, $end, $endereco)) {
            return false;
        }

        $this->appendNodeDps($dom, $end, 'xLgr', $logradouro);
        $this->appendNodeDps($dom, $end, 'nro', $numero);
        $complemento = $this->firstString([$endereco['xCpl'] ?? null, $endereco['complemento'] ?? null, $endereco['complement'] ?? null]);
        if ($complemento !== null) {
            $this->appendNodeDps($dom, $end, 'xCpl', $complemento);
        }
        $this->appendNodeDps($dom, $end, 'xBairro', $bairro);
        $parent->appendChild($end);

        return true;
    }

    /**
     * @param array<string,mixed> $endereco
     */
    private function appendEnderecoChoiceDps(\DOMDocument $dom, \DOMElement $end, array $endereco): bool
    {
        $endExt = $this->firstArray([$endereco['endExt'] ?? null, $endereco['end_ext'] ?? null, $endereco['exterior'] ?? null]);
        $cPais = $this->firstString([$endExt['cPais'] ?? null, $endereco['cPais'] ?? null, $endereco['codigo_pais'] ?? null]);
        $cEndPost = $this->firstString([$endExt['cEndPost'] ?? null, $endereco['cEndPost'] ?? null, $endereco['codigo_postal'] ?? null]);
        $xCidade = $this->firstString([$endExt['xCidade'] ?? null, $endereco['xCidade'] ?? null, $endereco['cidade'] ?? null]);
        $xEstProvReg = $this->firstString([$endExt['xEstProvReg'] ?? null, $endereco['xEstProvReg'] ?? null, $endereco['estado_provincia'] ?? null]);
        if ($cPais !== null && $cEndPost !== null && $xCidade !== null && $xEstProvReg !== null) {
            $node = $dom->createElementNS($this->getDpsNamespace(), 'endExt');
            $this->appendNodeDps($dom, $node, 'cPais', $cPais);
            $this->appendNodeDps($dom, $node, 'cEndPost', $cEndPost);
            $this->appendNodeDps($dom, $node, 'xCidade', $xCidade);
            $this->appendNodeDps($dom, $node, 'xEstProvReg', $xEstProvReg);
            $end->appendChild($node);
            return true;
        }

        $endNac = $this->firstArray([$endereco['endNac'] ?? null, $endereco['end_nac'] ?? null, $endereco['nacional'] ?? null]);
        $cMun = $this->onlyDigits((string)($this->firstString([
            $endNac['cMun'] ?? null,
            $endereco['cMun'] ?? null,
            $endereco['codigo_municipio'] ?? null,
            $endereco['codigoMunicipio'] ?? null,
        ]) ?? ''));
        $cep = $this->onlyDigits((string)($this->firstString([
            $endNac['CEP'] ?? null,
            $endereco['CEP'] ?? null,
            $endereco['cep'] ?? null,
            $endereco['postal_code'] ?? null,
        ]) ?? ''));
        if (strlen($cMun) !== 7 || strlen($cep) !== 8) {
            return false;
        }

        $node = $dom->createElementNS($this->getDpsNamespace(), 'endNac');
        $this->appendNodeDps($dom, $node, 'cMun', $cMun);
        $this->appendNodeDps($dom, $node, 'CEP', $cep);
        $end->appendChild($node);

        return true;
    }

    /**
     * @param array<string,mixed> $endereco
     */
    private function appendEnderecoObraEventoDps(\DOMDocument $dom, \DOMElement $parent, array $endereco): bool
    {
        $logradouro = $this->firstString([$endereco['xLgr'] ?? null, $endereco['logradouro'] ?? null, $endereco['street'] ?? null]);
        $numero = $this->firstString([$endereco['nro'] ?? null, $endereco['numero'] ?? null, $endereco['number'] ?? null]);
        $bairro = $this->firstString([$endereco['xBairro'] ?? null, $endereco['bairro'] ?? null, $endereco['district'] ?? null]);
        if ($logradouro === null || $numero === null || $bairro === null) {
            return false;
        }

        $end = $dom->createElementNS($this->getDpsNamespace(), 'end');
        if (!$this->appendEnderecoObraEventoChoiceDps($dom, $end, $endereco)) {
            return false;
        }

        $this->appendNodeDps($dom, $end, 'xLgr', $logradouro);
        $this->appendNodeDps($dom, $end, 'nro', $numero);
        $complemento = $this->firstString([$endereco['xCpl'] ?? null, $endereco['complemento'] ?? null, $endereco['complement'] ?? null]);
        if ($complemento !== null) {
            $this->appendNodeDps($dom, $end, 'xCpl', $complemento);
        }
        $this->appendNodeDps($dom, $end, 'xBairro', $bairro);
        $parent->appendChild($end);

        return true;
    }

    /**
     * @param array<string,mixed> $endereco
     */
    private function appendEnderecoObraEventoChoiceDps(\DOMDocument $dom, \DOMElement $end, array $endereco): bool
    {
        $endExt = $this->firstArray([$endereco['endExt'] ?? null, $endereco['end_ext'] ?? null, $endereco['exterior'] ?? null]);
        $cEndPost = $this->firstString([$endExt['cEndPost'] ?? null, $endereco['cEndPost'] ?? null, $endereco['codigo_postal'] ?? null]);
        $xCidade = $this->firstString([$endExt['xCidade'] ?? null, $endereco['xCidade'] ?? null, $endereco['cidade'] ?? null]);
        $xEstProvReg = $this->firstString([$endExt['xEstProvReg'] ?? null, $endereco['xEstProvReg'] ?? null, $endereco['estado_provincia'] ?? null]);
        if ($cEndPost !== null && $xCidade !== null && $xEstProvReg !== null) {
            $node = $dom->createElementNS($this->getDpsNamespace(), 'endExt');
            $this->appendNodeDps($dom, $node, 'cEndPost', $cEndPost);
            $this->appendNodeDps($dom, $node, 'xCidade', $xCidade);
            $this->appendNodeDps($dom, $node, 'xEstProvReg', $xEstProvReg);
            $end->appendChild($node);
            return true;
        }

        $cep = $this->onlyDigits((string)($this->firstString([
            $endereco['CEP'] ?? null,
            $endereco['cep'] ?? null,
            $endereco['postal_code'] ?? null,
        ]) ?? ''));
        if (strlen($cep) !== 8) {
            return false;
        }

        $this->appendNodeDps($dom, $end, 'CEP', $cep);
        return true;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstArray(array $values): array
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function normalizeArrayList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    /**
     * @param list<mixed> $values
     */
    private function firstDecimal(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            return round((float)$value, 2);
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstPositiveDecimal(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            $numeric = round((float) $value, 2);
            if ($numeric > 0) {
                return $numeric;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $tomador
     */
    private function appendTomadorEnderecoDps(\DOMDocument $dom, \DOMElement $toma, array $tomador): void
    {
        $resolved = $this->resolveTomadorEnderecoDps($tomador);
        if (!$this->tomadorEnderecoDpsCompleto($resolved)) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $end = $dom->createElementNS($ns, 'end');
        $toma->appendChild($end);

        $endNac = $dom->createElementNS($ns, 'endNac');
        $end->appendChild($endNac);
        $this->appendNodeDps(
            $dom,
            $endNac,
            'cMun',
            $resolved['codigo_municipio']
        );
        $this->appendNodeDps(
            $dom,
            $endNac,
            'CEP',
            $resolved['cep']
        );

        $this->appendNodeDps($dom, $end, 'xLgr', $resolved['logradouro']);
        $this->appendNodeDps($dom, $end, 'nro', $resolved['numero']);
        if ($resolved['complemento'] !== '') {
            $this->appendNodeDps($dom, $end, 'xCpl', $resolved['complemento']);
        }
        $this->appendNodeDps($dom, $end, 'xBairro', $resolved['bairro']);
    }

    /**
     * @param array<string,mixed> $tomador
     * @return array{codigo_municipio:string,cep:string,logradouro:string,numero:string,complemento:string,bairro:string}
     */
    private function resolveTomadorEnderecoDps(array $tomador): array
    {
        $endereco = is_array($tomador['endereco'] ?? null)
            ? $tomador['endereco']
            : (is_array($tomador['address'] ?? null) ? $tomador['address'] : []);

        return [
            'codigo_municipio' => $this->onlyDigits((string) ($this->firstString([
                $tomador['codigoMunicipio'] ?? null,
                $tomador['codigo_municipio'] ?? null,
                $tomador['codigo_ibge'] ?? null,
                $tomador['municipality_ibge'] ?? null,
                $tomador['cMun'] ?? null,
                $endereco['codigoMunicipio'] ?? null,
                $endereco['codigo_municipio'] ?? null,
                $endereco['codigo_ibge'] ?? null,
                $endereco['municipality_ibge'] ?? null,
                $endereco['cMun'] ?? null,
            ]) ?? '')),
            'cep' => $this->onlyDigits((string) ($this->firstString([
                $tomador['cep'] ?? null,
                $tomador['CEP'] ?? null,
                $tomador['postal_code'] ?? null,
                $tomador['zip'] ?? null,
                $endereco['cep'] ?? null,
                $endereco['CEP'] ?? null,
                $endereco['postal_code'] ?? null,
                $endereco['zip'] ?? null,
            ]) ?? '')),
            'logradouro' => trim((string) ($this->firstString([
                $tomador['logradouro'] ?? null,
                $tomador['xLgr'] ?? null,
                $tomador['street'] ?? null,
                $endereco['logradouro'] ?? null,
                $endereco['xLgr'] ?? null,
                $endereco['street'] ?? null,
            ]) ?? '')),
            'numero' => trim((string) ($this->firstString([
                $tomador['numero'] ?? null,
                $tomador['nro'] ?? null,
                $tomador['number'] ?? null,
                $endereco['numero'] ?? null,
                $endereco['nro'] ?? null,
                $endereco['number'] ?? null,
                'S/N',
            ]) ?? 'S/N')),
            'complemento' => trim((string) ($this->firstString([
                $tomador['complemento'] ?? null,
                $tomador['xCpl'] ?? null,
                $tomador['complement'] ?? null,
                $endereco['complemento'] ?? null,
                $endereco['xCpl'] ?? null,
                $endereco['complement'] ?? null,
            ]) ?? '')),
            'bairro' => trim((string) ($this->firstString([
                $tomador['bairro'] ?? null,
                $tomador['xBairro'] ?? null,
                $tomador['district'] ?? null,
                $endereco['bairro'] ?? null,
                $endereco['xBairro'] ?? null,
                $endereco['district'] ?? null,
            ]) ?? '')),
        ];
    }

    /**
     * @param array{codigo_municipio:string,cep:string,logradouro:string,numero:string,complemento:string,bairro:string} $endereco
     */
    private function tomadorEnderecoDpsCompleto(array $endereco): bool
    {
        return strlen($endereco['codigo_municipio']) === 7
            && strlen($endereco['cep']) === 8
            && $endereco['logradouro'] !== ''
            && $endereco['numero'] !== ''
            && $endereco['bairro'] !== '';
    }

    private function appendObraGroup(\DOMDocument $dom, \DOMElement $serv, array $obra): void
    {
        if ($obra === []) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $obraNode = $dom->createElementNS($ns, 'obra');

        $cObra = trim((string) ($obra['cObra'] ?? $obra['codigo'] ?? ''));
        $inscImobFisc = trim((string) ($obra['inscImobFisc'] ?? ''));
        $end = is_array($obra['end'] ?? null) ? $obra['end'] : [];

        if ($cObra !== '') {
            $this->appendNodeDps($dom, $obraNode, 'cObra', $cObra);
        } elseif ($inscImobFisc !== '') {
            $this->appendNodeDps($dom, $obraNode, 'inscImobFisc', $inscImobFisc);
        } elseif ($end !== []) {
            $endNode = $dom->createElementNS($ns, 'end');
            foreach ([
                'CEP' => 'CEP',
                'xLgr' => 'xLgr',
                'nro' => 'nro',
                'xCpl' => 'xCpl',
                'xBairro' => 'xBairro',
            ] as $target => $source) {
                $value = trim((string) ($end[$source] ?? ''));
                if ($value !== '') {
                    $this->appendNodeDps($dom, $endNode, $target, $value);
                }
            }
            if ($endNode->childNodes->length > 0) {
                $obraNode->appendChild($endNode);
            }
        }

        if ($obraNode->childNodes->length > 0) {
            $serv->appendChild($obraNode);
        }
    }

    /**
     * @param array<string,mixed> $servico
     */
    private function appendBeneficioMunicipalGroup(\DOMDocument $dom, \DOMElement $tribMun, array $servico): void
    {
        $beneficio = is_array($servico['BM'] ?? null) ? $servico['BM'] : [];
        $nBm = $this->onlyDigits((string) ($this->firstString([
            $beneficio['nBM'] ?? null,
            $servico['nBM'] ?? null,
            $servico['benefit_code'] ?? null,
            $servico['codigo_beneficio'] ?? null,
            $servico['codigoBeneficio'] ?? null,
        ]) ?? ''));
        if (strlen($nBm) !== 14) {
            return;
        }

        $percentualReducao = $this->firstPositiveDecimal([
            $beneficio['pRedBCBM'] ?? null,
            $servico['pRedBCBM'] ?? null,
            $servico['iss_reduction_percent'] ?? null,
            $servico['base_reduction_percent'] ?? null,
        ]);
        $valorReducao = $this->firstPositiveDecimal([
            $beneficio['vRedBCBM'] ?? null,
            $servico['vRedBCBM'] ?? null,
            $servico['valor_reducao_bc_bm'] ?? null,
        ]);
        if ($percentualReducao === null && $valorReducao === null) {
            return;
        }

        $ns = $this->getDpsNamespace();
        $bm = $dom->createElementNS($ns, 'BM');
        $tribMun->appendChild($bm);
        $this->appendNodeDps($dom, $bm, 'nBM', $nBm);
        if ($percentualReducao !== null) {
            $this->appendNodeDps($dom, $bm, 'pRedBCBM', $this->formatDecimal($percentualReducao, 2));
            return;
        }

        $this->appendNodeDps($dom, $bm, 'vRedBCBM', $this->formatDecimal((float) $valorReducao, 2));
    }

    protected function processarResposta(string $xmlResposta): array
    {
        if ($xmlResposta === '') {
            return [
                'sucesso' => false,
                'mensagem' => 'Resposta vazia',
                'dados' => [],
            ];
        }
        if (str_contains($xmlResposta, '%PDF-1.7')) {
            // Handle PDF response
            return [
                'sucesso' => true,
                'status' => 'success',
                'mensagem' => 'Processado com sucesso',
                'dados' => [
                    'pdf_base64' => base64_encode($xmlResposta),
                    'content_type' => 'application/pdf',
                ],
            ];
        }

        $json = json_decode($xmlResposta, true);
        if (is_array($json)) {
            $errors = $this->extractProcessingErrorDetails($json);
            $mensagens = $this->extractProcessingMessages($json);
            $mensagemErro = $mensagens[0] ?? null;
            $nfseXml = $this->decodeGZipBase64((string)($json['nfseXmlGZipB64'] ?? ''));
            $idDps = (string)($json['idDps'] ?? $json['idDPS'] ?? '');
            $chave = (string)($json['chaveAcesso'] ?? '');
            $nfseResumo = $this->extractNfseSummary($nfseXml, $chave);

            if ($nfseXml !== null) {
                return [
                    'sucesso' => true,
                    'status' => 'success',
                    'mensagem' => 'Processado com sucesso',
                    'mensagens' => $mensagens,
                    'errors' => $errors,
                    'raw_xml' => $nfseXml,
                    'nfse' => $nfseResumo,
                    'dados' => [
                        'xml_retorno' => $nfseXml,
                        'id_dps' => $idDps !== '' ? $idDps : null,
                        'chave_acesso' => $chave !== '' ? $chave : null,
                        'numero_nfse' => null,
                        'codigo_verificacao' => null,
                        'protocolo' => null,
                        'link_visualizacao' => null,
                        'cstat' => null,
                        'xmotivo' => null,
                    ],
                ];
            }

            return [
                'sucesso' => $chave !== '' && $idDps !== '',
                'status' => ($chave !== '' && $idDps !== '') ? 'success' : 'error',
                'mensagem' => (string)($mensagemErro ?: ($chave !== '' ? 'Processado com sucesso' : 'Falha no processamento da NFS-e')),
                'mensagens' => $mensagens,
                'errors' => $errors,
                'raw_xml' => null,
                'nfse' => $chave !== '' ? ['chave_acesso' => $chave] : null,
                'dados' => [
                    'id_dps' => $idDps !== '' ? $idDps : null,
                    'chave_acesso' => $chave !== '' ? $chave : null,
                ],
            ];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xmlResposta)) {
            $errors = libxml_get_errors();
            $message = $errors[0]->message ?? 'XML inválido';
            libxml_clear_errors();

            return [
                'sucesso' => false,
                'mensagem' => trim($message),
                'dados' => [],
            ];
        }
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $status = $this->getNodeValue($dom, ['Sucesso', 'sucesso', 'Status', 'cStat']);
        $mensagem = $this->getNodeValue($dom, ['Mensagem', 'mensagem', 'xMotivo']);
        $numeroNfse = $this->firstNodeValue($xpath, [
            "//*[local-name()='InfNfse']/*[local-name()='Numero']",
            "//*[local-name()='NumeroNfse']",
            "//*[local-name()='numeroNfse']",
        ]);
        $codigoVerificacao = $this->firstNodeValue($xpath, [
            "//*[local-name()='InfNfse']/*[local-name()='CodigoVerificacao']",
            "//*[local-name()='CodigoVerificacao']",
        ]);
        $linkVisualizacao = $this->firstNodeValue($xpath, [
            "//*[local-name()='LinkVisualizacaoNfse']",
        ]);
        $protocolo = $this->firstNodeValue($xpath, [
            "//*[local-name()='Protocolo']",
            "//*[local-name()='nProt']",
        ]);
        $mensagensRetorno = $xpath->query("//*[local-name()='MensagemRetorno']/*[local-name()='Mensagem']");
        $temMensagemRetorno = $mensagensRetorno && $mensagensRetorno->length > 0;
        $sucesso = $this->normalizeBool($status) || (!$temMensagemRetorno && $numeroNfse !== null);

        return [
            'sucesso' => $sucesso,
            'status' => $sucesso ? 'success' : 'error',
            'mensagem' => $mensagem ?? ($sucesso ? 'Processado com sucesso' : 'Retorno sem status explícito'),
            'mensagens' => $mensagem !== null ? [$mensagem] : [],
            'raw_xml' => $xmlResposta,
            'nfse' => $numeroNfse !== null || $codigoVerificacao !== null ? [
                'numero' => $numeroNfse,
                'codigo_verificacao' => $codigoVerificacao,
                'chave_acesso' => null,
            ] : null,
            'dados' => [
                'numero_nfse' => $numeroNfse,
                'codigo_verificacao' => $codigoVerificacao,
                'protocolo' => $protocolo,
                'link_visualizacao' => $linkVisualizacao,
                'cstat' => $this->getNodeValue($dom, ['cStat']),
                'xmotivo' => $this->getNodeValue($dom, ['xMotivo']),
            ],
        ];
    }

    public function validarDados(array $dados): bool
    {
        $dados = $this->normalizeDpsPayload($dados, false);
        parent::validarDados($dados);

        if (empty($dados['prestador']['cnpj']) || strlen($this->onlyDigits((string) $dados['prestador']['cnpj'])) !== 14) {
            throw new \InvalidArgumentException('CNPJ do prestador inválido');
        }

        $codigoServico = (string)($dados['servico']['codigo'] ?? $dados['servico']['cTribNac'] ?? $dados['servico']['codigoServicoNacional'] ?? '');
        if (trim($codigoServico) === '') {
            throw new \InvalidArgumentException('Código de serviço é obrigatório');
        }

        if (!isset($dados['valor_servicos']) || (float) $dados['valor_servicos'] <= 0) {
            throw new \InvalidArgumentException('Valor de serviços deve ser maior que zero');
        }

        if (empty($dados['tomador']['documento'])) {
            throw new \InvalidArgumentException('Documento do tomador é obrigatório');
        }

        $docTomador = $this->onlyDigits((string) $dados['tomador']['documento']);
        if (!in_array(strlen($docTomador), [11, 14], true)) {
            throw new \InvalidArgumentException('Documento do tomador deve ser CPF (11) ou CNPJ (14)');
        }

        if (empty($dados['tomador']['razaoSocial'])) {
            throw new \InvalidArgumentException('Razão Social do tomador é obrigatória');
        }

        return true;
    }

    private function shouldWrapDpsInNfse(): bool
    {
        $root = strtolower((string)($this->config['dps_root'] ?? 'dps'));
        if ($root === 'dps') {
            return false;
        }
        if ($root === 'nfse') {
            return true;
        }
        return true;
    }

    private function validarDadosDpsNacional(array $dados): void
    {
        $rawSerie = $this->onlyDigits((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'));
        if ($rawSerie === '' || strlen($rawSerie) > 5) {
            throw new \InvalidArgumentException('Layout DPS inválido: serie deve conter de 1 a 5 dígitos numéricos.');
        }

        $rawNDps = $this->onlyDigits((string)($dados['nDPS'] ?? $dados['numero_rps'] ?? '1'));
        if ($rawNDps === '' || strlen($rawNDps) > 15) {
            throw new \InvalidArgumentException('Layout DPS inválido: nDPS deve conter de 1 a 15 dígitos numéricos.');
        }

        $dados = $this->normalizeDpsPayload($dados, false);
        $errors = [];

        $dCompet = (string)($dados['dCompet'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dCompet)) {
            $errors[] = 'dCompet deve estar no formato YYYY-MM-DD.';
        }

        $tpAmb = (string)($dados['tpAmb'] ?? ($this->getAmbiente() === 'producao' ? '1' : '2'));
        if (!in_array($tpAmb, ['1', '2'], true)) {
            $errors[] = 'tpAmb deve ser 1 (producao) ou 2 (homologacao).';
        }

        $dhEmi = (string)($dados['dhEmi'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/', $dhEmi)) {
            $errors[] = 'dhEmi deve estar no formato UTC (YYYY-MM-DDThh:mm:ssZ ou com timezone).';
        }

        $verAplic = trim((string)($dados['verAplic'] ?? $this->config['ver_aplic'] ?? 'invoiceflow-1.0'));
        if ($verAplic === '' || strlen($verAplic) > 20) {
            $errors[] = 'verAplic deve ter entre 1 e 20 caracteres.';
        }

        $serie = $this->onlyDigits((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'));
        if ($serie === '' || strlen($serie) > 5) {
            $errors[] = 'serie deve conter de 1 a 5 dígitos numéricos.';
        }

        $nDps = $this->onlyDigits((string)($dados['nDPS'] ?? $dados['numero_rps'] ?? '1'));
        if ($nDps === '' || strlen($nDps) > 15) {
            $errors[] = 'nDPS deve conter de 1 a 15 dígitos numéricos.';
        }

        $tpEmit = (string)($dados['tpEmit'] ?? '1');
        if (!in_array($tpEmit, ['1', '2', '3'], true)) {
            $errors[] = 'tpEmit deve ser 1, 2 ou 3.';
        }
        if ($tpEmit !== '1') {
            $errors[] = 'Nesta versão do emissor, somente tpEmit=1 (prestador) é permitido.';
        }

        $cLocEmi = $this->onlyDigits((string)($dados['cLocEmi'] ?? $dados['prestador']['codigoMunicipio'] ?? $this->getCodigoMunicipio()));
        if (strlen($cLocEmi) !== 7) {
            $errors[] = 'cLocEmi deve conter 7 dígitos.';
        }

        $cLocPrest = $this->onlyDigits((string)($dados['servico']['cLocPrestacao'] ?? $dados['servico']['codigo_municipio'] ?? $cLocEmi));
        if (strlen($cLocPrest) !== 7) {
            $errors[] = 'cLocPrestacao deve conter 7 dígitos.';
        }

        $cTribNac = $this->normalizeCTribNac((string)($dados['servico']['cTribNac'] ?? $dados['servico']['codigoServicoNacional'] ?? $dados['servico']['codigo'] ?? ''));
        if (strlen($cTribNac) !== 6) {
            $errors[] = 'cTribNac deve conter 6 dígitos.';
        }

        $desc = trim((string)($dados['servico']['descricao'] ?? $dados['servico']['discriminacao'] ?? ''));
        if ($desc === '' || strlen($desc) > 2000) {
            $errors[] = 'xDescServ é obrigatório.';
        }

        $valorServ = (float)($dados['valor_servicos'] ?? 0);
        if ($valorServ <= 0) {
            $errors[] = 'vServ deve ser maior que zero.';
        }

        $tributacao = is_array($dados['tributacao'] ?? null) ? $dados['tributacao'] : [];
        $federal = is_array($tributacao['federal'] ?? null) ? $tributacao['federal'] : [];
        $vRetIrrf = $this->firstPositiveDecimal([
            $federal['vRetIRRF'] ?? null,
            $federal['valor_irrf'] ?? null,
            $federal['valor_ir'] ?? null,
            $dados['servico']['vRetIRRF'] ?? null,
            $dados['servico']['valor_irrf'] ?? null,
            $dados['servico']['valor_ir'] ?? null,
            $dados['vRetIRRF'] ?? null,
            $dados['valor_irrf'] ?? null,
            $dados['valor_ir'] ?? null,
        ]);
        if ($vRetIrrf !== null && $valorServ > 0 && $vRetIrrf >= $valorServ) {
            $errors[] = 'vRetIRRF deve ser maior que zero e menor que vServ.';
        }

        $municipal = is_array($tributacao['municipal'] ?? null) ? $tributacao['municipal'] : [];
        $servico = (array)($dados['servico'] ?? []);
        $tribIssqn = (string)($municipal['tribISSQN'] ?? $servico['tribISSQN'] ?? '1');
        if (!in_array($tribIssqn, ['1', '2', '3', '4'], true)) {
            $errors[] = 'tribISSQN deve ser 1, 2, 3 ou 4.';
        }

        $tpRetIssqn = $this->resolveDpsIssRetentionCode($municipal + $servico);
        if (!in_array($tpRetIssqn, ['1', '2', '3'], true)) {
            $errors[] = 'tpRetISSQN deve ser 1, 2 ou 3.';
        }
        if (in_array($tribIssqn, ['2', '3', '4'], true) && $tpRetIssqn !== '1') {
            $errors[] = 'tpRetISSQN deve ser 1 quando tribISSQN for 2, 3 ou 4.';
        }

        $tomadorDoc = $this->onlyDigits((string)($dados['tomador']['documento'] ?? ''));
        if (($tpEmit === '1' && strlen($tomadorDoc) === 14) || ($tpEmit !== '2' && $tpRetIssqn === '2')) {
            $tomadorEndereco = $this->resolveTomadorEnderecoDps((array)($dados['tomador'] ?? []));
            if (!$this->tomadorEnderecoDpsCompleto($tomadorEndereco)) {
                $errors[] = 'Endereco nacional do tomador deve conter codigo IBGE, CEP, logradouro, numero e bairro para DPS nacional.';
            }
        }

        $aliquota = (float)($municipal['pAliq'] ?? $municipal['aliquota'] ?? $servico['pAliq'] ?? $servico['aliquota'] ?? 0);
        if ($tribIssqn === '1' && $aliquota <= 0) {
            $errors[] = 'pAliq (servico.aliquota) deve ser informado e maior que zero quando tribISSQN=1.';
        }
        if ($aliquota > 0) {
            $aliquotaPercent = $this->normalizeDpsAliquotaPercent($aliquota);
            if ($aliquotaPercent > 5) {
                $errors[] = 'pAliq não pode ser superior a 5%.';
            }
            if (in_array($tribIssqn, ['2', '3', '4'], true)) {
                $errors[] = 'pAliq não deve ser informado quando tribISSQN for 2, 3 ou 4.';
            }
        }

        // Regra de consistência temporal do layout DPS.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dCompet) === 1 &&
            preg_match('/^\d{4}-\d{2}-\d{2}T/', $dhEmi) === 1) {
            $dhEmiDate = substr($dhEmi, 0, 10);
            if ($dCompet > $dhEmiDate) {
                $errors[] = 'dCompet não pode ser posterior à data de emissão (dhEmi).';
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Layout DPS inválido: ' . implode(' | ', $errors));
        }
    }

    private function assertDpsIdentityFieldsBeforeNormalization(array $dados): void
    {
        $rawSerie = $this->onlyDigits((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'));
        if ($rawSerie === '' || strlen($rawSerie) > 5) {
            throw new \InvalidArgumentException('Layout DPS inválido: serie deve conter de 1 a 5 dígitos numéricos.');
        }

        $rawNDps = $this->onlyDigits((string)($dados['nDPS'] ?? $dados['numero_rps'] ?? '1'));
        if ($rawNDps === '' || strlen($rawNDps) > 15) {
            throw new \InvalidArgumentException('Layout DPS inválido: nDPS deve conter de 1 a 15 dígitos numéricos.');
        }
    }

    private function enviarOperacao(string $operacao, ?string $xml, array $params = []): string
    {
        $rawEndpoint = (string)($this->config['endpoints'][$operacao] ?? '');
        $path = $this->resolveOperationPath($operacao, $params);
        $method = $this->resolveOperationMethod($operacao);
        $response = '';
        $useJsonTransport = $this->shouldUseJsonTransport($operacao, $rawEndpoint);
        $transport = $this->getTransport($operacao, $rawEndpoint);


        switch ($transport) {
            case 'json':
                $payload = $method === 'GET' ? null : $this->buildJsonPayloadForOperation($operacao, $xml);
                $headers = ['Accept: application/json'];
                if ($payload !== null) {
                    array_unshift($headers, 'Content-Type: application/json');
                }
                $response = $this->requestHttp($method, $path, $payload, $headers);
                break;

            case 'pdf':
                $response = $this->requestHttp($method, $path, $method === 'GET' ? null : $xml, [
                    'Content-Type: application/pdf',
                    'Accept: application/pdf',
                ]);
                break;
            default:
                $response = $this->requestHttp($method, $path, $method === 'GET' ? null : $xml, [
                            'Content-Type: application/xml',
                            'Accept: application/xml',
                        ]);
                break;
        }

        if ($response === '') {
            throw new \RuntimeException("Resposta vazia na operação {$operacao}");
        }

        return $response;
    }

    private function resolveOperationPath(string $operacao, array $params = []): string
    {
        $defaultMap = [
            'emitir' => '/nfse',
            'consultar' => '/nfse/{id}',
            'cancelar' => '/nfse/{id}/eventos',
            'substituir' => '/nfse',
            'consultar_rps' => '/nfse/consultar-rps',
            'consultar_lote' => '/nfse/consultar-lote',
            'baixar_xml' => '/nfse/download/xml',
            'baixar_danfse' => '/danfse/{chave}',
        ];

        $configured = $this->config['endpoints'][$operacao] ?? null;
        $path = (string) ($configured ?? $defaultMap[$operacao] ?? '');
        if ($path === '') {
            throw new \RuntimeException("Endpoint da operação {$operacao} não configurado");
        }

        return $this->resolveConfiguredEndpoint($path, $params);
    }

    private function resolveOperationMethod(string $operacao): string
    {
        $configured = strtoupper((string)($this->config['operation_methods'][$operacao] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return match ($operacao) {
            'consultar' => 'GET',
            default => 'POST',
        };
    }

    private function resolveConfiguredEndpoint(string $endpoint, array $params = []): string
    {
        $resolved = $this->replaceEndpointPlaceholders($endpoint, $params);
        if (preg_match('/^([a-z0-9_]+):(\/.*)$/i', $resolved, $matches) === 1) {
            $serviceName = strtolower((string)$matches[1]);
            $servicePath = (string)$matches[2];
            $serviceBase = trim((string)($this->config['services'][$serviceName][$this->ambiente] ?? ''));
            if ($serviceBase === '') {
                throw new \RuntimeException("Serviço '{$serviceName}' não configurado para o ambiente {$this->ambiente}.");
            }

            return rtrim($this->normalizeBaseUrl($serviceBase), '/') . $servicePath;
        }

        return str_starts_with($resolved, '/') || preg_match('#^https?://#i', $resolved) === 1
            ? $resolved
            : '/' . $resolved;
    }

    private function replaceEndpointPlaceholders(string $endpoint, array $params = []): string
    {
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $endpoint = str_replace(
                '{' . $key . '}',
                rawurlencode((string)$value),
                $endpoint
            );
        }

        return $endpoint;
    }

    private function shouldUseJsonTransport(string $operacao, string $rawEndpoint): bool
    {
        $transport = strtolower((string)($this->config['operation_transports'][$operacao] ?? ''));
        if ($transport !== '') {
            return $transport === 'json';
        }

        return preg_match('/^sefin:\//i', $rawEndpoint) === 1 || in_array($operacao, ['emitir', 'cancelar'], true);
    }

    private function getTransport(string $operacao, string $rawEndpoint): string
    {
        $transport = strtolower((string)($this->config['operation_transports'][$operacao] ?? ''));
        if ($transport !== '') {
            return $transport;
        }

        return preg_match('/^sefin:\//i', $rawEndpoint) === 1 || in_array($operacao, ['emitir', 'cancelar'], true) ? 'json' : 'pdf';
    }

    private function buildJsonPayloadForOperation(string $operacao, ?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }

        $gzipBase64 = base64_encode(gzencode($xml));
        $payload = match ($operacao) {
            'emitir' => ['dpsXmlGZipB64' => $gzipBase64],
            'cancelar' => ['pedidoRegistroEventoXmlGZipB64' => $gzipBase64],
            default => throw new \RuntimeException("Operação {$operacao} não suporta transporte JSON compactado configurado."),
        };

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException("Falha ao serializar payload JSON da operação {$operacao}.");
        }

        return $encoded;
    }

    private function requestHttp(string $method, string $path, ?string $body = null, array $headers = []): string
    {
        if (is_callable($this->httpClient)) {
            $result = call_user_func($this->httpClient, $method, $path, $body, $headers);
            if (!is_string($result)) {
                throw new \RuntimeException('Cliente HTTP mock retornou payload inválido');
            }

            return $result;
        }

        $isAbsoluteUrl = preg_match('#^https?://#i', $path) === 1;
        $baseUrl = $isAbsoluteUrl ? null : $this->normalizeBaseUrl((string)$this->getNationalApiBaseUrl());
        $url = $isAbsoluteUrl
            ? $path
            : rtrim((string)$baseUrl, '/') . $path;
        $authHeaders = $this->buildAuthHeaders();
        $allHeaders = array_merge($headers, $authHeaders);
        $requestId = 'nfse_' . bin2hex(random_bytes(6));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $tempCertFile = null;
            $tempKeyFile = null;
            $certificate = $this->resolveRuntimeCertificate();
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->getTimeout(),
                CURLOPT_CONNECTTIMEOUT => min(30, max(5, $this->getTimeout())),
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $allHeaders,
                CURLOPT_HEADER => true,
                CURLOPT_DEFAULT_PROTOCOL => 'https',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $urlParts = parse_url($url);
            if (($urlParts['scheme'] ?? 'https') === 'https') {
                curl_setopt($ch, CURLOPT_PORT, 443);
            }
            $mutualTlsApplied = $this->applyMutualTlsCurlOptions($ch, $tempCertFile, $tempKeyFile, $certificate);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $rawResponse = curl_exec($ch);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if (is_string($tempCertFile) && $tempCertFile !== '' && file_exists($tempCertFile)) {
                @unlink($tempCertFile);
            }
            if (is_string($tempKeyFile) && $tempKeyFile !== '' && file_exists($tempKeyFile)) {
                @unlink($tempKeyFile);
            }

            if ($rawResponse === false) {
                $this->logHttpDebug([
                    'request_id' => $requestId,
                    'method' => $method,
                    'url' => $url,
                    'path' => $path,
                    'status' => $status,
                'curl_error' => $curlErr,
                'headers' => $this->maskHttpHeaders($allHeaders),
                'request_body' => $this->summarizeHttpBody($body),
                'mutual_tls' => $mutualTlsApplied,
                'xml_signature_applied' => $this->lastSignatureApplied,
                'certificate' => $this->buildCertificateDebugContext($certificate),
                'response_headers' => null,
                'response_body' => null,
            ]);
                throw new \RuntimeException($this->buildHttpErrorMessage("Erro cURL: {$curlErr}"));
            }

            $responseHeaders = substr((string)$rawResponse, 0, $headerSize);
            $response = substr((string)$rawResponse, $headerSize);
            $this->logHttpDebug([
                'request_id' => $requestId,
                'method' => $method,
                'url' => $url,
                'path' => $path,
                'status' => $status,
                'curl_error' => $curlErr !== '' ? $curlErr : null,
                'headers' => $this->maskHttpHeaders($allHeaders),
                'request_body' => $this->summarizeHttpBody($body),
                'mutual_tls' => $mutualTlsApplied,
                'xml_signature_applied' => $this->lastSignatureApplied,
                'certificate' => $this->buildCertificateDebugContext($certificate),
                'response_headers' => $this->truncate($responseHeaders, 1200),
                'response_body' => $this->truncate((string)$response, 1200),
            ]);

            if ($status >= 400) {
                $snippet = $this->formatHttpErrorResponseSnippet((string) $response);
                $suffix = $snippet !== '' ? " | resposta: {$snippet}" : '';
                throw new \RuntimeException(
                    $this->buildHttpErrorMessage("HTTP {$status} na operação {$path} [req:{$requestId}]{$suffix}")
                );
            }

            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $this->getTimeout(),
                'header' => implode("\r\n", $allHeaders),
                'content' => $body ?? '',
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $this->logHttpDebug([
            'request_id' => $requestId,
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'status' => null,
            'curl_error' => null,
            'headers' => $this->maskHttpHeaders($allHeaders),
            'request_body' => $this->summarizeHttpBody($body),
            'response_headers' => null,
            'response_body' => $response !== false ? $this->truncate((string)$response, 1200) : null,
        ]);
        if ($response === false) {
            throw new \RuntimeException($this->buildHttpErrorMessage("Falha HTTP na operação {$path} [req:{$requestId}]"));
        }

        return (string) $response;
    }

    private function isHttpDebugEnabled(): bool
    {
        $configFlag = (bool)($this->config['debug_http'] ?? false);
        $envRaw = $_ENV['FISCAL_NFSE_DEBUG'] ?? getenv('FISCAL_NFSE_DEBUG') ?: '';
        $envFlag = in_array(strtolower((string)$envRaw), ['1', 'true', 'yes', 'on'], true);
        return $configFlag || $envFlag;
    }

    private function getHttpDebugLogPath(): string
    {
        $envConfigured = trim((string)($_ENV['FISCAL_NFSE_DEBUG_LOG'] ?? getenv('FISCAL_NFSE_DEBUG_LOG') ?: ''));
        if ($envConfigured !== '') {
            return $envConfigured;
        }

        $configured = (string)($this->config['debug_log_file'] ?? '');
        if ($configured !== '') {
            return $configured;
        }
        return sys_get_temp_dir() . '/nfse-http-debug.log';
    }

    private function logHttpDebug(array $data): void
    {
        if (!$this->isHttpDebugEnabled()) {
            return;
        }

        $payload = [
            'ts' => date('c'),
            'provider' => 'NacionalProvider',
            'ambiente' => $this->getAmbiente(),
            'api_base' => $this->getNationalApiBaseUrl(),
        ] + $data;

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        @file_put_contents($this->getHttpDebugLogPath(), $line . PHP_EOL, FILE_APPEND);
    }

    private function buildHttpErrorMessage(string $message): string
    {
        if (!$this->isHttpDebugEnabled()) {
            return $message;
        }

        return $message . ' | debug_log: ' . $this->getHttpDebugLogPath();
    }

    private function maskHttpHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = (string) $header;
            if (preg_match('/^(Authorization|X-API-Key|Api-Key|Token):/i', $header) === 1) {
                [$name] = explode(':', $header, 2);
                return $name . ': ***';
            }

            return $header;
        }, $headers);
    }

    private function summarizeHttpBody(?string $body): mixed
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (isset($decoded['dpsXmlGZipB64'])) {
                $xml = $this->decodeGZipBase64((string) $decoded['dpsXmlGZipB64']);
                $summary = [
                    'format' => 'json_gzip',
                    'keys' => array_keys($decoded),
                    'dpsXmlGZipB64_length' => strlen((string) $decoded['dpsXmlGZipB64']),
                ];
                if (is_string($xml) && $xml !== '') {
                    $summary['xml_root'] = $this->extractXmlRootName($xml);
                    $summary['xml_signed'] = str_contains($xml, '<Signature');
                    $summary['dps_id'] = $this->extractXmlReferenceId($xml);
                }

                return $summary;
            }

            if (isset($decoded['pedidoRegistroEventoXmlGZipB64'])) {
                $xml = $this->decodeGZipBase64((string) $decoded['pedidoRegistroEventoXmlGZipB64']);
                return [
                    'format' => 'json_gzip',
                    'keys' => array_keys($decoded),
                    'pedidoRegistroEventoXmlGZipB64_length' => strlen((string) $decoded['pedidoRegistroEventoXmlGZipB64']),
                    'xml_root' => is_string($xml) ? $this->extractXmlRootName($xml) : null,
                    'xml_signed' => is_string($xml) ? str_contains($xml, '<Signature') : null,
                ];
            }

            return $decoded;
        }

        return $this->truncate($body, 1200);
    }

    private function extractXmlRootName(string $xml): ?string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return null;
        }

        return $dom->documentElement?->localName;
    }

    private function extractXmlReferenceId(string $xml): ?string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $referenceNode = $xpath->query("//*[local-name()='infDPS']/@Id")->item(0);
        if ($referenceNode instanceof \DOMAttr) {
            return trim($referenceNode->value) !== '' ? $referenceNode->value : null;
        }

        return null;
    }

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit) . '...<truncated>';
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new \RuntimeException('API base URL da NFSe Nacional não configurada.');
        }

        if (!preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException("API base URL inválida: {$baseUrl}");
        }

        $host = (string)$parts['host'];
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        if (str_ends_with($host, 'nfse.gov.br') && $scheme !== 'https') {
            $scheme = 'https';
        }
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($parts['port']) ? (int)$parts['port'] : $defaultPort;
        $portSegment = $port !== $defaultPort ? ':' . $port : '';

        return "{$scheme}://{$host}{$portSegment}" . rtrim($path, '/') . $query;
    }

    private function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function findFirstValueByPaths(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $value = trim((string)$data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private function extractAliquotaMunicipalFromCatalog(array $catalogData, string $cTribNac): ?float
    {
        if (isset($catalogData['aliquotas']) && is_array($catalogData['aliquotas'])) {
            $aliquotas = $catalogData['aliquotas'];
            foreach ($aliquotas as $serviceCode => $historico) {
                $serviceCodeNorm = $this->onlyDigits((string)$serviceCode);
                if ($serviceCodeNorm !== '' && $serviceCodeNorm !== $cTribNac) {
                    continue;
                }
                if (!is_array($historico) || count($historico) === 0) {
                    continue;
                }
                $last = end($historico);
                if (is_array($last)) {
                    $aliqRaw = $last['Aliq'] ?? $last['aliq'] ?? null;
                    if ($aliqRaw !== null && is_numeric(str_replace(',', '.', (string)$aliqRaw))) {
                        return (float)str_replace(',', '.', (string)$aliqRaw);
                    }
                }
            }
        }

        $stack = [$catalogData];
        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }
            if ($this->isAssocArray($current)) {
                $code = $this->onlyDigits((string)($this->findFirstValueByPaths($current, [
                    'cTribNac',
                    'codigoServicoNacional',
                    'codigo_servico_nacional',
                    'codigo',
                ]) ?? ''));
                if ($code !== '' && $code === $cTribNac) {
                    $aliqRaw = $this->findFirstValueByPaths($current, [
                        'aliquota',
                        'aliquotaIss',
                        'aliquota_iss',
                        'pAliq',
                        'pAliquota',
                    ]);
                    if ($aliqRaw !== null && is_numeric(str_replace(',', '.', $aliqRaw))) {
                        return (float)str_replace(',', '.', $aliqRaw);
                    }
                }
            }
            foreach ($current as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
        return null;
    }

    private function catalogContainsServiceCode(array $catalogData, string $cTribNac): bool
    {
        if ($cTribNac === '') {
            return false;
        }

        if (isset($catalogData['aliquotas']) && is_array($catalogData['aliquotas'])) {
            foreach ($catalogData['aliquotas'] as $serviceCode => $historico) {
                $serviceCodeNorm = $this->onlyDigits((string) $serviceCode);
                if ($serviceCodeNorm === $cTribNac && is_array($historico) && $historico !== []) {
                    return true;
                }
            }
        }

        $stack = [$catalogData];
        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            if ($this->isAssocArray($current)) {
                $code = $this->onlyDigits((string) ($this->findFirstValueByPaths($current, [
                    'cTribNac',
                    'codigoServicoNacional',
                    'codigo_servico_nacional',
                    'codigo',
                ]) ?? ''));
                if ($code !== '' && $code === $cTribNac) {
                    return true;
                }
            }

            foreach ($current as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }

        return false;
    }

    private function normalizeCompetenciaForParamApi(?string $date): string
    {
        $raw = trim((string)$date);
        if ($raw === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw . 'T00:00:00Z';
        }
        return $raw;
    }

    private function buildAuthHeaders(): array
    {
        $auth = $this->getAuthConfig();
        $headers = [];

        if (!empty($auth['token'])) {
            $headers[] = 'Authorization: Bearer ' . $auth['token'];
        }

        if (!empty($auth['api_key'])) {
            $headers[] = 'X-API-Key: ' . $auth['api_key'];
        }

        return $headers;
    }

    private function applyMutualTlsCurlOptions($ch, ?string &$tempCertFile = null, ?string &$tempKeyFile = null, ?Certificate $certificate = null): bool
    {
        $certificate = $certificate ?? $this->resolveRuntimeCertificate();
        if ($certificate === null) {
            return false;
        }

        $certPem = (string)$certificate;
        $keyPem = (string)$certificate->privateKey;
        if ($certPem === '' || $keyPem === '') {
            return false;
        }

        $tempCertFile = tempnam(sys_get_temp_dir(), 'nfse_cert_');
        $tempKeyFile = tempnam(sys_get_temp_dir(), 'nfse_key_');
        if (!is_string($tempCertFile) || !is_string($tempKeyFile)) {
            return false;
        }

        file_put_contents($tempCertFile, $certPem);
        file_put_contents($tempKeyFile, $keyPem);
        @chmod($tempCertFile, 0600);
        @chmod($tempKeyFile, 0600);

        curl_setopt($ch, CURLOPT_SSLCERT, $tempCertFile);
        curl_setopt($ch, CURLOPT_SSLKEY, $tempKeyFile);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');

        return true;
    }

    private function decodeGZipBase64(string $content): ?string
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $decoded = base64_decode($content, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        $xml = @gzdecode($decoded);
        if ($xml === false) {
            $xml = @gzinflate(substr($decoded, 10));
        }
        if ($xml === false) {
            return null;
        }
        $xml = trim((string)$xml);
        return $xml !== '' ? $xml : null;
    }

    private function ensureUtf8XmlForTransmission(string $xml): string
    {
        $xml = ltrim($xml);
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;

        if (preg_match('//u', $xml) !== 1) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($xml, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                if (is_string($converted) && $converted !== '') {
                    $xml = $converted;
                }
            }
            if (preg_match('//u', $xml) !== 1 && function_exists('iconv')) {
                $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $xml);
                if (is_string($converted) && $converted !== '') {
                    $xml = $converted;
                }
            }
        }

        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            if (is_string($cleaned) && $cleaned !== '') {
                $xml = $cleaned;
            }
        }

        $xml = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $xml) ?? $xml;
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . trim($xml);

        if (preg_match('//u', $xml) !== 1) {
            throw new \RuntimeException('XML gerado não pôde ser normalizado para UTF-8 antes do envio.');
        }

        return $xml;
    }

    private function buildConsultaXml(string $chave): string
    {
        return $this->simpleEnvelope('ConsultarNfseExternoEnvio', [
            'ChaveNfse' => $chave,
        ]);
    }

    private function buildCancelamentoXml(string $chave, string $motivo, ?string $protocolo): string
    {
        $payload = [
            'ChaveNfse' => $chave,
            'Motivo' => $motivo,
        ];
        if (!empty($protocolo)) {
            $payload['Protocolo'] = $protocolo;
        }

        return $this->simpleEnvelope('CancelarNfseEnvio', $payload);
    }

    private function buildCancelamentoEventoNacionalXml(string $chave, string $motivo, ?string $protocolo): string
    {
        $chave = $this->onlyDigits($chave);
        if (strlen($chave) !== 50) {
            throw new \InvalidArgumentException('Chave de acesso NFSe nacional deve conter 50 digitos para cancelamento.');
        }

        $authorDocument = $this->resolveEventoAutorDocument();
        $eventCode = '101101';
        $versao = (string) ($this->config['evento_versao'] ?? '1.01');
        $ns = $this->getDpsNamespace();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElementNS($ns, 'pedRegEvento');
        $root->setAttribute('versao', $versao);
        $dom->appendChild($root);

        $inf = $dom->createElementNS($ns, 'infPedReg');
        $inf->setAttribute('Id', 'PRE' . $chave . $eventCode);
        $root->appendChild($inf);

        $this->appendNodeDps($dom, $inf, 'tpAmb', $this->getAmbiente() === 'producao' ? '1' : '2');
        $this->appendNodeDps($dom, $inf, 'verAplic', $this->resolveEventoApplicationVersion());
        $this->appendNodeDps($dom, $inf, 'dhEvento', $this->currentEventoDateTime());
        $this->appendNodeDps(
            $dom,
            $inf,
            strlen($authorDocument) === 11 ? 'CPFAutor' : 'CNPJAutor',
            $authorDocument
        );
        $this->appendNodeDps($dom, $inf, 'chNFSe', $chave);

        $evento = $dom->createElementNS($ns, 'e101101');
        $inf->appendChild($evento);
        $this->appendNodeDps($dom, $evento, 'xDesc', 'Cancelamento de NFS-e');
        $this->appendNodeDps($dom, $evento, 'cMotivo', $this->resolveCancelamentoCodigo($motivo, $protocolo));
        $this->appendNodeDps($dom, $evento, 'xMotivo', $this->normalizeCancelamentoMotivo($motivo));

        return $dom->saveXML() ?: '';
    }

    private function assinarPedRegEventoSeNecessario(string $xml): string
    {
        $signatureMode = strtolower((string) ($this->config['signature_mode'] ?? 'optional'));
        $this->lastSignatureApplied = false;
        if ($signatureMode === 'none') {
            return $xml;
        }

        $certificate = $this->resolveRuntimeCertificate();
        if ($certificate === null) {
            if ($signatureMode === 'required') {
                throw new \RuntimeException('Certificado digital obrigatório para assinatura XML em homologação.');
            }

            return $xml;
        }

        try {
            $signedXml = Signer::sign($certificate, $xml, 'infPedReg', 'Id', OPENSSL_ALGO_SHA256);
            $this->lastSignatureApplied = $signedXml !== $xml;

            return $signedXml;
        } catch (\Throwable $e) {
            if ($signatureMode === 'required') {
                throw new \RuntimeException('Falha ao assinar XML NFSe: ' . $e->getMessage(), 0, $e);
            }

            return $xml;
        }
    }

    private function resolveEventoAutorDocument(): string
    {
        $certificate = $this->resolveRuntimeCertificate();
        $configManager = ConfigManager::getInstance();
        $candidates = [
            $this->config['prestador']['cnpj'] ?? null,
            $this->config['prestador']['cpf'] ?? null,
            $this->config['prestador']['documento'] ?? null,
            $this->config['empresa']['cnpj'] ?? null,
            $configManager->get('empresa.cnpj'),
            $certificate?->getCnpj(),
            $certificate?->getCpf(),
        ];

        foreach ($candidates as $candidate) {
            $digits = $this->onlyDigits(is_scalar($candidate) ? (string) $candidate : '');
            if (strlen($digits) === 14 || strlen($digits) === 11) {
                return $digits;
            }
        }

        throw new \RuntimeException('Nao foi possivel determinar o CPF/CNPJ do autor do evento de cancelamento NFSe.');
    }

    private function resolveEventoApplicationVersion(): string
    {
        $version = trim((string) ($this->config['ver_aplic'] ?? $this->config['verAplic'] ?? 'fiscal-core'));

        return $version !== '' ? substr($version, 0, 20) : 'fiscal-core';
    }

    private function currentEventoDateTime(): string
    {
        return (new \DateTimeImmutable('now -5 seconds', new \DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-d\TH:i:sP');
    }

    private function resolveCancelamentoCodigo(string $motivo, ?string $protocolo): string
    {
        foreach ([$protocolo, $this->config['cancelamento_codigo'] ?? null, $motivo] as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            if (preg_match('/\b([129])\b/', (string) $candidate, $matches) === 1) {
                return $matches[1];
            }
        }

        return '9';
    }

    private function normalizeCancelamentoMotivo(string $motivo): string
    {
        $motivo = trim(preg_replace('/\s+/', ' ', $motivo) ?? '');
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($motivo) > 255) {
                $motivo = mb_substr($motivo, 0, 255);
            }
            if (mb_strlen($motivo) < 15) {
                throw new \InvalidArgumentException('Motivo do cancelamento NFSe nacional deve conter ao menos 15 caracteres.');
            }

            return $motivo;
        }

        if (strlen($motivo) > 255) {
            $motivo = substr($motivo, 0, 255);
        }
        if (strlen($motivo) < 15) {
            throw new \InvalidArgumentException('Motivo do cancelamento NFSe nacional deve conter ao menos 15 caracteres.');
        }

        return $motivo;
    }

    /**
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function normalizeCancelamentoResponse(string $response, array $parsed): array
    {
        if (($parsed['sucesso'] ?? false) === true) {
            return $parsed;
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return $parsed;
        }

        $errors = $json['erro'] ?? $json['erros'] ?? $json['errors'] ?? null;
        if ((is_array($errors) && $errors !== []) || (is_scalar($errors) && trim((string) $errors) !== '')) {
            return $parsed;
        }

        return array_replace_recursive($parsed, [
            'sucesso' => true,
            'status' => 'success',
            'mensagem' => (string) ($parsed['mensagem'] ?? 'Cancelamento registrado com sucesso'),
            'dados' => [
                'cancelamento_registrado' => true,
                'tipo_ambiente' => $json['tipoAmbiente'] ?? null,
                'versao_aplicativo' => $json['versaoAplicativo'] ?? null,
                'data_hora_processamento' => $json['dataHoraProcessamento'] ?? null,
            ],
        ]);
    }

    private function buildSubstituicaoXml(string $nfseOriginal, array $dadosSubstituicao): string
    {
        return $this->simpleEnvelope('SubstituirNfseEnvio', [
            'NfseOriginal' => $nfseOriginal,
            'NfseSubstituta' => $this->montarXmlRps($dadosSubstituicao),
        ]);
    }

    private function buildConsultaRpsXml(array $identificacaoRps): string
    {
        return $this->simpleEnvelope('ConsultarNfsePorRpsEnvio', [
            'Numero' => (string) $identificacaoRps['numero'],
            'Serie' => (string) $identificacaoRps['serie'],
            'Tipo' => (string) $identificacaoRps['tipo'],
        ]);
    }

    private function buildConsultaLoteXml(string $protocolo): string
    {
        return $this->simpleEnvelope('ConsultarLoteRpsEnvio', [
            'Protocolo' => $protocolo,
        ]);
    }

    private function buildDownloadXmlPayload(string $tipo, string $chave): string
    {
        return $this->simpleEnvelope('DownloadNfseEnvio', [
            'Tipo' => $tipo,
            'ChaveNfse' => $chave,
        ]);
    }

    /**
     * @param array<string,string> $payload
     */
    private function simpleEnvelope(string $rootName, array $payload): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS($this->getIntegrationNamespace(), $rootName);
        $root->setAttribute('versao', $this->getVersao());
        $dom->appendChild($root);

        foreach ($payload as $node => $value) {
            $this->appendNode($dom, $root, $node, $value);
        }

        return $dom->saveXML() ?: '';
    }

    private function getNodeValue(\DOMDocument $dom, array $possibleNames): ?string
    {
        foreach ($possibleNames as $name) {
            $node = $dom->getElementsByTagName($name)->item(0);
            if ($node !== null && $node->nodeValue !== null) {
                return trim($node->nodeValue);
            }
        }

        return null;
    }

    private function normalizeBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', '100', '150', 'true', 'sucesso', 'ok'], true);
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private function getIntegrationNamespace(): string
    {
        return (string) ($this->config['xml_namespace'] ?? 'http://www.publica.inf.br/integracao_nfse');
    }

    private function appendNode(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $node = $dom->createElementNS($this->getIntegrationNamespace(), $name);
        if ($value !== null) {
            $normalizedValue = $this->normalizeXmlText($value);
            $node->appendChild($dom->createTextNode($normalizedValue));
        }
        $parent->appendChild($node);
        return $node;
    }

    private function appendNodeNoNs(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $node = $dom->createElement($name);
        if ($value !== null && $value !== '') {
            $normalizedValue = $this->normalizeXmlText($value);
            $node->appendChild($dom->createTextNode($normalizedValue));
        }
        $parent->appendChild($node);
        return $node;
    }

    private function appendNodeDps(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $node = $dom->createElementNS($this->getDpsNamespace(), $name);
        if ($value !== null && $value !== '') {
            $normalizedValue = $this->normalizeXmlText($value);
            $node->appendChild($dom->createTextNode($normalizedValue));
        }
        $parent->appendChild($node);
        return $node;
    }

    private function buildDpsId(array $dados, ?string $serie = null, ?string $nDps = null): string
    {
        $providedId = trim((string) ($dados['id'] ?? ''));
        if (preg_match(NacionalDpsIdentityBuilder::ID_PATTERN, $providedId) === 1) {
            return $providedId;
        }

        $payload = $dados;
        if ($serie !== null) {
            $payload['serie'] = $serie;
        }
        if ($nDps !== null) {
            $payload['nDPS'] = $nDps;
        }

        $id = NacionalDpsIdentityBuilder::fromPayload($payload, [
            'codigo_municipio' => $this->getCodigoMunicipio(),
        ]);
        if ($id !== null) {
            return $id;
        }

        return NacionalDpsIdentityBuilder::build(
            (string) ($dados['cLocEmi'] ?? $dados['prestador']['codigoMunicipio'] ?? $this->getCodigoMunicipio()),
            (string) ($dados['prestador']['cnpj'] ?? $dados['prestador']['cpf'] ?? $dados['prestador']['documento'] ?? ''),
            $serie ?? ($dados['serie'] ?? $dados['serie_rps'] ?? '1'),
            $nDps ?? ($dados['nDPS'] ?? $dados['numero_rps'] ?? '1')
        );
    }

    private function normalizeNumeric(string $value, int $length, string $default): string
    {
        $digits = $this->onlyDigits($value);
        if ($digits === '') {
            $digits = $default;
        }
        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    private function normalizeDpsSerieForXml(string $serie, string $versao): string
    {
        $digits = $this->onlyDigits($serie);
        if ($digits === '') {
            $digits = '1';
        }

        $digits = substr($digits, 0, 5);
        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        if (version_compare($versao, '1.01', '<')) {
            return $digits;
        }

        return $normalized;
    }

    private function normalizeCTribNac(string $raw): string
    {
        $digits = $this->onlyDigits($raw);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 6) {
            return $digits;
        }

        // Aceita entradas no formato item/subitem sem desdobro e assume desdobro 01.
        // Ex.: "107" -> 01.07.01 => 010701 | "1705" -> 17.05.01 => 170501
        if (strlen($digits) === 3) {
            $item = (int)substr($digits, 0, 1);
            $subitem = (int)substr($digits, 1, 2);
            return sprintf('%02d%02d01', $item, $subitem);
        }
        if (strlen($digits) === 4) {
            $item = (int)substr($digits, 0, 2);
            $subitem = (int)substr($digits, 2, 2);
            return sprintf('%02d%02d01', $item, $subitem);
        }

        return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT);
    }

    private function normalizeCTribMun(string $raw): string
    {
        $digits = $this->onlyDigits($raw);

        return strlen($digits) === 3 ? $digits : '';
    }

    private function normalizeXmlText(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                if (is_string($converted) && $converted !== '') {
                    $value = $converted;
                }
            }

            if (preg_match('//u', $value) !== 1 && function_exists('iconv')) {
                $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
                if (is_string($converted) && $converted !== '') {
                    $value = $converted;
                }
            }

            if (preg_match('//u', $value) !== 1 && function_exists('utf8_encode')) {
                $value = utf8_encode($value);
            }
        }

        if (function_exists('iconv')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($cleaned) && $cleaned !== '') {
                $value = $cleaned;
            }
        }

        return preg_replace('/[^\P{C}\t\n\r]/u', '', $value) ?? $value;
    }

    /**
     * @param array<int,string> $queries
     */
    private function firstNodeValue(\DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function formatDecimal(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }

    /**
     * @param array<string,mixed> $servico
     */
    private function resolveDpsIssRetentionCode(array $servico): string
    {
        if (array_key_exists('tpRetISSQN', $servico) && is_scalar($servico['tpRetISSQN'])) {
            $explicitCode = trim((string)$servico['tpRetISSQN']);
            if (in_array($explicitCode, ['1', '2', '3'], true)) {
                return $explicitCode;
            }
        }

        foreach (['IssRetido', 'iss_retido', 'issRetido'] as $key) {
            if (!array_key_exists($key, $servico)) {
                continue;
            }

            $value = $servico[$key];
            if (is_bool($value)) {
                return $value ? '2' : '1';
            }

            if (!is_scalar($value)) {
                continue;
            }

            $normalized = strtolower(trim((string) $value));
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($converted !== false) {
                $normalized = $converted;
            }

            if ($normalized === '1') {
                return '2';
            }

            if ($normalized === '2' || $normalized === '0') {
                return '1';
            }

            if ($normalized === '3') {
                return '3';
            }

            if (in_array($normalized, ['true', 't', 's', 'sim', 'yes', 'y', 'retido', 'r'], true)) {
                return '2';
            }

            if (in_array($normalized, ['false', 'f', 'n', 'nao', 'no', 'nao_retido', 'nao-retido'], true)) {
                return '1';
            }
        }

        return '1';
    }

    private function normalizeDpsAliquotaPercent(float $aliquota): float
    {
        if ($aliquota <= 0) {
            return 0.0;
        }

        // Payload interno costuma trafegar fracao (0.02 = 2%). No layout DPS nacional, pAliq eh percentual.
        if ($aliquota > 0 && $aliquota <= 1) {
            return $aliquota * 100;
        }

        return $aliquota;
    }

    private function normalizeDpsDate(string $raw): string
    {
        $value = trim($raw);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        if ($value !== '') {
            try {
                $parsed = new \DateTimeImmutable($value);
                return $parsed->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        return date('Y-m-d');
    }

    private function normalizeDpsDateTime(string $raw): string
    {
        $value = trim($raw);
        $pattern = '/^(((20(([02468][048])|([13579][26]))-02-29))|(20[0-9][0-9])-((((0[1-9])|(1[0-2]))-((0[1-9])|(1\d)|(2[0-8])))|((((0[13578])|(1[02]))-31)|(((0[1,3-9])|(1[0-2]))-(29|30)))))T(20|21|22|23|[0-1]\d):[0-5]\d:[0-5]\d([\-,\+](0[0-9]|10|11):00|([\+](12):00))$/';
        $maxAllowed = new \DateTimeImmutable('now -5 seconds', new \DateTimeZone('UTC'));

        if ($value !== '' && preg_match($pattern, $value) === 1) {
            try {
                $parsed = new \DateTimeImmutable($value);
                if ($parsed > $maxAllowed) {
                    return $maxAllowed->format('Y-m-d\TH:i:sP');
                }
            } catch (\Throwable $e) {
            }

            return $value;
        }

        if ($value !== '') {
            try {
                $parsed = new \DateTimeImmutable($value);
                if ($parsed > $maxAllowed) {
                    return $maxAllowed->format('Y-m-d\TH:i:sP');
                }

                $formatted = $parsed->format('Y-m-d\TH:i:sP');
                if (preg_match($pattern, $formatted) === 1) {
                    return $formatted;
                }
            } catch (\Throwable $e) {
            }
        }

        return $maxAllowed->format('Y-m-d\TH:i:sP');
    }

    private function getDpsNamespace(): string
    {
        return (string) ($this->config['dps_xml_namespace'] ?? 'http://www.sped.fazenda.gov.br/nfse');
    }

    private function assinarXmlSeNecessario(string $xml): string
    {
        $signatureMode = (string) ($this->config['signature_mode'] ?? 'optional');
        $this->lastSignatureApplied = false;
        if ($signatureMode === 'none') {
            return $xml;
        }

        $certificate = $this->resolveRuntimeCertificate();
        if ($certificate === null) {
            if ($signatureMode === 'required') {
                throw new \RuntimeException('Certificado digital obrigatório para assinatura XML em homologação.');
            }
            return $xml;
        }

        try {
            [$signTag, $signAttr] = $this->resolveSignatureTarget($xml);
            $signedXml = Signer::sign(
                $certificate,
                $xml,
                $signTag,
                $signAttr,
                OPENSSL_ALGO_SHA256
            );
            $this->lastSignatureApplied = $signedXml !== $xml;
            return $signedXml;
        } catch (\Throwable $e) {
            if ($signatureMode === 'required') {
                throw new \RuntimeException('Falha ao assinar XML NFSe: ' . $e->getMessage(), 0, $e);
            }
            return $xml;
        }
    }

    private function resolveRuntimeCertificate(): ?Certificate
    {
        $configured = $this->config['certificate'] ?? null;
        if ($configured instanceof Certificate) {
            return $configured;
        }

        return CertificateManager::getInstance()->getCertificate();
    }

    private function buildCertificateDebugContext(?Certificate $certificate): array
    {
        if ($certificate === null) {
            $this->lastCertificateContext = ['loaded' => false];
            return $this->lastCertificateContext;
        }

        $documento = $certificate->getCnpj() ?: $certificate->getCpf() ?: '';
        $context = [
            'loaded' => true,
            'documento' => $documento,
            'razao_social' => trim((string) $certificate->getCompanyName()),
            'valid_to' => $certificate->getValidTo()?->format('Y-m-d H:i:s'),
        ];

        $this->lastCertificateContext = $context;

        return $context;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveSignatureTarget(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return ['InfDeclaracaoPrestacaoServico', 'Id'];
        }

        $xpath = new \DOMXPath($dom);
        $legacy = $xpath->query("//*[local-name()='InfDeclaracaoPrestacaoServico']");
        if ($legacy && $legacy->length > 0) {
            return ['InfDeclaracaoPrestacaoServico', 'Id'];
        }

        $dps = $xpath->query("//*[local-name()='infDPS']");
        if ($dps && $dps->length > 0) {
            return ['infDPS', 'Id'];
        }

        return ['InfDeclaracaoPrestacaoServico', 'Id'];
    }

    public function validarDpsXml(string $xml): array
    {
        $xsdPath = $this->resolveDpsXsdPath();
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        if (!is_file($xsdPath)) {
            return [
                'ok' => false,
                'errors' => [
                    [
                        'type' => 'XSD',
                        'message' => 'Arquivo XSD da DPS nao encontrado: ' . $xsdPath,
                        'line' => null,
                        'column' => null,
                    ],
                ],
            ];
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        // Carrega XML direto da string (memória)
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            $errs = array_map(function ($e) {
                return [
                'type' => 'XML_PARSE',
                'message' => trim($e->message),
                'line' => $e->line,
                'column' => $e->column,
                ];
            }, libxml_get_errors());
            libxml_clear_errors();
            return ['ok' => false, 'errors' => $errs];
        }

        if ($dom->schemaValidate($xsdPath)) {
            libxml_clear_errors();
            return ['ok' => true, 'errors' => []];
        }

        $errs = array_map(function ($e) {
            return [
            'type' => 'XSD',
            'message' => trim($e->message),
            'line' => $e->line,
            'column' => $e->column,
            ];
        }, libxml_get_errors());

        libxml_clear_errors();

        $usedSerieValidationFallback = false;
        $validationXml = $this->normalizeDpsSerieForBundledXsdValidation($xml);
        if ($validationXml !== $xml) {
            $usedSerieValidationFallback = true;
            libxml_clear_errors();
            $validationDom = new \DOMDocument();
            $validationDom->preserveWhiteSpace = false;
            if ($validationDom->loadXML($validationXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                if ($validationDom->schemaValidate($xsdPath)) {
                    libxml_clear_errors();
                    return ['ok' => true, 'errors' => []];
                }

                $errs = array_map(function ($e) {
                    return [
                    'type' => 'XSD',
                    'message' => trim($e->message),
                    'line' => $e->line,
                    'column' => $e->column,
                    ];
                }, libxml_get_errors());

                libxml_clear_errors();
            }
        }

        if ($usedSerieValidationFallback) {
            $errs = $this->removeBundledXsdSeriePatternErrors($errs);
            if ($errs === []) {
                return ['ok' => true, 'errors' => []];
            }
        }

        return ['ok' => false, 'errors' => $errs];

    }

    private function normalizeDpsSerieForBundledXsdValidation(string $xml): string
    {
        if (version_compare($this->resolveDpsVersion(), '1.01', '<')) {
            return $xml;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            libxml_clear_errors();
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $changed = false;
        foreach ($xpath->query('//*[local-name()="serie"]') ?: [] as $node) {
            $value = trim((string) $node->textContent);
            if (preg_match('/^\^\d+\$$/', $value) === 1) {
                continue;
            }

            $digits = $this->onlyDigits($value);
            if ($digits === '' || strlen($digits) > 5) {
                continue;
            }

            $normalized = ltrim(substr($digits, 0, 5), '0');
            $node->nodeValue = '^' . ($normalized !== '' ? $normalized : '0') . '$';
            $changed = true;
        }

        return $changed ? (string) $dom->saveXML() : $xml;
    }

    /**
     * @param list<array<string,mixed>> $errors
     * @return list<array<string,mixed>>
     */
    private function removeBundledXsdSeriePatternErrors(array $errors): array
    {
        return array_values(array_filter(
            $errors,
            static function (array $error): bool {
                $message = (string)($error['message'] ?? '');

                return !(
                    str_contains($message, "}serie'")
                    && (
                        str_contains($message, "[facet 'pattern']")
                        || str_contains($message, "[facet 'maxLength']")
                    )
                );
            }
        ));
    }

    private function resolveDpsXsdPath(): string
    {
        $configured = trim((string)($this->config['dps_xsd_path'] ?? ''));
        if ($configured === '') {
            $version = $this->resolveDpsVersion();
            if (version_compare($version, '1.01', '<')) {
                return __DIR__ . '/Xsd/1.00/DPS_v1.00.xsd';
            }

            return __DIR__ . '/Xsd/1.01/DPS_v1.01.xsd';
        }

        if (str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured) === 1) {
            return $configured;
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $configured;
    }

    private function resolveDpsVersion(): string
    {
        $version = trim((string)($this->config['dps_versao'] ?? $this->config['versao'] ?? '1.01'));

        return $version !== '' ? $version : '1.01';
    }

    public function consultarContribuinteCnc(string $cnc): array
    {
        $documento = $this->onlyDigits($cnc);
        if ($documento === '') {
            throw new \InvalidArgumentException('Documento é obrigatório para consultar contribuinte CNC.');
        }

        $endpoint = (string)($this->config['cnc_endpoints']['contribuinte'] ?? '/contribuintes/{cpfCnpj}');
        $path = $this->resolveConfiguredEndpoint($endpoint, ['cpfCnpj' => $documento]);
        $response = $this->requestHttp('GET', $path, null, [
            'Accept: application/json',
        ]);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta inválida na consulta CNC do contribuinte.');
        }

        return $decoded;
    }

    public function verificarHabilitacaoCnc(string $cnc, ?string $codigoMunicipio = null): bool
    {
        $documento = $this->onlyDigits($cnc);
        if ($documento === '') {
            throw new \InvalidArgumentException('Documento é obrigatório para verificar habilitação CNC.');
        }

        $endpoint = (string)($this->config['cnc_endpoints']['habilitacao'] ?? '/contribuintes/{cpfCnpj}/habilitacao');
        $path = $this->resolveConfiguredEndpoint($endpoint, ['cpfCnpj' => $documento]);
        if ($codigoMunicipio !== null && trim($codigoMunicipio) !== '') {
            $separator = str_contains($path, '?') ? '&' : '?';
            $path .= $separator . 'codigoMunicipio=' . rawurlencode($codigoMunicipio);
        }

        $response = $this->requestHttp('GET', $path, null, [
            'Accept: application/json',
        ]);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta inválida na verificação de habilitação CNC.');
        }

        if (isset($decoded['habilitado'])) {
            return (bool)$decoded['habilitado'];
        }

        $situacao = strtoupper((string)($decoded['situacao'] ?? ''));
        return $situacao === 'HABILITADO';
    }

    /**
     * @return array{data: array, metadata: array}
     */
    private function requestCatalogEndpoint(string $key, array $params, bool $forceRefresh): array
    {
        $cacheKey = 'catalog:' . $key . ':' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $cache = new FileCacheStore($this->config['cache_dir'] ?? null);
        $ttl = (int)($this->config['cache_ttl'] ?? 86400);
        $cached = $cache->get($cacheKey, $ttl);
        if (!$forceRefresh && $cached !== null && ($cached['stale'] ?? false) === false) {
            return [
                'data' => is_array($cached['value'] ?? null) ? $cached['value'] : [],
                'metadata' => [
                    'source' => 'cache',
                    'stale' => false,
                    'cache_key' => $cacheKey,
                ],
            ];
        }

        $endpoint = (string)$this->config['catalog_endpoints'][$key];
        $path = $this->resolveConfiguredEndpoint($endpoint, $params);
        $response = $this->requestHttp('GET', $path, null, [
            'Accept: application/json',
        ]);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Resposta inválida do catálogo nacional para '{$key}'.");
        }

        $data = $decoded['data'] ?? $decoded;
        if (!is_array($data)) {
            $data = [];
        }
        $cache->put($cacheKey, $data);

        return [
            'data' => $data,
            'metadata' => [
                'source' => 'remote',
                'stale' => false,
                'cache_key' => $cacheKey,
            ],
        ];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLastResponseData(): array
    {
        return $this->lastResponseData;
    }

    public function getLastOperationArtifacts(): array
    {
        return $this->lastOperationArtifacts;
    }

    public function getSupportedOperations(): array
    {
        return [
            'emitir',
            'consultar',
            'consultar_por_rps',
            'consultar_rps',
            'consultar_lote',
            'cancelar',
            'consultar_dps',
            'baixar_xml',
            'baixar_danfse',
        ];
    }

    private function normalizeConsultaResult(string $operation, array $parsedResponse, array $context = []): NFSeConsultaResultInterface
    {
        return (new NFSeResultNormalizer())->normalizeConsulta(
            $operation,
            $parsedResponse,
            $this->lastOperationArtifacts,
            $context + [
                'provider_class' => static::class,
            ]
        );
    }

    private function normalizePrintResult(array $parsedResponse, array $context = []): NFSeImpressaoResultInterface
    {
        $normalizer = new NFSeResultNormalizer();
        $filename = $context['filename'] ?? null;
        $provider = [
            'provider_class' => static::class,
        ];
        $raw = [
            'parsed_response' => $parsedResponse,
            'request_payload' => null,
            'request_xml' => $this->lastOperationArtifacts['request_xml'] ?? null,
            'response_body' => $this->lastOperationArtifacts['response_raw'] ?? null,
            'response_xml' => $this->lastOperationArtifacts['response_xml'] ?? ($parsedResponse['raw_xml'] ?? null),
        ];

        if (!empty($parsedResponse['pdf_base64'])) {
            return $normalizer->normalizePdfBase64((string) $parsedResponse['pdf_base64'], [
                'provider_class' => static::class,
                'filename' => $filename,
                'source' => $context['print_source'] ?? 'download_danfse',
            ], $raw);
        }

        
        if (!empty($parsedResponse['dados']['pdf_base64'])) {
            return $normalizer->normalizePdfBase64((string) $parsedResponse['dados']['pdf_base64'], [
                'provider_class' => static::class,
                'filename' => $filename,
                'source' => $context['print_source'] ?? 'download_danfse',
            ], $raw);
        }


        if (!empty($parsedResponse['nfse_url'])) {
            return $normalizer->normalizeUrl((string) $parsedResponse['nfse_url'], [
                'provider_class' => static::class,
                'filename' => $filename,
                'source' => $context['print_source'] ?? 'official_url',
            ], $raw);
        }

        return $normalizer->normalizeIndisponivel([
            'provider_class' => static::class,
            'filename' => $filename,
            'source' => $context['print_source'] ?? 'download_danfse',
        ], $raw);
    }

    private function storeOperationState(
        string $operation,
        ?string $requestXml,
        string $responseRaw,
        array $parsedResponse,
        array $extra = []
    ): void {
        $this->lastOperation = $operation;
        $this->lastResponseData = $parsedResponse;
        $this->lastOperationArtifacts = [
            'operation' => $operation,
            'request_xml' => $requestXml,
            'response_xml' => $this->looksLikeXml($responseRaw) ? $responseRaw : null,
            'response_raw' => $responseRaw,
            'parsed_response' => $parsedResponse,
            'transport' => array_merge([
                'mode' => 'rest',
                'service' => $this->resolveOperationServiceName($operation),
            ], $extra),
        ];
    }

    private function resolveOperationServiceName(string $operation): ?string
    {
        $endpoint = (string) ($this->config['endpoints'][$operation] ?? '');
        if (preg_match('/^([a-z0-9_]+):/i', $endpoint, $matches) === 1) {
            return strtolower((string) $matches[1]);
        }

        return null;
    }

    private function looksLikeXml(string $payload): bool
    {
        $payload = trim($payload);

        return $payload !== '' && str_starts_with($payload, '<');
    }

    private function extractProcessingMessages(array $json): array
    {
        $messages = array_map(
            fn (array $error): string => $this->formatProcessingErrorMessage($error),
            $this->extractProcessingErrorDetails($json)
        );

        return array_values(array_filter(array_unique($messages), static fn (string $message): bool => trim($message) !== ''));
    }

    /**
     * @return array<int,array{code?:string,description?:string,message?:string}>
     */
    private function extractProcessingErrorDetails(array $json): array
    {
        $errors = [];

        foreach (['erros', 'alertas', 'errors'] as $listKey) {
            $items = $json[$listKey] ?? null;
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $description = trim((string) ($item['descricao'] ?? $item['Descricao'] ?? $item['mensagem'] ?? $item['Mensagem'] ?? $item['message'] ?? ''));
                $code = trim((string) ($item['codigo'] ?? $item['Codigo'] ?? $item['code'] ?? ''));
                if ($description === '' && $code === '') {
                    continue;
                }

                $errors[] = array_filter([
                    'code' => $code !== '' ? $code : null,
                    'description' => $description !== '' ? $description : null,
                    'message' => $description !== '' ? $description : null,
                ], static fn ($value): bool => is_string($value) && trim($value) !== '');
            }
        }

        return array_values($errors);
    }

    /**
     * @param array{code?:string,description?:string,message?:string} $error
     */
    private function formatProcessingErrorMessage(array $error): string
    {
        $code = trim((string) ($error['code'] ?? ''));
        $description = trim((string) ($error['description'] ?? $error['message'] ?? ''));

        if ($code !== '' && $description !== '') {
            return "{$code}: {$description}";
        }

        return $description !== '' ? $description : $code;
    }

    private function formatHttpErrorResponseSnippet(string $response): string
    {
        $response = trim($response);
        if ($response === '') {
            return '';
        }

        $json = json_decode($response, true);
        if (is_array($json)) {
            $errors = $this->extractProcessingErrorDetails($json);
            if ($errors !== []) {
                return implode(' | ', array_map(
                    fn (array $error): string => $this->formatProcessingErrorMessage($error),
                    $errors
                ));
            }

            foreach (['mensagem', 'message'] as $key) {
                $message = trim((string) ($json[$key] ?? ''));
                if ($message !== '') {
                    return $message;
                }
            }
        }

        $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags($response)) ?? '');
        if ($plainText !== '') {
            return $this->truncate($plainText, 600);
        }

        return $this->truncate($response, 600);
    }

    /**
     * @return array{
     *   status:?int,
     *   request_id:?string,
     *   path:?string,
     *   errors:array<int,array{code?:string,description?:string,message?:string}>,
     *   messages:array<int,string>
     * }
     */
    private function parseTransportErrorDetails(string $message): array
    {
        $status = null;
        $requestId = null;
        $path = null;
        $responseText = trim($message);

        if (preg_match('/HTTP\s+(\d{3})\s+na operação\s+(.+?)(?:\s+\[req:([^\]]+)\])?(?:\s+\|\s+resposta:\s*(.+))?$/u', $message, $matches) === 1) {
            $status = isset($matches[1]) ? (int) $matches[1] : null;
            $path = isset($matches[2]) ? trim((string) $matches[2]) : null;
            $requestId = isset($matches[3]) ? trim((string) $matches[3]) : null;
            $responseText = isset($matches[4]) ? trim((string) $matches[4]) : '';
        }

        $errors = $this->extractTransportErrorEntries($responseText);
        $messages = array_map(
            fn (array $error): string => $this->formatProcessingErrorMessage($error),
            $errors
        );

        if ($messages === [] && $responseText !== '') {
            $messages[] = $responseText;
        }

        return [
            'status' => $status,
            'request_id' => $requestId !== '' ? $requestId : null,
            'path' => $path !== '' ? $path : null,
            'errors' => $errors,
            'messages' => array_values(array_filter($messages, static fn (string $value): bool => trim($value) !== '')),
        ];
    }

    /**
     * @return array<int,array{code?:string,description?:string,message?:string}>
     */
    private function extractTransportErrorEntries(string $responseText): array
    {
        $responseText = trim($responseText);
        if ($responseText === '') {
            return [];
        }

        $json = json_decode($responseText, true);
        if (is_array($json)) {
            return $this->extractProcessingErrorDetails($json);
        }

        $errors = [];
        foreach (preg_split('/\s+\|\s+/u', $responseText) ?: [] as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^([A-Z]{1,6}\d{2,}|\d{3,}|[A-Z0-9_]+)\s*[:\-]\s*(.+)$/u', $segment, $matches) === 1) {
                $errors[] = [
                    'code' => trim((string) $matches[1]),
                    'description' => trim((string) $matches[2]),
                    'message' => trim((string) $matches[2]),
                ];
                continue;
            }

            $errors[] = ['description' => $segment, 'message' => $segment];
        }

        return $errors;
    }

    private function extractNfseSummary(?string $nfseXml, ?string $chaveAcesso = null): ?array
    {
        if ($nfseXml === null || trim($nfseXml) === '') {
            return $chaveAcesso !== null && trim($chaveAcesso) !== ''
                ? ['chave_acesso' => trim($chaveAcesso)]
                : null;
        }

        return [
            'numero' => $this->extractNodeValueFromXml($nfseXml, 'Numero'),
            'codigo_verificacao' => $this->extractNodeValueFromXml($nfseXml, 'CodigoVerificacao'),
            'data_emissao' => $this->extractNodeValueFromXml($nfseXml, 'DataEmissao'),
            'chave_acesso' => $chaveAcesso !== null && trim($chaveAcesso) !== '' ? trim($chaveAcesso) : null,
        ];
    }

    private function extractNodeValueFromXml(string $xml, string $localName): ?string
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

    private function normalizeMunicipalRegistration(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $digits = $this->onlyDigits($trimmed);
        if ($digits !== '' && $digits === $trimmed) {
            return str_pad(substr($digits, 0, 15), 15, '0', STR_PAD_LEFT);
        }

        return $trimmed;
    }
}
