<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use PHPUnit\Framework\TestCase;

final class NFSeAdapterTest extends TestCase
{
    public function testJoinvilleUsesPublicaProvider(): void
    {
        $adapter = new NFSeAdapter('joinville');

        $info = $adapter->getProviderInfo();

        $this->assertSame('PUBLICA', $info['provider_key']);
        $this->assertStringContainsString('PublicaProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('cancelar_nfse', $info['supported_operations']);
    }

    public function testBelemUsesCurrentMunicipalProvider(): void
    {
        $adapter = new NFSeAdapter('belem');

        $info = $adapter->getProviderInfo();

        $this->assertSame('BELEM_MUNICIPAL_2025', $info['provider_key']);
        $this->assertStringContainsString('BelemMunicipalProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('cancelar_nfse', $info['supported_operations']);
    }

    public function testDirectBelemProviderKeyUsesCurrentMunicipalProvider(): void
    {
        $adapter = new NFSeAdapter('BELEM_MUNICIPAL_2025');

        $info = $adapter->getProviderInfo();

        $this->assertSame('BELEM_MUNICIPAL_2025', $info['provider_key']);
        $this->assertStringContainsString('BelemMunicipalProvider', $info['provider_class']);
    }

    public function testManausUsesNacionalProvider(): void
    {
        $adapter = new NFSeAdapter('manaus');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('baixar_danfse', $info['supported_operations']);
    }

    public function testPresidenteFigueiredoUsesIsswebProvider(): void
    {
        $adapter = new NFSeAdapter('presidente-figueiredo');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
        $this->assertContains('cancelar', $info['supported_operations']);
    }

    public function testRioPretoDaEvaUsesSharedIsswebProvider(): void
    {
        $adapter = new NFSeAdapter('rio-preto-da-eva');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertSame('1303569', $info['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
        $this->assertContains('cancelar', $info['supported_operations']);
    }

    public function testUnknownUsesNacionalProvider(): void
    {
        $adapter = new NFSeAdapter('nao-existe');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }
}
