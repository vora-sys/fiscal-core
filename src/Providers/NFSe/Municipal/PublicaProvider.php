<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Providers\NFSe\AbstractNFSeProvider;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use sabbajohn\FiscalCore\Support\NFSeSoapCurlTransport;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

class PublicaProvider extends AbstractNFSeProvider implements NFSeOperationalIntrospectionInterface
{
    private const NFSE_NS = 'http://www.publica.inf.br';
    private const SERVICE_NS = 'http://service.nfse.integracao.ws.publica/';

    private ?string $lastRequestXml = null;
    private ?string $lastSoapEnvelope = null;
    private ?string $lastResponseXml = null;
    private array $lastResponseData = [];
    private array $lastTransportData = [];
    private ?string $lastOperation = null;
    private array $lastOperationArtifacts = [];
    private array $lastPrestadorContext = [];

    private NFSeSoapTransportInterface $transport;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->transport = $config['soap_transport'] ?? new NFSeSoapCurlTransport();
    }

    public function emitir(array $dados): string
    {
        $this->validarDados($dados);
        $this->lastPrestadorContext = $this->extractPrestadorContext($dados['prestador'] ?? []);

        $requestXml = $this->montarXmlRps($dados);
        if ($this->shouldSignOperation('emitir')) {
            $requestXml = $this->assinarXml($requestXml, 'emitir');
        }

        return $this->dispatchSoapOperation(
            'emitir',
            'GerarNfse',
            $requestXml,
            'emitir',
            'services'
        );
    }

    public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
    {
        $this->validarIdentificacaoRps($identificacaoRps);

        $requestXml = $this->montarXmlConsultarNfsePorRps($identificacaoRps);
        if ($this->shouldSignOperation('consultar_nfse_rps')) {
            $requestXml = $this->assinarXml($requestXml, 'consultar_nfse_rps');
        }

        $this->dispatchSoapOperation(
            'consultar_nfse_rps',
            'ConsultarNfsePorRps',
            $requestXml,
            'consultar_nfse_rps',
            'consultas'
        );

        return $this->normalizeConsultaResult('consultar_nfse_rps', [
            'chave_consulta' => (string) $identificacaoRps['numero'],
            'source' => 'consultar_nfse_rps',
        ]);
    }

    public function consultarLote(string $protocolo): NFSeConsultaResultInterface
    {
        if (trim($protocolo) === '') {
            throw new \InvalidArgumentException('Protocolo do lote é obrigatório para consulta em Joinville.');
        }

        $requestXml = $this->montarXmlConsultarLote($protocolo);
        if ($this->shouldSignOperation('consultar_lote')) {
            $requestXml = $this->assinarXml($requestXml, 'consultar_lote');
        }

        $this->dispatchSoapOperation(
            'consultar_lote',
            'ConsultarLoteRps',
            $requestXml,
            'consultar_lote',
            'consultas'
        );

        return $this->normalizeConsultaResult('consultar_lote', [
            'chave_consulta' => $protocolo,
            'source' => 'consultar_lote',
        ]);
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Número da NFSe é obrigatório para cancelamento em Joinville.');
        }

        $requestXml = $this->montarXmlCancelarNfse($chave, $motivo, $protocolo);
        if ($this->shouldSignOperation('cancelar_nfse')) {
            $requestXml = $this->assinarXml($requestXml, 'cancelar_nfse');
        }

        $this->dispatchSoapOperation(
            'cancelar_nfse',
            'CancelarNfse',
            $requestXml,
            'cancelar_nfse',
            'services'
        );

        return ($this->lastResponseData['status'] ?? 'unknown') === 'success';
    }

    protected function montarXmlRps(array $dados): string
    {
        $prestador = $dados['prestador'];
        $servico = $this->resolveServicoData($dados);
        $tomador = $dados['tomador'] ?? [];
        $rps = $dados['rps'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'GerarNfseEnvio');
        $dom->appendChild($root);

        $rpsNode = $this->appendXmlNode($dom, $root, 'Rps', null, self::NFSE_NS);
        $infRps = $this->appendXmlNode($dom, $rpsNode, 'InfRps', null, self::NFSE_NS);
        $infRps->setAttribute('id', (string) ($dados['id'] ?? 'joinville-rps-1'));

        $identificacaoRps = $this->appendXmlNode($dom, $infRps, 'IdentificacaoRps', null, self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Numero', (string) ($rps['numero'] ?? '1'), self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Serie', (string) ($rps['serie'] ?? 'A1'), self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Tipo', (string) ($rps['tipo'] ?? '1'), self::NFSE_NS);

        $this->appendXmlNode(
            $dom,
            $infRps,
            'DataEmissao',
            $this->xmlLocalDateTime((string) ($rps['data_emissao'] ?? null)),
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $infRps,
            'NaturezaOperacao',
            (string) ($servico['natureza_operacao'] ?? $this->config['natureza_operacao_padrao'] ?? '16'),
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $infRps,
            'OptanteSimplesNacional',
            $this->booleanCode((bool) ($prestador['simples_nacional'] ?? false)),
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $infRps,
            'IncentivadorCultural',
            $this->booleanCode((bool) ($prestador['incentivador_cultural'] ?? false)),
            self::NFSE_NS
        );

        $competencia = trim((string) ($dados['competencia'] ?? $servico['competencia'] ?? ''));
        if ($competencia !== '') {
            $this->appendXmlNode($dom, $infRps, 'Competencia', $this->gYearMonth($competencia), self::NFSE_NS);
        }

        $this->appendXmlNode($dom, $infRps, 'Status', (string) ($rps['status'] ?? '1'), self::NFSE_NS);

        $servicoNode = $this->appendXmlNode($dom, $infRps, 'Servico', null, self::NFSE_NS);
        $valores = $this->appendXmlNode($dom, $servicoNode, 'Valores', null, self::NFSE_NS);
        $this->appendXmlNode(
            $dom,
            $valores,
            'ValorServicos',
            $this->decimal((float) $servico['valor_servicos']),
            self::NFSE_NS
        );
        $this->appendOptionalDecimal($dom, $valores, 'ValorDeducoes', $servico['valor_deducoes'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorPis', $servico['valor_pis'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorCofins', $servico['valor_cofins'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorInss', $servico['valor_inss'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorIr', $servico['valor_ir'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorCsll', $servico['valor_csll'] ?? null);
        $this->appendXmlNode(
            $dom,
            $valores,
            'IssRetido',
            $this->booleanCode((bool) ($servico['iss_retido'] ?? false)),
            self::NFSE_NS
        );
        $this->appendOptionalDecimal($dom, $valores, 'ValorIss', $servico['valor_iss'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorIssRetido', $servico['valor_iss_retido'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'OutrasRetencoes', $servico['outras_retencoes'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'BaseCalculo', $servico['base_calculo'] ?? null);
        $this->appendOptionalDecimal(
            $dom,
            $valores,
            'Aliquota',
            $servico['aliquota_xml'] ?? null,
            2
        );
        $this->appendOptionalDecimal($dom, $valores, 'ValorLiquidoNfse', $servico['valor_liquido_nfse'] ?? null);
        $this->appendOptionalDecimal(
            $dom,
            $valores,
            'DescontoIncondicionado',
            $servico['desconto_incondicionado'] ?? null
        );
        $this->appendOptionalDecimal(
            $dom,
            $valores,
            'DescontoCondicionado',
            $servico['desconto_condicionado'] ?? null
        );

        $this->appendXmlNode(
            $dom,
            $servicoNode,
            'ItemListaServico',
            (string) $servico['item_lista_servico'],
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $servicoNode,
            'Discriminacao',
            (string) $servico['discriminacao'],
            self::NFSE_NS
        );
        if (!empty($servico['informacoes_complementares'])) {
            $this->appendXmlNode(
                $dom,
                $servicoNode,
                'InformacoesComplementares',
                (string) $servico['informacoes_complementares'],
                self::NFSE_NS
            );
        }
        $this->appendXmlNode(
            $dom,
            $servicoNode,
            'CodigoMunicipio',
            (string) $servico['codigo_municipio'],
            self::NFSE_NS
        );
        if (!empty($servico['codigo_pais'])) {
            $this->appendXmlNode(
                $dom,
                $servicoNode,
                'CodigoPais',
                $this->normalizeDigits((string) $servico['codigo_pais']),
                self::NFSE_NS
            );
        }
        if (!empty($servico['codigo_municipio_local_prestacao'])) {
            $this->appendXmlNode(
                $dom,
                $servicoNode,
                'CodigoMunicipioLocalPrestacao',
                $this->normalizeDigits((string) $servico['codigo_municipio_local_prestacao']),
                self::NFSE_NS
            );
        }

        $prestadorNode = $this->appendXmlNode($dom, $infRps, 'Prestador', null, self::NFSE_NS);
        $this->appendDocumentoNode($dom, $prestadorNode, $this->normalizeDigits((string) $prestador['cnpj']));
        $this->appendXmlNode(
            $dom,
            $prestadorNode,
            'InscricaoMunicipal',
            (string) $prestador['inscricaoMunicipal'],
            self::NFSE_NS
        );

        if ($tomador !== []) {
            $this->appendTomadorNode($dom, $infRps, $tomador, $servico['codigo_municipio']);
        }

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    public function validarDados(array $dados): bool
    {
        parent::validarDados($dados);

        $required = [
            'prestador.cnpj' => $this->normalizeDigits((string) ($dados['prestador']['cnpj'] ?? '')),
            'prestador.inscricaoMunicipal' => (string) ($dados['prestador']['inscricaoMunicipal'] ?? ''),
            'servico.codigo' => (string) ($dados['servico']['codigo'] ?? $dados['servico']['item_lista_servico'] ?? ''),
            'servico.codigo_municipio' => $this->normalizeDigits((string) ($dados['servico']['codigo_municipio'] ?? '')),
            'servico.descricao' => (string) ($dados['servico']['descricao'] ?? $dados['servico']['discriminacao'] ?? ''),
            'valor_servicos' => $dados['valor_servicos'] ?? $dados['servico']['valor_servicos'] ?? null,
        ];

        foreach ($required as $field => $value) {
            if (trim((string) $value) === '') {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        if (isset($dados['tomador']) && is_array($dados['tomador'])) {
            $tomadorDocumento = $this->normalizeDigits((string) ($dados['tomador']['documento'] ?? ''));
            $tomadorRazao = trim((string) ($dados['tomador']['razao_social'] ?? ''));
            if ($tomadorDocumento === '' || $tomadorRazao === '') {
                throw new \InvalidArgumentException(
                    'Joinville exige identificação consistente do tomador quando o grupo tomador é informado.'
                );
            }
        }

        $this->assertItensCompativeis($dados);

        return true;
    }

    protected function processarResposta(string $xmlResposta): array
    {
        if (trim($xmlResposta) === '') {
            return [
                'status' => 'empty',
                'mensagens' => ['Resposta vazia do webservice de Joinville.'],
            ];
        }

        $payloadXml = $this->extractSoapPayload($xmlResposta);
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($payloadXml)) {
            return [
                'status' => 'invalid_xml',
                'mensagens' => ['Resposta XML inválida do webservice de Joinville.'],
                'raw_xml' => $xmlResposta,
            ];
        }

        $xpath = new \DOMXPath($dom);
        $mensagens = [];
        $faultString = trim((string) $xpath->evaluate("string(//*[local-name()='Fault']/*[local-name()='faultstring'])"));
        if ($faultString !== '') {
            $mensagens[] = $faultString;
        }

        foreach ($xpath->query("//*[local-name()='MensagemRetorno']") as $messageNode) {
            $codigo = trim((string) $xpath->evaluate("string(./*[local-name()='Codigo'])", $messageNode));
            $mensagem = trim((string) $xpath->evaluate("string(./*[local-name()='Mensagem'])", $messageNode));
            $correcao = trim((string) $xpath->evaluate("string(./*[local-name()='Correcao'])", $messageNode));
            $parts = array_values(array_filter([$codigo, $mensagem, $correcao]));
            if ($parts !== []) {
                $mensagens[] = implode(' ', $parts);
            }
        }

        $numeroLote = $this->firstNodeValue($xpath, ["//*[local-name()='NumeroLote']"]);
        $dataRecebimento = $this->firstNodeValue($xpath, ["//*[local-name()='DataRecebimento']"]);
        $protocolo = $this->firstNodeValue($xpath, ["//*[local-name()='Protocolo']"]);
        $rootName = $dom->documentElement?->localName;

        $listaNfse = [];
        foreach ($xpath->query("//*[local-name()='ListaNfse']/*[local-name()='CompNfse']") as $nfseNode) {
            $listaNfse[] = $this->parseCompNfse($xpath, $nfseNode);
        }

        $nfse = $listaNfse[0] ?? null;
        $cancelamento = null;
        $cancelamentoNode = $xpath->query("//*[local-name()='InfPedidoCancelamento']")->item(0);
        if ($cancelamentoNode instanceof \DOMNode) {
            $cancelamento = [
                'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='Numero']"], $cancelamentoNode),
                'codigo_cancelamento' => $this->firstNodeValue(
                    $xpath,
                    [".//*[local-name()='CodigoCancelamento']"],
                    $cancelamentoNode
                ),
                'motivo' => $this->firstNodeValue(
                    $xpath,
                    [".//*[local-name()='MotivoCancelamento']"],
                    $cancelamentoNode
                ),
                'data_hora_cancelamento' => $this->firstNodeValue(
                    $xpath,
                    ["//*[local-name()='DataHoraCancelamento']"]
                ),
                'sucesso' => $mensagens === [],
            ];
        }

        $hasSuccessPayload = $nfse !== null
            || $cancelamento !== null
            || $numeroLote !== null
            || $protocolo !== null;

        return [
            'status' => $mensagens !== [] ? 'error' : ($hasSuccessPayload ? 'success' : 'unknown'),
            'operation_response' => $rootName,
            'fault' => $faultString !== '' ? ['message' => $faultString] : null,
            'numero_lote' => $numeroLote,
            'data_recebimento' => $dataRecebimento,
            'protocolo' => $protocolo,
            'nfse' => $nfse,
            'lista_nfse' => $listaNfse,
            'cancelamento' => $cancelamento,
            'mensagens' => array_values(array_filter($mensagens)),
            'raw_xml' => $payloadXml,
            'raw_transport_xml' => $xmlResposta,
        ];
    }

    public function getLastRequestXml(): ?string
    {
        return $this->lastRequestXml;
    }

    public function getLastSoapEnvelope(): ?string
    {
        return $this->lastSoapEnvelope;
    }

    public function getLastResponseXml(): ?string
    {
        return $this->lastResponseXml;
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
            'consultar_lote',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];
    }

    private function normalizeConsultaResult(string $operation, array $context = []): NFSeConsultaResultInterface
    {
        return (new NFSeResultNormalizer())->normalizeConsulta(
            $operation,
            $this->lastResponseData,
            $this->lastOperationArtifacts,
            $context + [
                'provider_class' => static::class,
            ]
        );
    }

    private function resolveServicoData(array $dados): array
    {
        $servico = $dados['servico'];

        if (!empty($dados['itens']) && is_array($dados['itens'])) {
            $descricao = [];
            $valorTotal = 0.0;
            $firstItem = null;

            foreach ($dados['itens'] as $index => $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException("Item {$index} inválido para emissão de Joinville.");
                }

                $normalizedItem = [
                    'item_lista_servico' => (string) ($item['codigo'] ?? $item['item_lista_servico'] ?? $servico['codigo'] ?? ''),
                    'aliquota' => (string) ($item['aliquota'] ?? $servico['aliquota'] ?? ''),
                    'codigo_municipio' => $this->normalizeDigits(
                        (string) ($item['codigo_municipio'] ?? $servico['codigo_municipio'] ?? $this->getCodigoMunicipio())
                    ),
                ];

                if ($firstItem === null) {
                    $firstItem = $normalizedItem;
                } elseif ($normalizedItem !== $firstItem) {
                    throw new \InvalidArgumentException(
                        'Joinville exige um único ItemListaServico, alíquota e município por NFSe.'
                    );
                }

                $descricao[] = trim((string) ($item['descricao'] ?? $servico['descricao'] ?? 'Servico'));
                $valorTotal += (float) ($item['valor_servicos'] ?? $item['valor'] ?? 0);
            }

            $servico['discriminacao'] = implode(' | ', array_filter($descricao));
            $servico['valor_servicos'] = $valorTotal;
        } else {
            $servico['discriminacao'] = (string) ($servico['discriminacao'] ?? $servico['descricao'] ?? '');
            $servico['valor_servicos'] = (float) ($dados['valor_servicos'] ?? $servico['valor_servicos'] ?? 0.0);
        }

        $servico['item_lista_servico'] = (string) ($servico['item_lista_servico'] ?? $servico['codigo'] ?? '');
        $servico['codigo_municipio'] = $this->normalizeDigits((string) ($servico['codigo_municipio'] ?? $this->getCodigoMunicipio()));
        $servico['aliquota_xml'] = $this->normalizeAliquotaForXml($servico['aliquota'] ?? null);

        return $servico;
    }

    private function normalizeAliquotaForXml(mixed $aliquota): ?float
    {
        if ($aliquota === null || $aliquota === '') {
            return null;
        }

        $value = (float) $aliquota;
        if ($this->getAliquotaFormat() === 'percentual' && $value <= 1) {
            return $value * 100;
        }

        return $value;
    }

    private function validarIdentificacaoRps(array $identificacaoRps): void
    {
        foreach (['numero', 'serie', 'tipo'] as $campo) {
            if (trim((string) ($identificacaoRps[$campo] ?? '')) === '') {
                throw new \InvalidArgumentException(
                    "Identificação RPS inválida para Joinville: campo {$campo} é obrigatório."
                );
            }
        }
    }

    private function montarXmlConsultarLote(string $protocolo): string
    {
        $prestador = $this->resolvePrestadorContext();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'ConsultarLoteRpsEnvio');
        $dom->appendChild($root);

        $prestadorNode = $this->appendXmlNode($dom, $root, 'Prestador', null, self::NFSE_NS);
        $prestadorNode->setAttribute('id', 'prestador-consulta-lote');
        $this->appendDocumentoNode($dom, $prestadorNode, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal'], self::NFSE_NS);
        $this->appendXmlNode($dom, $root, 'Protocolo', trim($protocolo), self::NFSE_NS);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlConsultarNfsePorRps(array $identificacaoRps): string
    {
        $prestador = $this->resolvePrestadorContext($identificacaoRps['prestador'] ?? []);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'ConsultarNfseRpsEnvio');
        $dom->appendChild($root);

        $rpsNode = $this->appendXmlNode($dom, $root, 'IdentificacaoRps', null, self::NFSE_NS);
        $this->appendXmlNode($dom, $rpsNode, 'Numero', trim((string) $identificacaoRps['numero']), self::NFSE_NS);
        $this->appendXmlNode($dom, $rpsNode, 'Serie', trim((string) $identificacaoRps['serie']), self::NFSE_NS);
        $this->appendXmlNode($dom, $rpsNode, 'Tipo', trim((string) $identificacaoRps['tipo']), self::NFSE_NS);

        $prestadorNode = $this->appendXmlNode($dom, $root, 'Prestador', null, self::NFSE_NS);
        $prestadorNode->setAttribute('id', 'prestador-consulta-rps');
        $this->appendDocumentoNode($dom, $prestadorNode, $prestador['cnpj']);
        $this->appendXmlNode(
            $dom,
            $prestadorNode,
            'InscricaoMunicipal',
            $prestador['inscricao_municipal'],
            self::NFSE_NS
        );

        if (!empty($identificacaoRps['pagina'])) {
            $this->appendXmlNode($dom, $root, 'Pagina', trim((string) $identificacaoRps['pagina']), self::NFSE_NS);
        }

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlCancelarNfse(string $numeroNfse, string $motivo, ?string $protocolo): string
    {
        $prestador = $this->resolvePrestadorContext();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'CancelarNfseEnvio');
        $dom->appendChild($root);

        $pedido = $this->appendXmlNode($dom, $root, 'Pedido', null, self::NFSE_NS);
        $infPedido = $this->appendXmlNode($dom, $pedido, 'InfPedidoCancelamento', null, self::NFSE_NS);
        $infPedido->setAttribute('id', 'cancelar-' . $this->normalizeDigits($numeroNfse));

        $identificacao = $this->appendXmlNode($dom, $infPedido, 'IdentificacaoNfse', null, self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacao, 'Numero', trim($numeroNfse), self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacao, 'Cnpj', $prestador['cnpj'], self::NFSE_NS);
        $this->appendXmlNode(
            $dom,
            $identificacao,
            'InscricaoMunicipal',
            $prestador['inscricao_municipal'],
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $identificacao,
            'CodigoMunicipio',
            $prestador['codigo_municipio'],
            self::NFSE_NS
        );
        if ($protocolo !== null && trim($protocolo) !== '' && preg_match('/^[A-Z0-9-]+$/i', $protocolo) === 1) {
            $this->appendXmlNode($dom, $identificacao, 'CodigoVerificacao', trim($protocolo), self::NFSE_NS);
        }
        $this->appendXmlNode(
            $dom,
            $infPedido,
            'CodigoCancelamento',
            (string) ($this->config['cancelamento_codigo'] ?? 'C001'),
            self::NFSE_NS
        );
        if (trim($motivo) !== '') {
            $this->appendXmlNode($dom, $infPedido, 'MotivoCancelamento', trim($motivo), self::NFSE_NS);
        }

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function dispatchSoapOperation(
        string $operationKey,
        string $soapOperation,
        string $requestXml,
        string $schemaOperation,
        string $channel
    ): string {
        $this->assertRequestSchema($requestXml, $schemaOperation);

        $soapEnvelope = $this->montarSoapEnvelope($soapOperation, $requestXml);
        $transportData = $this->transport->send(
            $this->resolveSoapEndpoint($channel),
            $soapEnvelope,
            [
                'soap_action' => '',
                'timeout' => $this->getTimeout(),
                'soap_operation' => $soapOperation,
                'operation' => $operationKey,
                'channel' => $channel,
            ]
        );

        $responseXml = (string) ($transportData['response_xml'] ?? '');
        $parsedResponse = $this->enrichTransportDiagnostics(
            $this->processarResposta($responseXml),
            $transportData,
            $responseXml
        );

        $this->lastOperation = $operationKey;
        $this->lastRequestXml = $requestXml;
        $this->lastSoapEnvelope = $soapEnvelope;
        $this->lastResponseXml = $responseXml;
        $this->lastTransportData = $transportData;
        $this->lastResponseData = $parsedResponse;
        $this->lastOperationArtifacts = [
            'operation' => $operationKey,
            'channel' => $channel,
            'request_xml' => $requestXml,
            'soap_envelope' => $soapEnvelope,
            'response_xml' => $responseXml,
            'parsed_response' => $parsedResponse,
            'transport' => $transportData,
        ];

        $this->logSoapDebug($this->lastOperationArtifacts);

        return $responseXml;
    }

    private function enrichTransportDiagnostics(array $parsedResponse, array $transportData, string $responseXml): array
    {
        $statusCode = (int) ($transportData['status_code'] ?? 0);
        if ($statusCode > 0) {
            $parsedResponse['http_status'] = $statusCode;
        }

        $requestHeaders = is_array($transportData['request_headers'] ?? null)
            ? array_values($transportData['request_headers'])
            : [];
        $responseHeaders = is_array($transportData['response_headers'] ?? null)
            ? array_values($transportData['response_headers'])
            : (is_array($transportData['headers'] ?? null) ? array_values($transportData['headers']) : []);

        if ($responseHeaders !== []) {
            $parsedResponse['transport_headers'] = $responseHeaders;
            $parsedResponse['response_headers'] = $responseHeaders;
        }

        if ($requestHeaders !== []) {
            $parsedResponse['request_headers'] = $requestHeaders;
        }

        if (!isset($parsedResponse['raw_transport_xml']) || trim((string) $parsedResponse['raw_transport_xml']) === '') {
            $parsedResponse['raw_transport_xml'] = $responseXml;
        }

        $trimmedResponse = ltrim($responseXml);
        $looksLikeHtml = str_starts_with(strtolower($trimmedResponse), '<html')
            || str_contains(strtolower($trimmedResponse), '<title>502 bad gateway</title>');

        if ($statusCode >= 500 && $looksLikeHtml) {
            if (($parsedResponse['status'] ?? '') === 'unknown') {
                $parsedResponse['status'] = 'invalid_xml';
            }

            $mensagens = is_array($parsedResponse['mensagens'] ?? null) ? $parsedResponse['mensagens'] : [];
            if ($mensagens === []) {
                $mensagens[] = 'Resposta XML inválida do webservice de Joinville.';
            }
            $mensagens[] = sprintf(
                'Endpoint de Joinville retornou HTTP %d com HTML de gateway/proxy, antes de devolver XML NFSe.',
                $statusCode
            );
            $parsedResponse['mensagens'] = array_values(array_unique($mensagens));
            $parsedResponse['retryable'] = true;
            $parsedResponse['transport_error'] = 'gateway_unavailable';
        }

        return $parsedResponse;
    }

    private function assertItensCompativeis(array $dados): void
    {
        if (empty($dados['itens']) || !is_array($dados['itens'])) {
            return;
        }

        $first = null;
        foreach ($dados['itens'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Item {$index} inválido para emissão de Joinville.");
            }

            $normalized = [
                'item_lista_servico' => (string) ($item['codigo'] ?? $item['item_lista_servico'] ?? $dados['servico']['codigo'] ?? ''),
                'aliquota' => (string) ($item['aliquota'] ?? $dados['servico']['aliquota'] ?? ''),
                'codigo_municipio' => $this->normalizeDigits(
                    (string) ($item['codigo_municipio'] ?? $dados['servico']['codigo_municipio'] ?? $this->getCodigoMunicipio())
                ),
            ];

            if ($first === null) {
                $first = $normalized;
                continue;
            }

            if ($normalized !== $first) {
                throw new \InvalidArgumentException(
                    'Joinville exige um único ItemListaServico, alíquota e município por NFSe.'
                );
            }
        }
    }

    private function appendTomadorNode(
        \DOMDocument $dom,
        \DOMElement $parent,
        array $tomador,
        string $codigoMunicipioServico
    ): void {
        $tomadorNode = $this->appendXmlNode($dom, $parent, 'Tomador', null, self::NFSE_NS);
        $identificacaoTomador = $this->appendXmlNode($dom, $tomadorNode, 'IdentificacaoTomador', null, self::NFSE_NS);
        $cpfCnpj = $this->appendXmlNode($dom, $identificacaoTomador, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendDocumentoNode($dom, $cpfCnpj, $this->normalizeDigits((string) $tomador['documento']));
        if (!empty($tomador['inscricaoMunicipal'])) {
            $this->appendXmlNode(
                $dom,
                $identificacaoTomador,
                'InscricaoMunicipal',
                (string) $tomador['inscricaoMunicipal'],
                self::NFSE_NS
            );
        }

        $this->appendXmlNode($dom, $tomadorNode, 'RazaoSocial', (string) $tomador['razao_social'], self::NFSE_NS);
        if (!empty($tomador['nome_fantasia'])) {
            $this->appendXmlNode($dom, $tomadorNode, 'NomeFantasia', (string) $tomador['nome_fantasia'], self::NFSE_NS);
        }

        if (!empty($tomador['endereco']) && is_array($tomador['endereco'])) {
            $endereco = $tomador['endereco'];
            $enderecoNode = $this->appendXmlNode($dom, $tomadorNode, 'Endereco', null, self::NFSE_NS);
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Endereco', $endereco['logradouro'] ?? null);
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Numero', $endereco['numero'] ?? null);
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Complemento', $endereco['complemento'] ?? null);
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Bairro', $endereco['bairro'] ?? null);
            $this->appendOptionalStringNode(
                $dom,
                $enderecoNode,
                'CodigoMunicipio',
                $this->normalizeDigits((string) ($endereco['codigo_municipio'] ?? $codigoMunicipioServico))
            );
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Uf', $endereco['uf'] ?? null);
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Cep', $this->normalizeDigits((string) ($endereco['cep'] ?? '')));
            $this->appendOptionalStringNode(
                $dom,
                $enderecoNode,
                'CodigoPais',
                $this->normalizeDigits((string) ($endereco['codigo_pais'] ?? ''))
            );
            $this->appendOptionalStringNode($dom, $enderecoNode, 'Municipio', $endereco['municipio'] ?? null);
        }

        if (!empty($tomador['telefone']) || !empty($tomador['email'])) {
            $contatoNode = $this->appendXmlNode($dom, $tomadorNode, 'Contato', null, self::NFSE_NS);
            $this->appendOptionalStringNode(
                $dom,
                $contatoNode,
                'Telefone',
                $this->normalizeDigits((string) ($tomador['telefone'] ?? ''))
            );
            $this->appendOptionalStringNode($dom, $contatoNode, 'Email', $tomador['email'] ?? null);
        }
    }

    private function appendDocumentoNode(\DOMDocument $dom, \DOMElement $parent, string $documento): void
    {
        if (strlen($documento) === 11) {
            $this->appendXmlNode($dom, $parent, 'Cpf', $documento, self::NFSE_NS);
            return;
        }

        $this->appendXmlNode($dom, $parent, 'Cnpj', $documento, self::NFSE_NS);
    }

    private function appendOptionalDecimal(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $name,
        mixed $value,
        int $precision = 2
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        $this->appendXmlNode($dom, $parent, $name, $this->decimal((float) $value, $precision), self::NFSE_NS);
    }

    private function appendOptionalStringNode(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $name,
        ?string $value
    ): void {
        if ($value === null || trim($value) === '') {
            return;
        }

        $this->appendXmlNode($dom, $parent, $name, trim($value), self::NFSE_NS);
    }

    private function assinarXml(string $xml, string $operationKey): string
    {
        $certificate = $this->resolveCertificate();
        if ($certificate === null) {
            throw new \RuntimeException('Certificado digital obrigatório para o provider municipal de Joinville.');
        }

        $signed = match ($operationKey) {
            'emitir' => Signer::sign(
                $certificate,
                $xml,
                'InfRps',
                'id',
                OPENSSL_ALGO_SHA1,
                Signer::CANONICAL,
                'GerarNfseEnvio'
            ),
            'consultar_lote' => Signer::sign(
                $certificate,
                $xml,
                'Prestador',
                'id',
                OPENSSL_ALGO_SHA1,
                Signer::CANONICAL,
                'ConsultarLoteRpsEnvio'
            ),
            'consultar_nfse_rps' => Signer::sign(
                $certificate,
                $xml,
                'Prestador',
                'id',
                OPENSSL_ALGO_SHA1,
                Signer::CANONICAL,
                'ConsultarNfseRpsEnvio'
            ),
            'cancelar_nfse' => Signer::sign(
                $certificate,
                $xml,
                'InfPedidoCancelamento',
                'id',
                OPENSSL_ALGO_SHA1,
                Signer::CANONICAL,
                'CancelarNfseEnvio'
            ),
            default => $xml,
        };

        return match ($operationKey) {
            'emitir' => $this->relocateSignature($signed, 'Rps', 'InfRps'),
            'consultar_lote' => $this->relocateSignature($signed, 'ConsultarLoteRpsEnvio', 'Prestador'),
            'consultar_nfse_rps' => $this->relocateSignature($signed, 'ConsultarNfseRpsEnvio', 'Prestador'),
            'cancelar_nfse' => $this->relocateSignature($signed, 'Pedido', 'InfPedidoCancelamento'),
            default => $signed,
        };
    }

    private function shouldSignOperation(string $operationKey): bool
    {
        $configured = $this->config['sign_operations'] ?? [
            'emitir',
            'consultar_lote',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];

        return is_array($configured) && in_array($operationKey, $configured, true);
    }

    private function resolveCertificate(): ?Certificate
    {
        $configCertificate = $this->config['certificate'] ?? null;
        if ($configCertificate instanceof Certificate) {
            return $configCertificate;
        }

        $pfxContent = $this->config['certificate_pfx_content'] ?? null;
        $pfxPassword = $this->config['certificate_password'] ?? null;
        if (is_string($pfxContent) && $pfxContent !== '' && is_string($pfxPassword) && $pfxPassword !== '') {
            return Certificate::readPfx($pfxContent, $pfxPassword);
        }

        return CertificateManager::getInstance()->getCertificate();
    }

    private function assertRequestSchema(string $requestXml, string $operation): void
    {
        $resolver = new NFSeSchemaResolver();
        $validator = new NFSeSchemaValidator();
        $schemaPath = $resolver->resolve('PUBLICA', $operation);
        $validation = $validator->validate($requestXml, $schemaPath);

        if ($validation['valid']) {
            return;
        }

        throw new \RuntimeException(
            'XML de Joinville inválido para o schema da operação '
            . $operation
            . ': '
            . implode('; ', $validation['errors'])
        );
    }

    private function montarSoapEnvelope(string $soapOperation, string $requestXml): string
    {
        $soap = new \DOMDocument('1.0', 'UTF-8');
        $soap->preserveWhiteSpace = false;
        $soap->formatOutput = false;

        $envelope = $soap->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:svc', self::SERVICE_NS);
        $soap->appendChild($envelope);

        $header = $soap->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Header');
        $envelope->appendChild($header);

        $body = $soap->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Body');
        $envelope->appendChild($body);

        $operationNode = $soap->createElementNS(self::SERVICE_NS, 'svc:' . $soapOperation);
        $body->appendChild($operationNode);
        $operationNode->appendChild($soap->createElementNS(self::SERVICE_NS, 'XML', $requestXml));

        return $soap->saveXML() ?: '';
    }

    private function extractSoapPayload(string $xmlResposta): string
    {
        $soap = new \DOMDocument();
        if (!@$soap->loadXML($xmlResposta)) {
            return $xmlResposta;
        }

        $xpath = new \DOMXPath($soap);
        $returnNode = $xpath->query("//*[local-name()='return']")->item(0);
        if (!$returnNode instanceof \DOMNode) {
            return $xmlResposta;
        }

        $payload = trim((string) $returnNode->textContent);
        if ($payload === '') {
            return $xmlResposta;
        }

        $decoded = html_entity_decode($payload, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return str_starts_with(trim($decoded), '<') ? $decoded : $payload;
    }

    private function resolveSoapEndpoint(string $channel): string
    {
        $configured = match ($channel) {
            'consultas' => (string) (
                $this->config['consultas_wsdl_' . $this->getAmbiente()]
                ?? $this->config['consultas_wsdl']
                ?? ''
            ),
            default => (string) (
                $this->config['wsdl_' . $this->getAmbiente()]
                ?? $this->config['wsdl']
                ?? ''
            ),
        };

        if ($configured === '') {
            $configured = $channel === 'consultas'
                ? (string) $this->getWsdlUrl()
                : (string) $this->getWsdlUrl();
        }

        return preg_replace('/\?wsdl$/i', '', $configured) ?: $configured;
    }

    private function parseCompNfse(\DOMXPath $xpath, \DOMNode $nfseNode): array
    {
        return [
            'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='Numero']"], $nfseNode),
            'codigo_verificacao' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='CodigoVerificacao']"],
                $nfseNode
            ),
            'data_emissao' => $this->firstNodeValue($xpath, [".//*[local-name()='DataEmissao']"], $nfseNode),
            'numero_rps' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='IdentificacaoRps']/*[local-name()='Numero']"],
                $nfseNode
            ),
            'serie_rps' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='IdentificacaoRps']/*[local-name()='Serie']"],
                $nfseNode
            ),
            'valor_servicos' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='ValorServicos']"],
                $nfseNode
            ),
            'valor_liquido' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='ValorLiquidoNfse']"],
                $nfseNode
            ),
            'tomador' => $this->firstNodeValue(
                $xpath,
                [".//*[local-name()='TomadorServico']/*[local-name()='RazaoSocial']"],
                $nfseNode
            ),
        ];
    }

    private function firstNodeValue(\DOMXPath $xpath, array $queries, ?\DOMNode $context = null): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);
            if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractPrestadorContext(array $prestador): array
    {
        return [
            'cnpj' => $this->normalizeDigits((string) ($prestador['cnpj'] ?? '')),
            'inscricao_municipal' => trim((string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal'] ?? '')),
            'codigo_municipio' => trim((string) ($prestador['codigo_municipio'] ?? $this->getCodigoMunicipio())),
        ];
    }

    private function resolvePrestadorContext(array $override = []): array
    {
        $candidates = [
            $this->extractPrestadorContext($override),
            $this->lastPrestadorContext,
            $this->extractPrestadorContext((array) ($this->config['prestador'] ?? [])),
            [
                'cnpj' => $this->normalizeDigits((string) ($this->config['prestador_cnpj'] ?? '')),
                'inscricao_municipal' => trim((string) ($this->config['prestador_inscricao_municipal'] ?? '')),
                'codigo_municipio' => trim((string) ($this->config['prestador_codigo_municipio'] ?? $this->getCodigoMunicipio())),
            ],
        ];

        foreach ($candidates as $candidate) {
            $cnpj = $this->normalizeDigits((string) ($candidate['cnpj'] ?? ''));
            $inscricao = trim((string) ($candidate['inscricao_municipal'] ?? ''));
            $codigoMunicipio = trim((string) ($candidate['codigo_municipio'] ?? $this->getCodigoMunicipio()));

            if ($cnpj !== '' && $inscricao !== '') {
                return [
                    'cnpj' => $cnpj,
                    'inscricao_municipal' => $inscricao,
                    'codigo_municipio' => $codigoMunicipio !== '' ? $codigoMunicipio : $this->getCodigoMunicipio(),
                ];
            }
        }

        throw new \InvalidArgumentException(
            'Joinville requer CNPJ e inscrição municipal do prestador para consulta/cancelamento.'
        );
    }

    private function xmlLocalDateTime(?string $value = null): string
    {
        if (is_string($value) && trim($value) !== '') {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s');
        }

        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s');
    }

    private function relocateSignature(string $xml, string $parentLocalName, string $afterLocalName): string
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $signature = $xpath->query("//*[local-name()='Signature' and namespace-uri()='http://www.w3.org/2000/09/xmldsig#']")->item(0);
        $targetParent = $xpath->query("//*[local-name()='{$parentLocalName}']")->item(0);

        if (!$signature instanceof \DOMElement || !$targetParent instanceof \DOMElement) {
            return $xml;
        }

        $currentParent = $signature->parentNode;
        if ($currentParent instanceof \DOMNode) {
            $currentParent->removeChild($signature);
        }

        $afterNode = null;
        foreach ($targetParent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $afterLocalName) {
                $afterNode = $child;
            }
        }

        if ($afterNode instanceof \DOMNode && $afterNode->nextSibling instanceof \DOMNode) {
            $targetParent->insertBefore($signature, $afterNode->nextSibling);
        } else {
            $targetParent->appendChild($signature);
        }

        return $dom->saveXML($dom->documentElement) ?: $xml;
    }

    private function isSoapDebugEnabled(): bool
    {
        $configFlag = (bool) ($this->config['debug_http'] ?? false);
        $envRaw = $_ENV['FISCAL_NFSE_DEBUG'] ?? getenv('FISCAL_NFSE_DEBUG') ?: '';
        $envFlag = in_array(strtolower((string) $envRaw), ['1', 'true', 'yes', 'on'], true);

        return $configFlag || $envFlag;
    }

    private function getSoapDebugLogPath(): string
    {
        $configured = (string) ($this->config['debug_log_file'] ?? '');
        if ($configured !== '') {
            return $configured;
        }

        return sys_get_temp_dir() . '/nfse-joinville-soap-debug.log';
    }

    private function logSoapDebug(array $artifacts): void
    {
        if (!$this->isSoapDebugEnabled()) {
            return;
        }

        $payload = [
            'ts' => date(DATE_ATOM),
            'provider' => 'PublicaProvider',
            'ambiente' => $this->getAmbiente(),
            'operation' => $artifacts['operation'] ?? null,
            'channel' => $artifacts['channel'] ?? null,
            'endpoint' => $this->resolveSoapEndpoint((string) ($artifacts['channel'] ?? 'services')),
            'request_xml' => $this->maskSensitiveData($artifacts['request_xml'] ?? null),
            'soap_envelope' => $this->maskSensitiveData($artifacts['soap_envelope'] ?? null),
            'response_xml' => $this->maskSensitiveData($artifacts['response_xml'] ?? null),
            'parsed_response' => $this->maskSensitiveData($artifacts['parsed_response'] ?? []),
            'transport' => $this->maskSensitiveData($artifacts['transport'] ?? []),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        @file_put_contents($this->getSoapDebugLogPath(), $line . PHP_EOL, FILE_APPEND);
    }

    private function maskSensitiveData(mixed $value): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/(cnpj|cpf|documento|email|telefone|protocolo|codigo_verificacao|inscricao|id)/i', $key) === 1) {
                    $masked[$key] = $this->maskSensitiveString((string) $item);
                    continue;
                }

                $masked[$key] = $this->maskSensitiveData($item);
            }

            return $masked;
        }

        if (is_string($value)) {
            return $this->maskSensitiveString($value);
        }

        return $value;
    }

    private function maskSensitiveString(string $value): string
    {
        $patterns = [
            '/(<(?:\w+:)?(?:Cpf|Cnpj|Documento|InscricaoMunicipal|Telefone|Email|Protocolo|CodigoVerificacao|Numero)\b[^>]*>)(.*?)(<\/(?:\w+:)?(?:Cpf|Cnpj|Documento|InscricaoMunicipal|Telefone|Email|Protocolo|CodigoVerificacao|Numero)>)/si',
            '/([A-Z0-9._%+-]+)@([A-Z0-9.-]+\.[A-Z]{2,})/i',
            '/\b\d{11,14}\b/',
        ];

        $value = preg_replace_callback(
            $patterns[0],
            static fn (array $matches): string => $matches[1] . str_repeat('*', max(4, strlen(trim($matches[2])))) . $matches[3],
            $value
        ) ?? $value;

        $value = preg_replace($patterns[1], '***@$2', $value) ?? $value;
        $value = preg_replace_callback(
            $patterns[2],
            static fn (array $matches): string => str_repeat('*', max(0, strlen($matches[0]) - 4)) . substr($matches[0], -4),
            $value
        ) ?? $value;
        $value = preg_replace('/\(\d{2}\)\s*\d{4,5}-?\d{4}/', '(**) *****-****', $value) ?? $value;

        return $value;
    }
}
