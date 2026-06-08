<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Support/TestCertificateFile.php';

use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderConfigTest extends TestCase
{
    private string $projectRoot;
    private string $originalCwd;
    /** @var array{path:string,password:string} */
    private array $certificateFile;
    /** @var string[] */
    private array $managedEnvKeys = [
        'FISCAL_ENVIRONMENT',
        'FISCAL_CNPJ',
        'FISCAL_RAZAO_SOCIAL',
        'FISCAL_IM',
        'FISCAL_UF',
        'FISCAL_CERT_PATH',
        'FISCAL_CERT_PASSWORD',
        'OPENSSL_CONF',
    ];

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->originalCwd = getcwd();
        $this->certificateFile = TestCertificateFile::create(
            'NFSe Provider Config Test',
            'provider-secret',
            '83188342000104'
        );
        $this->bootstrapEnvironment();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->clearEnvironment();
        TestCertificateFile::cleanup($this->certificateFile['path'] ?? null);
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    public function testFacadeLoadsPilotProviderInfoForBelem(): void
    {
        $facade = new NFSeFacade('belem');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('BELEM_MUNICIPAL_2025', $data['provider_key']);
        $this->assertSame('1501402', $data['codigo_municipio']);
        $this->assertStringContainsString('BelemMunicipalProvider', $data['provider_class']);
        $this->assertContains('consultar_nfse_rps', $data['supported_operations']);
        $this->assertSame('belem_municipal_policy', $data['form_policy']['policy_source']);
        $this->assertContains('servico.codigoCnae', $data['form_policy']['required_fields']);
        $this->assertContains('prestador.mei', $data['form_policy']['required_fields']);
        $this->assertContains('servico.codigo_atividade', $data['form_policy']['visible_fields']);
        $this->assertSame('select', $data['form_policy']['field_schema']['prestador.mei']['control']);
        $this->assertSame('864020100', $data['form_policy']['default_values']['servico.codigoCnae']);
        $this->assertSame('1', $data['translation_policy']['field_translations']['service.iss_withheld']['codes']['true']);
        $this->assertSame('2', $data['translation_policy']['field_translations']['service.iss_withheld']['codes']['false']);
    }

    public function testFacadeListsActiveMunicipiosFromCurrentCatalog(): void
    {
        $facade = new NFSeFacade('belem');
        $response = $facade->listarMunicipios();

        $this->assertTrue($response->isSuccess());
        $municipios = $response->getData('municipios');

        $this->assertIsArray($municipios);
        $this->assertContains('belem', $municipios);
        $this->assertContains('joinville', $municipios);
        $this->assertContains('manaus', $municipios);
        $this->assertContains('nacional', $municipios);
        $this->assertContains('presidente-figueiredo', $municipios);
        $this->assertContains('rio-preto-da-eva', $municipios);
        $this->assertGreaterThan(100, count($municipios));
    }

    public function testFacadeMapsJoinvilleToPublica(): void
    {
        $facade = new NFSeFacade('joinville');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('PUBLICA', $data['provider_key']);
        $this->assertSame('4209102', $data['codigo_municipio']);
        $this->assertStringContainsString('PublicaProvider', $data['provider_class']);
        $this->assertContains('consultar_nfse_rps', $data['supported_operations']);
        $this->assertSame('publica_policy', $data['form_policy']['policy_source']);
        $this->assertSame(['servico.cTribMun'], $data['form_policy']['required_fields']);
    }

    public function testFacadeMapsSouthPriorityCitiesToExpectedFamilies(): void
    {
        $expectedMappings = [
            'curitiba' => ['provider_key' => 'nfse_nacional', 'ibge' => '4106902'],
            'balneario-camboriu' => ['provider_key' => 'nfse_nacional', 'ibge' => '4202008'],
            'balneario-barra-do-sul' => ['provider_key' => 'IPM', 'ibge' => '4202057'],
            'itajai' => ['provider_key' => 'PUBLICA', 'ibge' => '4208203'],
            'campo-alegre' => ['provider_key' => 'IPM', 'ibge' => '4203303'],
            'sao-bento-do-sul' => ['provider_key' => 'IPM', 'ibge' => '4215802'],
            'sao-francisco' => ['provider_key' => 'IPM', 'ibge' => '4216206'],
            'garuva' => ['provider_key' => 'IPM', 'ibge' => '4205803'],
            'itapoa' => ['provider_key' => 'IPM', 'ibge' => '4208450'],
            'jaragua' => ['provider_key' => 'nfse_nacional', 'ibge' => '4208906'],
        ];

        foreach ($expectedMappings as $municipio => $expected) {
            $facade = new NFSeFacade($municipio);
            $response = $facade->getProviderInfo();

            $this->assertTrue($response->isSuccess(), "Falha ao resolver {$municipio}");
            $data = $response->getData();
            $this->assertSame($expected['provider_key'], $data['provider_key'], "Provider inesperado para {$municipio}");
            $this->assertSame($expected['ibge'], $data['codigo_municipio'], "IBGE inesperado para {$municipio}");
        }
    }

    public function testFacadeFallsBackToNationalForUnknownMunicipio(): void
    {
        $facade = new NFSeFacade('municipio-inexistente');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertTrue($data['municipio_ignored']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
    }

    public function testFacadeMapsPresidenteFigueiredoToIsswebProvider(): void
    {
        $facade = new NFSeFacade('presidente-figueiredo');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('ISSWEB_AM', $data['provider_key']);
        $this->assertSame('1303536', $data['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $data['provider_class']);
        $this->assertContains('consultar', $data['supported_operations']);
    }

    public function testFacadeMapsRioPretoDaEvaToIsswebProvider(): void
    {
        $facade = new NFSeFacade('rio-preto-da-eva');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('ISSWEB_AM', $data['provider_key']);
        $this->assertSame('1303569', $data['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $data['provider_class']);
        $this->assertContains('consultar', $data['supported_operations']);
    }

    public function testFacadeMapsManausToNationalProvider(): void
    {
        $facade = new NFSeFacade('manaus');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertSame('1302603', $data['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
        $this->assertContains('consultar_por_rps', $data['supported_operations']);
        $this->assertSame('nfse_nacional_policy', $data['form_policy']['policy_source']);
        $this->assertNotContains('servico.cTribMun', $data['form_policy']['required_fields']);
        $this->assertContains('servico.cNBS', $data['form_policy']['required_fields']);
        $this->assertSame('2', $data['translation_policy']['field_translations']['service.iss_withheld']['codes']['true']);
        $this->assertSame('1', $data['translation_policy']['field_translations']['service.iss_withheld']['codes']['false']);
    }

    public function testFacadeHomologationReadinessUsesNationalProviderConfigForManaus(): void
    {
        $facade = new NFSeFacade('manaus');
        $response = $facade->verificarProntidaoHomologacao();

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('ready'));
        $this->assertSame('nfse_nacional', $response->getData('provider_key'));
        $this->assertSame([], $response->getData('missing_requirements'));
        $this->assertTrue($response->getData('certificado_carregado'));
        $this->assertTrue($response->getData('certificado_valido'));
    }

    public function testFacadeMapsSaoLuisToNationalProvider(): void
    {
        $facade = new NFSeFacade('sao-luis');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertSame('2111300', $data['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
    }

    public function testFacadeMapsAnanindeuaToNationalProvider(): void
    {
        $facade = new NFSeFacade('ananindeua');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertSame('1500800', $data['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
    }

    public function testFacadeMapsMarabaToNationalProvider(): void
    {
        $facade = new NFSeFacade('maraba');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertSame('1504208', $data['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
    }

    public function testFacadeMapsCampoGrandeToAbrasfSharedProvider(): void
    {
        $facade = new NFSeFacade('campo-grande');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('ABRASF_SHARED', $data['provider_key']);
        $this->assertSame('5002704', $data['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $data['provider_class']);
    }

    public function testFacadeMapsJoaoPessoaToAbrasfSharedProvider(): void
    {
        $facade = new NFSeFacade('joao-pessoa');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('ABRASF_SHARED', $data['provider_key']);
        $this->assertSame('2507507', $data['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $data['provider_class']);
    }

    public function testFacadeMapsNatalToNationalProvider(): void
    {
        $facade = new NFSeFacade('natal');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());

        $data = $response->getData();
        $this->assertSame('nfse_nacional', $data['provider_key']);
        $this->assertSame('2408102', $data['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $data['provider_class']);
    }

    public function testFacadeMapsFortalezaAndMaceioToGinfesProvider(): void
    {
        $expected = [
            'fortaleza' => '2304400',
            'maceio' => '2704302',
        ];

        foreach ($expected as $slug => $ibge) {
            $facade = new NFSeFacade($slug);
            $response = $facade->getProviderInfo();

            $this->assertTrue($response->isSuccess(), "Falha ao resolver {$slug}");
            $data = $response->getData();
            $this->assertSame('GINFES', $data['provider_key'], "Provider inesperado para {$slug}");
            $this->assertSame($ibge, $data['codigo_municipio'], "IBGE inesperado para {$slug}");
            $this->assertStringContainsString('GinfesProvider', $data['provider_class']);
        }
    }

    public function testFacadeMapsBrasiliaGoianiaAndCuiabaToIssnetProvider(): void
    {
        $expected = [
            'brasilia' => '5300108',
            'goiania' => '5208707',
            'cuiaba' => '5103403',
        ];

        foreach ($expected as $slug => $ibge) {
            $facade = new NFSeFacade($slug);
            $response = $facade->getProviderInfo();

            $this->assertTrue($response->isSuccess(), "Falha ao resolver {$slug}");
            $data = $response->getData();
            $this->assertSame('ISSNET', $data['provider_key'], "Provider inesperado para {$slug}");
            $this->assertSame($ibge, $data['codigo_municipio'], "IBGE inesperado para {$slug}");
            $this->assertStringContainsString('IssnetProvider', $data['provider_class']);
        }
    }

    private function bootstrapEnvironment(): void
    {
        $this->clearEnvironment();
        $tempDir = sys_get_temp_dir() . '/nfse-provider-config-' . uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/.env', implode(PHP_EOL, [
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=4007197',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="' . $this->certificateFile['path'] . '"',
            'FISCAL_CERT_PASSWORD="' . $this->certificateFile['password'] . '"',
        ]) . PHP_EOL);

        chdir($tempDir);
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    private function clearEnvironment(): void
    {
        foreach ($this->managedEnvKeys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }
}
