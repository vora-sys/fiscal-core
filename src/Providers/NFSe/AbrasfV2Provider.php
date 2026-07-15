<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use sabbajohn\FiscalCore\Support\NFSeSoapCurlTransport;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use NFePHP\Common\Certificate;

/**
 * Provider base para municipios que seguem ABRASF v2.02/v2.03.
 *
 * Esta classe cobre o contrato XML comum. Providers municipais com
 * envelopes, assinatura ou transporte especificos devem especializar o fluxo.
 */
class AbrasfV2Provider extends AbstractNFSeProvider implements NFSeOperationalIntrospectionInterface
{
    private const NFSE_NS = 'http://www.abrasf.org.br/nfse.xsd';
    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
    private const SERVICE_NS = 'http://nfse.abrasf.org.br';

    private NFSeSoapTransportInterface $transport;
    private ?string $lastRequestXml = null;
    private ?string $lastSoapEnvelope = null;
    private ?string $lastResponseXml = null;
    private array $lastResponseData = [];
    private array $lastOperationArtifacts = [];
    private array $lastTransportData = [];
    private ?string $lastOperation = null;
    private array $lastPrestadorContext = [];

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->transport = $config['soap_transport'] ?? new NFSeSoapCurlTransport();
    }

    public function emitir(array $dados): string
    {
        $this->validarDados($dados);
        $this->lastPrestadorContext = $this->extractPrestadorContext($dados['prestador'] ?? []);

        return $this->dispatchSoapOperation(
            'emitir',
            $this->resolveSoapOperationName('emitir', 'RecepcionarLoteRpsSincrono'),
            $this->montarXmlRps($dados)
        );
    }

    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        $numeroNfse = trim($chave);
        if ($numeroNfse === '') {
            throw new \InvalidArgumentException('Numero da NFSe e obrigatorio para consulta ABRASF.');
        }

        $this->dispatchSoapOperation(
            'consultar_nfse_numero',
            $this->resolveSoapOperationName('consultar_nfse_numero', 'ConsultarNfseServicoPrestado'),
            $this->montarXmlConsultarNfsePorNumero($numeroNfse)
        );

        return $this->normalizeConsultaResult('consultar_nfse_numero', [
            'chave_consulta' => $numeroNfse,
            'source' => 'consultar_nfse_numero',
        ]);
    }

    public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
    {
        $this->validarIdentificacaoRps($identificacaoRps);

        $this->dispatchSoapOperation(
            'consultar_nfse_rps',
            $this->resolveSoapOperationName('consultar_nfse_rps', 'ConsultarNfsePorRps'),
            $this->montarXmlConsultarNfsePorRps($identificacaoRps)
        );

        return $this->normalizeConsultaResult('consultar_nfse_rps', [
            'chave_consulta' => (string) $identificacaoRps['numero'],
            'source' => 'consultar_nfse_rps',
        ]);
    }

    public function consultarLote(string $protocolo): NFSeConsultaResultInterface
    {
        if (trim($protocolo) === '') {
            throw new \InvalidArgumentException('Protocolo do lote e obrigatorio para consulta ABRASF.');
        }

        $this->dispatchSoapOperation(
            'consultar_lote',
            $this->resolveSoapOperationName('consultar_lote', 'ConsultarLoteRps'),
            $this->montarXmlConsultarLote($protocolo)
        );

        return $this->normalizeConsultaResult('consultar_lote', [
            'chave_consulta' => $protocolo,
            'source' => 'consultar_lote',
        ]);
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Numero da NFSe e obrigatorio para cancelamento ABRASF.');
        }

        $this->dispatchSoapOperation(
            'cancelar_nfse',
            $this->resolveSoapOperationName('cancelar_nfse', 'CancelarNfse'),
            $this->montarXmlCancelarNfse($chave, $motivo, $protocolo)
        );

        return ($this->lastResponseData['status'] ?? 'unknown') === 'success';
    }

    public function substituir(string $chave, array $dados): string
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Numero da NFSe substituida e obrigatorio para substituicao ABRASF.');
        }

        $this->validarDados($dados);
        $this->lastPrestadorContext = $this->extractPrestadorContext($dados['prestador'] ?? []);

        return $this->dispatchSoapOperation(
            'substituir_nfse',
            $this->resolveSoapOperationName('substituir_nfse', 'SubstituirNfse'),
            $this->montarXmlSubstituirNfse($chave, $dados)
        );
    }

    protected function montarXmlRps(array $dados): string
    {
        $this->validarDados($dados);

        $prestador = $dados['prestador'];
        $tomador = $dados['tomador'];
        $servico = $this->resolveServicoData($dados);
        $rps = $dados['rps'] ?? [];
        $lote = $dados['lote'] ?? [];
        $documentId = (string) ($dados['id'] ?? 'AbrasfRps1');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'EnviarLoteRpsSincronoEnvio');
        $dom->appendChild($root);

        $loteRps = $this->appendXmlNode($dom, $root, 'LoteRps', null, self::NFSE_NS);
        $loteRps->setAttribute('Id', (string) ($lote['id'] ?? 'LoteAbrasf1'));
        $loteRps->setAttribute('versao', (string) ($this->config['versao'] ?? '2.03'));

        $this->appendXmlNode($dom, $loteRps, 'NumeroLote', (string) ($lote['numero'] ?? '1'), self::NFSE_NS);
        $cpfCnpjLote = $this->appendXmlNode($dom, $loteRps, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpjLote, $prestador['cnpj'] ?? $prestador['cpf'] ?? '');
        $this->appendXmlNode(
            $dom,
            $loteRps,
            'InscricaoMunicipal',
            (string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal']),
            self::NFSE_NS
        );
        $this->appendXmlNode($dom, $loteRps, 'QuantidadeRps', '1', self::NFSE_NS);

        $listaRps = $this->appendXmlNode($dom, $loteRps, 'ListaRps', null, self::NFSE_NS);
        $rpsNode = $this->appendXmlNode($dom, $listaRps, 'Rps', null, self::NFSE_NS);
        $infDeclaracao = $this->appendXmlNode(
            $dom,
            $rpsNode,
            'InfDeclaracaoPrestacaoServico',
            null,
            self::NFSE_NS
        );
        $infDeclaracao->setAttribute('Id', $documentId);

        $this->appendRpsNode($dom, $infDeclaracao, $rps, $documentId);
        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'Competencia',
            $this->xmlDate((string) ($dados['competencia'] ?? $servico['competencia'] ?? $rps['data_emissao'] ?? null)),
            self::NFSE_NS
        );
        $this->appendServicoNode($dom, $infDeclaracao, $servico);
        $this->appendPrestadorNode($dom, $infDeclaracao, $prestador);
        $this->appendTomadorNode($dom, $infDeclaracao, $tomador);

        if (!empty($servico['intermediario'])) {
            $this->appendIntermediarioNode($dom, $infDeclaracao, (array) $servico['intermediario']);
        }

        if (!empty($servico['construcao_civil'])) {
            $this->appendConstrucaoCivilNode($dom, $infDeclaracao, (array) $servico['construcao_civil']);
        }

        $this->appendOptionalValue(
            $dom,
            $infDeclaracao,
            'RegimeEspecialTributacao',
            $prestador['regime_especial_tributacao'] ?? $dados['regime_especial_tributacao'] ?? null
        );
        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'OptanteSimplesNacional',
            $this->booleanCode((bool) ($prestador['simples_nacional'] ?? $dados['simples_nacional'] ?? false)),
            self::NFSE_NS
        );
        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'IncentivadorCultural',
            $this->booleanCode((bool) ($prestador['incentivador_cultural'] ?? $dados['incentivador_cultural'] ?? false)),
            self::NFSE_NS
        );

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    protected function processarResposta(string $xmlResposta): array
    {
        if (trim($xmlResposta) === '') {
            return [
                'status' => 'empty',
                'mensagens' => ['Resposta vazia do webservice ABRASF.'],
            ];
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xmlResposta)) {
            return [
                'status' => 'invalid_xml',
                'mensagens' => ['Resposta XML invalida do webservice ABRASF.'],
                'raw_xml' => $xmlResposta,
            ];
        }

        $xpath = new \DOMXPath($dom);
        $mensagens = $this->extractMensagens($xpath);
        $faultString = trim((string) $xpath->evaluate("string(//*[local-name()='Fault']/*[local-name()='faultstring'])"));
        if ($faultString !== '') {
            $mensagens[] = $faultString;
        }

        $listaNfse = [];
        foreach ($xpath->query("//*[local-name()='CompNfse']") as $nfseNode) {
            if ($nfseNode instanceof \DOMNode) {
                $listaNfse[] = $this->parseCompNfse($xpath, $nfseNode);
            }
        }

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
                'sucesso' => $mensagens === [],
            ];
        }

        $protocolo = $this->firstNodeValue($xpath, ["//*[local-name()='Protocolo']"]);
        $numeroLote = $this->firstNodeValue($xpath, ["//*[local-name()='NumeroLote']"]);
        $dataRecebimento = $this->firstNodeValue($xpath, ["//*[local-name()='DataRecebimento']"]);
        $situacaoLote = $this->firstNodeValue($xpath, ["//*[local-name()='Situacao']"]);
        $rootName = $dom->documentElement?->localName;
        $hasSuccessPayload = $listaNfse !== []
            || $cancelamento !== null
            || $protocolo !== null
            || $numeroLote !== null
            || $situacaoLote !== null;

        return [
            'status' => $mensagens !== [] ? 'error' : ($hasSuccessPayload ? 'success' : 'unknown'),
            'operation_response' => $rootName,
            'fault' => $faultString !== '' ? ['message' => $faultString] : null,
            'protocolo' => $protocolo,
            'numero_lote' => $numeroLote,
            'data_recebimento' => $dataRecebimento,
            'situacao_lote' => $situacaoLote,
            'nfse' => $listaNfse[0] ?? null,
            'lista_nfse' => $listaNfse,
            'cancelamento' => $cancelamento,
            'mensagens' => array_values(array_unique(array_filter($mensagens))),
            'raw_xml' => $xmlResposta,
        ];
    }

    public function validarDados(array $dados): bool
    {
        $servico = $dados['servico'] ?? [];
        $prestador = $dados['prestador'] ?? [];
        $tomador = $dados['tomador'] ?? [];
        $required = [
            'prestador.cnpj' => $this->normalizeDigits((string) ($prestador['cnpj'] ?? $prestador['cpf'] ?? '')),
            'prestador.inscricaoMunicipal' => (string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal'] ?? ''),
            'tomador.documento' => $this->normalizeDigits((string) ($tomador['documento'] ?? $tomador['cnpj'] ?? $tomador['cpf'] ?? '')),
            'tomador.razao_social' => (string) ($tomador['razao_social'] ?? $tomador['nome'] ?? ''),
            'servico.item_lista_servico' => (string) ($servico['item_lista_servico'] ?? $servico['codigo'] ?? ''),
            'servico.codigo_municipio' => $this->normalizeDigits((string) ($servico['codigo_municipio'] ?? $this->getCodigoMunicipio())),
            'servico.discriminacao' => (string) ($servico['discriminacao'] ?? $servico['descricao'] ?? ''),
            'valor_servicos' => $dados['valor_servicos'] ?? $servico['valor_servicos'] ?? null,
        ];

        foreach ($required as $field => $value) {
            if (trim((string) $value) === '') {
                throw new \InvalidArgumentException("Campo obrigatorio ausente: {$field}");
            }
        }

        return true;
    }

    private function appendRpsNode(\DOMDocument $dom, \DOMElement $parent, array $rps, string $documentId): void
    {
        $infRps = $this->appendXmlNode($dom, $parent, 'Rps', null, self::NFSE_NS);
        $infRps->setAttribute('Id', (string) ($rps['id'] ?? ($documentId . '-rps')));

        $identificacaoRps = $this->appendXmlNode($dom, $infRps, 'IdentificacaoRps', null, self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Numero', (string) ($rps['numero'] ?? '1'), self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Serie', (string) ($rps['serie'] ?? 'RPS'), self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacaoRps, 'Tipo', (string) ($rps['tipo'] ?? '1'), self::NFSE_NS);
        $this->appendXmlNode(
            $dom,
            $infRps,
            'DataEmissao',
            $this->xmlDate((string) ($rps['data_emissao'] ?? null)),
            self::NFSE_NS
        );
        $this->appendXmlNode($dom, $infRps, 'Status', (string) ($rps['status'] ?? '1'), self::NFSE_NS);
    }

    private function appendServicoNode(\DOMDocument $dom, \DOMElement $parent, array $servico): void
    {
        $servicoNode = $this->appendXmlNode($dom, $parent, 'Servico', null, self::NFSE_NS);
        $valores = $this->appendXmlNode($dom, $servicoNode, 'Valores', null, self::NFSE_NS);
        $this->appendDecimalNode($dom, $valores, 'ValorServicos', $servico['valor_servicos']);
        $this->appendOptionalDecimal($dom, $valores, 'ValorDeducoes', $servico['valor_deducoes'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorPis', $servico['valor_pis'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorCofins', $servico['valor_cofins'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorInss', $servico['valor_inss'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorIr', $servico['valor_ir'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorCsll', $servico['valor_csll'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'OutrasRetencoes', $servico['outras_retencoes'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'ValorIss', $servico['valor_iss'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'Aliquota', $servico['aliquota_xml'] ?? $servico['aliquota'] ?? null, 4);
        $this->appendOptionalDecimal($dom, $valores, 'DescontoIncondicionado', $servico['desconto_incondicionado'] ?? null);
        $this->appendOptionalDecimal($dom, $valores, 'DescontoCondicionado', $servico['desconto_condicionado'] ?? null);

        $this->appendXmlNode($dom, $servicoNode, 'IssRetido', $this->booleanCode((bool) ($servico['iss_retido'] ?? false)), self::NFSE_NS);
        $this->appendXmlNode($dom, $servicoNode, 'ItemListaServico', (string) $servico['item_lista_servico'], self::NFSE_NS);
        $this->appendOptionalValue($dom, $servicoNode, 'CodigoCnae', $servico['codigo_cnae'] ?? $servico['codigo_atividade'] ?? null);
        $this->appendOptionalValue($dom, $servicoNode, 'CodigoTributacaoMunicipio', $servico['codigo_tributacao_municipio'] ?? null);
        $this->appendXmlNode($dom, $servicoNode, 'Discriminacao', (string) $servico['discriminacao'], self::NFSE_NS);
        $this->appendXmlNode($dom, $servicoNode, 'CodigoMunicipio', $this->normalizeDigits((string) $servico['codigo_municipio']), self::NFSE_NS);
        $this->appendOptionalValue($dom, $servicoNode, 'CodigoPais', $servico['codigo_pais'] ?? null);
        $this->appendXmlNode(
            $dom,
            $servicoNode,
            'ExigibilidadeISS',
            (string) ($servico['exigibilidade_iss'] ?? '1'),
            self::NFSE_NS
        );
        $this->appendOptionalValue($dom, $servicoNode, 'MunicipioIncidencia', $servico['municipio_incidencia'] ?? null);
        $this->appendOptionalValue($dom, $servicoNode, 'NumeroProcesso', $servico['numero_processo'] ?? null);
    }

    private function appendPrestadorNode(\DOMDocument $dom, \DOMElement $parent, array $prestador): void
    {
        $prestadorNode = $this->appendXmlNode($dom, $parent, 'Prestador', null, self::NFSE_NS);
        $cpfCnpj = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $prestador['cnpj'] ?? $prestador['cpf'] ?? '');
        $this->appendXmlNode(
            $dom,
            $prestadorNode,
            'InscricaoMunicipal',
            (string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal']),
            self::NFSE_NS
        );
    }

    private function appendTomadorNode(\DOMDocument $dom, \DOMElement $parent, array $tomador): void
    {
        $tomadorNode = $this->appendXmlNode($dom, $parent, 'Tomador', null, self::NFSE_NS);
        $identificacaoTomador = $this->appendXmlNode($dom, $tomadorNode, 'IdentificacaoTomador', null, self::NFSE_NS);
        $cpfCnpjTomador = $this->appendXmlNode($dom, $identificacaoTomador, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpjTomador, $tomador['documento'] ?? $tomador['cnpj'] ?? $tomador['cpf'] ?? '');
        $this->appendOptionalValue($dom, $identificacaoTomador, 'InscricaoMunicipal', $tomador['inscricaoMunicipal'] ?? $tomador['inscricao_municipal'] ?? null);

        $this->appendXmlNode($dom, $tomadorNode, 'RazaoSocial', (string) ($tomador['razao_social'] ?? $tomador['nome']), self::NFSE_NS);

        if (isset($tomador['endereco']) && is_array($tomador['endereco'])) {
            $this->appendEnderecoNode($dom, $tomadorNode, $tomador['endereco']);
        }

        if (isset($tomador['email']) || isset($tomador['telefone'])) {
            $contato = $this->appendXmlNode($dom, $tomadorNode, 'Contato', null, self::NFSE_NS);
            $this->appendOptionalValue($dom, $contato, 'Telefone', $tomador['telefone'] ?? null);
            $this->appendOptionalValue($dom, $contato, 'Email', $tomador['email'] ?? null);
        }
    }

    private function appendEnderecoNode(\DOMDocument $dom, \DOMElement $parent, array $endereco): void
    {
        $enderecoNode = $this->appendXmlNode($dom, $parent, 'Endereco', null, self::NFSE_NS);
        $this->appendOptionalValue($dom, $enderecoNode, 'Endereco', $endereco['logradouro'] ?? $endereco['endereco'] ?? null);
        $this->appendOptionalValue($dom, $enderecoNode, 'Numero', $endereco['numero'] ?? null);
        $this->appendOptionalValue($dom, $enderecoNode, 'Complemento', $endereco['complemento'] ?? null);
        $this->appendOptionalValue($dom, $enderecoNode, 'Bairro', $endereco['bairro'] ?? null);
        $this->appendOptionalValue($dom, $enderecoNode, 'CodigoMunicipio', $endereco['codigo_municipio'] ?? null);
        $this->appendOptionalValue($dom, $enderecoNode, 'Uf', $endereco['uf'] ?? null);
        $cep = $this->normalizeDigits((string) ($endereco['cep'] ?? ''));
        if ($cep !== '') {
            $this->appendXmlNode($dom, $enderecoNode, 'Cep', $cep, self::NFSE_NS);
        }
    }

    private function appendIntermediarioNode(\DOMDocument $dom, \DOMElement $parent, array $intermediario): void
    {
        $node = $this->appendXmlNode($dom, $parent, 'Intermediario', null, self::NFSE_NS);
        $identificacao = $this->appendXmlNode($dom, $node, 'IdentificacaoIntermediario', null, self::NFSE_NS);
        $cpfCnpj = $this->appendXmlNode($dom, $identificacao, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $intermediario['documento'] ?? $intermediario['cnpj'] ?? $intermediario['cpf'] ?? '');
        $this->appendOptionalValue($dom, $identificacao, 'InscricaoMunicipal', $intermediario['inscricao_municipal'] ?? null);
        $this->appendOptionalValue($dom, $node, 'RazaoSocial', $intermediario['razao_social'] ?? null);
    }

    private function appendConstrucaoCivilNode(\DOMDocument $dom, \DOMElement $parent, array $construcaoCivil): void
    {
        $node = $this->appendXmlNode($dom, $parent, 'ConstrucaoCivil', null, self::NFSE_NS);
        $this->appendOptionalValue($dom, $node, 'CodigoObra', $construcaoCivil['codigo_obra'] ?? null);
        $this->appendOptionalValue($dom, $node, 'Art', $construcaoCivil['art'] ?? null);
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
        $cpfCnpj = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal'], self::NFSE_NS);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlConsultarNfsePorNumero(string $numeroNfse): string
    {
        $prestador = $this->resolvePrestadorContext();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'ConsultarNfseServicoPrestadoEnvio');
        $dom->appendChild($root);

        $prestadorNode = $this->appendXmlNode($dom, $root, 'Prestador', null, self::NFSE_NS);
        $cpfCnpj = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal'], self::NFSE_NS);
        $this->appendXmlNode($dom, $root, 'NumeroNfse', $numeroNfse, self::NFSE_NS);
        $this->appendXmlNode($dom, $root, 'Pagina', '1', self::NFSE_NS);

        return $dom->saveXML($dom->documentElement) ?: '';
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
        $cpfCnpj = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal'], self::NFSE_NS);
        $this->appendXmlNode($dom, $root, 'Protocolo', trim($protocolo), self::NFSE_NS);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlCancelarNfse(string $numeroNfse, string $motivo, ?string $protocolo): string
    {
        $prestador = $this->resolvePrestadorContext();
        $codigoCancelamento = trim((string) ($protocolo ?: ($this->config['cancelamento_codigo'] ?? '1')));

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'CancelarNfseEnvio');
        $dom->appendChild($root);

        $pedido = $this->appendXmlNode($dom, $root, 'Pedido', null, self::NFSE_NS);
        $infPedido = $this->appendXmlNode($dom, $pedido, 'InfPedidoCancelamento', null, self::NFSE_NS);
        $infPedido->setAttribute(
            'Id',
            sprintf('Cancelamento_%s_%s', $prestador['cnpj'], $this->normalizeDigits($numeroNfse))
        );

        $identificacao = $this->appendXmlNode($dom, $infPedido, 'IdentificacaoNfse', null, self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacao, 'Numero', trim($numeroNfse), self::NFSE_NS);
        $cpfCnpj = $this->appendXmlNode($dom, $identificacao, 'CpfCnpj', null, self::NFSE_NS);
        $this->appendCpfCnpjNode($dom, $cpfCnpj, $prestador['cnpj']);
        $this->appendXmlNode($dom, $identificacao, 'InscricaoMunicipal', $prestador['inscricao_municipal'], self::NFSE_NS);
        $this->appendXmlNode($dom, $identificacao, 'CodigoMunicipio', $prestador['codigo_municipio'], self::NFSE_NS);
        $this->appendXmlNode($dom, $infPedido, 'CodigoCancelamento', $codigoCancelamento, self::NFSE_NS);
        $this->appendOptionalValue($dom, $infPedido, 'MotivoCancelamento', $motivo);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlSubstituirNfse(string $numeroNfse, array $dados): string
    {
        $motivo = trim((string) (
            $dados['substituicao']['motivo']
            ?? $dados['justificativa']
            ?? $dados['justificativa_substituicao']
            ?? $dados['motivo_cancelamento']
            ?? 'Substituicao de NFSe'
        ));
        $codigoCancelamento = $dados['substituicao']['codigo_cancelamento']
            ?? $dados['codigo_cancelamento']
            ?? ($this->config['substitution_reason_codes'][(string) ($dados['motivo_substituicao'] ?? 'outros')] ?? null)
            ?? null;

        $cancelamentoXml = $this->montarXmlCancelarNfse(
            $numeroNfse,
            $motivo,
            is_scalar($codigoCancelamento) ? (string) $codigoCancelamento : null
        );
        $rpsXml = $this->montarXmlRps($dados);

        $cancelamentoDom = new \DOMDocument('1.0', 'UTF-8');
        $cancelamentoDom->preserveWhiteSpace = false;
        $cancelamentoDom->formatOutput = false;
        if (!@$cancelamentoDom->loadXML($cancelamentoXml)) {
            throw new \RuntimeException('XML de cancelamento invalido para substituicao ABRASF.');
        }

        $rpsDom = new \DOMDocument('1.0', 'UTF-8');
        $rpsDom->preserveWhiteSpace = false;
        $rpsDom->formatOutput = false;
        if (!@$rpsDom->loadXML($rpsXml)) {
            throw new \RuntimeException('XML de RPS invalido para substituicao ABRASF.');
        }

        $cancelamentoXpath = new \DOMXPath($cancelamentoDom);
        $rpsXpath = new \DOMXPath($rpsDom);
        $pedido = $cancelamentoXpath->query("//*[local-name()='Pedido']")->item(0);
        $rps = $rpsXpath->query("//*[local-name()='ListaRps']/*[local-name()='Rps']")->item(0);

        if (!$pedido instanceof \DOMElement || !$rps instanceof \DOMElement) {
            throw new \RuntimeException('Nos obrigatorios para substituicao ABRASF nao encontrados.');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NFSE_NS, 'SubstituirNfseEnvio');
        $dom->appendChild($root);

        $substituicao = $this->appendXmlNode($dom, $root, 'SubstituicaoNfse', null, self::NFSE_NS);
        $substituicao->appendChild($dom->importNode($pedido, true));
        $substituicao->appendChild($dom->importNode($rps, true));

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function resolveServicoData(array $dados): array
    {
        $servico = $dados['servico'];
        $valorServicos = $dados['valor_servicos'] ?? $servico['valor_servicos'] ?? null;
        $servico['valor_servicos'] = $valorServicos;
        $servico['item_lista_servico'] = $servico['item_lista_servico'] ?? $servico['codigo'];
        $servico['discriminacao'] = $servico['discriminacao'] ?? $servico['descricao'];
        $servico['codigo_municipio'] = $servico['codigo_municipio'] ?? $this->getCodigoMunicipio();

        return $servico;
    }

    private function appendCpfCnpjNode(\DOMDocument $dom, \DOMElement $parent, mixed $documento): void
    {
        $digits = $this->normalizeDigits((string) $documento);
        $name = strlen($digits) === 11 ? 'Cpf' : 'Cnpj';
        $this->appendXmlNode($dom, $parent, $name, $digits, self::NFSE_NS);
    }

    private function dispatchSoapOperation(string $operationKey, string $soapOperation, string $requestXml): string
    {
        if ($this->shouldSignOperation($operationKey)) {
            $requestXml = $this->assinarXml($requestXml, $operationKey);
        }

        $soapEnvelope = $this->montarSoapEnvelope($requestXml, $soapOperation);
        $transportData = $this->transport->send(
            $this->resolveSoapEndpoint(),
            $soapEnvelope,
            [
                'soap_action' => $this->resolveSoapAction($operationKey),
                'timeout' => $this->getTimeout(),
                'soap_operation' => $soapOperation,
                'operation' => $operationKey,
            ]
        );

        $responseXml = (string) ($transportData['response_xml'] ?? '');
        $parsedResponse = $this->processarResposta($responseXml);
        $this->persistArtifacts($operationKey, $requestXml, $soapEnvelope, $responseXml, $transportData, $parsedResponse);

        return $responseXml;
    }

    private function assinarXml(string $xml, string $operationKey): string
    {
        $certificate = $this->resolveCertificate();
        if (!$certificate instanceof Certificate) {
            throw new \RuntimeException('Certificado digital requerido para assinatura ABRASF nao foi carregado.');
        }

        if ($operationKey === 'emitir') {
            return $this->assinarXmlEmissao($certificate, $xml);
        }

        if ($operationKey === 'substituir_nfse') {
            return $this->assinarXmlSubstituicao($certificate, $xml);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura ABRASF.');
        }

        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Raiz do XML nao encontrada para assinatura ABRASF.');
        }

        $xpath = new \DOMXPath($dom);
        if ($operationKey === 'cancelar_nfse') {
            $pedido = $xpath->query("//*[local-name()='Pedido']")->item(0);
            $infPedido = $xpath->query("//*[local-name()='InfPedidoCancelamento']")->item(0);
            if (!$pedido instanceof \DOMElement || !$infPedido instanceof \DOMElement) {
                throw new \RuntimeException('Nos obrigatorios para assinatura ABRASF de cancelamento nao encontrados.');
            }

            $this->appendSignatureNode($dom, $certificate, $pedido, $infPedido, 'Id');

            return $dom->saveXML($dom->documentElement) ?: '';
        }

        if (!$root->hasAttribute('Id')) {
            $root->setAttribute('Id', sprintf('AbrasfRequest%s', substr(sha1($operationKey), 0, 12)));
        }

        $this->appendSignatureNode($dom, $certificate, $root, $root, 'Id', $this->resolveSignatureAlgorithm(), true);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function assinarXmlEmissao(Certificate $certificate, string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura da emissao ABRASF.');
        }

        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Raiz do XML nao encontrada para assinatura da emissao ABRASF.');
        }

        $xpath = new \DOMXPath($dom);
        $loteRps = $xpath->query("//*[local-name()='LoteRps']")->item(0);
        $rpsWrapper = $xpath->query("//*[local-name()='ListaRps']/*[local-name()='Rps']")->item(0);
        $infDeclaracao = $xpath->query("//*[local-name()='InfDeclaracaoPrestacaoServico']")->item(0);

        if (
            !$loteRps instanceof \DOMElement
            || !$rpsWrapper instanceof \DOMElement
            || !$infDeclaracao instanceof \DOMElement
        ) {
            throw new \RuntimeException('Nos obrigatorios para assinatura da emissao ABRASF nao encontrados.');
        }

        $algorithm = $this->resolveSignatureAlgorithm();
        $this->appendSignatureNode($dom, $certificate, $rpsWrapper, $infDeclaracao, 'Id', $algorithm);
        $this->appendSignatureNode($dom, $certificate, $root, $loteRps, 'Id', $algorithm);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function assinarXmlSubstituicao(Certificate $certificate, string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura da substituicao ABRASF.');
        }

        $xpath = new \DOMXPath($dom);
        $pedido = $xpath->query("//*[local-name()='Pedido']")->item(0);
        $infPedido = $xpath->query("//*[local-name()='InfPedidoCancelamento']")->item(0);
        $rpsWrapper = $xpath->query("//*[local-name()='SubstituicaoNfse']/*[local-name()='Rps']")->item(0);
        $infDeclaracao = $xpath->query("//*[local-name()='InfDeclaracaoPrestacaoServico']")->item(0);

        if (
            !$pedido instanceof \DOMElement
            || !$infPedido instanceof \DOMElement
            || !$rpsWrapper instanceof \DOMElement
            || !$infDeclaracao instanceof \DOMElement
        ) {
            throw new \RuntimeException('Nos obrigatorios para assinatura da substituicao ABRASF nao encontrados.');
        }

        $algorithm = $this->resolveSignatureAlgorithm();
        $this->appendSignatureNode($dom, $certificate, $pedido, $infPedido, 'Id', $algorithm);
        $this->appendSignatureNode($dom, $certificate, $rpsWrapper, $infDeclaracao, 'Id', $algorithm);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function appendSignatureNode(
        \DOMDocument $dom,
        Certificate $certificate,
        \DOMElement $signatureParent,
        \DOMElement $signedNode,
        string $mark,
        int $algorithm = OPENSSL_ALGO_SHA1,
        bool $stripEmbeddedSignatures = false
    ): void {
        $nsSignatureMethod = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
        $nsDigestMethod = 'http://www.w3.org/2000/09/xmldsig#sha1';
        $digestAlgorithm = 'sha1';
        if ($algorithm === OPENSSL_ALGO_SHA256) {
            $nsSignatureMethod = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $nsDigestMethod = 'http://www.w3.org/2001/04/xmlenc#sha256';
            $digestAlgorithm = 'sha256';
        }

        $signatureNode = $dom->createElementNS(self::DSIG_NS, 'Signature');
        $signatureParent->appendChild($signatureNode);

        $signedInfoNode = $dom->createElement('SignedInfo');
        $signatureNode->appendChild($signedInfoNode);

        $canonicalizationMethodNode = $dom->createElement('CanonicalizationMethod');
        $canonicalizationMethodNode->setAttribute(
            'Algorithm',
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
        );
        $signedInfoNode->appendChild($canonicalizationMethodNode);

        $signatureMethodNode = $dom->createElement('SignatureMethod');
        $signatureMethodNode->setAttribute('Algorithm', $nsSignatureMethod);
        $signedInfoNode->appendChild($signatureMethodNode);

        $referenceNode = $dom->createElement('Reference');
        $signedInfoNode->appendChild($referenceNode);

        $idSigned = trim($signedNode->getAttribute($mark));
        $referenceNode->setAttribute('URI', $idSigned !== '' ? '#' . $idSigned : '');

        $transformsNode = $dom->createElement('Transforms');
        $referenceNode->appendChild($transformsNode);

        $transform1 = $dom->createElement('Transform');
        $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transformsNode->appendChild($transform1);

        $transform2 = $dom->createElement('Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transformsNode->appendChild($transform2);

        $digestMethodNode = $dom->createElement('DigestMethod');
        $digestMethodNode->setAttribute('Algorithm', $nsDigestMethod);
        $referenceNode->appendChild($digestMethodNode);

        $digestValueNode = $dom->createElement(
            'DigestValue',
            $this->calculateDigestValue($signedNode, $digestAlgorithm, $stripEmbeddedSignatures)
        );
        $referenceNode->appendChild($digestValueNode);

        $signedInfoCanonical = $signedInfoNode->C14N(true, false, null, null);
        if ($signedInfoCanonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar SignedInfo para assinatura ABRASF.');
        }

        $signatureValue = base64_encode($certificate->sign($signedInfoCanonical, $algorithm));
        $signatureNode->appendChild($dom->createElement('SignatureValue', $signatureValue));

        $keyInfoNode = $dom->createElement('KeyInfo');
        $signatureNode->appendChild($keyInfoNode);

        $x509DataNode = $dom->createElement('X509Data');
        $keyInfoNode->appendChild($x509DataNode);

        $x509CertificateNode = $dom->createElement('X509Certificate', $certificate->publicKey->unFormated());
        $x509DataNode->appendChild($x509CertificateNode);
    }

    private function calculateDigestValue(\DOMElement $node, string $algorithm, bool $stripEmbeddedSignatures): string
    {
        $targetNode = $node;
        if ($stripEmbeddedSignatures) {
            $referenceDom = new \DOMDocument('1.0', 'UTF-8');
            $referenceDom->preserveWhiteSpace = false;
            $referenceDom->formatOutput = false;
            $referenceRoot = $referenceDom->importNode($node, true);
            if (!$referenceRoot instanceof \DOMElement) {
                throw new \RuntimeException('Falha ao clonar no assinado ABRASF.');
            }

            $referenceDom->appendChild($referenceRoot);
            $referenceXPath = new \DOMXPath($referenceDom);
            foreach ($referenceXPath->query("//*[local-name()='Signature' and namespace-uri()='" . self::DSIG_NS . "']") as $embeddedSignature) {
                $embeddedSignature->parentNode?->removeChild($embeddedSignature);
            }

            $targetNode = $referenceRoot;
        }

        $canonical = $targetNode->C14N(true, false, null, null);
        if ($canonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar no assinado ABRASF.');
        }

        return base64_encode(hash($algorithm, $canonical, true));
    }

    private function shouldSignOperation(string $operationKey): bool
    {
        $configured = $this->config['sign_operations'] ?? [];

        return is_array($configured)
            && (in_array('*', $configured, true) || in_array($operationKey, $configured, true));
    }

    private function resolveSignatureAlgorithm(): int
    {
        $configured = strtolower(trim((string) ($this->config['signature_algorithm'] ?? 'sha1')));

        return in_array($configured, ['sha256', 'rsa-sha256'], true) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
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

    private function montarSoapEnvelope(string $requestXml, string $soapOperation): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:svc="%s" xmlns:nfse="%s">'
            . '<soapenv:Header><nfse:cabecalho><nfse:versaoDados>%s</nfse:versaoDados></nfse:cabecalho></soapenv:Header>'
            . '<soapenv:Body><svc:%s>%s</svc:%s></soapenv:Body>'
            . '</soapenv:Envelope>',
            self::SERVICE_NS,
            self::NFSE_NS,
            htmlspecialchars((string) $this->getVersao(), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $soapOperation,
            $requestXml,
            $soapOperation
        );
    }

    private function resolveSoapEndpoint(): string
    {
        return preg_replace('/\?wsdl$/i', '', $this->getWsdlUrl()) ?: $this->getWsdlUrl();
    }

    private function persistArtifacts(
        string $operationKey,
        string $requestXml,
        string $soapEnvelope,
        string $responseXml,
        array $transportData,
        array $parsedResponse
    ): void {
        $this->lastOperation = $operationKey;
        $this->lastRequestXml = $requestXml;
        $this->lastSoapEnvelope = $soapEnvelope;
        $this->lastResponseXml = $responseXml;
        $this->lastTransportData = $transportData;
        $this->lastResponseData = $parsedResponse;
        $this->lastOperationArtifacts = [
            'operation' => $operationKey,
            'request_xml' => $requestXml,
            'soap_envelope' => $soapEnvelope,
            'response_xml' => $responseXml,
            'parsed_response' => $parsedResponse,
            'transport' => $transportData,
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

    private function validarIdentificacaoRps(array $identificacaoRps): void
    {
        foreach (['numero', 'serie', 'tipo'] as $campo) {
            if (trim((string) ($identificacaoRps[$campo] ?? '')) === '') {
                throw new \InvalidArgumentException("Identificacao RPS invalida para ABRASF: campo {$campo} e obrigatorio.");
            }
        }
    }

    /**
     * @param array<string,mixed> $override
     * @return array{cnpj:string,inscricao_municipal:string,codigo_municipio:string}
     */
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
            'ABRASF requer CNPJ e inscricao municipal do prestador para consulta/cancelamento.'
        );
    }

    /**
     * @param array<string,mixed> $prestador
     * @return array{cnpj:string,inscricao_municipal:string,codigo_municipio:string}
     */
    private function extractPrestadorContext(array $prestador): array
    {
        return [
            'cnpj' => $this->normalizeDigits((string) ($prestador['cnpj'] ?? $prestador['cpf'] ?? '')),
            'inscricao_municipal' => trim((string) ($prestador['inscricaoMunicipal'] ?? $prestador['inscricao_municipal'] ?? '')),
            'codigo_municipio' => trim((string) ($prestador['codigo_municipio'] ?? $this->getCodigoMunicipio())),
        ];
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
        if (is_array($this->config['supported_operations'] ?? null) && $this->config['supported_operations'] !== []) {
            return array_values(array_map('strval', $this->config['supported_operations']));
        }

        $operations = [
            'emitir',
            'consultar_lote',
            'consultar_nfse_numero',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];
        if (($this->config['substitution_enabled'] ?? false) === true) {
            $operations[] = 'substituir_nfse';
        }

        return $operations;
    }

    private function resolveSoapOperationName(string $operationKey, string $default): string
    {
        $operations = is_array($this->config['soap_operations'] ?? null) ? $this->config['soap_operations'] : [];
        $operation = trim((string) ($operations[$operationKey] ?? ''));

        return $operation !== '' ? $operation : $default;
    }

    private function resolveSoapAction(string $operationKey): string
    {
        $actions = is_array($this->config['soap_action'] ?? null) ? $this->config['soap_action'] : [];

        return trim((string) ($actions[$operationKey] ?? ''));
    }

    private function appendDecimalNode(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value, int $precision = 2): void
    {
        $this->appendXmlNode($dom, $parent, $name, $this->decimal((float) $value, $precision), self::NFSE_NS);
    }

    private function appendOptionalDecimal(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value, int $precision = 2): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->appendDecimalNode($dom, $parent, $name, $value, $precision);
    }

    private function appendOptionalValue(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $this->appendXmlNode($dom, $parent, $name, (string) $value, self::NFSE_NS);
    }

    /**
     * @return list<string>
     */
    private function extractMensagens(\DOMXPath $xpath): array
    {
        $mensagens = [];
        foreach ($xpath->query("//*[local-name()='MensagemRetorno']") as $messageNode) {
            $codigo = trim((string) $xpath->evaluate("string(./*[local-name()='Codigo'])", $messageNode));
            $mensagem = trim((string) $xpath->evaluate("string(./*[local-name()='Mensagem'])", $messageNode));
            $correcao = trim((string) $xpath->evaluate("string(./*[local-name()='Correcao'])", $messageNode));
            $parts = array_values(array_filter([$codigo, $mensagem, $correcao]));
            if ($parts !== []) {
                $mensagens[] = implode(' ', $parts);
            }
        }

        return $mensagens;
    }

    /**
     * @param list<string> $queries
     */
    private function firstNodeValue(\DOMXPath $xpath, array $queries, ?\DOMNode $context = null): ?string
    {
        foreach ($queries as $query) {
            $value = trim((string) $xpath->evaluate('string(' . $query . ')', $context));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseCompNfse(\DOMXPath $xpath, \DOMNode $node): array
    {
        return [
            'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='InfNfse']/*[local-name()='Numero']", ".//*[local-name()='Numero']"], $node),
            'codigo_verificacao' => $this->firstNodeValue($xpath, [".//*[local-name()='CodigoVerificacao']"], $node),
            'data_emissao' => $this->firstNodeValue($xpath, [".//*[local-name()='DataEmissao']"], $node),
            'valor_servicos' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorServicos']"], $node),
            'valor_liquido' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorLiquidoNfse']"], $node),
            'tomador' => $this->firstNodeValue($xpath, [".//*[local-name()='TomadorServico']/*[local-name()='RazaoSocial']", ".//*[local-name()='Tomador']/*[local-name()='RazaoSocial']"], $node),
        ];
    }
}
