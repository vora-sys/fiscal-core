<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Support/TestCertificateFile.php';

use freeline\FiscalCore\Facade\FiscalFacade;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class FiscalFacadeCachingTest extends TestCase
{
    private string $projectRoot;
    private string $originalCwd;
    /** @var array{path:string,password:string} */
    private array $certificateFile;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->originalCwd = getcwd();
        $this->certificateFile = TestCertificateFile::create(
            'Fiscal Facade Cache Test',
            'cache-secret',
            '83188342000104'
        );

        $tempDir = sys_get_temp_dir() . '/fiscal-facade-cache-' . uniqid('', true);
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

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        TestCertificateFile::cleanup($this->certificateFile['path'] ?? null);
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    public function testFiscalFacadeCachesNfseInstancesByMunicipio(): void
    {
        $facade = new FiscalFacade();

        $belemA = $facade->nfse('belem');
        $belemB = $facade->nfse('belem');
        $joinville = $facade->nfse('joinville');

        $this->assertSame($belemA, $belemB);
        $this->assertNotSame($belemA, $joinville);
    }
}
