<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSeIsswebFixtures.php';

use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use PHPUnit\Framework\TestCase;

final class IsswebProviderTest extends TestCase
{
    public function testEmitirGeneratesSchemaValidXmlAndParsesSuccess(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::successResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload());
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver())->resolve('ISSWEB_AM', 'emitir');
        $validation = (new NFSeSchemaValidator())->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('success', $provider->getLastResponseData()['status']);
        $this->assertSame('4567', $provider->getLastResponseData()['numero']);
        $this->assertSame('AB12-C3456', $provider->getLastResponseData()['chave_validacao']);
        $this->assertStringContainsString('servicosweb.pmpf.am.gov.br', (string) $provider->getLastResponseData()['nfse_url']);
    }

    public function testConsultarGeneratesSchemaValidXml(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::consultaResponse()),
        ]));

        $provider->consultar('4567');
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver())->resolve('ISSWEB_AM', 'consultar');
        $validation = (new NFSeSchemaValidator())->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('4567', $provider->getLastResponseData()['numero']);
    }

    public function testCancelarGeneratesSchemaValidXml(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::cancelResponse()),
        ]));

        $result = $provider->cancelar('4567', 'Cancelamento de teste em homologacao', 'AB12-C3456');
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver())->resolve('ISSWEB_AM', 'cancelar_nfse');
        $validation = (new NFSeSchemaValidator())->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($result);
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
    }

    public function testParsesRejectionResponse(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::rejectionResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload());

        $this->assertSame('error', $provider->getLastResponseData()['status']);
        $this->assertSame(['[123] Item de atividade invalido para o prestador.'], $provider->getLastResponseData()['mensagens']);
    }

    public function testThrowsWhenAuthKeyIsMissing(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'auth' => ['chave' => ''],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NFSE_ISSWEB_CHAVE');

        $provider->emitir(NFSeIsswebFixtures::payload());
    }

    public function testThrowsWhenEndpointIsMissing(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'wsdl_homologacao' => '',
            'service_base_homologacao' => '',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Endpoint ISSWEB de homologacao');

        $provider->emitir(NFSeIsswebFixtures::payload());
    }

    public function testRejectsMissingPrestadorInscricaoMunicipal(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config());
        $payload = NFSeIsswebFixtures::payload();
        unset($payload['prestador']['inscricaoMunicipal']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prestador.inscricaoMunicipal');

        $provider->emitir($payload);
    }

    public function testRioPretoDaEvaUsesSharedIsswebFamilyWithMunicipalCode(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'municipio_slug' => 'rio-preto-da-eva',
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::successResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload([
            'municipio_slug' => 'rio-preto-da-eva',
        ]));
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver())->resolve('ISSWEB_AM', 'emitir');
        $validation = (new NFSeSchemaValidator())->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('1303569', $provider->getCodigoMunicipio());
        $this->assertNull($provider->getLastResponseData()['nfse_url']);
    }

    public function testFacadeEmitirCompletoReturnsIsswebDocumentAndOfficialUrl(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::successResponse()),
        ]));
        $facade = new NFSeFacade('presidente-figueiredo', new NFSeAdapter('presidente-figueiredo', $provider));

        $response = $facade->emitirCompleto(NFSeIsswebFixtures::payload());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('completo', $response->getData('flow_status'));
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertSame('4567', $response->getData('documento')['numero'] ?? null);
        $this->assertSame('AB12-C3456', $response->getData('documento')['codigo_verificacao'] ?? null);
        $this->assertSame('789', $response->getData('documento')['protocolo'] ?? null);
        $this->assertSame('url', $response->getData('impressao')['modo'] ?? null);
        $this->assertStringContainsString('servicosweb.pmpf.am.gov.br', (string) ($response->getData('impressao')['url'] ?? ''));
    }

    public function testFacadeConsultarDisponibilidadeIsswebUsesConsultaAndOfficialUrl(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::consultaResponse()),
        ]));
        $facade = new NFSeFacade('presidente-figueiredo', new NFSeAdapter('presidente-figueiredo', $provider));

        $response = $facade->consultarDisponibilidade(['numero_nfse' => '4567']);

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertTrue($response->getData('disponivel'));
        $this->assertSame('autorizada', $response->getData('authorization_status'));
        $this->assertSame('4567', $response->getData('nfse')['numero'] ?? null);
        $this->assertSame('AB12-C3456', $response->getData('nfse')['chave_validacao'] ?? null);
        $this->assertStringContainsString('validacao?numero=4567', (string) $response->getData('danfse_url'));
    }

    public function testFacadeBaixarDanfseIsswebReturnsOfficialUrl(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::consultaResponse()),
        ]));
        $facade = new NFSeFacade('presidente-figueiredo', new NFSeAdapter('presidente-figueiredo', $provider));

        $response = $facade->baixarDanfse('4567');

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('url', $response->getData('impressao')['modo'] ?? null);
        $this->assertSame('official_url', $response->getData('impressao')['source'] ?? null);
        $this->assertStringContainsString('chave=AB12-C3456', (string) ($response->getData('impressao')['url'] ?? ''));
    }
}
