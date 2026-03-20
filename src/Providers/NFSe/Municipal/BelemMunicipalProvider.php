<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Providers\NFSe\Municipal;

use freeline\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use freeline\FiscalCore\Providers\NFSe\AbstractNFSeProvider;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\NFSeSchemaResolver;
use freeline\FiscalCore\Support\NFSeSchemaValidator;
use freeline\FiscalCore\Support\NFSeSoapCurlTransport;
use freeline\FiscalCore\Support\NFSeSoapTransportInterface;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

class BelemMunicipalProvider extends AbstractNFSeProvider implements NFSeOperationalIntrospectionInterface
{
    private const NFSE_NS = 'http://www.abrasf.org.br/nfse.xsd';
    private const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
    private const SERVICE_NS = 'http://nfse.abrasf.org.br';

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
            'RecepcionarLoteRpsSincrono',
            $requestXml,
            'emitir'
        );
    }

    public function consultarPorRps(array $identificacaoRps): string
    {
        $this->validarIdentificacaoRps($identificacaoRps);
        $baseRequestXml = $this->montarXmlConsultarNfsePorRps($identificacaoRps);
        $requestXml = $this->shouldSignOperation('consultar_nfse_rps')
            ? $this->assinarXml($baseRequestXml, 'consultar_nfse_rps')
            : $baseRequestXml;

        return $this->dispatchSoapOperation(
            'consultar_nfse_rps',
            'ConsultarNfsePorRps',
            $requestXml,
            'consultar_nfse_rps',
            $baseRequestXml
        );
    }

    public function consultarLote(string $protocolo): string
    {
        if (trim($protocolo) === '') {
            throw new \InvalidArgumentException('Protocolo do lote é obrigatório para consulta em Belém.');
        }

        $baseRequestXml = $this->montarXmlConsultarLote($protocolo);
        $requestXml = $this->shouldSignOperation('consultar_lote')
            ? $this->assinarXml($baseRequestXml, 'consultar_lote')
            : $baseRequestXml;

        return $this->dispatchSoapOperation(
            'consultar_lote',
            'ConsultarLoteRps',
            $requestXml,
            'consultar_lote',
            $baseRequestXml
        );
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Número da NFSe é obrigatório para cancelamento em Belém.');
        }

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('Motivo do cancelamento é obrigatório para Belém.');
        }

        $requestXml = $this->montarXmlCancelarNfse($chave, $motivo, $protocolo);
        if ($this->shouldSignOperation('cancelar_nfse')) {
            $requestXml = $this->assinarXml($requestXml, 'cancelar_nfse');
        }

        $this->dispatchSoapOperation(
            'cancelar_nfse',
            'CancelarNfse',
            $requestXml,
            'cancelar_nfse'
        );

        return ($this->lastResponseData['status'] ?? 'unknown') === 'success';
    }

    protected function montarXmlRps(array $dados): string
    {
        $prestador = $dados['prestador'];
        $servico = $this->resolveServicoData($dados);
        $tomador = $dados['tomador'];
        $rps = $dados['rps'] ?? [];
        $lote = $dados['lote'] ?? [];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElement('EnviarLoteRpsSincronoEnvio');
        $dom->appendChild($root);

        $loteRps = $this->appendXmlNode($dom, $root, 'LoteRps');
        $loteRps->setAttribute('Id', (string) ($lote['id'] ?? 'LoteBelem1'));
        $loteRps->setAttribute('versao', (string) ($this->config['versao'] ?? '2.03'));

        $this->appendXmlNode($dom, $loteRps, 'NumeroLote', (string) ($lote['numero'] ?? '1'));
        $cpfCnpjPrestador = $this->appendXmlNode($dom, $loteRps, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjPrestador, $this->normalizeDigits((string) $prestador['cnpj']));
        $this->appendXmlNode($dom, $loteRps, 'InscricaoMunicipal', (string) $prestador['inscricaoMunicipal']);
        $this->appendXmlNode($dom, $loteRps, 'QuantidadeRps', '1');

        $listaRps = $this->appendXmlNode($dom, $loteRps, 'ListaRps');
        $rpsNode = $this->appendXmlNode($dom, $listaRps, 'Rps');
        $infDeclaracao = $this->appendXmlNode($dom, $rpsNode, 'InfDeclaracaoPrestacaoServico');
        $infDeclaracao->setAttribute('Id', (string) ($dados['id'] ?? 'BelemRps1'));

        $infRps = $this->appendXmlNode($dom, $infDeclaracao, 'Rps');
        $infRps->setAttribute('Id', (string) ($rps['id'] ?? (($dados['id'] ?? 'BelemRps1') . '-rps')));

        $identificacaoRps = $this->appendXmlNode($dom, $infRps, 'IdentificacaoRps');
        $this->appendXmlNode($dom, $identificacaoRps, 'Numero', (string) ($rps['numero'] ?? '1'));
        $this->appendXmlNode($dom, $identificacaoRps, 'Serie', (string) ($rps['serie'] ?? 'RPS'));
        $this->appendXmlNode($dom, $identificacaoRps, 'Tipo', (string) ($rps['tipo'] ?? '1'));
        $this->appendXmlNode($dom, $infRps, 'DataEmissao', $this->xmlDate((string) ($rps['data_emissao'] ?? null)));
        $this->appendXmlNode($dom, $infRps, 'Status', (string) ($rps['status'] ?? '1'));

        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'Competencia',
            $this->xmlDate((string) ($dados['competencia'] ?? $rps['data_emissao'] ?? null))
        );

        $servicoNode = $this->appendXmlNode($dom, $infDeclaracao, 'Servico');
        $valoresNode = $this->appendXmlNode($dom, $servicoNode, 'Valores');
        $this->appendXmlNode($dom, $valoresNode, 'ValorServicos', $this->decimal((float) $servico['valor_servicos']));
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorDeducoes', $servico['valor_deducoes'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorPis', $servico['valor_pis'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorCofins', $servico['valor_cofins'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorInss', $servico['valor_inss'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorIr', $servico['valor_ir'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorCsll', $servico['valor_csll'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'OutrasRetencoes', $servico['outras_retencoes'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'ValorIss', $servico['valor_iss'] ?? null);
        $this->appendOptionalDecimal($dom, $valoresNode, 'Aliquota', $servico['aliquota'] ?? null, 4);
        $this->appendOptionalDecimal($dom, $valoresNode, 'DescontoIncondicionado', $servico['desconto_incondicionado'] ?? 0.0);
        $this->appendOptionalDecimal($dom, $valoresNode, 'DescontoCondicionado', $servico['desconto_condicionado'] ?? 0.0);

        $this->appendXmlNode($dom, $servicoNode, 'IssRetido', $this->booleanCode((bool) ($servico['iss_retido'] ?? false)));
        $this->appendXmlNode($dom, $servicoNode, 'ItemListaServico', (string) $servico['item_lista_servico']);
        $this->appendXmlNode($dom, $servicoNode, 'CodigoCnae', (string) $servico['codigo_cnae']);
        if (!empty($servico['codigo_tributacao_municipio'])) {
            $this->appendXmlNode($dom, $servicoNode, 'CodigoTributacaoMunicipio', (string) $servico['codigo_tributacao_municipio']);
        }
        $this->appendXmlNode($dom, $servicoNode, 'Discriminacao', (string) $servico['discriminacao']);
        $this->appendXmlNode($dom, $servicoNode, 'CodigoMunicipio', (string) $servico['codigo_municipio']);
        $this->appendXmlNode($dom, $servicoNode, 'ExigibilidadeISS', (string) ($servico['exigibilidade_iss'] ?? '1'));

        $prestadorNode = $this->appendXmlNode($dom, $infDeclaracao, 'Prestador');
        $cpfCnpjPrestadorInterno = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjPrestadorInterno, $this->normalizeDigits((string) $prestador['cnpj']));
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', (string) $prestador['inscricaoMunicipal']);

        $tomadorNode = $this->appendXmlNode($dom, $infDeclaracao, 'Tomador');
        $identificacaoTomador = $this->appendXmlNode($dom, $tomadorNode, 'IdentificacaoTomador');
        $cpfCnpjTomador = $this->appendXmlNode($dom, $identificacaoTomador, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjTomador, $this->normalizeDigits((string) $tomador['documento']));
        if (!empty($tomador['inscricaoMunicipal'])) {
            $this->appendXmlNode($dom, $identificacaoTomador, 'InscricaoMunicipal', (string) $tomador['inscricaoMunicipal']);
        }
        $this->appendXmlNode($dom, $tomadorNode, 'RazaoSocial', (string) $tomador['razao_social']);
        $enderecoNode = $this->appendXmlNode($dom, $tomadorNode, 'Endereco');
        $this->appendXmlNode($dom, $enderecoNode, 'Endereco', (string) $tomador['endereco']['logradouro']);
        $this->appendXmlNode($dom, $enderecoNode, 'Numero', (string) ($tomador['endereco']['numero'] ?? 'S/N'));
        if (!empty($tomador['endereco']['complemento'])) {
            $this->appendXmlNode($dom, $enderecoNode, 'Complemento', (string) $tomador['endereco']['complemento']);
        }
        $this->appendXmlNode($dom, $enderecoNode, 'Bairro', (string) $tomador['endereco']['bairro']);
        $this->appendXmlNode(
            $dom,
            $enderecoNode,
            'CodigoMunicipio',
            (string) ($tomador['endereco']['codigo_municipio'] ?? $servico['codigo_municipio'])
        );
        $this->appendXmlNode($dom, $enderecoNode, 'Uf', (string) ($tomador['endereco']['uf'] ?? 'PA'));
        $this->appendXmlNode($dom, $enderecoNode, 'Cep', $this->normalizeDigits((string) $tomador['endereco']['cep']));

        if (!empty($tomador['telefone']) || !empty($tomador['email'])) {
            $contatoNode = $this->appendXmlNode($dom, $tomadorNode, 'Contato');
            if (!empty($tomador['telefone'])) {
                $this->appendXmlNode($dom, $contatoNode, 'Telefone', $this->normalizeDigits((string) $tomador['telefone']));
            }
            if (!empty($tomador['email'])) {
                $this->appendXmlNode($dom, $contatoNode, 'Email', (string) $tomador['email']);
            }
        }

        if (!empty($prestador['regime_especial_tributacao'])) {
            $this->appendXmlNode(
                $dom,
                $infDeclaracao,
                'RegimeEspecialTributacao',
                (string) $prestador['regime_especial_tributacao']
            );
        }
        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'OptanteSimplesNacional',
            $this->booleanCode((bool) ($prestador['simples_nacional'] ?? false))
        );
        $this->appendXmlNode(
            $dom,
            $infDeclaracao,
            'IncentivoFiscal',
            $this->booleanCode((bool) ($prestador['incentivo_fiscal'] ?? false))
        );

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    public function validarDados(array $dados): bool
    {
        parent::validarDados($dados);

        $required = [
            'prestador.cnpj' => $this->normalizeDigits((string) ($dados['prestador']['cnpj'] ?? '')),
            'prestador.inscricaoMunicipal' => (string) ($dados['prestador']['inscricaoMunicipal'] ?? ''),
            'prestador.razao_social' => (string) ($dados['prestador']['razao_social'] ?? ''),
            'tomador.documento' => $this->normalizeDigits((string) ($dados['tomador']['documento'] ?? '')),
            'tomador.razao_social' => (string) ($dados['tomador']['razao_social'] ?? ''),
            'tomador.endereco.logradouro' => (string) ($dados['tomador']['endereco']['logradouro'] ?? ''),
            'tomador.endereco.bairro' => (string) ($dados['tomador']['endereco']['bairro'] ?? ''),
            'tomador.endereco.cep' => $this->normalizeDigits((string) ($dados['tomador']['endereco']['cep'] ?? '')),
            'servico.item_lista_servico' => (string) ($dados['servico']['item_lista_servico'] ?? $dados['servico']['codigo'] ?? ''),
            'servico.codigo_cnae' => (string) ($dados['servico']['codigo_cnae'] ?? $dados['servico']['codigo_atividade'] ?? ''),
            'servico.codigo_municipio' => (string) ($dados['servico']['codigo_municipio'] ?? ''),
            'servico.discriminacao' => (string) ($dados['servico']['discriminacao'] ?? $dados['servico']['descricao'] ?? ''),
            'servico.aliquota' => $dados['servico']['aliquota'] ?? null,
        ];

        foreach ($required as $field => $value) {
            if (trim((string) $value) === '') {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        if (!isset($dados['prestador']['mei']) && !isset($dados['prestador']['regime_tributario'])) {
            throw new \InvalidArgumentException(
                'Belém exige classificação explícita do emitente para distinguir MEI do fluxo municipal.'
            );
        }

        if (($dados['prestador']['mei'] ?? false) === true) {
            throw new \InvalidArgumentException('Emitente MEI deve usar o provider nacional para Belém.');
        }

        $this->assertItensCompativeis($dados);

        return true;
    }

    protected function processarResposta(string $xmlResposta): array
    {
        if (trim($xmlResposta) === '') {
            return [
                'status' => 'empty',
                'mensagens' => ['Resposta vazia do webservice de Belém.'],
            ];
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xmlResposta)) {
            return [
                'status' => 'invalid_xml',
                'mensagens' => ['Resposta XML inválida do webservice de Belém.'],
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

        $protocol = $this->firstNodeValue($xpath, [
            "//*[local-name()='Protocolo']",
        ]);
        $numeroLote = $this->firstNodeValue($xpath, [
            "//*[local-name()='NumeroLote']",
        ]);
        $dataRecebimento = $this->firstNodeValue($xpath, [
            "//*[local-name()='DataRecebimento']",
        ]);
        $rootName = $dom->documentElement?->localName;

        $nfse = null;
        $compNfse = $xpath->query("//*[local-name()='CompNfse']")->item(0);
        if ($compNfse instanceof \DOMNode) {
            $nfse = [
                'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='Numero']"], $compNfse),
                'codigo_verificacao' => $this->firstNodeValue($xpath, [".//*[local-name()='CodigoVerificacao']"], $compNfse),
                'data_emissao' => $this->firstNodeValue($xpath, [".//*[local-name()='DataEmissao']"], $compNfse),
                'valor_servicos' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorServicos']"], $compNfse),
                'valor_liquido' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorLiquidoNfse']"], $compNfse),
            ];
        }

        $listaNfse = [];
        foreach ($xpath->query("//*[local-name()='ListaNfse']/*[local-name()='CompNfse']") as $nfseNode) {
            $listaNfse[] = [
                'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='Numero']"], $nfseNode),
                'codigo_verificacao' => $this->firstNodeValue($xpath, [".//*[local-name()='CodigoVerificacao']"], $nfseNode),
                'data_emissao' => $this->firstNodeValue($xpath, [".//*[local-name()='DataEmissao']"], $nfseNode),
                'tomador' => $this->firstNodeValue($xpath, [".//*[local-name()='RazaoSocial']"], $nfseNode),
                'valor_servicos' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorServicos']"], $nfseNode),
            ];
        }

        foreach ($xpath->query("//*[local-name()='ListaNotaFiscal']/*[local-name()='Nfse']") as $nfseNode) {
            $listaNfse[] = [
                'numero' => $this->firstNodeValue($xpath, [".//*[local-name()='Numero']"], $nfseNode),
                'codigo_verificacao' => $this->firstNodeValue($xpath, [".//*[local-name()='CodigoVerificacao']"], $nfseNode),
                'data_emissao' => $this->firstNodeValue($xpath, [".//*[local-name()='DataEmissao']"], $nfseNode),
                'tomador' => $this->firstNodeValue($xpath, [".//*[local-name()='TomadorServico']/*[local-name()='RazaoSocial']"], $nfseNode),
                'valor_servicos' => $this->firstNodeValue($xpath, [".//*[local-name()='ValorServicos']"], $nfseNode),
            ];
        }

        $cancelamento = null;
        $cancelamentoNode = $xpath->query("//*[local-name()='InfPedidoCancelamento']")->item(0);
        if ($cancelamentoNode instanceof \DOMNode || str_contains((string) $rootName, 'CancelarNfseResponse')) {
            $cancelamento = [
                'numero' => $this->firstNodeValue($xpath, [
                    "//*[local-name()='InfPedidoCancelamento']//*[local-name()='Numero']",
                    "//*[local-name()='IdentificacaoNfse']/*[local-name()='Numero']",
                ]),
                'codigo_cancelamento' => $this->firstNodeValue($xpath, [
                    "//*[local-name()='CodigoCancelamento']",
                ]),
                'sucesso' => $mensagens === [],
            ];
        }

        $hasSuccessPayload = $nfse !== null
            || $listaNfse !== []
            || $cancelamento !== null
            || $protocol !== null
            || $numeroLote !== null;

        return [
            'status' => $mensagens !== [] ? 'error' : ($hasSuccessPayload ? 'success' : 'unknown'),
            'operation_response' => $rootName,
            'fault' => $faultString !== '' ? ['message' => $faultString] : null,
            'protocolo' => $protocol,
            'numero_lote' => $numeroLote,
            'data_recebimento' => $dataRecebimento,
            'nfse' => $nfse,
            'lista_nfse' => $listaNfse,
            'cancelamento' => $cancelamento,
            'mensagens' => array_values(array_filter($mensagens)),
            'raw_xml' => $xmlResposta,
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

    public function getLastTransportData(): array
    {
        return $this->lastTransportData;
    }

    public function getLastOperation(): ?string
    {
        return $this->lastOperation;
    }

    public function getLastOperationArtifacts(): array
    {
        return $this->lastOperationArtifacts;
    }

    public function getSupportedOperations(): array
    {
        return [
            'emitir_sincrono',
            'consultar_lote',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];
    }

    private function resolveServicoData(array $dados): array
    {
        $servico = $dados['servico'];

        if (!empty($dados['itens']) && is_array($dados['itens'])) {
            $descricao = [];
            $valorTotal = 0.0;
            foreach ($dados['itens'] as $item) {
                $descricao[] = trim((string) ($item['descricao'] ?? $servico['descricao'] ?? $servico['discriminacao'] ?? 'Servico'));
                $valorTotal += (float) ($item['valor_servicos'] ?? $item['valor'] ?? 0);
            }

            $servico['discriminacao'] = implode(' | ', array_filter($descricao));
            $servico['valor_servicos'] = $valorTotal;
        } else {
            $servico['discriminacao'] = (string) ($servico['discriminacao'] ?? $servico['descricao'] ?? '');
            $servico['valor_servicos'] = (float) ($dados['valor_servicos'] ?? $servico['valor_servicos'] ?? 0.0);
        }

        $servico['item_lista_servico'] = (string) ($servico['item_lista_servico'] ?? $servico['codigo'] ?? '');
        $servico['codigo_cnae'] = $this->normalizeDigits((string) ($servico['codigo_cnae'] ?? $servico['codigo_atividade'] ?? ''));
        $servico['codigo_municipio'] = $this->normalizeDigits((string) ($servico['codigo_municipio'] ?? $this->getCodigoMunicipio()));
        $servico['aliquota'] = $this->normalizeAliquota($servico['aliquota'] ?? null);

        return $servico;
    }

    private function normalizeAliquota(mixed $aliquota): float
    {
        if ($aliquota === null || $aliquota === '') {
            return 0.0;
        }

        $value = (float) $aliquota;

        return $value > 1 ? $value / 100 : $value;
    }

    private function validarIdentificacaoRps(array $identificacaoRps): void
    {
        foreach (['numero', 'serie', 'tipo'] as $campo) {
            if (trim((string) ($identificacaoRps[$campo] ?? '')) === '') {
                throw new \InvalidArgumentException("Identificação RPS inválida para Belém: campo {$campo} é obrigatório.");
            }
        }
    }

    private function montarXmlConsultarLote(string $protocolo): string
    {
        $prestador = $this->resolvePrestadorContext();
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $root = $dom->createElement('ConsultarLoteRpsEnvio');
        $dom->appendChild($root);

        $prestadorNode = $this->appendXmlNode($dom, $root, 'Prestador');
        $cpfCnpjNode = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjNode, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal']);
        $this->appendXmlNode($dom, $root, 'Protocolo', trim($protocolo));

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlConsultarNfsePorRps(array $identificacaoRps): string
    {
        $prestador = $this->resolvePrestadorContext($identificacaoRps['prestador'] ?? []);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $root = $dom->createElement('ConsultarNfseRpsEnvio');
        $dom->appendChild($root);

        $rpsNode = $this->appendXmlNode($dom, $root, 'IdentificacaoRps');
        $this->appendXmlNode($dom, $rpsNode, 'Numero', trim((string) $identificacaoRps['numero']));
        $this->appendXmlNode($dom, $rpsNode, 'Serie', trim((string) $identificacaoRps['serie']));
        $this->appendXmlNode($dom, $rpsNode, 'Tipo', trim((string) $identificacaoRps['tipo']));

        $prestadorNode = $this->appendXmlNode($dom, $root, 'Prestador');
        $cpfCnpjNode = $this->appendXmlNode($dom, $prestadorNode, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjNode, $prestador['cnpj']);
        $this->appendXmlNode($dom, $prestadorNode, 'InscricaoMunicipal', $prestador['inscricao_municipal']);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function montarXmlCancelarNfse(string $numeroNfse, string $motivo, ?string $protocolo): string
    {
        $prestador = $this->resolvePrestadorContext();
        $codigoCancelamento = $this->resolveCodigoCancelamento($protocolo);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement('CancelarNfseEnvio');
        $dom->appendChild($root);

        $pedido = $this->appendXmlNode($dom, $root, 'Pedido');
        $infPedido = $this->appendXmlNode($dom, $pedido, 'InfPedidoCancelamento');
        $infPedido->setAttribute(
            'Id',
            sprintf(
                'Cancelamento_%s_%s',
                $prestador['cnpj'],
                $this->normalizeDigits($numeroNfse)
            )
        );

        $identificacao = $this->appendXmlNode($dom, $infPedido, 'IdentificacaoNfse');
        $this->appendXmlNode($dom, $identificacao, 'Numero', trim($numeroNfse));
        $cpfCnpjNode = $this->appendXmlNode($dom, $identificacao, 'CpfCnpj');
        $this->appendDocumentoNode($dom, $cpfCnpjNode, $prestador['cnpj']);
        $this->appendXmlNode($dom, $identificacao, 'InscricaoMunicipal', $prestador['inscricao_municipal']);
        $this->appendXmlNode($dom, $identificacao, 'CodigoMunicipio', $prestador['codigo_municipio']);
        $this->appendXmlNode($dom, $infPedido, 'CodigoCancelamento', $codigoCancelamento);

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function dispatchSoapOperation(
        string $operationKey,
        string $soapOperation,
        string $requestXml,
        string $schemaOperation,
        ?string $schemaXml = null
    ): string {
        $this->assertRequestSchema($schemaXml ?? $requestXml, $schemaOperation);

        $soapEnvelope = $this->montarSoapEnvelope($requestXml, $soapOperation);
        $transportData = $this->transport->send(
            $this->resolveSoapEndpoint(),
            $soapEnvelope,
            [
                'soap_action' => '',
                'timeout' => $this->getTimeout(),
                'soap_operation' => $soapOperation,
                'operation' => $operationKey,
            ]
        );

        $responseXml = (string) ($transportData['response_xml'] ?? '');
        $parsedResponse = $this->processarResposta($responseXml);

        if ($this->shouldRetryConsultaWithAlternativeSignature($operationKey, $parsedResponse, $schemaXml)) {
            $certificate = $this->resolveCertificateForConsultaRetry();
            $attempts = [[
                'signature_variant' => 'prestador_reference',
                'request_xml' => $requestXml,
                'soap_envelope' => $soapEnvelope,
                'response_xml' => $responseXml,
                'parsed_response' => $parsedResponse,
            ]];

            foreach ($this->buildConsultaRetryVariants($operationKey, $certificate, $schemaXml) as $signatureVariant => $alternativeRequestXml) {
                $alternativeSoapEnvelope = $this->montarSoapEnvelope($alternativeRequestXml, $soapOperation);
                $alternativeTransportData = $this->transport->send(
                    $this->resolveSoapEndpoint(),
                    $alternativeSoapEnvelope,
                    [
                        'soap_action' => '',
                        'timeout' => $this->getTimeout(),
                        'soap_operation' => $soapOperation,
                        'operation' => $operationKey,
                        'retry_signature_variant' => $signatureVariant,
                    ]
                );

                $alternativeResponseXml = (string) ($alternativeTransportData['response_xml'] ?? '');
                $alternativeParsedResponse = $this->processarResposta($alternativeResponseXml);

                $attempts[] = [
                    'signature_variant' => $signatureVariant,
                    'request_xml' => $alternativeRequestXml,
                    'soap_envelope' => $alternativeSoapEnvelope,
                    'response_xml' => $alternativeResponseXml,
                    'parsed_response' => $alternativeParsedResponse,
                ];

                $requestXml = $alternativeRequestXml;
                $soapEnvelope = $alternativeSoapEnvelope;
                $transportData = $alternativeTransportData + [
                    'signature_variant' => $signatureVariant,
                    'retry_attempts' => $attempts,
                ];
                $responseXml = $alternativeResponseXml;
                $parsedResponse = $alternativeParsedResponse;

                if (!$this->shouldRetryConsultaWithAlternativeSignature($operationKey, $parsedResponse, $schemaXml)) {
                    break;
                }
            }

            $transportData += [
                'signature_variant' => 'prestador_reference',
                'retry_attempts' => $attempts,
            ];
        }

        $this->persistArtifacts(
            $operationKey,
            $requestXml,
            $soapEnvelope,
            $responseXml,
            $transportData,
            $parsedResponse
        );

        return $responseXml;
    }

    private function shouldRetryConsultaWithAlternativeSignature(
        string $operationKey,
        array $parsedResponse,
        ?string $unsignedRequestXml
    ): bool {
        if (!in_array($operationKey, ['consultar_lote', 'consultar_nfse_rps'], true)) {
            return false;
        }

        if (!is_string($unsignedRequestXml) || trim($unsignedRequestXml) === '') {
            return false;
        }

        $faultMessage = strtolower(trim((string)($parsedResponse['fault']['message'] ?? '')));
        if ($faultMessage === '') {
            return false;
        }

        return str_contains($faultMessage, 'assinatura');
    }

    private function resolveCertificateForConsultaRetry(): Certificate
    {
        $certificate = $this->resolveCertificate();
        if (!$certificate instanceof Certificate) {
            throw new \RuntimeException('Certificado digital obrigatório para o provider municipal de Belém.');
        }

        return $certificate;
    }

    private function buildConsultaRetryVariants(
        string $operationKey,
        Certificate $certificate,
        string $xml
    ): array
    {
        $variants = [
            'prestador_embedded' => $this->assinarXmlConsultaMovendoParaPrestador($certificate, $xml),
        ];

        if ($operationKey === 'consultar_nfse_rps') {
            $variants['rps_reference'] = $this->assinarXmlConsultaRpsReference($certificate, $xml);
        }

        $variants += [
            'root_reference' => $this->assinarXmlConsultaRootReference($certificate, $xml),
            'whole_document' => $this->assinarXmlConsultaWholeDocument($certificate, $xml),
            'unsigned' => $xml,
        ];

        return $variants;
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

        $this->logSoapDebug($this->lastOperationArtifacts);
    }

    private function assertItensCompativeis(array $dados): void
    {
        if (empty($dados['itens']) || !is_array($dados['itens'])) {
            return;
        }

        $first = null;
        foreach ($dados['itens'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Item {$index} inválido para emissão de Belém.");
            }

            $normalized = [
                'classificacao' => (string) ($item['codigo_cnae'] ?? $item['codigo_cbo'] ?? $dados['servico']['codigo_cnae'] ?? $dados['servico']['codigo_atividade'] ?? ''),
                'aliquota' => (string) ($item['aliquota'] ?? $dados['servico']['aliquota'] ?? ''),
                'incidencia' => (string) ($item['exigibilidade_iss'] ?? $dados['servico']['exigibilidade_iss'] ?? '1'),
                'iss_retido' => (string) ($item['iss_retido'] ?? $dados['servico']['iss_retido'] ?? '0'),
            ];

            if ($first === null) {
                $first = $normalized;
                continue;
            }

            if ($normalized !== $first) {
                throw new \InvalidArgumentException(
                    'Belém exige itens compatíveis com o mesmo CNAE/CBO, alíquota e regra de incidência na mesma nota.'
                );
            }
        }
    }

    private function appendDocumentoNode(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $documento,
        ?string $namespace = null
    ): void
    {
        if (strlen($documento) === 11) {
            $this->appendXmlNode($dom, $parent, 'Cpf', $documento, $namespace);
            return;
        }

        $this->appendXmlNode($dom, $parent, 'Cnpj', $documento, $namespace);
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

        $this->appendXmlNode($dom, $parent, $name, $this->decimal((float) $value, $precision));
    }

    private function assinarXml(string $xml, string $operationKey): string
    {
        $certificate = $this->resolveCertificate();
        if ($certificate === null) {
            throw new \RuntimeException('Certificado digital obrigatório para o provider municipal de Belém.');
        }

        return match ($operationKey) {
            'emitir' => $this->assinarXmlEmissao($certificate, $xml),
            'consultar_lote', 'consultar_nfse_rps' => $this->assinarXmlConsulta($certificate, $xml),
            'cancelar_nfse' => Signer::sign(
                $certificate,
                $xml,
                'InfPedidoCancelamento',
                'Id',
                OPENSSL_ALGO_SHA1,
                Signer::CANONICAL,
                'CancelarNfseEnvio'
            ),
            default => $xml,
        };
    }

    private function assinarXmlConsulta(Certificate $certificate, string $xml): string
    {
        return $this->assinarXmlConsultaNodeReference(
            $certificate,
            $xml,
            'Prestador',
            'PrestadorConsulta'
        );
    }

    private function assinarXmlConsultaRpsReference(Certificate $certificate, string $xml): string
    {
        return $this->assinarXmlConsultaNodeReference(
            $certificate,
            $xml,
            'IdentificacaoRps',
            'IdentificacaoRpsConsulta'
        );
    }

    private function assinarXmlConsultaNodeReference(
        Certificate $certificate,
        string $xml,
        string $signedNodeLocalName,
        string $signedNodeId
    ): string {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura da consulta de Belem.');
        }

        $xpath = new \DOMXPath($dom);
        $root = $dom->documentElement;
        $signedNode = $xpath->query("//*[local-name()='{$signedNodeLocalName}']")->item(0);

        if (!$root instanceof \DOMElement || !$signedNode instanceof \DOMElement) {
            throw new \RuntimeException('Nos obrigatorios para assinatura da consulta de Belem nao encontrados.');
        }

        if (!$signedNode->hasAttribute('Id')) {
            $signedNode->setAttribute('Id', $signedNodeId);
        }

        $this->appendSignatureNode(
            $dom,
            $certificate,
            $root,
            $signedNode,
            'Id',
            OPENSSL_ALGO_SHA1
        );

        return $dom->saveXML($dom->documentElement) ?: $xml;
    }

    private function assinarXmlConsultaMovendoParaPrestador(Certificate $certificate, string $xml): string
    {
        $signedXml = $this->assinarXmlConsulta($certificate, $xml);

        return $this->relocateSignature($signedXml, 'Prestador', 'InscricaoMunicipal');
    }

    private function assinarXmlConsultaRootReference(Certificate $certificate, string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura por raiz da consulta de Belem.');
        }

        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Raiz do XML nao encontrada para assinatura por raiz da consulta de Belem.');
        }

        if (!$root->hasAttribute('Id')) {
            $root->setAttribute('Id', 'ConsultaBelem');
        }

        $this->appendSignatureNode(
            $dom,
            $certificate,
            $root,
            $root,
            'Id',
            OPENSSL_ALGO_SHA1
        );

        return $dom->saveXML($dom->documentElement) ?: $xml;
    }

    private function assinarXmlConsultaWholeDocument(Certificate $certificate, string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML invalido para assinatura alternativa da consulta de Belem.');
        }

        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Raiz do XML nao encontrada para assinatura alternativa da consulta de Belem.');
        }

        $signatureNode = $dom->createElementNS(self::DSIG_NS, 'Signature');
        $root->appendChild($signatureNode);

        $signedInfoNode = $dom->createElement('SignedInfo');
        $signatureNode->appendChild($signedInfoNode);

        $canonicalizationMethodNode = $dom->createElement('CanonicalizationMethod');
        $canonicalizationMethodNode->setAttribute(
            'Algorithm',
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
        );
        $signedInfoNode->appendChild($canonicalizationMethodNode);

        $signatureMethodNode = $dom->createElement('SignatureMethod');
        $signatureMethodNode->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfoNode->appendChild($signatureMethodNode);

        $referenceNode = $dom->createElement('Reference');
        $referenceNode->setAttribute('URI', '');
        $signedInfoNode->appendChild($referenceNode);

        $transformsNode = $dom->createElement('Transforms');
        $referenceNode->appendChild($transformsNode);

        $transform1 = $dom->createElement('Transform');
        $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transformsNode->appendChild($transform1);

        $transform2 = $dom->createElement('Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transformsNode->appendChild($transform2);

        $digestMethodNode = $dom->createElement('DigestMethod');
        $digestMethodNode->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $referenceNode->appendChild($digestMethodNode);

        $referenceDom = new \DOMDocument('1.0', 'UTF-8');
        $referenceDom->preserveWhiteSpace = false;
        $referenceDom->formatOutput = false;
        $referenceRoot = $referenceDom->importNode($root, true);
        if (!$referenceRoot instanceof \DOMElement) {
            throw new \RuntimeException('Falha ao clonar documento da consulta de Belem para assinatura alternativa.');
        }

        $referenceDom->appendChild($referenceRoot);
        $referenceXPath = new \DOMXPath($referenceDom);
        foreach ($referenceXPath->query("//*[local-name()='Signature' and namespace-uri()='" . self::DSIG_NS . "']") as $embeddedSignature) {
            $embeddedSignature->parentNode?->removeChild($embeddedSignature);
        }

        $canonical = $referenceRoot->C14N(true, false, null, null);
        if ($canonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar documento inteiro na consulta de Belem.');
        }

        $referenceNode->appendChild($dom->createElement('DigestValue', base64_encode(hash('sha1', $canonical, true))));

        $signedInfoCanonical = $signedInfoNode->C14N(true, false, null, null);
        if ($signedInfoCanonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar SignedInfo da assinatura alternativa de Belem.');
        }

        $signatureValue = base64_encode($certificate->sign($signedInfoCanonical, OPENSSL_ALGO_SHA1));
        $signatureNode->appendChild($dom->createElement('SignatureValue', $signatureValue));

        $keyInfoNode = $dom->createElement('KeyInfo');
        $signatureNode->appendChild($keyInfoNode);
        $x509DataNode = $dom->createElement('X509Data');
        $keyInfoNode->appendChild($x509DataNode);
        $x509CertificateNode = $dom->createElement('X509Certificate', $certificate->publicKey->unFormated());
        $x509DataNode->appendChild($x509CertificateNode);

        return $dom->saveXML($dom->documentElement) ?: $xml;
    }

    private function assinarXmlEmissao(Certificate $certificate, string $xml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new \RuntimeException('XML inválido para assinatura da emissão de Belém.');
        }

        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('Raiz do XML não encontrada para assinatura da emissão de Belém.');
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
            throw new \RuntimeException('Nós obrigatórios para assinatura da emissão de Belém não encontrados.');
        }

        $this->appendSignatureNode(
            $dom,
            $certificate,
            $rpsWrapper,
            $infDeclaracao,
            'Id',
            OPENSSL_ALGO_SHA1
        );
        $this->appendSignatureNode(
            $dom,
            $certificate,
            $root,
            $loteRps,
            'Id',
            OPENSSL_ALGO_SHA1
        );

        return $dom->saveXML($dom->documentElement) ?: '';
    }

    private function appendSignatureNode(
        \DOMDocument $dom,
        Certificate $certificate,
        \DOMElement $signatureParent,
        \DOMElement $signedNode,
        string $mark,
        int $algorithm
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
            $this->calculateDigestValue($signedNode, $digestAlgorithm)
        );
        $referenceNode->appendChild($digestValueNode);

        $signedInfoCanonical = $signedInfoNode->C14N(true, false, null, null);
        if ($signedInfoCanonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar SignedInfo para assinatura de Belém.');
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

    private function calculateDigestValue(\DOMElement $node, string $algorithm): string
    {
        $canonical = $node->C14N(true, false, null, null);
        if ($canonical === false) {
            throw new \RuntimeException('Falha ao canonicalizar nó assinado de Belém.');
        }

        return base64_encode(hash($algorithm, $canonical, true));
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
        $signature = $xpath->query("//*[local-name()='Signature' and namespace-uri()='" . self::DSIG_NS . "']")->item(0);
        $targetParent = $xpath->query("//*[local-name()='{$parentLocalName}']")->item(0);

        if (!$signature instanceof \DOMElement || !$targetParent instanceof \DOMElement) {
            return $xml;
        }

        $signature->parentNode?->removeChild($signature);

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

    private function shouldSignOperation(string $operationKey): bool
    {
        $configured = $this->config['sign_operations'] ?? [
            'emitir',
            'consultar_lote',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];
        if (!is_array($configured)) {
            $configured = [
                'emitir',
                'consultar_lote',
                'consultar_nfse_rps',
                'cancelar_nfse',
            ];
        }

        return in_array($operationKey, $configured, true);
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
        $schemaPath = $resolver->resolve('BELEM_MUNICIPAL_2025', $operation);
        $validation = $validator->validate($this->normalizeRequestXmlForSchema($requestXml), $schemaPath);

        if ($validation['valid']) {
            return;
        }

        throw new \RuntimeException(
            'XML de Belém inválido para o schema da operação '
            . $operation
            . ': '
            . implode('; ', $validation['errors'])
        );
    }

    private function normalizeRequestXmlForSchema(string $requestXml): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (@$dom->loadXML($requestXml)) {
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query("//*[local-name()='Signature' and namespace-uri()='" . self::DSIG_NS . "']") as $signatureNode) {
                if ($signatureNode->parentNode instanceof \DOMNode) {
                    $signatureNode->parentNode->removeChild($signatureNode);
                }
            }

            foreach ($xpath->query("//*[local-name()='Prestador']/@Id") as $attributeNode) {
                if ($attributeNode instanceof \DOMAttr) {
                    $attributeNode->ownerElement?->removeAttributeNode($attributeNode);
                }
            }

            $root = $dom->documentElement;
            if ($root instanceof \DOMElement && !$root->hasAttribute('xmlns')) {
                $normalized = $dom->saveXML($root) ?: $requestXml;
                return preg_replace(
                    '/^<([A-Za-z0-9_:-]+)/',
                    '<$1 xmlns="' . self::NFSE_NS . '"',
                    $normalized,
                    1
                ) ?: $normalized;
            }

            return $dom->saveXML($root) ?: $requestXml;
        }

        return preg_replace(
            '/^<([A-Za-z0-9_:-]+)/',
            '<$1 xmlns="' . self::NFSE_NS . '"',
            $requestXml,
            1
        ) ?: $requestXml;
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
            'Belém requer CNPJ e inscrição municipal do prestador para consulta/cancelamento. '
            . 'Informe no payload da emissão anterior ou na configuração do provider.'
        );
    }

    private function resolveCodigoCancelamento(?string $protocolo): string
    {
        $configured = trim((string) ($this->config['cancelamento_codigo'] ?? '1'));
        $candidate = trim((string) ($protocolo ?? ''));

        if ($candidate !== '' && preg_match('/^\d+$/', $candidate) === 1) {
            return $candidate;
        }

        return $configured !== '' ? $configured : '1';
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

        return sys_get_temp_dir() . '/nfse-belem-soap-debug.log';
    }

    private function logSoapDebug(array $artifacts): void
    {
        if (!$this->isSoapDebugEnabled()) {
            return;
        }

        $payload = [
            'ts' => date(DATE_ATOM),
            'provider' => 'BelemMunicipalProvider',
            'ambiente' => $this->getAmbiente(),
            'endpoint' => $this->resolveSoapEndpoint(),
            'operation' => $artifacts['operation'] ?? null,
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

        $value = preg_replace(
            $patterns[1],
            '***@$2',
            $value
        ) ?? $value;

        $value = preg_replace_callback(
            $patterns[2],
            static function (array $matches): string {
                $digits = $matches[0];
                return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
            },
            $value
        ) ?? $value;

        $value = preg_replace('/\(\d{2}\)\s*\d{4,5}-?\d{4}/', '(**) *****-****', $value) ?? $value;

        return $value;
    }
}
