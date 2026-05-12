<?php

declare(strict_types=1);

namespace Tests\Unit\NFSe;

require_once dirname(__DIR__, 2) . '/Support/TestCertificateFile.php';

use DOMDocument;
use DOMXPath;
use NFePHP\Common\Certificate;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Providers\NFSe\AbrasfV2Provider;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;

final class AbrasfV2ProviderTest extends TestCase
{
    public function testMontaXmlRpsAbrasfComLoteServicoPrestadorETomador(): void
    {
        $provider = new TestableAbrasfV2Provider($this->config());

        $xml = $provider->buildXml($this->payload());

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);

        $this->assertSame('EnviarLoteRpsSincronoEnvio', $dom->documentElement?->localName);
        $this->assertSame('2.03', $xpath->evaluate("string(//*[local-name()='LoteRps']/@versao)"));
        $this->assertSame('1', $xpath->evaluate("string(//*[local-name()='NumeroLote'])"));
        $this->assertSame('11222333000181', $xpath->evaluate("string(//*[local-name()='Prestador']/*[local-name()='CpfCnpj']/*[local-name()='Cnpj'])"));
        $this->assertSame('12345678000195', $xpath->evaluate("string(//*[local-name()='Tomador']/*[local-name()='IdentificacaoTomador']/*[local-name()='CpfCnpj']/*[local-name()='Cnpj'])"));
        $this->assertSame('101', $xpath->evaluate("string(//*[local-name()='ItemListaServico'])"));
        $this->assertSame('1500.00', $xpath->evaluate("string(//*[local-name()='ValorServicos'])"));
        $this->assertSame('0.0200', $xpath->evaluate("string(//*[local-name()='Aliquota'])"));
        $this->assertSame('3550308', $xpath->evaluate("string(//*[local-name()='CodigoMunicipio'])"));
    }

    public function testValidaCamposObrigatorios(): void
    {
        $provider = new TestableAbrasfV2Provider($this->config());
        $payload = $this->payload();
        unset($payload['servico']['item_lista_servico'], $payload['servico']['codigo']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('servico.item_lista_servico');

        $provider->buildXml($payload);
    }

    public function testProcessaRespostaAbrasfComNfseAutorizada(): void
    {
        $provider = new TestableAbrasfV2Provider($this->config());

        $parsed = $provider->parseXml(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <ListaNfse>
    <CompNfse>
      <Nfse>
        <InfNfse>
          <Numero>202600000000123</Numero>
          <CodigoVerificacao>ABC123</CodigoVerificacao>
          <DataEmissao>2026-05-12T10:00:00</DataEmissao>
          <ValoresNfse>
            <BaseCalculo>1500.00</BaseCalculo>
            <ValorLiquidoNfse>1470.00</ValorLiquidoNfse>
          </ValoresNfse>
          <PrestadorServico>
            <RazaoSocial>PRESTADOR TESTE LTDA</RazaoSocial>
          </PrestadorServico>
          <TomadorServico>
            <RazaoSocial>TOMADOR TESTE LTDA</RazaoSocial>
          </TomadorServico>
        </InfNfse>
      </Nfse>
    </CompNfse>
  </ListaNfse>
</ConsultarNfseResposta>
XML);

        $this->assertSame('success', $parsed['status']);
        $this->assertSame('202600000000123', $parsed['nfse']['numero']);
        $this->assertSame('ABC123', $parsed['nfse']['codigo_verificacao']);
        $this->assertSame('1470.00', $parsed['nfse']['valor_liquido']);
        $this->assertSame('TOMADOR TESTE LTDA', $parsed['nfse']['tomador']);
        $this->assertSame([], $parsed['mensagens']);
    }

    public function testProcessaRespostaAbrasfComMensagemDeErro(): void
    {
        $provider = new TestableAbrasfV2Provider($this->config());

        $parsed = $provider->parseXml(<<<'XML'
<GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <ListaMensagemRetorno>
    <MensagemRetorno>
      <Codigo>E160</Codigo>
      <Mensagem>RPS ja informado.</Mensagem>
      <Correcao>Informe outro numero.</Correcao>
    </MensagemRetorno>
  </ListaMensagemRetorno>
</GerarNfseResposta>
XML);

        $this->assertSame('error', $parsed['status']);
        $this->assertSame(['E160 RPS ja informado. Informe outro numero.'], $parsed['mensagens']);
    }

    public function testEmitirDespachaSoapEGuardaArtefatos(): void
    {
        $transport = new RecordingSoapTransport(<<<'XML'
<RecepcionarLoteRpsSincronoResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <NumeroLote>1</NumeroLote>
  <DataRecebimento>2026-05-12T10:00:00</DataRecebimento>
  <Protocolo>PROTO123</Protocolo>
</RecepcionarLoteRpsSincronoResposta>
XML);
        $provider = new TestableAbrasfV2Provider($this->config(['soap_transport' => $transport]));

        $response = $provider->emitir($this->payload());

        $this->assertStringContainsString('PROTO123', $response);
        $this->assertStringContainsString('RecepcionarLoteRpsSincrono', $transport->lastEnvelope);
        $this->assertStringContainsString('EnviarLoteRpsSincronoEnvio', $transport->lastEnvelope);
        $this->assertSame('https://nfse.local/service', $transport->lastEndpoint);
        $this->assertSame('emitir', $provider->getLastOperationArtifacts()['operation']);
        $this->assertSame('success', $provider->getLastOperationArtifacts()['parsed_response']['status']);
    }

    public function testEmitirAssinaXmlQuandoOperacaoEstaConfigurada(): void
    {
        $certificateFile = \TestCertificateFile::create('ABRASF Teste', 'secret', '11222333000181');

        try {
            $certificate = Certificate::readPfx((string) file_get_contents($certificateFile['path']), $certificateFile['password']);
            $transport = new RecordingSoapTransport(<<<'XML'
<RecepcionarLoteRpsSincronoResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <NumeroLote>1</NumeroLote>
  <Protocolo>PROTO123</Protocolo>
</RecepcionarLoteRpsSincronoResposta>
XML);
            $provider = new TestableAbrasfV2Provider($this->config([
                'soap_transport' => $transport,
                'certificate' => $certificate,
                'sign_operations' => ['emitir'],
            ]));

            $provider->emitir($this->payload());

            $dom = new DOMDocument();
            $this->assertTrue($dom->loadXML($transport->lastEnvelope));
            $xpath = new DOMXPath($dom);
            $signatures = $xpath->query("//*[local-name()='Signature' and namespace-uri()='http://www.w3.org/2000/09/xmldsig#']");
            $references = $xpath->query("//*[local-name()='Reference']");

            $this->assertSame(2, $signatures?->length);
            $this->assertSame('#AbrasfRpsTeste1', $references?->item(0)?->attributes?->getNamedItem('URI')?->nodeValue);
            $this->assertSame('#LoteAbrasf1', $references?->item(1)?->attributes?->getNamedItem('URI')?->nodeValue);
            $this->assertStringContainsString('SignatureValue', $provider->getLastOperationArtifacts()['request_xml']);
        } finally {
            \TestCertificateFile::cleanup($certificateFile['path'] ?? null);
        }
    }

    public function testConsultarPorRpsDespachaSoapERetornaResultadoNormalizado(): void
    {
        $transport = new RecordingSoapTransport(<<<'XML'
<ConsultarNfseRpsResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <CompNfse>
    <Nfse>
      <InfNfse>
        <Numero>202600000000777</Numero>
        <CodigoVerificacao>XYZ789</CodigoVerificacao>
        <DataEmissao>2026-05-12T10:00:00</DataEmissao>
      </InfNfse>
    </Nfse>
  </CompNfse>
</ConsultarNfseRpsResposta>
XML);
        $provider = new TestableAbrasfV2Provider($this->config(['soap_transport' => $transport]));

        $result = $provider->consultarPorRps(['numero' => '9001', 'serie' => 'RPS', 'tipo' => '1']);

        $this->assertStringContainsString('ConsultarNfsePorRps', $transport->lastEnvelope);
        $this->assertStringContainsString('<Numero>9001</Numero>', $transport->lastEnvelope);
        $this->assertSame('202600000000777', $result->getDocumento()['numero']);
        $this->assertSame('XYZ789', $result->getDocumento()['codigo_verificacao']);
        $this->assertSame('consultar_nfse_rps', $result->getConsulta()['operation']);
    }

    public function testCancelarDespachaSoapERetornaSucesso(): void
    {
        $transport = new RecordingSoapTransport(<<<'XML'
<CancelarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <RetCancelamento>
    <NfseCancelamento>
      <Confirmacao>
        <Pedido>
          <InfPedidoCancelamento>
            <IdentificacaoNfse>
              <Numero>202600000000777</Numero>
            </IdentificacaoNfse>
            <CodigoCancelamento>1</CodigoCancelamento>
          </InfPedidoCancelamento>
        </Pedido>
      </Confirmacao>
    </NfseCancelamento>
  </RetCancelamento>
</CancelarNfseResposta>
XML);
        $provider = new TestableAbrasfV2Provider($this->config(['soap_transport' => $transport]));

        $this->assertTrue($provider->cancelar('202600000000777', 'Erro operacional'));
        $this->assertStringContainsString('CancelarNfse', $transport->lastEnvelope);
        $this->assertStringContainsString('<Numero>202600000000777</Numero>', $transport->lastEnvelope);
        $this->assertSame('cancelar_nfse', $provider->getLastOperationArtifacts()['operation']);
    }

    public function testSubstituirDespachaSoapComPedidoCancelamentoERpsSubstitutoAssinados(): void
    {
        $certificateFile = \TestCertificateFile::create('ABRASF Substituicao Teste', 'secret', '11222333000181');

        try {
            $certificate = Certificate::readPfx((string) file_get_contents($certificateFile['path']), $certificateFile['password']);
            $transport = new RecordingSoapTransport(<<<'XML'
<SubstituirNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <CompNfse>
    <Nfse>
      <InfNfse>
        <Numero>202600000000888</Numero>
        <CodigoVerificacao>SUB123</CodigoVerificacao>
        <DataEmissao>2026-05-12T10:30:00</DataEmissao>
      </InfNfse>
    </Nfse>
  </CompNfse>
</SubstituirNfseResposta>
XML);
            $payload = $this->payload();
            $payload['substituicao'] = [
                'motivo' => 'Correcao de dados do servico',
                'codigo_cancelamento' => '2',
            ];
            $provider = new TestableAbrasfV2Provider($this->config([
                'soap_transport' => $transport,
                'certificate' => $certificate,
                'sign_operations' => ['substituir_nfse'],
            ]));

            $response = $provider->substituir('202600000000777', $payload);

            $this->assertStringContainsString('SUB123', $response);
            $this->assertStringContainsString('SubstituirNfse', $transport->lastEnvelope);
            $this->assertStringContainsString('SubstituirNfseEnvio', $transport->lastEnvelope);
            $this->assertStringContainsString('<Numero>202600000000777</Numero>', $transport->lastEnvelope);
            $this->assertStringContainsString('<CodigoCancelamento>2</CodigoCancelamento>', $transport->lastEnvelope);
            $this->assertStringContainsString('Correcao de dados do servico', $transport->lastEnvelope);
            $this->assertSame('substituir_nfse', $provider->getLastOperationArtifacts()['operation']);
            $this->assertSame('success', $provider->getLastOperationArtifacts()['parsed_response']['status']);

            $dom = new DOMDocument();
            $this->assertTrue($dom->loadXML($provider->getLastOperationArtifacts()['request_xml']));
            $xpath = new DOMXPath($dom);
            $signatures = $xpath->query("//*[local-name()='Signature' and namespace-uri()='http://www.w3.org/2000/09/xmldsig#']");
            $references = $xpath->query("//*[local-name()='Reference']");

            $this->assertSame(2, $signatures?->length);
            $this->assertSame('#Cancelamento_11222333000181_202600000000777', $references?->item(0)?->attributes?->getNamedItem('URI')?->nodeValue);
            $this->assertSame('#AbrasfRpsTeste1', $references?->item(1)?->attributes?->getNamedItem('URI')?->nodeValue);
        } finally {
            \TestCertificateFile::cleanup($certificateFile['path'] ?? null);
        }
    }

    public function testAdapterExpoeResultadoNormalizadoDaSubstituicaoAbrasf(): void
    {
        $transport = new RecordingSoapTransport(<<<'XML'
<SubstituirNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd">
  <CompNfse>
    <Nfse>
      <InfNfse>
        <Numero>202600000000889</Numero>
        <CodigoVerificacao>SUB889</CodigoVerificacao>
        <DataEmissao>2026-05-12T10:45:00</DataEmissao>
      </InfNfse>
    </Nfse>
  </CompNfse>
</SubstituirNfseResposta>
XML);
        $provider = new TestableAbrasfV2Provider($this->config(['soap_transport' => $transport]));
        $adapter = new NFSeAdapter('sao-paulo', $provider);

        $adapter->substituir('202600000000777', $this->payload());
        $info = $adapter->getLastOperationInfo();
        $normalized = $info['normalized_result'] ?? [];

        $this->assertSame('substituir', $info['operation']);
        $this->assertSame('substituir', $normalized['operacao']['operation'] ?? null);
        $this->assertTrue($normalized['operacao']['ok'] ?? false);
        $this->assertSame('success', $normalized['operacao']['status'] ?? null);
        $this->assertSame('202600000000889', $normalized['documento']['numero'] ?? null);
        $this->assertSame('SUB889', $normalized['documento']['codigo_verificacao'] ?? null);
        $this->assertStringContainsString('SubstituirNfseResposta', $normalized['raw']['response_xml'] ?? '');
    }

    private function config(array $overrides = []): array
    {
        return [
            'codigo_municipio' => '3550308',
            'versao' => '2.03',
            'ambiente' => 'homologacao',
            'wsdl' => 'https://nfse.local/service?wsdl',
            'prestador_cnpj' => '11222333000181',
            'prestador_inscricao_municipal' => '12345',
        ] + $overrides;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 'AbrasfRpsTeste1',
            'valor_servicos' => 1500.0,
            'prestador' => [
                'cnpj' => '11.222.333/0001-81',
                'inscricaoMunicipal' => '12345',
                'simples_nacional' => true,
            ],
            'tomador' => [
                'documento' => '12.345.678/0001-95',
                'razao_social' => 'Tomador Teste Ltda',
                'email' => 'financeiro@example.com',
                'endereco' => [
                    'logradouro' => 'Rua Central',
                    'numero' => '100',
                    'bairro' => 'Centro',
                    'codigo_municipio' => '3550308',
                    'uf' => 'SP',
                    'cep' => '01001-000',
                ],
            ],
            'servico' => [
                'item_lista_servico' => '101',
                'codigo_municipio' => '3550308',
                'discriminacao' => 'Servico de testes ABRASF',
                'aliquota' => 0.02,
                'iss_retido' => false,
            ],
            'rps' => [
                'numero' => '9001',
                'serie' => 'RPS',
                'tipo' => '1',
                'data_emissao' => '2026-05-12',
            ],
        ];
    }
}

final class RecordingSoapTransport implements NFSeSoapTransportInterface
{
    public string $lastEndpoint = '';
    public string $lastEnvelope = '';
    public array $lastOptions = [];

    public function __construct(private readonly string $responseXml)
    {
    }

    public function send(string $endpoint, string $envelope, array $options = []): array
    {
        $this->lastEndpoint = $endpoint;
        $this->lastEnvelope = $envelope;
        $this->lastOptions = $options;

        return [
            'request_xml' => $envelope,
            'response_xml' => $this->responseXml,
            'status_code' => 200,
            'headers' => [],
        ];
    }
}

final class TestableAbrasfV2Provider extends AbrasfV2Provider
{
    /**
     * @param array<string,mixed> $dados
     */
    public function buildXml(array $dados): string
    {
        return $this->montarXmlRps($dados);
    }

    /**
     * @return array<string,mixed>
     */
    public function parseXml(string $xml): array
    {
        return $this->processarResposta($xml);
    }
}
