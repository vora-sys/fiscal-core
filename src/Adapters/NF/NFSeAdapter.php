<?php

namespace sabbajohn\FiscalCore\Adapters\NF;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;
use sabbajohn\FiscalCore\Contracts\NotaServicoInterface;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Support\NFSeEmissionRoutingPolicy;
use sabbajohn\FiscalCore\Support\NFSeFormPolicy;
use sabbajohn\FiscalCore\Support\NFSeProviderTranslationPolicy;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use NFePHP\Common\Certificate;

class NFSeAdapter implements NotaServicoInterface
{
    private NFSeProviderConfigInterface $provider;
    private string $municipio;
    private string $providerKey;
    private array $compatMetadata;
    private bool $injectedProvider;
    private array $lastEmissionInfo = [];
    private array $lastOperationInfo = [];
    private NFSeEmissionRoutingPolicy $routingPolicy;

    public function __construct(string $municipio, ?NFSeProviderConfigInterface $provider = null)
    {
        $this->municipio = $municipio;
        $this->routingPolicy = new NFSeEmissionRoutingPolicy();

        $resolver = new NFSeProviderResolver();
        $this->providerKey = $resolver->resolveKey($municipio);
        $this->compatMetadata = $resolver->buildMetadata($municipio);

        if ($provider !== null) {
            $this->provider = $provider;
            $this->injectedProvider = true;
            return;
        }

        $registry = ProviderRegistry::getInstance();
        $this->provider = $registry->getByMunicipio($municipio);
        $this->injectedProvider = false;
    }

    public function emitir(array $dados): string
    {
        [$providerKey, $provider, $routingMode] = $this->resolveProviderForEmission($dados);

        try {
            $result = $provider->emitir($dados);
        } catch (\Throwable $e) {
            $info = [
                'effective_provider_key' => $providerKey,
                'effective_provider_class' => get_class($provider),
                'routing_mode' => $routingMode,
                'parsed_response' => $provider instanceof NFSeOperationalIntrospectionInterface
                    ? $provider->getLastResponseData()
                    : null,
                'artifacts' => $provider instanceof NFSeOperationalIntrospectionInterface
                    ? $provider->getLastOperationArtifacts()
                    : null,
                'exception' => [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                ],
            ];
            $this->lastEmissionInfo = $info;
            $this->lastOperationInfo = ['operation' => 'emitir'] + $info;

            throw $e;
        }

        $info = [
            'effective_provider_key' => $providerKey,
            'effective_provider_class' => get_class($provider),
            'routing_mode' => $routingMode,
            'parsed_response' => $provider instanceof NFSeOperationalIntrospectionInterface
                ? $provider->getLastResponseData()
                : null,
            'artifacts' => $provider instanceof NFSeOperationalIntrospectionInterface
                ? $provider->getLastOperationArtifacts()
                : null,
        ];
        $this->lastEmissionInfo = $info;
        $this->lastOperationInfo = ['operation' => 'emitir'] + $info;

        return $result;
    }

    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        $result = $this->provider->consultar($chave);
        $this->lastOperationInfo = $this->buildProviderOperationInfo('consultar', $this->provider, [
            'chave' => $chave,
            'result' => $result,
        ]);

        return $result;
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        $result = $this->provider->cancelar($chave, $motivo, $protocolo);
        $this->lastOperationInfo = $this->buildProviderOperationInfo('cancelar', $this->provider, [
            'chave' => $chave,
            'motivo' => $motivo,
            'protocolo' => $protocolo,
            'resultado' => $result,
        ]);

