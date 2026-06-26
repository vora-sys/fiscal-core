<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSeBelemMunicipalFixtures.php';
require_once dirname(__DIR__, 2) . '/Fakes/RecordingNfseProvider.php';

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\RecordingNfseProvider;

final class NFSeBelemRoutingTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::getInstance()->reload();
        RecordingNfseProvider::reset();
    }

    public function testBelemMeiRoutesAutomaticallyToNationalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();
        $registry->register('nfse_nacional', [
            'provider_class' => RecordingNfseProvider::class,
            'codigo_municipio' => '1001058',
            'aliquota_format' => 'decimal',
            'wsdl' => '',
            'api_base_url' => 'https://example.test/api',
            'ambiente' => 'homologacao',
        ]);

        $adapter = new NFSeAdapter('belem');
        $result = $adapter->emitir(NFSeBelemMunicipalFixtures::meiPayload());

        $this->assertSame('<nacional-gravado />', $result);
        $this->assertNotNull(RecordingNfseProvider::$lastPayload);
        $this->assertSame(
            'nfse_nacional',
            $adapter->getLastEmissionInfo()['effective_provider_key'] ?? null
        );
        $this->assertSame(
            'mei_nacional',
            $adapter->getLastEmissionInfo()['routing_mode'] ?? null
        );
    }

    public function testBelemRejectsEmissionWhenMeiClassificationIsMissing(): void
    {
        $adapter = new NFSeAdapter('belem');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identificação explícita do emitente como MEI ou não MEI');

        $adapter->emitir(NFSeBelemMunicipalFixtures::payloadWithoutClassification());
    }

    public function testAnyMunicipalProviderRoutesMeiEmissionToNationalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();
        $registry->register('PUBLICA', [
            'provider_class' => RecordingNfseProvider::class,
            'codigo_municipio' => '4209102',
            'aliquota_format' => 'percentual',
            'wsdl' => 'https://example.test/publica',
            'ambiente' => 'homologacao',
        ]);
        $registry->register('nfse_nacional', [
            'provider_class' => RecordingNfseProvider::class,
            'codigo_municipio' => '1001058',
            'aliquota_format' => 'decimal',
            'wsdl' => '',
            'api_base_url' => 'https://example.test/api',
            'ambiente' => 'homologacao',
        ]);

        $adapter = new NFSeAdapter('itajai');
        $payload = NFSeBelemMunicipalFixtures::payload();
        $payload['prestador']['mei'] = true;
        $payload['prestador']['regime_tributario'] = 'mei';

        $adapter->emitir($payload);

        $this->assertSame('nfse_nacional', $adapter->getLastEmissionInfo()['effective_provider_key'] ?? null);
        $this->assertSame('mei_nacional', $adapter->getLastEmissionInfo()['routing_mode'] ?? null);
    }

    public function testBelemMunicipalConsultAndCancelUseMunicipalProviderCapabilities(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = match ($this->call) {
                    1 => NFSeBelemMunicipalFixtures::consultarNfseServicoPrestadoSoapResponse(),
                    2 => NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse(),
                    3 => NFSeBelemMunicipalFixtures::consultarLoteSoapResponse(),
                    default => NFSeBelemMunicipalFixtures::cancelarSoapSuccessResponse(),
                };

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $adapter = new NFSeAdapter('belem', $provider);

        $consulta = $adapter->consultar(NFSeBelemMunicipalFixtures::chaveNfse());
        $this->assertStringContainsString('ConsultarNfseServicoPrestadoResponse', (string) ($consulta->getRaw()['response_xml'] ?? ''));
        $this->assertSame('1105', $consulta->getDocumento()['numero'] ?? null);
        $this->assertSame('consultar', $adapter->getLastOperationInfo()['operation']);
        $this->assertSame('success', $adapter->getLastOperationInfo()['parsed_response']['status'] ?? null);

        $consultaRps = $adapter->consultarPorRps(NFSeBelemMunicipalFixtures::consultaRps());
        $this->assertStringContainsString('ConsultarNfsePorRpsResponse', (string) ($consultaRps->getRaw()['response_xml'] ?? ''));
        $this->assertSame('consultar_por_rps', $adapter->getLastOperationInfo()['operation']);

        $consultaLote = $adapter->consultarLote(NFSeBelemMunicipalFixtures::loteProtocolo());
        $this->assertStringContainsString('ConsultarLoteRpsResponse', (string) ($consultaLote->getRaw()['response_xml'] ?? ''));
        $this->assertSame('consultar_lote', $adapter->getLastOperationInfo()['operation']);

        $cancelado = $adapter->cancelar(
            NFSeBelemMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );
        $this->assertTrue($cancelado);
        $this->assertSame('cancelar', $adapter->getLastOperationInfo()['operation']);
        $this->assertSame('success', $adapter->getLastOperationInfo()['parsed_response']['status']);
    }

    public function testBelemFacadeIncludesMunicipalOperationMetadata(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = $this->call === 1
                    ? NFSeBelemMunicipalFixtures::consultarLoteSoapResponse()
                    : NFSeBelemMunicipalFixtures::cancelarSoapSuccessResponse();

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $adapter = new NFSeAdapter('belem', $provider);
        $facade = new NFSeFacade('belem', $adapter);

        $consulta = $facade->consultarLote(NFSeBelemMunicipalFixtures::loteProtocolo());
        $this->assertTrue($consulta->isSuccess());
        $this->assertSame('consultar_lote', $consulta->getData('consulta_operacao')['operation']);

        $cancelamento = $facade->cancelar(
            NFSeBelemMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );
        $this->assertTrue($cancelamento->isSuccess());
        $this->assertSame('cancelar', $cancelamento->getData('cancelamento')['operation']);
    }

    public function testBelemFacadeReturnsErrorWhenCancellationIsRejected(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::cancelarSoapRejectionResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $cancelamento = $facade->cancelar(
            NFSeBelemMunicipalFixtures::chaveNfse(),
            'Cancelamento de homologacao'
        );

        $this->assertTrue($cancelamento->isError());
        $this->assertSame('NFSE_CANCELLATION_REJECTED', $cancelamento->getErrorCode());
        $this->assertStringContainsString('NFSe ja se encontra cancelada', (string) $cancelamento->getError());
        $this->assertSame('cancelar', $cancelamento->getMetadata('cancelamento')['operation']);
    }

    public function testBelemFacadeConsultarByChaveReturnsOfficialUrl(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::consultarNfseServicoPrestadoSoapResponse()) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $response)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $response = $facade->consultar(NFSeBelemMunicipalFixtures::chaveNfse());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('autorizada', $response->getData('consulta')['status_autorizacao'] ?? null);
        $this->assertTrue($response->getData('consulta')['disponivel'] ?? false);
        $this->assertSame('consultar_nfse_numero', $response->getData('consulta')['source'] ?? null);
        $this->assertSame('1105', $response->getData('documento')['numero'] ?? null);
        $this->assertSame('consultar', $response->getData('consulta_operacao')['operation'] ?? null);
        $this->assertNotEmpty($response->getData('raw')['response_xml'] ?? null);
        $this->assertSame(
            'https://notafiscal.belem.pa.gov.br/notafiscal-ws/servico/notafiscal/autenticacao/cpfCnpj/12345678000195/inscricaoMunicipal/4007197/numeroNota/1105/codigoVerificacao/ABC123XYZ',
            $response->getData('impressao')['url'] ?? null
        );
    }

    public function testBelemFacadeEmitirCompletoFallsBackToConsultarLoteAndReturnsOfficialUrl(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = match ($this->call) {
                    1 => NFSeBelemRoutingTest::emissaoComProtocoloSemNfseSoapResponse(),
                    default => NFSeBelemMunicipalFixtures::consultarLoteSoapResponse(),
                };

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $adapter = new NFSeAdapter('belem', $provider);
        $facade = new NFSeFacade('belem', $adapter);

        $response = $facade->emitirCompleto(NFSeBelemMunicipalFixtures::payload());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('completo', $response->getData('flow_status'));
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertSame('consultar_lote', $response->getData('consulta')['operation'] ?? null);
        $this->assertSame('1105', $response->getData('documento')['numero'] ?? null);
        $this->assertSame(
            'https://notafiscal.belem.pa.gov.br/notafiscal-ws/servico/notafiscal/autenticacao/cpfCnpj/12345678000195/inscricaoMunicipal/4007197/numeroNota/1105/codigoVerificacao/ABC123XYZ',
            $response->getData('impressao')['url'] ?? null
        );
    }

    public function testBelemFacadeEmitirCompletoFallsBackToConsultarPorRpsWhenLoteHasNoNfseAndReturnsOfficialUrl(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = match ($this->call) {
                    1 => NFSeBelemRoutingTest::emissaoComProtocoloSemNfseSoapResponse(),
                    2 => NFSeBelemRoutingTest::consultaSemNfseSoapResponse(),
                    default => NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse(),
                };

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $adapter = new NFSeAdapter('belem', $provider);
        $facade = new NFSeFacade('belem', $adapter);

        $response = $facade->emitirCompleto(NFSeBelemMunicipalFixtures::payload());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('completo', $response->getData('flow_status'));
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertSame('consultar_nfse_rps', $response->getData('consulta')['operation'] ?? null);
        $this->assertSame('1105', $response->getData('documento')['numero'] ?? null);
        $this->assertNotEmpty($response->getData('impressao')['url'] ?? null);
    }

    public function testBelemFacadeEmitirCompletoReturnsPartialWhenConsultationDoesNotResolveNfse(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = match ($this->call) {
                    1 => NFSeBelemRoutingTest::emissaoComProtocoloSemNfseSoapResponse(),
                    default => NFSeBelemRoutingTest::consultaSemNfseSoapResponse(),
                };

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $adapter = new NFSeAdapter('belem', $provider);
        $facade = new NFSeFacade('belem', $adapter);

        $response = $facade->emitirCompleto(NFSeBelemMunicipalFixtures::payload());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('parcial', $response->getData('flow_status'));
        $this->assertSame('pendente', $response->getData('authorization_status'));
        $this->assertSame('indisponivel', $response->getData('impressao')['modo'] ?? null);
        $this->assertNotEmpty($response->getData('warnings'));
    }

    public function testBelemFacadeConsultarDisponibilidadeByProtocoloReturnsOfficialUrl(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::consultarLoteSoapResponse()) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $response)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $response = $facade->consultarDisponibilidade([
            'protocolo' => NFSeBelemMunicipalFixtures::loteProtocolo(),
        ]);

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertTrue($response->getData('disponivel'));
        $this->assertSame('lote', $response->getData('source'));
        $this->assertSame('1105', $response->getData('nfse')['numero'] ?? null);
        $this->assertSame(
            'https://notafiscal.belem.pa.gov.br/notafiscal-ws/servico/notafiscal/autenticacao/cpfCnpj/12345678000195/inscricaoMunicipal/4007197/numeroNota/1105/codigoVerificacao/ABC123XYZ',
            $response->getData('danfse_url')
        );
    }

    public function testBelemFacadeConsultarDisponibilidadeFallsBackToRpsWhenLoteHasNoNfse(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = $this->call === 1
                    ? NFSeBelemRoutingTest::consultaSemNfseSoapResponse()
                    : NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse();

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $response = $facade->consultarDisponibilidade([
            'protocolo' => NFSeBelemMunicipalFixtures::loteProtocolo(),
            'rps' => NFSeBelemMunicipalFixtures::consultaRps(),
        ]);

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertTrue($response->getData('disponivel'));
        $this->assertSame('rps', $response->getData('source'));
        $this->assertContains(
            'Consulta por RPS utilizada como fallback da disponibilidade em Belem.',
            $response->getData('warnings') ?? []
        );
    }

    public function testBelemFacadeConsultarDisponibilidadeReturnsPendenteWhenNoNfseIsAvailable(): void
    {
        $transport = new class(NFSeBelemRoutingTest::consultaSemNfseSoapResponse()) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $response)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]));
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $response = $facade->consultarDisponibilidade([
            'protocolo' => NFSeBelemMunicipalFixtures::loteProtocolo(),
        ]);

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('pendente', $response->getData('authorization_status'));
        $this->assertFalse($response->getData('disponivel'));
        $this->assertNull($response->getData('danfse_url'));
    }

    public function testBelemFacadeGerarDanfseRendersLocalPdfFromAuthorizedXml(): void
    {
        $provider = new BelemMunicipalProvider(NFSeBelemMunicipalFixtures::belemConfig());
        $facade = new NFSeFacade('belem', new NFSeAdapter('belem', $provider));

        $response = $facade->gerarDanfse(NFSeBelemMunicipalFixtures::successSoapResponse());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('pdf_base64', $response->getData('impressao')['modo']);
        $this->assertSame('render_local', $response->getData('impressao')['source']);
        $this->assertSame('application/pdf', $response->getData('impressao')['content_type']);
        $this->assertStringStartsWith('%PDF', base64_decode($response->getData('impressao')['pdf_base64'], true) ?: '');
    }

    public static function emissaoComProtocoloSemNfseSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:RecepcionarLoteRpsSincronoResponse>
      <tns:EnviarLoteRpsSincronoResposta>
        <nfse:NumeroLote>1001</nfse:NumeroLote>
        <nfse:DataRecebimento>2026-03-18T10:15:00</nfse:DataRecebimento>
        <nfse:Protocolo>PROTOCOLO-BELEM-2026</nfse:Protocolo>
      </tns:EnviarLoteRpsSincronoResposta>
    </tns:RecepcionarLoteRpsSincronoResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function consultaSemNfseSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:ConsultarLoteRpsResponse>
      <tns:ConsultarLoteRpsResposta>
        <nfse:NumeroLote>1001</nfse:NumeroLote>
        <nfse:Protocolo>PROTOCOLO-BELEM-2026</nfse:Protocolo>
      </tns:ConsultarLoteRpsResposta>
    </tns:ConsultarLoteRpsResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }
}
