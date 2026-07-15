<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/Fixtures/NFSeIsswebFixtures.php';

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;

final class IsswebProviderTest extends TestCase
{
    public function test_emitir_generates_schema_valid_xml_and_parses_success(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::successResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload());
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver)->resolve('ISSWEB_AM', 'emitir');
        $validation = (new NFSeSchemaValidator)->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('success', $provider->getLastResponseData()['status']);
        $this->assertSame('4567', $provider->getLastResponseData()['numero']);
        $this->assertSame('AB12-C3456', $provider->getLastResponseData()['chave_validacao']);
        $this->assertStringContainsString('servicosweb.pmpf.am.gov.br', (string) $provider->getLastResponseData()['nfse_url']);
    }

    public function test_consultar_generates_schema_valid_xml(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::consultaResponse()),
        ]));

        $provider->consultar('4567');
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver)->resolve('ISSWEB_AM', 'consultar');
        $validation = (new NFSeSchemaValidator)->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('4567', $provider->getLastResponseData()['numero']);
    }

    public function test_cancelar_generates_schema_valid_xml(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::cancelResponse()),
        ]));

        $result = $provider->cancelar('4567', 'Cancelamento de teste em homologacao', 'AB12-C3456');
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver)->resolve('ISSWEB_AM', 'cancelar_nfse');
        $validation = (new NFSeSchemaValidator)->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($result);
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
    }

    public function test_parses_rejection_response(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::rejectionResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload());

        $this->assertSame('error', $provider->getLastResponseData()['status']);
        $this->assertSame(['[123] Item de atividade invalido para o prestador.'], $provider->getLastResponseData()['mensagens']);
    }

    public function test_throws_when_auth_key_is_missing(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'auth' => ['chave' => ''],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NFSE_ISSWEB_CHAVE');

        $provider->emitir(NFSeIsswebFixtures::payload());
    }

    public function test_throws_when_endpoint_is_missing(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'wsdl_homologacao' => '',
            'service_base_homologacao' => '',
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Endpoint ISSWEB de homologacao');

        $provider->emitir(NFSeIsswebFixtures::payload());
    }

    public function test_rejects_missing_prestador_inscricao_municipal(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config());
        $payload = NFSeIsswebFixtures::payload();
        unset($payload['prestador']['inscricaoMunicipal']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prestador.inscricaoMunicipal');

        $provider->emitir($payload);
    }

    public function test_rio_preto_da_eva_uses_shared_issweb_family_with_municipal_code(): void
    {
        $provider = new IsswebProvider(NFSeIsswebFixtures::config([
            'municipio_slug' => 'rio-preto-da-eva',
            'soap_transport' => NFSeIsswebFixtures::makeTransport(NFSeIsswebFixtures::successResponse()),
        ]));

        $provider->emitir(NFSeIsswebFixtures::payload([
            'municipio_slug' => 'rio-preto-da-eva',
        ]));
        $artifacts = $provider->getLastOperationArtifacts();

        $schema = (new NFSeSchemaResolver)->resolve('ISSWEB_AM', 'emitir');
        $validation = (new NFSeSchemaValidator)->validate((string) $artifacts['request_xml'], $schema);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('1303569', $provider->getCodigoMunicipio());
        $this->assertNull($provider->getLastResponseData()['nfse_url']);
    }

    public function test_facade_emitir_completo_returns_issweb_document_and_official_url(): void
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

    public function test_facade_consultar_disponibilidade_issweb_uses_consulta_and_official_url(): void
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

    public function test_facade_baixar_danfse_issweb_returns_official_url(): void
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
