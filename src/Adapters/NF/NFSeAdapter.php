<?php

namespace freeline\FiscalCore\Adapters\NF;

use freeline\FiscalCore\Contracts\NotaServicoInterface;
use freeline\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use freeline\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use freeline\FiscalCore\Contracts\NFSeProviderConfigInterface;
use freeline\FiscalCore\Support\NFSeProviderResolver;
use freeline\FiscalCore\Support\ProviderRegistry;
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

    public function __construct(string $municipio, ?NFSeProviderConfigInterface $provider = null)
    {
        $this->municipio = $municipio;

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

        $result = $provider->emitir($dados);

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

    public function consultar(string $chave): string
    {
        return $this->provider->consultar($chave);
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
        return $this->provider->substituir($chave, $dados);
    }

    public function consultarPorRps(array $identificacaoRps): string
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
        ]);

        return $result;
    }

    public function consultarLote(string $protocolo): string
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
        ]);

        return $result;
    }

    public function baixarXml(string $chave): string
    {
        return $this->requireNacionalCapabilities()->baixarXml($chave);
    }

    public function baixarDanfse(string $chave): string
    {
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
        if ($this->providerKey === 'BELEM_MUNICIPAL_2025') {
            $routingRules[] = 'Belém exige classificação explícita de MEI';
            $routingRules[] = 'Emitente MEI é roteado automaticamente para NFSe nacional';
        }
        if ($this->provider instanceof NFSeOperationalIntrospectionInterface) {
            $supportedOperations = $this->provider->getSupportedOperations();
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
            'routing_rules' => $routingRules,
            'supported_operations' => $supportedOperations,
            'certificate_loaded' => (($this->provider->getConfig()['certificate'] ?? null) instanceof Certificate),
            'prestador_runtime' => is_array($this->provider->getConfig()['prestador'] ?? null)
                ? $this->provider->getConfig()['prestador']
                : null,
        ];
    }

    public function getLastEmissionInfo(): array
    {
        return $this->lastEmissionInfo;
    }

    public function getLastOperationInfo(): array
    {
        return $this->lastOperationInfo;
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

        if ($provider instanceof NFSeOperationalIntrospectionInterface) {
            $info['artifacts'] = $provider->getLastOperationArtifacts();
        }

        return $info;
    }

    /**
     * @return array{0:string,1:NFSeProviderConfigInterface,2:string}
     */
    private function resolveProviderForEmission(array $dados): array
    {
        if ($this->injectedProvider || $this->providerKey !== 'BELEM_MUNICIPAL_2025') {
            return [$this->providerKey, $this->provider, 'configured_provider'];
        }

        $mei = $this->resolveMeiClassification($dados);
        if ($mei === null) {
            throw new \InvalidArgumentException(
                'Belém exige identificação explícita do emitente como MEI ou não MEI antes da emissão.'
            );
        }

        if ($mei) {
            $registry = ProviderRegistry::getInstance();
            return [ProviderRegistry::NFSE_NATIONAL_KEY, $registry->getNfseNacional(), 'belem_mei_nacional'];
        }

        return [$this->providerKey, $this->provider, 'belem_municipal'];
    }

    private function resolveMeiClassification(array $dados): ?bool
    {
        $prestador = $dados['prestador'] ?? null;
        if (!is_array($prestador)) {
            return null;
        }

        foreach (['mei', 'microempreendedor_individual'] as $boolKey) {
            if (array_key_exists($boolKey, $prestador)) {
                return filter_var($prestador[$boolKey], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? null;
            }
        }

        foreach (['regime_tributario', 'regime', 'tipo_empresa', 'enquadramento'] as $stringKey) {
            if (!isset($prestador[$stringKey])) {
                continue;
            }

            $normalized = strtolower(trim((string) $prestador[$stringKey]));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['mei', 'microempreendedor individual'], true)) {
                return true;
            }

            if (in_array($normalized, ['simples nacional', 'lucro presumido', 'lucro real', 'normal', 'nao mei', 'não mei'], true)) {
                return false;
            }
        }

        return null;
    }
}
