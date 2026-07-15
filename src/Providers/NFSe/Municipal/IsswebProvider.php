<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Providers\NFSe\AbstractNFSeProvider;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use sabbajohn\FiscalCore\Support\NFSeSoapCurlTransport;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;

class IsswebProvider extends AbstractNFSeProvider implements NFSeOperationalIntrospectionInterface
{
    private ?string $lastRequestXml = null;

    private ?string $lastSoapEnvelope = null;

    private ?string $lastResponseXml = null;

    private array $lastResponseData = [];

    private array $lastOperationArtifacts = [];

    private ?string $lastOperation = null;

    private NFSeSoapTransportInterface $transport;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->transport = $config['soap_transport'] ?? new NFSeSoapCurlTransport;

        $envKey = $_ENV['NFSE_ISSWEB_CHAVE'] ?? getenv('NFSE_ISSWEB_CHAVE') ?: '';
        if ($envKey !== '' && trim((string) ($this->config['auth']['chave'] ?? '')) === '') {
            $this->config['auth']['chave'] = trim((string) $envKey);
        }
    }

    public function emitir(array $dados): string
    {
        $this->validarDados($dados);
        $requestXml = $this->montarXmlRps($dados);

        return $this->dispatchOperation('emitir', $requestXml);
    }

    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        if (trim($chave) === '' || preg_match('/^\d+$/', trim($chave)) !== 1) {
            throw new \InvalidArgumentException('ISSWEB requer o numero da nota para consulta.');
        }

        $requestXml = $this->montarXmlConsultarNota($chave);

        $this->dispatchOperation('consultar', $requestXml);

        return (new NFSeResultNormalizer)->normalizeConsulta(
            'consultar',
            $this->lastResponseData,
            $this->lastOperationArtifacts,
            [
                'provider_class' => static::class,
                'chave_consulta' => $chave,
                'source' => 'consultar',
            ]
        );
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if (trim($chave) === '' || preg_match('/^\d+$/', trim($chave)) !== 1) {
            throw new \InvalidArgumentException('ISSWEB requer o numero da nota para cancelamento.');
        }

        $validationKey = trim((string) ($protocolo ?? ''));
        if ($validationKey === '') {
            throw new \InvalidArgumentException('ISSWEB requer a chave de validacao no parametro protocolo para cancelamento.');
        }
        if (preg_match('/^[0-9A-Z]{4}-[0-9A-Z]{5}$/', $validationKey) !== 1) {
            throw new \InvalidArgumentException('Chave de validacao ISSWEB invalida. Use o formato 9999-AAAAA.');
        }

        $requestXml = $this->montarXmlCancelarNota($chave, $validationKey, $motivo);
        $this->dispatchOperation('cancelar_nfse', $requestXml);

        return ($this->lastResponseData['status'] ?? 'error') === 'success';
    }

    public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
    {
        $consulta = $this->consultar($chave);
        $impressao = $consulta->getImpressao();

        if (($impressao['disponivel'] ?? false) === true && is_string($impressao['url'] ?? null)) {
            return (new NFSeResultNormalizer)->normalizeUrl($impressao['url'], [
                'provider_key' => 'ISSWEB_AM',
                'provider_class' => static::class,
                'municipio' => (string) ($this->config['municipio_nome'] ?? ''),
                'source' => $impressao['source'] ?? 'official_url',
            ], $consulta->getRaw());
        }

        return (new NFSeResultNormalizer)->normalizeIndisponivel([
            'provider_key' => 'ISSWEB_AM',
            'provider_class' => static::class,
            'municipio' => (string) ($this->config['municipio_nome'] ?? ''),
            'source' => 'issweb_url_unavailable',
        ], $consulta->getRaw());
    }

    protected function montarXmlRps(array $dados): string
    {
        $prestador = $dados['prestador'];
        $tomador = $dados['tomador'];
        $servico = $dados['servico'];
        $enderecoTomador = is_array($tomador['endereco'] ?? null) ? $tomador['endereco'] : [];
        $localPrestacao = is_array($servico['local_prestacao'] ?? null) ? $servico['local_prestacao'] : [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('NFEEletronica');
        $dom->appendChild($root);

        $header = $this->appendXmlNode($dom, $root, 'Header');
        $this->appendXmlNode($dom, $header, 'Versao', '002');
        $this->appendXmlNode($dom, $header, 'CNPJCPFPrestador', $this->normalizeDigits((string) $prestador['cnpj']));
        $this->appendXmlNode($dom, $header, 'Chave', $this->resolveAuthKey());

        $nota = $this->appendXmlNode($dom, $root, 'DadosNotaFiscal');
        $numeroRps = $this->normalizePositiveInteger((string) ($dados['rps']['numero'] ?? $dados['id'] ?? '1'));
        $this->appendXmlNode($dom, $nota, 'ID', $numeroRps);
        $this->appendXmlNode($dom, $nota, 'NumeroNF', '0000000000');
        $this->appendXmlNode($dom, $nota, 'SituacaoNF', 'N');
        $this->appendXmlNode($dom, $nota, 'TipoNF', 'P');
        $this->appendXmlNode($dom, $nota, 'Emissao', '1900-01-01');
        $this->appendXmlNode($dom, $nota, 'CNPJCPFTomador', $this->normalizeDigits((string) $tomador['documento']));
        $this->appendXmlNode($dom, $nota, 'NomeTomador', $this->truncate((string) $this->resolveTomadorRazaoSocial($tomador), 60));
        $this->appendXmlNode($dom, $nota, 'UFTomador', $this->normalizeUf((string) ($enderecoTomador['uf'] ?? $this->config['municipio_uf'] ?? 'AM')));
        $this->appendXmlNode($dom, $nota, 'CidadeTomador', $this->normalizeIbge((string) ($enderecoTomador['codigo_municipio'] ?? $this->getCodigoMunicipio())));
        $this->appendXmlNode($dom, $nota, 'EnderecoTomador', $this->truncate((string) ($enderecoTomador['logradouro'] ?? ''), 60));
        $this->appendXmlNode($dom, $nota, 'NumeroTomador', $this->truncate((string) ($enderecoTomador['numero'] ?? 'S/N'), 10));
        $this->appendXmlNode($dom, $nota, 'ComplementoTomador', $this->truncate((string) ($enderecoTomador['complemento'] ?? ''), 60));
        $this->appendXmlNode($dom, $nota, 'BairroTomador', $this->truncate((string) ($enderecoTomador['bairro'] ?? ''), 40));
        $this->appendXmlNode($dom, $nota, 'CEPTomador', $this->normalizeCep((string) ($enderecoTomador['cep'] ?? '00000-000')));
        $this->appendXmlNode($dom, $nota, 'EmailTomador', $this->truncate((string) ($tomador['email'] ?? ''), 50));
        $this->appendXmlNode($dom, $nota, 'Observacao', $this->truncate((string) ($servico['discriminacao'] ?? $servico['descricao'] ?? ''), 200));
        $this->appendXmlNode($dom, $nota, 'NFSubstituta', '0000000000');
        $this->appendXmlNode($dom, $nota, 'LocalPrestacao', (string) ($localPrestacao['tipo'] ?? '1'));
        $this->appendXmlNode($dom, $nota, 'DescricaoLocalPrestacao', $this->truncate((string) ($localPrestacao['descricao'] ?? ''), 60));
        $this->appendXmlNode($dom, $nota, 'DescricaoLocalPrestacaoComplementar', $this->truncate((string) ($localPrestacao['complemento'] ?? ''), 50));
        $this->appendXmlNode($dom, $nota, 'InscricaoImovel', (string) ($localPrestacao['inscricao_imovel'] ?? '0'));
        $this->appendXmlNode($dom, $nota, 'UFLocalPrestacao', $this->normalizeUf((string) ($localPrestacao['uf'] ?? $this->config['municipio_uf'] ?? 'AM')));
        $this->appendXmlNode($dom, $nota, 'CidadeLocalPrestacao', $this->normalizeIbge((string) ($localPrestacao['codigo_municipio'] ?? $this->getCodigoMunicipio())));
        $this->appendXmlNode($dom, $nota, 'EnderecoLocalPrestacao', $this->truncate((string) ($localPrestacao['logradouro'] ?? ''), 60));
        $this->appendXmlNode($dom, $nota, 'NumeroLocalPrestacao', $this->truncate((string) ($localPrestacao['numero'] ?? ''), 10));
        $this->appendXmlNode($dom, $nota, 'ComplementoLocalPrestacao', $this->truncate((string) ($localPrestacao['complemento_endereco'] ?? ''), 30));
        $this->appendXmlNode($dom, $nota, 'BairroLocalPrestacao', $this->truncate((string) ($localPrestacao['bairro'] ?? ''), 40));
        $this->appendXmlNode($dom, $nota, 'CEPLocalPrestacao', $this->normalizeCep((string) ($localPrestacao['cep'] ?? '00000-000')));
        $this->appendXmlNode($dom, $nota, 'MotivoCancelamento', '');
        $this->appendXmlNode($dom, $nota, 'TipoDocumento', $this->normalizeTipoDocumento((string) ($servico['tipo_documento'] ?? $this->config['tipo_documento_padrao'] ?? '000')));

        $item = $this->appendXmlNode($dom, $nota, 'DadosItensNotaFiscal');
        $this->appendXmlNode($dom, $item, 'ItemAtividade', $this->normalizePositiveInteger((string) ($servico['codigo'] ?? '1')));
        $this->appendXmlNode($dom, $item, 'TextoItem', $this->normalizeTextoItem((string) ($servico['descricao'] ?? $servico['discriminacao'] ?? '')));
        $this->appendXmlNode($dom, $item, 'ValorItem', $this->decimal((float) ($dados['valor_servicos'] ?? 0), 2));
        $this->appendXmlNode($dom, $item, 'ValorDeducao', $this->decimal((float) ($servico['valor_deducao'] ?? 0), 2));
        $this->appendXmlNode($dom, $item, 'Retido', ! empty($servico['iss_retido']) ? 'S' : 'N');
        $this->appendXmlNode($dom, $item, 'Pais', $this->truncate((string) ($tomador['pais'] ?? 'Brasil'), 50));

        foreach ((array) ($servico['impostos'] ?? []) as $imposto) {
            if (! is_array($imposto)) {
                continue;
            }

            $imp = $this->appendXmlNode($dom, $nota, 'DadosImpostosNotaFiscal');
            $this->appendXmlNode($dom, $imp, 'Imposto', $this->truncate((string) ($imposto['sigla'] ?? 'ISS'), 6));
            $this->appendXmlNode($dom, $imp, 'ValorImposto', $this->decimal((float) ($imposto['valor'] ?? 0), 2));
        }

        return $dom->saveXML() ?: '';
    }

    protected function processarResposta(string $xmlResposta): array
    {
        $xml = trim($xmlResposta);
        $messages = [];
        $notas = [];

        if ($xml === '') {
            return [
                'status' => 'empty',
                'mensagens' => ['Resposta vazia do ISSWEB.'],
                'raw_xml' => '',
                'numero' => null,
                'chave_validacao' => null,
                'lote' => null,
                'nfse_url' => null,
            ];
        }

        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return [
                'status' => 'invalid_xml',
                'mensagens' => ['XML de resposta inválido do ISSWEB.'],
                'raw_xml' => $xml,
                'numero' => null,
                'chave_validacao' => null,
                'lote' => null,
                'nfse_url' => null,
            ];
        }

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[local-name()="Retorno"]/*[local-name()="Erro"]') ?: [] as $erroNode) {
            $id = trim((string) $xpath->evaluate('string(./*[local-name()="ID"][1])', $erroNode));
            $message = trim((string) $xpath->evaluate('string(./*[local-name()="Erro"][1])', $erroNode));
            $messages[] = trim(($id !== '' ? "[{$id}] " : '').$message);
        }

        foreach ($xpath->query('//*[local-name()="Retorno"]/*[local-name()="NotaFiscal"]') ?: [] as $notaNode) {
            $numero = trim((string) $xpath->evaluate('string(./*[local-name()="NumeroNF"][1])', $notaNode));
            $chave = trim((string) $xpath->evaluate('string(./*[local-name()="ChaveValidacao"][1])', $notaNode));
            $lote = trim((string) $xpath->evaluate('string(./*[local-name()="Lote"][1])', $notaNode));
            $notas[] = [
                'numero' => $numero !== '' ? $numero : null,
                'chave_validacao' => $chave !== '' ? $chave : null,
                'lote' => $lote !== '' ? $lote : null,
            ];
        }

        $primary = $notas[0] ?? [];
        $nfseUrl = $this->buildOfficialNfseUrl(
            (string) ($primary['numero'] ?? ''),
            (string) ($primary['chave_validacao'] ?? '')
        );

        return [
            'status' => $messages === [] ? 'success' : 'error',
            'mensagens' => $messages,
            'numero' => $primary['numero'] ?? null,
            'chave_validacao' => $primary['chave_validacao'] ?? null,
            'lote' => $primary['lote'] ?? null,
            'notas' => $notas,
            'raw_xml' => $xml,
            'nfse_url' => $nfseUrl,
        ];
    }

    public function validarDados(array $dados): bool
    {
        parent::validarDados($dados);

        $required = [
            'prestador.cnpj' => $this->normalizeDigits((string) ($dados['prestador']['cnpj'] ?? '')),
            'prestador.inscricaoMunicipal' => trim((string) ($dados['prestador']['inscricaoMunicipal'] ?? '')),
            'tomador.documento' => $this->normalizeDigits((string) ($dados['tomador']['documento'] ?? '')),
            'tomador.razao_social|razaoSocial' => trim((string) $this->resolveTomadorRazaoSocial((array) ($dados['tomador'] ?? []))),
            'tomador.endereco.codigo_municipio' => $this->normalizeIbge((string) (($dados['tomador']['endereco']['codigo_municipio'] ?? ''))),
            'tomador.endereco.cep' => $this->normalizeCep((string) (($dados['tomador']['endereco']['cep'] ?? ''))),
            'servico.codigo' => trim((string) ($dados['servico']['codigo'] ?? '')),
            'servico.descricao|discriminacao' => trim((string) ($dados['servico']['descricao'] ?? $dados['servico']['discriminacao'] ?? '')),
            'valor_servicos' => (string) ($dados['valor_servicos'] ?? ''),
        ];

        foreach ($required as $field => $value) {
            if ($value === '' || $value === '0' && $field !== 'valor_servicos') {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        if (strlen($required['prestador.cnpj']) !== 14) {
            throw new \InvalidArgumentException('CNPJ do prestador deve conter 14 dígitos.');
        }

        $tomadorDoc = $required['tomador.documento'];
        if (! in_array(strlen($tomadorDoc), [11, 14], true)) {
            throw new \InvalidArgumentException('Documento do tomador deve conter 11 ou 14 dígitos.');
        }

        if ((float) ($dados['valor_servicos'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('valor_servicos deve ser maior que zero.');
        }

        if (preg_match('/^\d+$/', trim((string) ($dados['servico']['codigo'] ?? ''))) !== 1) {
            throw new \InvalidArgumentException('servico.codigo deve ser numérico para o ISSWEB.');
        }

        $this->resolveAuthKey();

        return true;
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
        return ['emitir', 'consultar', 'cancelar', 'baixar_danfse'];
    }

    private function montarXmlConsultarNota(string $numeroNf): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('ISSEConsultaNota');
        $dom->appendChild($root);

        $header = $this->appendXmlNode($dom, $root, 'Header');
        $this->appendXmlNode($dom, $header, 'Versao', '003');
        $this->appendXmlNode($dom, $header, 'CNPJCPFPrestador', $this->resolvePrestadorCnpj());
        $this->appendXmlNode($dom, $header, 'Chave', $this->resolveAuthKey());

        $filtro = $this->appendXmlNode($dom, $root, 'Filtro');
        $this->appendXmlNode($dom, $filtro, 'NumeroNFInicial', $this->normalizePositiveInteger($numeroNf));
        $this->appendXmlNode($dom, $filtro, 'NumeroNFFinal', $this->normalizePositiveInteger($numeroNf));
        $this->appendXmlNode($dom, $filtro, 'Referencia', '000000');
        $this->appendXmlNode($dom, $filtro, 'Lote', '0');
        $this->appendXmlNode($dom, $filtro, 'TipoDocumento', '000');

        return $dom->saveXML() ?: '';
    }

    private function montarXmlCancelarNota(string $numeroNf, string $chaveValidacao, string $motivo): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('ISSECancelaNFe');
        $dom->appendChild($root);

        $header = $this->appendXmlNode($dom, $root, 'Header');
        $this->appendXmlNode($dom, $header, 'Versao', '004');
        $this->appendXmlNode($dom, $header, 'CNPJCPFPrestador', $this->resolvePrestadorCnpj());
        $this->appendXmlNode($dom, $header, 'Chave', $this->resolveAuthKey());

        $filtro = $this->appendXmlNode($dom, $root, 'Filtro');
        $this->appendXmlNode($dom, $filtro, 'NumeroNF', $this->normalizePositiveInteger($numeroNf));
        $this->appendXmlNode($dom, $filtro, 'ChaveValidacao', strtoupper($chaveValidacao));
        $this->appendXmlNode($dom, $filtro, 'MotivoCancelamento', $this->normalizeCancelReason($motivo));

        return $dom->saveXML() ?: '';
    }

    private function dispatchOperation(string $operation, string $requestXml): string
    {
        $schemaOperation = match ($operation) {
            'emitir' => 'emitir',
            'consultar' => 'consultar',
            'cancelar_nfse' => 'cancelar_nfse',
            default => $operation,
        };

        $this->validateSchema($schemaOperation, $requestXml);

        $this->lastOperation = $operation;
        $this->lastRequestXml = $requestXml;
        $this->lastSoapEnvelope = $this->buildSoapEnvelope($requestXml);

        $transport = $this->transport->send(
            $this->resolveOperationEndpoint($operation),
            $this->lastSoapEnvelope,
            [
                'soap_action' => $this->resolveSoapAction($operation),
                'timeout' => $this->getTimeout(),
            ]
        );

        $this->lastResponseXml = (string) ($transport['response_xml'] ?? '');
        $this->lastResponseData = $this->processarResposta($this->lastResponseXml);
        $this->lastOperationArtifacts = [
            'operation' => $operation,
            'request_xml' => $this->lastRequestXml,
            'soap_envelope' => $this->lastSoapEnvelope,
            'response_xml' => $this->lastResponseXml,
            'parsed_response' => $this->lastResponseData,
            'transport' => $transport,
        ];

        return $this->lastResponseXml ?? '';
    }

    private function buildSoapEnvelope(string $bodyXml): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>%s</soapenv:Body></soapenv:Envelope>',
            $bodyXml
        );
    }

    private function resolveOperationEndpoint(string $operation): string
    {
        $endpoint = trim((string) (
            ($this->config['operation_endpoints'][$operation][$this->ambiente] ?? '')
            ?: ($this->config['operation_endpoints'][$operation] ?? '')
            ?: ($this->config['service_base_'.$this->ambiente] ?? '')
            ?: ($this->ambiente === 'producao'
                ? ($this->config['wsdl_producao'] ?? '')
                : ($this->config['wsdl_homologacao'] ?? ''))
            ?: ($this->config['wsdl'] ?? '')
        ));

        if ($endpoint === '') {
            throw new \RuntimeException(
                "Endpoint ISSWEB de {$this->ambiente} não configurado para '{$operation}'. "
                .'Preencha wsdl_homologacao/wsdl_producao ou service_base_* na família do provider.'
            );
        }

        return $endpoint;
    }

    private function resolveSoapAction(string $operation): string
    {
        return trim((string) ($this->config['soap_action'][$operation] ?? ''));
    }

    private function resolveAuthKey(): string
    {
        $key = trim((string) ($this->config['auth']['chave'] ?? ''));
        if ($key === '') {
            throw new \RuntimeException('NFSE_ISSWEB_CHAVE é obrigatória para operar o ISSWEB.');
        }
        if (strlen($key) !== 48) {
            throw new \RuntimeException('A chave ISSWEB deve conter exatamente 48 caracteres.');
        }

        return $key;
    }

    private function resolvePrestadorCnpj(): string
    {
        $prestador = is_array($this->config['prestador'] ?? null) ? $this->config['prestador'] : [];
        $cnpj = $this->normalizeDigits((string) ($prestador['cnpj'] ?? ''));
        if ($cnpj === '' || strlen($cnpj) !== 14) {
            throw new \RuntimeException('CNPJ do prestador não está disponível no runtime do provider ISSWEB.');
        }

        return $cnpj;
    }

    private function validateSchema(string $operation, string $xml): void
    {
        $resolver = new NFSeSchemaResolver;
        $schemaPath = $resolver->resolve('ISSWEB_AM', $operation);
        $validation = (new NFSeSchemaValidator)->validate($xml, $schemaPath);
        if (($validation['valid'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'XML ISSWEB inválido para '.$operation.': '.implode(' | ', $validation['errors'] ?? [])
            );
        }
    }

    private function buildOfficialNfseUrl(string $numero, string $chaveValidacao): ?string
    {
        $template = trim((string) ($this->config['official_validation_url_template'] ?? ''));
        if ($template === '' || $numero === '' || $chaveValidacao === '') {
            return null;
        }

        return strtr($template, [
            '{numero}' => rawurlencode($numero),
            '{chave_validacao}' => rawurlencode($chaveValidacao),
        ]);
    }

    private function resolveTomadorRazaoSocial(array $tomador): string
    {
        return trim((string) ($tomador['razaoSocial'] ?? $tomador['razao_social'] ?? ''));
    }

    private function normalizePositiveInteger(string $value): string
    {
        $digits = ltrim($this->normalizeDigits($value), '0');

        return $digits !== '' ? $digits : '1';
    }

    private function normalizeIbge(string $value): string
    {
        $digits = $this->normalizeDigits($value);

        return strlen($digits) === 7 ? $digits : $this->getCodigoMunicipio();
    }

    private function normalizeCep(string $value): string
    {
        $digits = $this->normalizeDigits($value);
        if (strlen($digits) !== 8) {
            return '00000-000';
        }

        return substr($digits, 0, 5).'-'.substr($digits, 5, 3);
    }

    private function normalizeUf(string $value): string
    {
        $uf = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $uf) === 1 ? $uf : '';
    }

    private function normalizeTipoDocumento(string $value): string
    {
        $digits = str_pad(substr($this->normalizeDigits($value), 0, 3), 3, '0', STR_PAD_LEFT);

        return $digits;
    }

    private function normalizeTextoItem(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            $text = 'Servico prestado em homologacao ISSWEB.';
        }

        if (strlen($text) < 10) {
            $text = str_pad($text, 10, '.');
        }

        return $this->truncate($text, 1000);
    }

    private function normalizeCancelReason(string $value): string
    {
        $reason = trim($value);
        if ($reason === '') {
            $reason = 'Cancelamento em homologacao ISSWEB';
        }
        if (strlen($reason) < 10) {
            $reason = str_pad($reason, 10, '.');
        }

        return $this->truncate($reason, 200);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
