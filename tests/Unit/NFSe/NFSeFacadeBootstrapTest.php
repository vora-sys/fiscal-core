<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/Support/TestCertificateFile.php';

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

final class NFSeFacadeBootstrapTest extends TestCase
{
    private string $projectRoot;

    private string $originalCwd;

    /** @var array{path:string,password:string} */
    private array $certificateFile;

    /** @var array{path:string,password:string} */
    private array $runtimeCertificateFile;

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
        $this->certificateFile = TestCertificateFile::create(
            'NFSe Facade Bootstrap Test',
            'bootstrap-secret',
            '83188342000104'
        );
        $this->runtimeCertificateFile = TestCertificateFile::create(
            'NFSe Runtime Bootstrap Test',
            'runtime-secret',
            '01824852000166'
        );
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->clearEnvironment();
        TestCertificateFile::cleanup($this->certificateFile['path'] ?? null);
        TestCertificateFile::cleanup($this->runtimeCertificateFile['path'] ?? null);
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    public function test_facade_bootstraps_municipal_runtime_from_config_and_certificate(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=987654321',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="'.$this->certificateFile['path'].'"',
            'FISCAL_CERT_PASSWORD="'.$this->certificateFile['password'].'"',
        ]);

        $facade = new NFSeFacade('itajai');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertTrue((bool) $response->getData('runtime_bootstrapped'));
        $this->assertTrue((bool) $response->getData('certificate_loaded'));
        $this->assertSame('83188342000104', $response->getData('prestador_runtime')['cnpj'] ?? null);
        $this->assertSame('987654321', $response->getData('prestador_runtime')['inscricaoMunicipal'] ?? null);
    }

    public function test_facade_fails_clearly_when_fiscal_im_is_missing_for_municipal_provider(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="'.$this->certificateFile['path'].'"',
            'FISCAL_CERT_PASSWORD="'.$this->certificateFile['password'].'"',
        ]);

        $facade = new NFSeFacade('itajai');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('FISCAL_IM', (string) $response->getError());
    }

    public function test_facade_fails_clearly_when_configured_cnpj_differs_from_certificate(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=987654321',
            'FISCAL_CNPJ=11111111111111',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="'.$this->certificateFile['path'].'"',
            'FISCAL_CERT_PASSWORD="'.$this->certificateFile['password'].'"',
        ]);

        $facade = new NFSeFacade('itajai');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('diverge do certificado', (string) $response->getError());
    }

    public function test_facade_preserves_runtime_certificate_already_loaded_before_bootstrap(): void
    {
        $this->bootstrapEnvironment([
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_CNPJ=01824852000166',
            'FISCAL_RAZAO_SOCIAL="AGROAM - AGRICOLA AMAZONAS COMERCIAL LTDA"',
            'FISCAL_UF=AM',
            'FISCAL_CERT_PATH="'.$this->certificateFile['path'].'"',
            'FISCAL_CERT_PASSWORD="'.$this->certificateFile['password'].'"',
        ]);

        $runtimeRaw = file_get_contents($this->runtimeCertificateFile['path']);
        $this->assertNotFalse($runtimeRaw);

        CertificateManager::getInstance()->clear()->loadFromContent(
            (string) $runtimeRaw,
            $this->runtimeCertificateFile['password']
        );

        $facade = new NFSeFacade('manaus');
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertTrue((bool) $response->getData('certificate_loaded'));
        $this->assertSame('01824852000166', CertificateManager::getInstance()->getCnpj());
    }

    private function bootstrapEnvironment(array $lines): void
    {
        $tempDir = sys_get_temp_dir().'/nfse-facade-bootstrap-'.uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir.'/.env', implode(PHP_EOL, $lines).PHP_EOL);
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
