<?php

declare(strict_types=1);

use freeline\FiscalCore\Facade\FiscalFacade;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class FiscalFacadeCachingTest extends TestCase
{
    private string $projectRoot;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->originalCwd = getcwd();

        $tempDir = sys_get_temp_dir() . '/fiscal-facade-cache-' . uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/.env', implode(PHP_EOL, [
            'FISCAL_ENVIRONMENT=homologacao',
            'FISCAL_IM=4007197',
            'FISCAL_CNPJ=83188342000104',
            'FISCAL_RAZAO_SOCIAL="FREELINE INFORMATICA LTDA"',
            'FISCAL_UF=SC',
            'FISCAL_CERT_PATH="' . $this->projectRoot . '/certs/cert2026-senha-free2026.pfx"',
            'FISCAL_CERT_PASSWORD="free2026"',
        ]) . PHP_EOL);

        chdir($tempDir);
        ConfigManager::getInstance()->reload();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
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
