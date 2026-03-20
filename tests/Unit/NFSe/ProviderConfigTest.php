<?php

declare(strict_types=1);

use freeline\FiscalCore\Facade\NFSeFacade;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderConfigTest extends TestCase
{
    private string $projectRoot;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->originalCwd = getcwd();
        $this->bootstrapEnvironment();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
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
        $tempDir = sys_get_temp_dir() . '/nfse-provider-config-' . uniqid('', true);
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
}
