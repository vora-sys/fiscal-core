<?php

declare(strict_types=1);

use freeline\FiscalCore\Facade\NFSeFacade;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class NFSeFacadeBootstrapTest extends TestCase
{
    private string $projectRoot;
    private string $originalCwd;
    /** @var string[] */
    private array $managedEnvKeys = [
        'FISCAL_ENVIRONMENT',
        'FISCAL_TIMEOUT',
        'FISCAL_CNPJ',
        'FISCAL_RAZAO_SOCIAL',
        'FISCAL_IE',
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
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->clearEnvironment();
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    public function testFacadeBootstrapsMunicipalRuntimeFromConfigAndCertificate(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=987654321',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="' . $this->projectRoot . '/certs/cert2026-senha-free2026.pfx"',
            'FISCAL_CERT_PASSWORD="free2026"',
        ]);

        $facade = new NFSeFacade('joinville');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertTrue((bool) $response->getData('runtime_bootstrapped'));
        $this->assertTrue((bool) $response->getData('certificate_loaded'));
        $this->assertSame('83188342000104', $response->getData('prestador_runtime')['cnpj'] ?? null);
        $this->assertSame('987654321', $response->getData('prestador_runtime')['inscricaoMunicipal'] ?? null);
    }

    public function testFacadeFailsClearlyWhenFiscalImIsMissingForMunicipalProvider(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="' . $this->projectRoot . '/certs/cert2026-senha-free2026.pfx"',
            'FISCAL_CERT_PASSWORD="free2026"',
        ]);

        $facade = new NFSeFacade('joinville');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('FISCAL_IM', (string) $response->getError());
    }

    public function testFacadeFailsClearlyWhenConfiguredCnpjDiffersFromCertificate(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=987654321',
            'FISCAL_CNPJ=11111111111111',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="' . $this->projectRoot . '/certs/cert2026-senha-free2026.pfx"',
            'FISCAL_CERT_PASSWORD="free2026"',
        ]);

        $facade = new NFSeFacade('joinville');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('diverge do certificado', (string) $response->getError());
    }

    private function bootstrapEnvironment(array $lines): void
    {
        $tempDir = sys_get_temp_dir() . '/nfse-facade-bootstrap-' . uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/.env', implode(PHP_EOL, $lines) . PHP_EOL);
        chdir($tempDir);

        $this->clearEnvironment();
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    private function clearEnvironment(): void
    {
        foreach ($this->managedEnvKeys as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }
}