        return $result;
    }

    public function substituir(string $chave, array $dados): string
    {
        $result = $this->provider->substituir($chave, $dados);
        $this->lastOperationInfo = $this->buildProviderOperationInfo('substituir', $this->provider, [
            'chave' => $chave,
            'resultado' => $result,
        ]);

        return $result;
    }

    public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
    {
        if ($this->provider instanceof NFSeNacionalCapabilitiesInterface) {
            $result = $this->provider->consultarPorRps($identificacaoRps);
        } elseif (method_exists($this->provider, 'consultarPorRps')) {
            /** @var callable $callable */
            $callable = [$this->provider, 'consultarPorRps'];
            $result = $callable($identificacaoRps);
        } else {
            return $this->requireNacionalCapabilities()->consultarPorRps($identificacaoRps);
        }

        $this->lastOperationInfo = $this->buildProviderOperationInfo('consultar_por_rps', $this->provider, [
            'identificacao_rps' => $identificacaoRps,
            'result' => $result,
        ]);

        return $result;
    }

    public function consultarLote(string $protocolo): NFSeConsultaResultInterface
    {
        if ($this->provider instanceof NFSeNacionalCapabilitiesInterface) {
            $result = $this->provider->consultarLote($protocolo);
        } elseif (method_exists($this->provider, 'consultarLote')) {
            /** @var callable $callable */
            $callable = [$this->provider, 'consultarLote'];
            $result = $callable($protocolo);
        } else {
            return $this->requireNacionalCapabilities()->consultarLote($protocolo);
        }

        $this->lastOperationInfo = $this->buildProviderOperationInfo('consultar_lote', $this->provider, [
            'protocolo' => $protocolo,
            'result' => $result,
        ]);

        return $result;
    }

    public function baixarXml(string $chave): string
    {
        return $this->requireNacionalCapabilities()->baixarXml($chave);
    }

    public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
    {
        if (method_exists($this->provider, 'baixarDanfse')) {
            /** @var callable $callable */
            $callable = [$this->provider, 'baixarDanfse'];
            $result = $callable($chave);
            $this->lastOperationInfo = $this->buildProviderOperationInfo('baixar_danfse', $this->provider, [
                'chave' => $chave,
                'result' => $result,
            ]);

            return $result;
        }

        return $this->requireNacionalCapabilities()->baixarDanfse($chave);
    }

    public function listarMunicipiosNacionais(bool $forceRefresh = false): array
    {
        return $this->requireNacionalCapabilities()->listarMunicipiosNacionais($forceRefresh);
    }

    public function consultarAliquotasMunicipio(
        string $codigoMunicipio,
        ?string $codigoServico = null,
        ?string $competencia = null,
        bool $forceRefresh = false
    ): array {
        return $this->requireNacionalCapabilities()->consultarAliquotasMunicipio(
            $codigoMunicipio,
            $codigoServico,
            $competencia,
            $forceRefresh
        );
    }

    public function consultarContribuinteCnc(string $cnc): array
    {
        return $this->provider->consultarContribuinteCnc($cnc);
    }

    public function verificarHabilitacaoCnc(string $cnc): bool
    {
        return $this->provider->verificarHabilitacaoCnc($cnc);
    }

    public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
    {
        return $this->requireNacionalCapabilities()->consultarConvenioMunicipio($codigoMunicipio, $forceRefresh);
    }

    public function validarLayoutDps(array $payload, bool $checkCatalog = true): array
    {
        return $this->requireNacionalCapabilities()->validarLayoutDps($payload, $checkCatalog);
    }

    public function gerarXmlDpsPreview(array $payload): ?string
    {
        return $this->requireNacionalCapabilities()->gerarXmlDpsPreview($payload);
    }

    public function gerarXmlEnvioPreview(array $payload): ?string
    {
        [$providerKey, $provider, $routingMode] = $this->resolveProviderForEmission($payload);

        if ($provider instanceof NFSeNacionalCapabilitiesInterface) {
            $xml = $provider->gerarXmlDpsPreview($payload);
        } elseif (method_exists($provider, 'gerarXmlEnvioPreview')) {
            /** @var callable $callable */
            $callable = [$provider, 'gerarXmlEnvioPreview'];
            $xml = $callable($payload);
        } else {
            throw new \RuntimeException(
                "Provider '{$providerKey}' não suporta preview do XML de envio."
            );
        }

        $this->lastOperationInfo = [
            'operation' => 'preview_xml_envio',
            'effective_provider_key' => $providerKey,
            'effective_provider_class' => get_class($provider),
            'routing_mode' => $routingMode,
            'artifacts' => [
                'request_xml_preview' => $xml,
            ],
        ];

        return $xml;
    }

    public function validarXmlDps(array $payload): array
    {
        return $this->requireNacionalCapabilities()->validarXmlDps($payload);
    }

    public function getMunicipio(): string
    {
        return $this->municipio;
    }

    public function getProviderInfo(): array
    {
        $routingRules = [];
        $supportedOperations = [];
        if ($this->providerKey !== ProviderRegistry::NFSE_NATIONAL_KEY) {
            $routingRules[] = 'Emitente MEI é roteado automaticamente para NFSe nacional';
        }
        if ($this->routingPolicy->requiresExplicitMeiClassification($this->provider)) {
            $routingRules[] = 'Este provider exige classificação explícita de MEI no payload de emissão';
        }
        if ($this->provider instanceof NFSeOperationalIntrospectionInterface) {
            $supportedOperations = $this->normalizeSupportedOperations($this->provider->getSupportedOperations());
        } elseif ($this->provider instanceof NFSeNacionalCapabilitiesInterface) {
            $supportedOperations = [
                'emitir',
                'consultar',
                'cancelar',
                'consultar_por_rps',
                'consultar_lote',
                'baixar_xml',
                'baixar_danfse',
            ];
        }

        return [
            'municipio' => $this->municipio,
            'provider_key' => $this->providerKey,
            'provider_class' => get_class($this->provider),
            'supports_nacional' => $this->provider instanceof NFSeNacionalCapabilitiesInterface,
            'municipio_ignored' => $this->compatMetadata['municipio_ignored'] ?? false,
            'warnings' => $this->compatMetadata['warnings'] ?? [],
            'codigo_municipio' => $this->provider->getCodigoMunicipio(),
            'versao' => $this->provider->getVersao(),
            'ambiente' => $this->provider->getAmbiente(),
            'wsdl_url' => $this->provider->getWsdlUrl(),
            'api_base_url' => $this->provider->getNationalApiBaseUrl(),
            'timeout' => $this->provider->getTimeout(),
            'aliquota_format' => $this->provider->getAliquotaFormat(),
            'form_policy' => $this->buildFormPolicy(),
            'translation_policy' => $this->buildTranslationPolicy(),
            'routing_rules' => $routingRules,
            'supported_operations' => $supportedOperations,
            'certificate_loaded' => (($this->provider->getConfig()['certificate'] ?? null) instanceof Certificate),
            'prestador_runtime' => is_array($this->provider->getConfig()['prestador'] ?? null)
                ? $this->provider->getConfig()['prestador']
                : null,
        ];
    }

    /** @return list<string> */
    private function normalizeSupportedOperations(array $operations): array
    {
        $aliases = [
            'cancelar_nfse' => 'cancelar',
            'substituir_nfse' => 'substituir',
            'consultar_nfse_numero' => 'consultar',
            'consultar_nfse_rps' => 'consultar_por_rps',
        ];
        return array_values(array_unique(array_map(
            static fn (mixed $operation): string => $aliases[(string) $operation] ?? (string) $operation,
            $operations,
        )));
    }

    public function getLastEmissionInfo(): array
    {
        return $this->lastEmissionInfo;
    }

    public function getLastOperationInfo(): array
    {
        return $this->lastOperationInfo;
    }

    private function buildFormPolicy(): array
    {
        $config = $this->provider->getConfig();
        $layoutFamily = (string) ($config['layout_family'] ?? '');
        $municipioIbge = (string) ($this->provider->getCodigoMunicipio() ?: ($config['codigo_municipio'] ?? ''));
        $municipioNome = (string) ($config['municipio_nome'] ?? $this->municipio);

        return (new NFSeFormPolicy())->build(
            $this->providerKey,
            $layoutFamily,
            $municipioIbge,
            $municipioNome,
            $config
        );
    }

    private function buildTranslationPolicy(): array
    {
        $config = $this->provider->getConfig();

        return NFSeProviderTranslationPolicy::fromProviderContext(
            $this->providerKey,
            (string) ($config['layout_family'] ?? ''),
            $config
        )->toArray();
    }

    private function requireNacionalCapabilities(): NFSeNacionalCapabilitiesInterface
    {
        if (!$this->provider instanceof NFSeNacionalCapabilitiesInterface) {
            throw new \RuntimeException(
                "Provider '{$this->municipio}' não suporta capacidades avançadas da NFSe Nacional"
            );
        }

        return $this->provider;
    }

    private function buildProviderOperationInfo(
        string $operation,
        NFSeProviderConfigInterface $provider,
        array $context = []
    ): array {
        $info = [
            'operation' => $operation,
            'provider_key' => $this->providerKey,
            'provider_class' => get_class($provider),
            'parsed_response' => $provider instanceof NFSeOperationalIntrospectionInterface
                ? $provider->getLastResponseData()
                : null,
        ] + $context;

        foreach ($context as $value) {
            if ($value instanceof NFSeConsultaResultInterface || $value instanceof NFSeImpressaoResultInterface) {
                $info['parsed_response'] = $value->getRaw()['parsed_response'] ?? null;
                break;
            }
        }

        if ($provider instanceof NFSeOperationalIntrospectionInterface) {
            $info['artifacts'] = $provider->getLastOperationArtifacts();
        }

        $parsedResponse = is_array($info['parsed_response'] ?? null) ? $info['parsed_response'] : null;
        if ($parsedResponse !== null) {
            $info['normalized_result'] = (new NFSeResultNormalizer())->normalizeOperacao(
                $operation,
                $parsedResponse,
                is_array($info['artifacts'] ?? null) ? $info['artifacts'] : [],
                [
                    'provider_key' => $this->providerKey,
                    'provider_class' => get_class($provider),
                    'municipio' => $this->municipio,
                    'source' => $operation,
                    'chave_consulta' => $context['chave'] ?? null,
                ]
            );
        }

        return $info;
    }

    /**
     * @return array{0:string,1:NFSeProviderConfigInterface,2:string}
     */
    private function resolveProviderForEmission(array $dados): array
    {
        return $this->routingPolicy->resolve($this->providerKey, $this->provider, $dados, $this->injectedProvider);
    }
}
