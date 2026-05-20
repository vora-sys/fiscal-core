<?php

namespace sabbajohn\FiscalCore\Providers\NFSe;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;

/**
 * Provider base abstrato para NFSe
 * 
 * Implementa funcionalidades comuns a todos os providers.
 * Providers específicos herdam desta classe e implementam apenas
 * as particularidades do município.
 */
abstract class AbstractNFSeProvider implements NFSeProviderConfigInterface
{
    protected array $config;
    protected string $ambiente; // 'producao' ou 'homologacao'
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->ambiente = $config['ambiente'] ?? 'homologacao';
    }
    
    /**
     * Monta o XML da RPS (Recibo Provisório de Serviços)
     * 
     * @param array $dados
     * @return string XML montado
     */
    abstract protected function montarXmlRps(array $dados): string;
    
    /**
     * Processa a resposta do webservice
     * 
     * @param string $xmlResposta
     * @return array Dados normalizados
     */
    abstract protected function processarResposta(string $xmlResposta): array;

    /**
     * Gera o XML de envio sem transmitir para o webservice.
     */
    public function gerarXmlEnvioPreview(array $dados): string
    {
        $this->validarDados($dados);

        return $this->montarXmlRps($dados);
    }
    
    /**
     * {@inheritDoc}
     */
    public function emitir(array $dados): string
    {
        // TODO: Implementar lógica de emissão
        // 1. Validar dados
        // 2. Montar XML
        // 3. Assinar XML
        // 4. Enviar para webservice
        // 5. Retornar XML da resposta
        
        $this->validarDados($dados);
        
        $xml = $this->montarXmlRps($dados);
        
        // TODO: Integrar com SOAP/REST para envio
        // Por enquanto retorna XML montado
        
        return $xml;
    }
    
    /**
     * {@inheritDoc}
     */
    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        return (new NFSeResultNormalizer())->normalizeConsulta('consultar', [
            'status' => 'unknown',
            'mensagens' => ['Implementação pendente'],
            'raw_xml' => '<?xml version="1.0"?><consultaNfseResposta><mensagem>Implementação pendente</mensagem></consultaNfseResposta>',
        ], [], [
            'provider_class' => static::class,
        ]);
    }
    
    /**
     * {@inheritDoc}
     */
    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        // TODO: Implementar cancelamento
        // Usar $chave, $motivo e $protocolo (se fornecido)
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function substituir(string $chave, array $dados): string
    {
        // TODO: Implementar substituição quando o provider suportar
        return '<?xml version="1.0"?><substituirNfseResposta><mensagem>Implementação pendente</mensagem></substituirNfseResposta>';
    }
    
    /**
     * {@inheritDoc}
     */
    public function getWsdlUrl(): string
    {
        $defaultWsdl = $this->config['wsdl'] ?? '';
        $urls = [
            'producao' => $this->config['wsdl_producao'] ?? $defaultWsdl,
            'homologacao' => $this->config['wsdl_homologacao'] ?? $defaultWsdl
        ];
        
        return (string) ($urls[$this->ambiente] ?? '');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getVersao(): string
    {
        return $this->config['versao'] ?? '2.02';
    }
    
    /**
     * {@inheritDoc}
     */
    public function getAliquotaFormat(): string
    {
        return $this->config['aliquota_format'] ?? 'decimal';
    }
    
    /**
     * {@inheritDoc}
     */
    public function getCodigoMunicipio(): string
    {
        return $this->config['codigo_municipio'] ?? '';
    }

    /**
     * {@inheritDoc}
     */
    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeout(): int
    {
        $timeout = (int)($this->config['timeout'] ?? 180);
        return $timeout > 0 ? $timeout : 180;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthConfig(): array
    {
        return $this->config['auth'] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function getNationalApiBaseUrl(): string
    {
        return rtrim((string) ($this->config['api_base_url'] ?? $this->config['wsdl'] ?? ''), '/');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getSefinApiBaseUrl(): string
    {
        return rtrim((string)($this->config['services']['sefin'][$this->ambiente] ?? ''), '/');
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * {@inheritDoc}
     */
    public function validarDados(array $dados): bool
    {
        // Validações básicas comuns a todos os providers
        $camposObrigatorios = [
            'prestador',
            'tomador',
            'servico',
            'valor_servicos'
        ];
        
        foreach ($camposObrigatorios as $campo) {
            if (!isset($dados[$campo])) {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$campo}");
            }
        }
        
        return true;
    }
    
    /**
     * Formata alíquota conforme padrão do município
     * 
     * @param float $aliquota Alíquota em formato decimal (ex: 0.02)
     * @return string|float
     */
    protected function formatarAliquota(float $aliquota): string|float
    {
        if ($this->getAliquotaFormat() === 'percentual') {
            return $aliquota * 100; // 0.02 -> 2
        }
        
        return $aliquota; // 0.02
    }

    protected function appendXmlNode(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $name,
        ?string $value = null,
        ?string $namespace = null
    ): \DOMElement {
        $node = $namespace !== null
            ? $dom->createElementNS($namespace, $name)
            : $dom->createElement($name);

        if ($value !== null) {
            $node->appendChild($dom->createTextNode($value));
        }

        $parent->appendChild($node);

        return $node;
    }

    protected function normalizeDigits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    protected function decimal(float $value, int $precision = 2): string
    {
        return number_format($value, $precision, '.', '');
    }

    protected function booleanCode(bool $value): string
    {
        return $value ? '1' : '2';
    }

    protected function xmlDateTime(?string $value = null): string
    {
        if (is_string($value) && trim($value) !== '') {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        }

        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }

    protected function xmlDate(?string $value = null): string
    {
        if (is_string($value) && trim($value) !== '') {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        }

        return (new \DateTimeImmutable())->format('Y-m-d');
    }

    protected function gYearMonth(?string $value = null): string
    {
        if (is_string($value) && trim($value) !== '') {
            return (new \DateTimeImmutable($value))->format('Y-m');
        }

        return (new \DateTimeImmutable())->format('Y-m');
    }

    public function consultarContribuinteCnc(string $cnc): array
    {
        throw new \BadMethodCallException(
            static::class . ' não suporta consultarContribuinteCnc.'
        );
    }

    public function verificarHabilitacaoCnc(string $cnc): bool
    {
        throw new \BadMethodCallException(
            static::class . ' não suporta verificarHabilitacaoCnc.'
        );
    }
}
