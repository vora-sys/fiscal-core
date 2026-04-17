<?php

namespace sabbajohn\FiscalCore\Providers\NFSe;

use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Services\NFSe\NacionalCatalogService;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;
use sabbajohn\FiscalCore\Support\CertificateManager;
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
        $this->validarDados($dados);
        $this->validarDadosDpsNacional($dados);
        // $this->assertCatalogCompatibilityBeforeEmission($dados);
        $xml = $this->montarXmlDpsNacional($dados);
        $xml = $this->assinarXmlSeNecessario($xml);
        $xml = $this->ensureUtf8XmlForTransmission($xml);
        $response = $this->enviarOperacao('emitir', $xml);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('emitir', $xml, $response, $parsed);

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

    public function consultar(string $chave): string
    {
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave da NFSe é obrigatória');
        }

        // $xml = $this->buildConsultaXml($chave);
        $response = $this->enviarOperacao('consultar', null, ['id' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar', null, $response, $parsed, ['chave_acesso' => $chave]);

        return json_encode($parsed);
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if ($chave === '' || $motivo === '') {
            throw new \InvalidArgumentException('Chave e motivo são obrigatórios para cancelamento');
        }

        $xml = $this->buildCancelamentoXml($chave, $motivo, $protocolo);
        $response = $this->enviarOperacao('cancelar', $xml, ['id' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('cancelar', $xml, $response, $parsed, [
            'chave_acesso' => $chave,
            'motivo' => $motivo,
            'protocolo' => $protocolo,
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

    public function consultarPorRps(array $identificacaoRps): string
    {
        foreach (['numero', 'serie', 'tipo', 'id'] as $campo) {
            if (!isset($identificacaoRps[$campo])) {
                throw new \InvalidArgumentException("Identificação RPS inválida: campo {$campo} é obrigatório");
            }
        }

        $id = trim((string) ($identificacaoRps['id'] ?? ''));
        if (preg_match('/^DPS\d{42}$/', $id) === 1) {
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

            return $response;
        }

        $xml = $this->buildConsultaRpsXml($identificacaoRps);
        $response = $this->enviarOperacao('consultar_rps', $xml);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar_rps', $xml, $response, $parsed, ['rps' => $identificacaoRps]);

        return $response;
    }

    public function consultarLote(string $protocolo): string
    {
        if ($protocolo === '') {
            throw new \InvalidArgumentException('Protocolo do lote é obrigatório');
        }

        $xml = $this->buildConsultaLoteXml($protocolo);
        $response = $this->enviarOperacao('consultar_lote', $xml);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('consultar_lote', $xml, $response, $parsed, ['protocolo' => $protocolo]);

        return $response;
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

    public function baixarDanfse(string $chave): string
    {
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave é obrigatória');
        }

        $xml = $this->buildDownloadXmlPayload('danfse', $chave);
        $response = $this->enviarOperacao('baixar_danfse', $xml, ['chave' => $chave]);
        $parsed = $this->processarResposta($response);
        $this->storeOperationState('baixar_danfse', $xml, $response, $parsed, ['chave_acesso' => $chave]);

        return json_encode($parsed);
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
            'valores.trib.tribMun.tribISSQN' => (string)($payload['servico']['tribISSQN'] ?? ''),
            'valores.trib.tribMun.tpRetISSQN' => (string)($payload['servico']['tpRetISSQN'] ?? ''),
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

        $aliqPayload = (float)($payload['servico']['aliquota'] ?? 0);
        $tribIssqnPayload = (string)($payload['servico']['tribISSQN'] ?? '');
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
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $ns = $this->getDpsNamespace();

        $serie = $this->normalizeNumeric((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'), 5, '1');
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

        $versao = (string)($this->config['dps_versao'] ?? '1.00');
        $dpsId = $this->buildDpsId($dados, $serie, $nDpsId);
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

        $prest = $dom->createElementNS($ns, 'prest');
        $inf->appendChild($prest);
        $prestDoc = $this->onlyDigits((string)($dados['prestador']['cnpj'] ?? ''));
        if (strlen($prestDoc) === 14) {
            $this->appendNodeDps($dom, $prest, 'CNPJ', $prestDoc);
        } else {
            $this->appendNodeDps($dom, $prest, 'CPF', str_pad(substr($prestDoc, 0, 11), 11, '0', STR_PAD_LEFT));
        }
        $prestIm = $this->normalizeMunicipalRegistration((string)($dados['prestador']['inscricaoMunicipal'] ?? ''));
        if ($prestIm !== '') {
            $this->appendNodeDps($dom, $prest, 'IM', $prestIm);
        }
        $prestNome = trim((string)($dados['prestador']['razaoSocial'] ?? ''));
        if ($prestNome !== '' && $tpEmit !== '1') {
            $this->appendNodeDps($dom, $prest, 'xNome', $prestNome);
        }

        $regTrib = $dom->createElementNS($ns, 'regTrib');
        $prest->appendChild($regTrib);
        $this->appendNodeDps($dom, $regTrib, 'opSimpNac', (string)($dados['prestador']['opSimpNac'] ?? '1'));
        $this->appendNodeDps($dom, $regTrib, 'regEspTrib', (string)($dados['prestador']['regEspTrib'] ?? '0'));

        $tomadorDoc = $this->onlyDigits((string)($dados['tomador']['documento'] ?? ''));
        $tomadorNome = trim((string)($dados['tomador']['razaoSocial'] ?? $dados['tomador']['nome'] ?? ''));
        if ($tomadorDoc !== '' && $tomadorNome !== '') {
            $toma = $dom->createElementNS($ns, 'toma');
            $inf->appendChild($toma);
            if (strlen($tomadorDoc) === 14) {
                $this->appendNodeDps($dom, $toma, 'CNPJ', $tomadorDoc);
            } else {
                $this->appendNodeDps($dom, $toma, 'CPF', str_pad(substr($tomadorDoc, 0, 11), 11, '0', STR_PAD_LEFT));
            }
            $this->appendNodeDps($dom, $toma, 'xNome', $tomadorNome);
        }

        $serv = $dom->createElementNS($ns, 'serv');
        $inf->appendChild($serv);

        $locPrest = $dom->createElementNS($ns, 'locPrest');
        $serv->appendChild($locPrest);
        $cLocPrestInput = (string)($dados['servico']['cLocPrestacao'] ?? $dados['servico']['codigo_municipio'] ?? $cLocEmi);
        $cLocPrest = str_pad(substr($this->onlyDigits($cLocPrestInput), 0, 7), 7, '0', STR_PAD_LEFT);
        $this->appendNodeDps($dom, $locPrest, 'cLocPrestacao', $cLocPrest);

        $cServ = $dom->createElementNS($ns, 'cServ');
        $serv->appendChild($cServ);
        $cTribNac = $this->normalizeCTribNac((string)($dados['servico']['cTribNac'] ?? $dados['servico']['codigoServicoNacional'] ?? $dados['servico']['codigo'] ?? ''));
        if ($cTribNac === '') {
            $cTribNac = '010101';
        }
        $cTribMun = trim((string)($dados['servico']['cTribMun'] ?? $dados['servico']['codigoMunicipal'] ?? ''));
        $cNbs = preg_replace('/\D+/', '', (string)($dados['servico']['cNBS'] ?? $dados['servico']['nbs'] ?? '')) ?? '';
        $this->appendNodeDps($dom, $cServ, 'cTribNac', $cTribNac);
        if ($cTribMun !== '') {
            $this->appendNodeDps($dom, $cServ, 'cTribMun', $cTribMun);
        }
        $this->appendNodeDps($dom, $cServ, 'xDescServ', (string)($dados['servico']['descricao'] ?? $dados['servico']['discriminacao'] ?? 'Servico'));
        if ($cNbs !== '') {
            $this->appendNodeDps($dom, $cServ, 'cNBS', $cNbs);
        }
        $this->appendObraGroup($dom, $serv, (array) ($dados['servico']['obra'] ?? []));

        $valores = $dom->createElementNS($ns, 'valores');
        $inf->appendChild($valores);

        $vServPrest = $dom->createElementNS($ns, 'vServPrest');
        $valores->appendChild($vServPrest);
        $valorServicos = (float)($dados['valor_servicos'] ?? 0);
        $this->appendNodeDps($dom, $vServPrest, 'vServ', $this->formatDecimal($valorServicos, 2));

        $trib = $dom->createElementNS($ns, 'trib');
        $valores->appendChild($trib);
        $tribMun = $dom->createElementNS($ns, 'tribMun');
        $trib->appendChild($tribMun);
        $tribIssqn = (string)($dados['servico']['tribISSQN'] ?? '1');
        $this->appendNodeDps($dom, $tribMun, 'tribISSQN', $tribIssqn);
        $aliquota = $this->normalizeDpsAliquotaPercent((float)($dados['servico']['aliquota'] ?? 0));
        $sendPAliq = (bool)($this->config['dps_send_paliq'] ?? ($tribIssqn === '1'));
        if (array_key_exists('enviarPAliq', (array)($dados['servico'] ?? []))) {
            $sendPAliq = (bool)$dados['servico']['enviarPAliq'];
        }
        if ($sendPAliq && $aliquota > 0) {
            $this->appendNodeDps($dom, $tribMun, 'pAliq', $this->formatDecimal($aliquota, 2));
        }
        $this->appendNodeDps($dom, $tribMun, 'tpRetISSQN', (string)($dados['servico']['tpRetISSQN'] ?? '1'));

        $totTrib = $dom->createElementNS($ns, 'totTrib');
        $trib->appendChild($totTrib);
        $vTotTrib = $dom->createElementNS($ns, 'vTotTrib');
        $totTrib->appendChild($vTotTrib);
        $this->appendNodeDps($dom, $vTotTrib, 'vTotTribFed', '0.00');
        $this->appendNodeDps($dom, $vTotTrib, 'vTotTribEst', '0.00');
        $this->appendNodeDps($dom, $vTotTrib, 'vTotTribMun', '0.00');

        return $dom->saveXML() ?: '';
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
        parent::validarDados($dados);

        if (empty($dados['prestador']['cnpj']) || strlen($this->onlyDigits((string) $dados['prestador']['cnpj'])) !== 14) {
            throw new \InvalidArgumentException('CNPJ do prestador inválido');
        }

        if (trim((string) ($dados['prestador']['inscricaoMunicipal'] ?? '')) === '') {
            throw new \InvalidArgumentException('Campo obrigatório ausente: prestador.inscricaoMunicipal');
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
        if ($desc === '' || strlen($desc) > 1000) {
            $errors[] = 'xDescServ é obrigatório.';
        }

        $valorServ = (float)($dados['valor_servicos'] ?? 0);
        if ($valorServ <= 0) {
            $errors[] = 'vServ deve ser maior que zero.';
        }

        $tribIssqn = (string)($dados['servico']['tribISSQN'] ?? '1');
        if (!in_array($tribIssqn, ['1', '2', '3', '4'], true)) {
            $errors[] = 'tribISSQN deve ser 1, 2, 3 ou 4.';
        }

        $tpRetIssqn = (string)($dados['servico']['tpRetISSQN'] ?? '1');
        if (!in_array($tpRetIssqn, ['1', '2', '3'], true)) {
            $errors[] = 'tpRetISSQN deve ser 1, 2 ou 3.';
        }
        if (in_array($tribIssqn, ['2', '3', '4'], true) && $tpRetIssqn !== '1') {
            $errors[] = 'tpRetISSQN deve ser 1 quando tribISSQN for 2, 3 ou 4.';
        }

        $aliquota = (float)($dados['servico']['aliquota'] ?? 0);
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

        return preg_match('/^sefin:\//i', $rawEndpoint) === 1 || $operacao === 'emitir';
    }

    private function getTransport(string $operacao, string $rawEndpoint): string
    {
        $transport = strtolower((string)($this->config['operation_transports'][$operacao] ?? ''));
        if ($transport !== '') {
            return $transport;
        }

        return preg_match('/^sefin:\//i', $rawEndpoint) === 1 || $operacao === 'emitir' ? 'json' : 'pdf';
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
                $snippet = substr(trim((string)$response), 0, 300);
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
        $cLoc = str_pad(substr($this->onlyDigits((string)($dados['prestador']['codigoMunicipio'] ?? $this->getCodigoMunicipio())), 0, 7), 7, '0', STR_PAD_LEFT);
        $doc = $this->onlyDigits((string)($dados['prestador']['cnpj'] ?? ''));
        $tpInsc = strlen($doc) === 14 ? '2' : '1';
        $insc = strlen($doc) === 14
            ? $doc
            : str_pad(substr($doc, 0, 11), 14, '0', STR_PAD_LEFT);
        $serie = $serie !== null
            ? $this->normalizeNumeric($serie, 5, '1')
            : $this->normalizeNumeric((string)($dados['serie'] ?? $dados['serie_rps'] ?? '1'), 5, '1');
        $numero = $nDps !== null
            ? $this->normalizeNumeric($nDps, 15, '1')
            : $this->normalizeNumeric((string)($dados['nDPS'] ?? $dados['numero_rps'] ?? '1'), 15, '1');

        return 'DPS' . $cLoc . $tpInsc . $insc . $serie . $numero;
    }

    private function normalizeNumeric(string $value, int $length, string $default): string
    {
        $digits = $this->onlyDigits($value);
        if ($digits === '') {
            $digits = $default;
        }
        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
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
        $xsdPath = (string)($this->config['dps_xsd_path'] ?? (__DIR__ . '/Xsd/DPS_v1.00.xsd'));
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

        $ok = $dom->schemaValidate($xsdPath);

        if ($ok) {
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
        return ['ok' => false, 'errors' => $errs];

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
            'cancelar',
            'consultar_dps',
            'baixar_xml',
            'baixar_danfse',
        ];
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
            'response_xml' => $parsedResponse['raw_xml'] ?? null,
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

    private function extractProcessingMessages(array $json): array
    {
        $messages = [];
        foreach (['erros', 'alertas'] as $listKey) {
            $items = $json[$listKey] ?? null;
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $message = trim((string) ($item['descricao'] ?? $item['mensagem'] ?? $item['Mensagem'] ?? ''));
                $code = trim((string) ($item['codigo'] ?? $item['Codigo'] ?? ''));
                if ($message === '' && $code === '') {
                    continue;
                }

                $messages[] = $code !== '' && $message !== '' ? "{$code}: {$message}" : ($message !== '' ? $message : $code);
            }
        }

        return array_values(array_filter(array_unique($messages)));
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
