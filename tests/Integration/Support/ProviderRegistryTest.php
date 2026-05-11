<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\PublicaProvider;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testGetByMunicipioJoinvilleReturnsPublicaProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('joinville');

        $this->assertInstanceOf(PublicaProvider::class, $provider);
        $this->assertSame('async_lote', $provider->getConfig()['emission_mode'] ?? null);
    }

    public function testGetByMunicipioBelemReturnsCurrentMunicipalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('belem');

        $this->assertInstanceOf(BelemMunicipalProvider::class, $provider);
    }

    public function testGetByMunicipioManausReturnsNacionalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('manaus');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }

    public function testGetByMunicipioPresidenteFigueiredoReturnsIsswebProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('presidente-figueiredo');

        $this->assertInstanceOf(IsswebProvider::class, $provider);
        $this->assertSame(
            'https://servicosweb.pmpf.am.gov.br/issweb/validacao?numero={numero}&chave={chave_validacao}',
            $provider->getConfig()['official_validation_url_template'] ?? null
        );
    }

    public function testGetByMunicipioRioPretoDaEvaReturnsIsswebProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('rio-preto-da-eva');

        $this->assertInstanceOf(IsswebProvider::class, $provider);
        $this->assertSame('1303569', $provider->getCodigoMunicipio());
    }

    public function testGetConfigForMunicipioAppliesMunicipalOverridesWithoutAffectingSharedFamily(): void
    {
        $registry = ProviderRegistry::getInstance();

        $presidente = $registry->getConfigForMunicipio('presidente-figueiredo');
        $rioPreto = $registry->getConfigForMunicipio('rio-preto-da-eva');

        $this->assertSame('1303536', $presidente['codigo_municipio']);
        $this->assertSame('1303569', $rioPreto['codigo_municipio']);
        $this->assertArrayHasKey('payload_defaults', $presidente);
        $this->assertSame(
            'https://servicosweb.pmpf.am.gov.br/issweb/validacao?numero={numero}&chave={chave_validacao}',
            $presidente['official_validation_url_template'] ?? null
        );
        $this->assertArrayNotHasKey('official_validation_url_template', $rioPreto);
    }

    public function testGetByUnknownMunicipioReturnsNacionalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('nao-existe');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }
}
