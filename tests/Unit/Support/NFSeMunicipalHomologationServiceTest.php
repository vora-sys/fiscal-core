<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSeBelemMunicipalFixtures.php';
require_once dirname(__DIR__, 2) . '/Fixtures/NFSeJoinvilleMunicipalFixtures.php';
require_once dirname(__DIR__, 2) . '/Support/TestCertificateFile.php';

use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\NFSeMunicipalHomologationService;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class NFSeMunicipalHomologationServiceTest extends TestCase
{
    private string $projectRoot;
    /** @var array{path:string,password:string} */
    private array $belemCertificateFile;
    /** @var array{path:string,password:string} */
    private array $joinvilleCertificateFile;
    /** @var string[] */
    private array $envKeys = [
        'FISCAL_ENVIRONMENT',
        'FISCAL_IM',
        'FISCAL_RAZAO_SOCIAL',
        'FISCAL_CERT_PATH',
        'FISCAL_CERT_PASSWORD',
        'OPENSSL_CONF',
        'FISCAL_CNPJ',
        'FISCAL_UF',
    ];

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->belemCertificateFile = TestCertificateFile::create(
            'Faives Teste',
            'belem-secret',
            '41954766000192',
            'Faives Solucoes em Tecnologia Ltda'
        );
        $this->joinvilleCertificateFile = TestCertificateFile::create(
            'Freeline Joinville Teste',
            'joinville-secret',
            '83188342000104',
            'FREELINE INFORMATICA LTDA'
        );
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();
        TestCertificateFile::cleanup($this->belemCertificateFile['path'] ?? null);
        TestCertificateFile::cleanup($this->joinvilleCertificateFile['path'] ?? null);
        ProviderRegistry::getInstance()->reload();
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
    }

    public function testPreviewBuildsJoinvilleRequestFromEnvAndMockedTomadorLookup(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_IM=123456
FISCAL_RAZAO_SOCIAL="Freeline Informatica Ltda"
ENV);

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $cnpj): array => [
                'cnpj' => $cnpj,
                'razao_social' => 'Tomador Mock Joinville Ltda',
                'telefone' => '(47) 99999-1234',
                'email' => 'financeiro@example.com',
                'endereco' => [
                    'logradouro' => 'Rua do Comercio',
                    'numero' => '100',
                    'bairro' => 'Centro',
                    'cep' => '89201001',
                    'municipio' => 'Joinville',
                    'uf' => 'SC',
                ],
            ]
        );

        $result = $service->preview('joinville', '11222333000181', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->joinvilleCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->joinvilleCertificateFile['password'],
            ],
        ]);

        $this->assertSame('preview', $result['mode']);
        $this->assertSame('joinville', $result['provider']['municipio']);
        $this->assertStringContainsString('PublicaProvider', $result['provider']['class']);
        $this->assertSame('success', $result['parsed_response']['status']);
        $this->assertSame('Tomador Mock Joinville Ltda', $result['tomador']['razao_social']);
        $this->assertStringContainsString('<GerarNfseEnvio', (string) $result['request_xml']);
        $this->assertNotEmpty($result['resolved_paths']['FISCAL_CERT_PATH'] ?? null);
    }

    public function testPreviewFailsWhenFiscalImIsMissing(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_RAZAO_SOCIAL="Freeline Informatica Ltda"
ENV);

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $cnpj): array => [
                'cnpj' => $cnpj,
                'razao_social' => 'Tomador Mock Ltda',
                'endereco' => [
                    'logradouro' => 'Rua 1',
                    'numero' => '10',
                    'bairro' => 'Centro',
                    'cep' => '89201001',
                    'municipio' => 'Joinville',
                    'uf' => 'SC',
                ],
            ]
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FISCAL_IM');

        $service->preview('joinville', '000000000000000', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->joinvilleCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->joinvilleCertificateFile['password'],
            ],
        ]);
    }

    public function testPreviewLoadsFaivesLegacyCertificateWhenOpenSslConfIsProvided(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_IM=4007197
FISCAL_RAZAO_SOCIAL="Faives Solucoes em Tecnologia Ltda"
ENV);

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $cnpj): array => [
                'cnpj' => $cnpj,
                'razao_social' => 'Tomador Mock Belem Ltda',
                'telefone' => '(91) 99999-0000',
                'email' => 'financeiro@example.com',
                'endereco' => [
                    'logradouro' => 'Rua das Mangueiras',
                    'numero' => '100',
                    'bairro' => 'Nazare',
                    'cep' => '66000000',
                    'municipio' => 'Belem',
                    'uf' => 'PA',
                ],
            ]
        );

        $result = $service->preview('belem', '18171321000114', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->belemCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->belemCertificateFile['password'],
                'OPENSSL_CONF' => $this->projectRoot . '/openssl.cnf',
            ],
        ]);

        $this->assertSame('41954766000192', $result['certificate']['cnpj']);
        $this->assertSame('success', $result['parsed_response']['status']);
        $this->assertStringContainsString('<EnviarLoteRpsSincronoEnvio', (string) $result['request_xml']);
        $this->assertSame(
            realpath($this->projectRoot . '/openssl.cnf'),
            $result['resolved_paths']['OPENSSL_CONF'] ?? null
        );
    }

    public function testPreviewAcceptsCpfTomadorWhenLookupProvidesAddress(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_IM=123456
FISCAL_RAZAO_SOCIAL="Freeline Informatica Ltda"
ENV);

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $documento): array => [
                'documento' => $documento,
                'razao_social' => 'TOMADOR DE EXEMPLO',
                'endereco' => [
                    'logradouro' => 'Rua Homologacao',
                    'numero' => 'S/N',
                    'bairro' => 'Centro',
                    'cep' => '89220650',
                    'municipio' => 'Joinville',
                    'uf' => 'SC',
                    'codigo_municipio' => '4209102',
                ],
            ]
        );

        $result = $service->preview('joinville', '12345678909', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->joinvilleCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->joinvilleCertificateFile['password'],
            ],
        ]);

        $this->assertSame('12345678909', $result['tomador']['documento']);
        $this->assertSame('TOMADOR DE EXEMPLO', $result['tomador']['razao_social']);
        $this->assertSame('success', $result['parsed_response']['status']);
    }

    public function testSendBuildsBelemRequestAndDispatchesThroughSoapTransport(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_IM=4007197
FISCAL_RAZAO_SOCIAL="Faives Solucoes em Tecnologia Ltda"
ENV);

        $transport = new class implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $documento): array => [
                'documento' => $documento,
                'razao_social' => 'TOMADOR DE EXEMPLO',
                'endereco' => [
                    'numero' => 'S/N',
                    'cep' => '66065112',
                ],
            ]
        );

        $result = $service->send('belem', '12345678909', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->belemCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->belemCertificateFile['password'],
                'OPENSSL_CONF' => $this->projectRoot . '/openssl.cnf',
            ],
            'provider_config_overrides' => [
                'soap_transport' => $transport,
            ],
            'tomador_defaults' => [
                'cep' => '66065112',
                'endereco' => [
                    'numero' => 'S/N',
                ],
            ],
        ]);

        $this->assertSame('send', $result['mode']);
        $this->assertSame('belem', $result['provider']['municipio']);
        $this->assertSame('success', $result['parsed_response']['status']);
        $this->assertCount(1, $transport->calls);
        $this->assertSame(
            'https://sefin-hml.belem.pa.gov.br/notafiscal-abrasfv203-ws/NotaFiscalSoap',
            $transport->calls[0]['endpoint']
        );
        $this->assertStringContainsString('<svc:RecepcionarLoteRpsSincrono>', (string) $result['soap_envelope']);
        $this->assertStringContainsString('<EnviarLoteRpsSincronoEnvio', (string) $result['request_xml']);

        $dom = new DOMDocument();
        $dom->loadXML((string) $result['request_xml']);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $references = [];
        foreach ($xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/@URI') as $node) {
            $references[] = $node->nodeValue;
        }
        sort($references);

        $this->assertSame(['#LOTE-BELEM-', '#RPS-BELEM-'], array_map(
            static fn (string $reference): string => str_starts_with($reference, '#LOTE-BELEM-')
                ? '#LOTE-BELEM-'
                : '#RPS-BELEM-',
            $references
        ));

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
    }

    public function testSendBuildsJoinvilleRequestAndDispatchesThroughSoapTransport(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=homologacao
FISCAL_IM=987654321
FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"
FISCAL_CNPJ="83188342000104"
FISCAL_UF="SC"
ENV);

        $transport = new class implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeJoinvilleMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $documento): array => [
                'documento' => $documento,
                'razao_social' => 'TOMADOR DE EXEMPLO',
                'endereco' => [
                    'numero' => 'S/N',
                    'cep' => '89220650',
                ],
            ]
        );

        $result = $service->send('joinville', '12345678909', [
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->joinvilleCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->joinvilleCertificateFile['password'],
                'FISCAL_CNPJ' => '83188342000104',
                'FISCAL_RAZAO_SOCIAL' => 'FREELINE INFORMATICA LTDA',
                'FISCAL_UF' => 'SC',
            ],
            'provider_config_overrides' => [
                'soap_transport' => $transport,
            ],
            'tomador_defaults' => [
                'cep' => '89220650',
                'endereco' => [
                    'numero' => 'S/N',
                ],
            ],
        ]);

        $this->assertSame('send', $result['mode']);
        $this->assertSame('joinville', $result['provider']['municipio']);
        $this->assertSame('success', $result['parsed_response']['status']);
        $this->assertSame('83188342000104', $result['prestador']['cnpj']);
        $this->assertSame('FREELINE INFORMATICA LTDA', $result['prestador']['razao_social']);
        $this->assertCount(1, $transport->calls);
        $this->assertSame(
            'https://nfsehomologacao.joinville.sc.gov.br/nfse_integracao/Services',
            $transport->calls[0]['endpoint']
        );
        $this->assertStringContainsString('<svc:GerarNfse>', (string) $result['soap_envelope']);
        $this->assertStringContainsString('<GerarNfseEnvio', (string) $result['request_xml']);
        $this->assertStringContainsString('<Cnpj>83188342000104</Cnpj>', (string) $result['request_xml']);
        $this->assertStringContainsString('<InscricaoMunicipal>987654321</InscricaoMunicipal>', (string) $result['request_xml']);
    }

    public function testPreviewAllowsBelemProductionWhenExplicitlyEnabledAndUsesFivePercentAliquota(): void
    {
        $envPath = $this->makeEnvFile(<<<ENV
FISCAL_ENVIRONMENT=producao
FISCAL_IM=4007197
FISCAL_RAZAO_SOCIAL="Faives Solucoes em Tecnologia Ltda"
ENV);

        $transport = new class implements NFSeSoapTransportInterface {
            public array $calls = [];

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                $this->calls[] = compact('endpoint', 'envelope', 'options');

                return [
                    'request_xml' => $envelope,
                    'response_xml' => NFSeBelemMunicipalFixtures::successSoapResponse(),
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                ];
            }
        };

        $service = new NFSeMunicipalHomologationService(
            $this->projectRoot,
            fn (string $documento): array => [
                'documento' => $documento,
                'razao_social' => 'TOMADOR DE EXEMPLO',
                'endereco' => [
                    'numero' => 'S/N',
                    'cep' => '66065112',
                ],
            ]
        );

        $result = $service->preview('belem', '12345678909', [
            'allow_production' => true,
            'env_path' => $envPath,
            'env_overrides' => [
                'FISCAL_CERT_PATH' => $this->belemCertificateFile['path'],
                'FISCAL_CERT_PASSWORD' => $this->belemCertificateFile['password'],
                'OPENSSL_CONF' => $this->projectRoot . '/openssl.cnf',
            ],
            'provider_config_overrides' => [
                'soap_transport' => $transport,
            ],
            'payload_overrides' => [
                'valor_servicos' => 10.00,
                'servico' => [
                    'aliquota' => 0.05,
                    'descricao' => 'Servicos de tecnologia da informacao em producao.',
                    'discriminacao' => 'Servicos de tecnologia da informacao em producao.',
                ],
            ],
            'tomador_defaults' => [
                'cep' => '66065112',
                'endereco' => [
                    'numero' => 'S/N',
                ],
            ],
        ]);

        $this->assertSame('preview', $result['mode']);
        $this->assertSame('producao', $result['provider']['ambiente']);
        $this->assertSame(10.0, $result['payload']['valor_servicos']);
        $this->assertSame(0.05, $result['payload']['servico']['aliquota']);
        $this->assertSame('Servicos de tecnologia da informacao em producao.', $result['payload']['servico']['descricao']);
        $this->assertStringContainsString('<ValorServicos>10.00</ValorServicos>', (string) $result['request_xml']);
        $this->assertStringContainsString('<Aliquota>0.0500</Aliquota>', (string) $result['request_xml']);
        $this->assertCount(1, $transport->calls);
    }

    private function makeEnvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nfse-hml-');
        if ($path === false) {
            $this->fail('Nao foi possivel criar arquivo temporario .env');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function clearEnvironment(): void
    {
        foreach ($this->envKeys as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }
}
