<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSeBelemMunicipalFixtures.php';

use freeline\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use freeline\FiscalCore\Support\NFSeSchemaResolver;
use freeline\FiscalCore\Support\NFSeSchemaValidator;
use freeline\FiscalCore\Support\NFSeSoapTransportInterface;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class BelemMunicipalProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::getInstance()->reload();
    }

    public function testEmitirBuildsSignedSchemaValidRequestAndParsesSynchronousResponse(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::successSoapResponse()) implements NFSeSoapTransportInterface {
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
        $responseXml = $provider->emitir(NFSeBelemMunicipalFixtures::payload());

        $this->assertStringContainsString('RecepcionarLoteRpsSincronoResponse', $responseXml);
        $this->assertCount(1, $transport->calls);
        $this->assertSame(
            'https://sefin-hml.belem.pa.gov.br/notafiscal-abrasfv203-ws/NotaFiscalSoap',
            $transport->calls[0]['endpoint']
        );

        $requestXml = $provider->getLastRequestXml();
        $this->assertIsString($requestXml);
        $this->assertStringContainsString('EnviarLoteRpsSincronoEnvio', $requestXml);
        $this->assertStringContainsString('http://www.w3.org/2000/09/xmldsig#', $requestXml);

        $validation = (new NFSeSchemaValidator())->validate(
            $this->schemaCompatibleXml($requestXml),
            (new NFSeSchemaResolver())->resolve('BELEM_MUNICIPAL_2025', 'emitir')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));

        $dom = new DOMDocument();
        $dom->loadXML($requestXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $references = [];
        foreach ($xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/@URI') as $node) {
            $references[] = $node->nodeValue;
        }
        sort($references);
        $this->assertSame(['#LOTE-BELEM-2026-1', '#RPS-BELEM-2026-1'], $references);

        $signatureMethods = [];
        foreach ($xpath->query('//ds:Signature/ds:SignedInfo/ds:SignatureMethod/@Algorithm') as $node) {
            $signatureMethods[] = $node->nodeValue;
        }
        $this->assertSame(
            [
                'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
                'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
            ],
            $signatureMethods
        );

        $digestMethods = [];
        foreach ($xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestMethod/@Algorithm') as $node) {
            $digestMethods[] = $node->nodeValue;
        }
        $this->assertSame(
            [
                'http://www.w3.org/2000/09/xmldsig#sha1',
                'http://www.w3.org/2000/09/xmldsig#sha1',
            ],
            $digestMethods
        );

        $envelope = $provider->getLastSoapEnvelope();
        $this->assertIsString($envelope);
        $this->assertStringContainsString('<nfse:cabecalho>', $envelope);
        $this->assertStringContainsString('<nfse:versaoDados>2.03</nfse:versaoDados>', $envelope);
        $this->assertStringContainsString('<svc:RecepcionarLoteRpsSincrono>', $envelope);
        $this->assertStringContainsString($requestXml, $envelope);
        $this->assertStringNotContainsString('<nfse:EnviarLoteRpsSincronoEnvio', $envelope);

        $parsed = $provider->getLastResponseData();
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('PROTOCOLO-BELEM-2026', $parsed['protocolo']);
        $this->assertSame('1105', $parsed['nfse']['numero']);
        $this->assertSame('ABC123XYZ', $parsed['nfse']['codigo_verificacao']);
    }

    public function testConsultarLoteBuildsSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::consultarLoteSoapResponse()) implements NFSeSoapTransportInterface {
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
        $responseXml = $provider->consultarLote(NFSeBelemMunicipalFixtures::loteProtocolo());

        $this->assertStringContainsString('ConsultarLoteRpsResponse', $responseXml);
        $this->assertCount(1, $transport->calls);
        $this->assertStringContainsString('<svc:ConsultarLoteRps>', $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            $this->schemaCompatibleXml((string) $provider->getLastRequestXml()),
            (new NFSeSchemaResolver())->resolve('BELEM_MUNICIPAL_2025', 'consultar_lote')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('consultar_lote', $provider->getLastOperation());

        $parsed = $provider->getLastResponseData();
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('PROTOCOLO-BELEM-2026', $parsed['protocolo']);
        $this->assertSame('1105', $parsed['nfse']['numero']);

        $dom = new DOMDocument();
        $dom->loadXML((string) $provider->getLastRequestXml());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $this->assertSame(
            '#PrestadorConsulta',
            $xpath->evaluate('string(//ds:Signature/ds:SignedInfo/ds:Reference/@URI)')
        );
        $this->assertSame(
            'ConsultarLoteRpsEnvio',
            $xpath->evaluate('local-name(//ds:Signature/parent::*)')
        );
    }

    public function testConsultarNfsePorRpsBuildsSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse()) implements NFSeSoapTransportInterface {
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
        $provider->consultarPorRps(NFSeBelemMunicipalFixtures::consultaRps());

        $this->assertStringContainsString('<svc:ConsultarNfsePorRps>', $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            $this->schemaCompatibleXml((string) $provider->getLastRequestXml()),
            (new NFSeSchemaResolver())->resolve('BELEM_MUNICIPAL_2025', 'consultar_nfse_rps')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));

        $parsed = $provider->getLastResponseData();
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('1105', $parsed['nfse']['numero']);

        $dom = new DOMDocument();
        $dom->loadXML((string) $provider->getLastRequestXml());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $this->assertSame(
            '#PrestadorConsulta',
            $xpath->evaluate('string(//ds:Signature/ds:SignedInfo/ds:Reference/@URI)')
        );
        $this->assertSame(
            'ConsultarNfseRpsEnvio',
            $xpath->evaluate('local-name(//ds:Signature/parent::*)')
        );
    }

    public function testConsultarLoteRetriesWithAlternativeSignatureVariantWhenFaultMentionsAssinatura(): void
    {
        $faultResponse = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <soap:Fault>
      <faultcode>soap:Server</faultcode>
      <faultstring>Arquivo enviado com erro na assinatura. / Acerte a assinatura do arquivo.</faultstring>
    </soap:Fault>
  </soap:Body>
</soap:Envelope>
XML;

        $transport = new class($faultResponse, NFSeBelemMunicipalFixtures::consultarLoteSoapResponse()) implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function __construct(
                private readonly string $firstResponse,
                private readonly string $secondResponse
            ) {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                return [
                    'request_xml' => $envelope,
                    'response_xml' => count($this->calls) === 1 ? $this->firstResponse : $this->secondResponse,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $provider->consultarLote(NFSeBelemMunicipalFixtures::loteProtocolo());

        $this->assertCount(2, $transport->calls);
        $artifacts = $provider->getLastOperationArtifacts();
        $this->assertSame('success', $artifacts['parsed_response']['status']);
        $this->assertSame('prestador_embedded', $artifacts['transport']['signature_variant']);
        $this->assertCount(2, $artifacts['transport']['retry_attempts']);
        $this->assertSame(
            'prestador_reference',
            $artifacts['transport']['retry_attempts'][0]['signature_variant']
        );
        $this->assertSame(
            'prestador_embedded',
            $artifacts['transport']['retry_attempts'][1]['signature_variant']
        );
        $this->assertStringContainsString(
            '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">',
            $artifacts['request_xml']
        );
    }

    public function testConsultarNfsePorRpsRetriesAllSignatureVariantsAndKeepsFailureArtifacts(): void
    {
        $faultResponse = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <soap:Fault>
      <faultcode>soap:Server</faultcode>
      <faultstring>Arquivo enviado com erro na assinatura. / Acerte a assinatura do arquivo.</faultstring>
    </soap:Fault>
  </soap:Body>
</soap:Envelope>
XML;

        $transport = new class($faultResponse) implements NFSeSoapTransportInterface {
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
                    'status_code' => 500,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $provider = $this->makeProvider($transport);
        $responseXml = $provider->consultarPorRps(NFSeBelemMunicipalFixtures::consultaRps());

        $this->assertStringContainsString('faultstring', $responseXml);
        $this->assertCount(6, $transport->calls);

        $artifacts = $provider->getLastOperationArtifacts();
        $this->assertSame('error', $artifacts['parsed_response']['status']);
        $this->assertSame(
            'Arquivo enviado com erro na assinatura. / Acerte a assinatura do arquivo.',
            $artifacts['parsed_response']['fault']['message']
        );
        $this->assertSame('unsigned', $artifacts['transport']['signature_variant']);
        $this->assertCount(6, $artifacts['transport']['retry_attempts']);
        $this->assertSame(
            [
                'prestador_reference',
                'prestador_embedded',
                'rps_reference',
                'root_reference',
                'whole_document',
                'unsigned',
            ],
            array_map(
                static fn (array $attempt): string => (string) ($attempt['signature_variant'] ?? ''),
                $artifacts['transport']['retry_attempts']
            )
        );
        $this->assertStringNotContainsString('<Signature', (string) $artifacts['request_xml']);
        $this->assertStringContainsString('<ConsultarNfseRpsEnvio>', (string) $artifacts['request_xml']);

        $wholeDocumentAttempt = null;
        foreach ($artifacts['transport']['retry_attempts'] as $attempt) {
            if (($attempt['signature_variant'] ?? null) === 'whole_document') {
                $wholeDocumentAttempt = $attempt;
                break;
            }
        }

        $this->assertIsArray($wholeDocumentAttempt);
        $this->assertStringNotContainsString(
            '<DigestValue>2jmj7l5rSw0yVb/vlWAYkK/YBwk=</DigestValue>',
            (string) ($wholeDocumentAttempt['request_xml'] ?? '')
        );
    }

    public function testCancelarNfseBuildsSchemaValidRequestAndParsesResponse(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::cancelarSoapSuccessResponse()) implements NFSeSoapTransportInterface {
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
            NFSeBelemMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );

        $this->assertTrue($result);
        $this->assertStringContainsString('<svc:CancelarNfse>', $provider->getLastSoapEnvelope());

        $validation = (new NFSeSchemaValidator())->validate(
            $this->schemaCompatibleXml((string) $provider->getLastRequestXml()),
            (new NFSeSchemaResolver())->resolve('BELEM_MUNICIPAL_2025', 'cancelar_nfse')
        );
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));

        $parsed = $provider->getLastResponseData();
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('1105', $parsed['cancelamento']['numero']);
        $this->assertSame('1', $parsed['cancelamento']['codigo_cancelamento']);
    }

    public function testCancelarNfseReturnsFalseOnBusinessRejection(): void
    {
        $transport = new class(NFSeBelemMunicipalFixtures::cancelarSoapRejectionResponse()) implements NFSeSoapTransportInterface {
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
            NFSeBelemMunicipalFixtures::cancelamentoNumeroNfse(),
            'Cancelamento de homologacao'
        );

        $this->assertFalse($result);
        $this->assertSame('error', $provider->getLastResponseData()['status']);
        $this->assertSame(['E301 NFSe ja se encontra cancelada.'], $provider->getLastResponseData()['mensagens']);
    }

    public function testProcessaRespostaDaFixtureSanitizadaDeExportacao(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                throw new RuntimeException('Transporte não deve ser usado neste teste.');
            }
        });

        $method = new ReflectionMethod(BelemMunicipalProvider::class, 'processarResposta');
        $method->setAccessible(true);

        $parsed = $method->invoke($provider, NFSeBelemMunicipalFixtures::sanitizedExportXml());

        $this->assertSame('success', $parsed['status']);
        $this->assertCount(2, $parsed['lista_nfse']);
        $this->assertSame('1105', $parsed['lista_nfse'][0]['numero']);
        $this->assertSame('TOMADOR SANITIZADO A LTDA', $parsed['lista_nfse'][0]['tomador']);
        $this->assertSame('1104', $parsed['lista_nfse'][1]['numero']);
    }

    public function testProcessaRespostaComMensagemDeRejeicao(): void
    {
        $provider = $this->makeProvider(new class(NFSeBelemMunicipalFixtures::rejectionSoapResponse()) implements NFSeSoapTransportInterface {
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

        $provider->emitir(NFSeBelemMunicipalFixtures::payload());

        $parsed = $provider->getLastResponseData();
        $this->assertSame('error', $parsed['status']);
        $this->assertSame(['E160 CNAE informado nao habilitado para o prestador.'], $parsed['mensagens']);
    }

    public function testRejectsIncompatibleItemsForSingleBelmDocument(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mesmo CNAE/CBO');

        $provider->emitir(NFSeBelemMunicipalFixtures::incompatibleItemsPayload());
    }

    public function testRejectsMeiOnMunicipalProvider(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider nacional');

        $provider->emitir(NFSeBelemMunicipalFixtures::meiPayload());
    }

    public function testConsultaOperationsReusePrestadorContextFromPreviousEmission(): void
    {
        $transport = new class implements NFSeSoapTransportInterface {
            private int $call = 0;

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->call++;
                $response = match ($this->call) {
                    1 => NFSeBelemMunicipalFixtures::successSoapResponse(),
                    2 => NFSeBelemMunicipalFixtures::consultarLoteSoapResponse(),
                    default => NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse(),
                };

                return [
                    'request_xml' => $envelope,
                    'response_xml' => $response,
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        };

        $config = NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]);
        unset($config['prestador']);

        $provider = new BelemMunicipalProvider($config);
        $provider->emitir(NFSeBelemMunicipalFixtures::payload());
        $provider->consultarLote(NFSeBelemMunicipalFixtures::loteProtocolo());

        $this->assertSame('success', $provider->getLastResponseData()['status']);
    }

    public function testConsultarPorRpsRequiresRequiredFields(): void
    {
        $provider = $this->makeProvider(new class implements NFSeSoapTransportInterface {
            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::consultarNfseRpsSoapResponse(),
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('campo numero');

        $provider->consultarPorRps([
            'serie' => 'RPS',
            'tipo' => '1',
        ]);
    }

    public function testHomologationDebugMasksSensitiveData(): void
    {
        $logFile = sys_get_temp_dir() . '/belem-provider-debug-' . uniqid('', true) . '.log';
        @unlink($logFile);

        $transport = new class(NFSeBelemMunicipalFixtures::successSoapResponse()) implements NFSeSoapTransportInterface {
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
        $provider->emitir(NFSeBelemMunicipalFixtures::payload());

        $contents = (string) file_get_contents($logFile);
        @unlink($logFile);

        $this->assertStringContainsString('BelemMunicipalProvider', $contents);
        $this->assertStringNotContainsString('12345678000195', $contents);
        $this->assertStringNotContainsString('financeiro@example.com', $contents);
        $this->assertStringNotContainsString('91999990000', $contents);
    }

    private function makeProvider(NFSeSoapTransportInterface $transport, array $overrides = []): BelemMunicipalProvider
    {
        $config = NFSeBelemMunicipalFixtures::belemConfig([
            'soap_transport' => $transport,
        ]);
        foreach ($overrides as $key => $value) {
            $config[$key] = $value;
        }

        return new BelemMunicipalProvider($config);
    }

    private function schemaCompatibleXml(string $xml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (@$dom->loadXML($xml)) {
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query("//*[local-name()='Signature' and namespace-uri()='http://www.w3.org/2000/09/xmldsig#']") as $signatureNode) {
                if ($signatureNode->parentNode instanceof DOMNode) {
                    $signatureNode->parentNode->removeChild($signatureNode);
                }
            }

            foreach ($xpath->query("//*[local-name()='Prestador']/@Id") as $attributeNode) {
                if ($attributeNode instanceof DOMAttr) {
                    $attributeNode->ownerElement?->removeAttributeNode($attributeNode);
                }
            }

            $root = $dom->documentElement;
            if ($root instanceof DOMElement) {
                $normalized = $dom->saveXML($root) ?: $xml;
                if (str_contains($normalized, 'xmlns="http://www.abrasf.org.br/nfse.xsd"')) {
                    return $normalized;
                }

                return preg_replace(
                    '/^<([A-Za-z0-9_:-]+)/',
                    '<$1 xmlns="http://www.abrasf.org.br/nfse.xsd"',
                    $normalized,
                    1
                ) ?: $normalized;
            }
        }

        return preg_replace(
            '/^<([A-Za-z0-9_:-]+)/',
            '<$1 xmlns="http://www.abrasf.org.br/nfse.xsd"',
            $xml,
            1
        ) ?: $xml;
    }
}
