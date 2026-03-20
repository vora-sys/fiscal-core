<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Support/TestCertificateFile.php';

use freeline\FiscalCore\Facade\NFSeFacade;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ProviderRegistry;
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
    }

    public function testFacadeListsOnlyPilotMunicipios(): void
    {
        $facade = new NFSeFacade('belem');
        $response = $facade->listarMunicipios();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(
            ['belem', 'joinville', 'manaus', 'nacional'],
            $response->getData('municipios')
        );
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
