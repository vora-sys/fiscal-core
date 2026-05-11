<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSeJoinvilleMunicipalFixtures.php';

use sabbajohn\FiscalCore\Providers\NFSe\Municipal\PublicaProvider;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class JoinvilleMunicipalProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::getInstance()->reload();
    }

    public function testEmitirBuildsSignedSchemaValidRequestAndParsesSoapResponse(): void
    {
        $transport = new class(NFSeJoinvilleMunicipalFixtures::asyncEnviarLoteSoapResponse()) implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function __construct(private readonly string $response)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $responseXml = $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $this->assertStringContainsString('RecepcionarLoteRpsResponse', $responseXml);
        $this->assertCount(1, $transport->calls);
        $this->assertSame(
            'https://nfsehomologacao.joinville.sc.gov.br/nfse_integracao/Services',
            $transport->calls[0]['endpoint']
        );

        $requestXml = $provider->getLastRequestXml();
        $this->assertIsString($requestXml);
        $this->assertStringContainsString('EnviarLoteRpsEnvio', $requestXml);
        $this->assertStringContainsString('http://www.w3.org/2000/09/xmldsig#', $requestXml);

        $validation = (new NFSeSchemaValidator())->validate(
            $requestXml,
            (new NFSeSchemaResolver())->resolve('PUBLICA', 'enviar_lote_rps')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));

        $dom = new DOMDocument();
        $dom->loadXML($requestXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $referenceUri = $xpath->evaluate('string(//ds:Signature/ds:SignedInfo/ds:Reference/@URI)');
        $this->assertSame('#JOINVILLE-RPS-2026-1', $referenceUri);

        $envelope = (string) $provider->getLastSoapEnvelope();
        $this->assertStringContainsString('<svc:RecepcionarLoteRps>', $envelope);
        $this->assertStringContainsString('<XML>&lt;EnviarLoteRpsEnvio', $envelope);

        $parsed = $provider->getLastResponseData();
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('1001', $parsed['numero_lote']);
        $this->assertSame('PROTOCOLO-JOINVILLE-2026', $parsed['protocolo']);
        $this->assertNull($parsed['nfse']);
    }

    public function testEmitirFallsBackToAsyncLoteWhenGerarNfseIsDiscontinued(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                $response = count($this->calls) === 1
                    ? NFSeJoinvilleMunicipalFixtures::deprecatedGerarNfseSoapResponse()
                    : NFSeJoinvilleMunicipalFixtures::asyncEnviarLoteSoapResponse();

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = $this->makeProvider($transport, ['emission_mode' => '']);
        $responseXml = $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $this->assertCount(2, $transport->calls);
        $this->assertStringContainsString('<svc:GerarNfse>', $transport->calls[0]['envelope']);
        $this->assertStringContainsString('<svc:RecepcionarLoteRps>', $transport->calls[1]['envelope']);
        $this->assertStringContainsString('RecepcionarLoteRpsResponse', $responseXml);
        $this->assertSame('PROTOCOLO-JOINVILLE-2026', $provider->getLastResponseData()['protocolo']);
    }

    public function testConsultarLoteBuildsSignedSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                $response = count($this->calls) === 1
                    ? NFSeJoinvilleMunicipalFixtures::consultarSituacaoLoteSoapResponse('4')
                    : NFSeJoinvilleMunicipalFixtures::consultarLoteSoapResponse();

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $provider->consultarLote(NFSeJoinvilleMunicipalFixtures::loteProtocolo());

        $this->assertCount(2, $transport->calls);
        $this->assertSame(
            'https://nfsehomologacao.joinville.sc.gov.br/nfse_integracao/Consultas',
            $transport->calls[0]['endpoint']
        );
        $this->assertStringContainsString('<svc:ConsultarSituacaoLoteRps>', $transport->calls[0]['envelope']);
        $this->assertStringContainsString('<svc:ConsultarLoteRps>', $transport->calls[1]['envelope']);
        $this->assertStringContainsString('<svc:ConsultarLoteRps>', (string) $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            (string) $provider->getLastRequestXml(),
            (new NFSeSchemaResolver())->resolve('PUBLICA', 'consultar_lote')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('consultar_lote', $provider->getLastOperationArtifacts()['operation']);
        $this->assertSame('success', $provider->getLastResponseData()['status']);
        $this->assertSame('4', $provider->getLastResponseData()['situacao_lote']);
        $this->assertSame('202600000000123', $provider->getLastResponseData()['nfse']['numero']);
    }

    public function testConsultarPorRpsBuildsSignedSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class(NFSeJoinvilleMunicipalFixtures::consultarNfseRpsSoapResponse()) implements NFSeSoapTransportInterface {
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

        $provider = $this->makeProvider($transport);
        $provider->consultarPorRps(NFSeJoinvilleMunicipalFixtures::consultaRps());

        $this->assertStringContainsString('<svc:ConsultarNfsePorRps>', (string) $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            (string) $provider->getLastRequestXml(),
            (new NFSeSchemaResolver())->resolve('PUBLICA', 'consultar_nfse_rps')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('success', $provider->getLastResponseData()['status']);
        $this->assertSame('202600000000123', $provider->getLastResponseData()['nfse']['numero']);
    }

    public function testCancelarBuildsSignedSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class(NFSeJoinvilleMunicipalFixtures::cancelarSoapSuccessResponse()) implements NFSeSoapTransportInterface {
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

        $provider = $this->makeProvider($transport);
        $result = $provider->cancelar(
            NFSeJoinvilleMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );

        $this->assertTrue($result);
        $this->assertStringContainsString('<svc:CancelarNfse>', (string) $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            (string) $provider->getLastRequestXml(),
            (new NFSeSchemaResolver())->resolve('PUBLICA', 'cancelar_nfse')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('success', $provider->getLastResponseData()['status']);
        $this->assertSame('202600000000123', $provider->getLastResponseData()['cancelamento']['numero']);
        $this->assertSame('C001', $provider->getLastResponseData()['cancelamento']['codigo_cancelamento']);
    }

    public function testCancelarReturnsFalseOnBusinessRejection(): void
    {
        $transport = new class(NFSeJoinvilleMunicipalFixtures::cancelarSoapRejectionResponse()) implements NFSeSoapTransportInterface {
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

        $provider = $this->makeProvider($transport);
        $result = $provider->cancelar(
            NFSeJoinvilleMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );

        $this->assertFalse($result);
        $this->assertSame('error', $provider->getLastResponseData()['status']);
        $this->assertSame(['E301 NFSe ja se encontra cancelada.'], $provider->getLastResponseData()['mensagens']);
    }

    public function testProcessaRespostaDaFixtureSanitizada(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                throw new RuntimeException('Transporte não deve ser usado neste teste.');
            }
        });

        $method = new ReflectionMethod(PublicaProvider::class, 'processarResposta');
        $method->setAccessible(true);

        $parsed = $method->invoke($provider, NFSeJoinvilleMunicipalFixtures::sanitizedExampleResponseXml());

        $this->assertSame('success', $parsed['status']);
        $this->assertSame('202600000000123', $parsed['nfse']['numero']);
        $this->assertSame('TOMADOR SANITIZADO LTDA', $parsed['nfse']['tomador']);
    }

    public function testProcessaRespostaComMensagemDeRejeicao(): void
    {
        $provider = $this->makeProvider(new class(NFSeJoinvilleMunicipalFixtures::rejectionSoapResponse()) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $response)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->response,
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $parsed = $provider->getLastResponseData();
        $this->assertSame('error', $parsed['status']);
        $this->assertSame(['E201 Item de serviço inválido para o prestador.'], $parsed['mensagens']);
    }

    public function testEmitirComHtmlDeGatewayRetornaDiagnosticoDeTransporte(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => "<html>\n<head><title>502 Bad Gateway</title></head>\n<body>nginx</body>\n</html>\n",
                    'status_code' => 502,
                    'headers' => ['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'],
                    'request_headers' => ['Content-Type: text/xml; charset=utf-8'],
                    'response_headers' => ['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $parsed = $provider->getLastResponseData();

        $this->assertSame('invalid_xml', $parsed['status']);
        $this->assertSame(502, $parsed['http_status']);
        $this->assertStringContainsString('502 Bad Gateway', (string) $parsed['raw_xml']);
        $this->assertStringContainsString(
            'Endpoint de Joinville retornou HTTP 502',
            implode(' | ', $parsed['mensagens'])
        );
        $this->assertTrue($parsed['retryable']);
        $this->assertSame('gateway_unavailable', $parsed['transport_error']);
        $this->assertSame(['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'], $parsed['transport_headers']);
        $this->assertSame(['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'], $parsed['response_headers']);
        $this->assertSame(['Content-Type: text/xml; charset=utf-8'], $parsed['request_headers']);
    }

    public function testEmitirComRedirectHttpRetornaDiagnosticoDeTransporte(): void
    {
        $location = 'https://nfsehomologacao.joinville.sc.gov.br/nfse_integracao/Services';
        $transport = new class($location) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $location)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => "<html>\n<head><title>301 Moved Permanently</title></head>\n<body>nginx</body>\n</html>\n",
                    'status_code' => 301,
                    'headers' => [
                        'HTTP/1.1 301 Moved Permanently',
                        'Content-Type: text/html',
                        'Location: ' . $this->location,
                    ],
                    'request_headers' => ['Content-Type: text/xml; charset=utf-8'],
                    'response_headers' => [
                        'HTTP/1.1 301 Moved Permanently',
                        'Content-Type: text/html',
                        'Location: ' . $this->location,
                    ],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $parsed = $provider->getLastResponseData();

        $this->assertSame('invalid_xml', $parsed['status']);
        $this->assertSame(301, $parsed['http_status']);
        $this->assertStringContainsString('301 Moved Permanently', (string) $parsed['raw_xml']);
        $this->assertStringContainsString(
            'Endpoint de Joinville retornou HTTP 301 redirecionando',
            implode(' | ', $parsed['mensagens'])
        );
        $this->assertFalse($parsed['retryable']);
        $this->assertSame('redirect_response', $parsed['transport_error']);
        $this->assertSame($location, $parsed['redirect_location']);
        $this->assertSame([
            'HTTP/1.1 301 Moved Permanently',
            'Content-Type: text/html',
            'Location: ' . $location,
        ], $parsed['response_headers']);
        $this->assertSame(['Content-Type: text/xml; charset=utf-8'], $parsed['request_headers']);
    }

    public function testFacadeEmitirReturnsErrorOnJoinvilleGatewayHtml(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => "<html>\n<head><title>502 Bad Gateway</title></head>\n<body>nginx</body>\n</html>\n",
                    'status_code' => 502,
                    'headers' => ['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'],
                    'request_headers' => ['Content-Type: text/xml; charset=utf-8'],
                    'response_headers' => ['HTTP/1.1 502 Bad Gateway', 'Content-Type: text/html'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $facade = new NFSeFacade('joinville', new NFSeAdapter('joinville', $provider));

        $response = $facade->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_EMISSION_FAILED', $response->getErrorCode());
        $this->assertStringContainsString('Endpoint de Joinville retornou HTTP 502', (string) $response->getError());
        $this->assertSame('invalid_xml', $response->getMetadata('emission_status'));
        $this->assertSame(502, $response->getMetadata('http_status'));
        $this->assertTrue($response->getMetadata('retryable'));
        $this->assertSame('gateway_unavailable', $response->getMetadata('transport_error'));
    }

    public function testFacadeEmitirReturnsErrorOnJoinvilleHttpRedirect(): void
    {
        $location = 'https://nfsehomologacao.joinville.sc.gov.br/nfse_integracao/Services';
        $transport = new class($location) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $location)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => "<html>\n<head><title>301 Moved Permanently</title></head>\n<body>nginx</body>\n</html>\n",
                    'status_code' => 301,
                    'request_headers' => ['Content-Type: text/xml; charset=utf-8'],
                    'response_headers' => [
                        'HTTP/1.1 301 Moved Permanently',
                        'Content-Type: text/html',
                        'Location: ' . $this->location,
                    ],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $facade = new NFSeFacade('joinville', new NFSeAdapter('joinville', $provider));

        $response = $facade->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_EMISSION_FAILED', $response->getErrorCode());
        $this->assertStringContainsString('Endpoint de Joinville retornou HTTP 301', (string) $response->getError());
        $this->assertSame('invalid_xml', $response->getMetadata('emission_status'));
        $this->assertSame(301, $response->getMetadata('http_status'));
        $this->assertFalse($response->getMetadata('retryable'));
        $this->assertSame('redirect_response', $response->getMetadata('transport_error'));
        $this->assertSame($location, $response->getMetadata('redirect_location'));
    }

    public function testRejectsIncompatibleItems(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeJoinvilleMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('único ItemListaServico');

        $provider->emitir(NFSeJoinvilleMunicipalFixtures::incompatibleItemsPayload());
    }

    public function testConsultarPorRpsRequiresRequiredFields(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeJoinvilleMunicipalFixtures::consultarNfseRpsSoapResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('campo numero');

        $provider->consultarPorRps([
            'serie' => 'A1',
            'tipo' => '1',
        ]);
    }

    public function testHomologationDebugMasksSensitiveData(): void
    {
        $logFile = sys_get_temp_dir() . '/joinville-provider-debug-' . uniqid('', true) . '.log';
        @unlink($logFile);

        $transport = new class(NFSeJoinvilleMunicipalFixtures::successSoapResponse()) implements NFSeSoapTransportInterface {
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

        $provider = $this->makeProvider($transport, [
            'debug_http' => true,
            'debug_log_file' => $logFile,
        ]);
        $provider->emitir(NFSeJoinvilleMunicipalFixtures::payload());

        $contents = (string) file_get_contents($logFile);
        @unlink($logFile);

        $this->assertStringContainsString('PublicaProvider', $contents);
        $this->assertStringNotContainsString('12345678000195', $contents);
        $this->assertStringNotContainsString('financeiro.joinville@example.com', $contents);
        $this->assertStringNotContainsString('47999991234', $contents);
    }

    public function testFacadeEmitirCompletoReturnsAuthorizedJoinvilleDocumentAndLocalDanfse(): void
    {
        $transport = new class(NFSeJoinvilleMunicipalFixtures::successSoapResponse()) implements NFSeSoapTransportInterface {
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

        $provider = $this->makeProvider($transport);
        $facade = new NFSeFacade('joinville', new NFSeAdapter('joinville', $provider));

        $response = $facade->emitirCompleto(NFSeJoinvilleMunicipalFixtures::payload());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('completo', $response->getData('flow_status'));
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertSame('202600000000123', $response->getData('documento')['numero'] ?? null);
        $this->assertSame('AB12-CD34', $response->getData('documento')['codigo_verificacao'] ?? null);
        $this->assertSame('render_local', $response->getData('impressao')['source'] ?? null);
        $this->assertSame('application/pdf', $response->getData('impressao')['content_type'] ?? null);
        $this->assertNotEmpty($response->getData('impressao')['pdf_base64'] ?? null);
        $this->assertSame([], $response->getData('warnings'));
    }

    private function makeProvider(NFSeSoapTransportInterface $transport, array $overrides = []): PublicaProvider
    {
        $config = NFSeJoinvilleMunicipalFixtures::joinvilleConfig([
            'soap_transport' => $transport,
        ]);
        foreach ($overrides as $key => $value) {
            $config[$key] = $value;
        }

        return new PublicaProvider($config);
    }
}
