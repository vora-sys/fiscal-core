<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\AbrasfSharedProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\ElProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\WebissProvider;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function testGetByMunicipioJoinvilleReturnsNacionalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('joinville');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
        $this->assertSame('4209102', $provider->getCodigoMunicipio());
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

    public function testGetByMunicipioSaoLuisReturnsNacionalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('sao-luis');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }

    public function testGetByMunicipioCampoGrandeReturnsAbrasfSharedProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('campo-grande');

        $this->assertInstanceOf(AbrasfSharedProvider::class, $provider);
        $this->assertSame('5002704', $provider->getCodigoMunicipio());
    }

    public function testGetByMunicipioCastanhalReturnsAbrasfSharedProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('castanhal');

        $this->assertInstanceOf(AbrasfSharedProvider::class, $provider);
        $this->assertSame('1502400', $provider->getCodigoMunicipio());
    }

    public function testGetByMunicipioPresidenteFigueiredoReturnsIsswebProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('presidente-figueiredo');

        $this->assertInstanceOf(IsswebProvider::class, $provider);
        $this->assertSame('1303536', $provider->getCodigoMunicipio());
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

    public function testGetByMunicipioWave4NordesteCitiesReturnSharedProviders(): void
    {
        $registry = ProviderRegistry::getInstance();

        $webissCities = [
            'aracaju' => '2800308',
            'feira-de-santana' => '2910800',
            'itabuna' => '2914802',
        ];

        foreach ($webissCities as $municipio => $ibge) {
            $provider = $registry->getByMunicipio($municipio);
            $this->assertInstanceOf(WebissProvider::class, $provider);
            $this->assertSame($ibge, $provider->getCodigoMunicipio());
        }

        $provider = $registry->getByMunicipio('vitoria-da-conquista');
        $this->assertInstanceOf(ElProvider::class, $provider);
        $this->assertSame('2933307', $provider->getCodigoMunicipio());
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

    public function testGetConfigForMunicipioIncludesWave4PayloadDefaults(): void
    {
        $registry = ProviderRegistry::getInstance();

        $expected = [
            'aracaju' => ['family' => 'WEBISS', 'ibge' => '2800308', 'descricao' => 'Servico de homologacao NFSe para Aracaju.'],
            'feira-de-santana' => ['family' => 'WEBISS', 'ibge' => '2910800', 'descricao' => 'Servico de homologacao NFSe para Feira de Santana.'],
            'itabuna' => ['family' => 'WEBISS', 'ibge' => '2914802', 'descricao' => 'Servico de homologacao NFSe para Itabuna.'],
            'vitoria-da-conquista' => ['family' => 'EL', 'ibge' => '2933307', 'descricao' => 'Servico de homologacao NFSe para Vitoria da Conquista.'],
        ];

        foreach ($expected as $municipio => $meta) {
            $config = $registry->getConfigForMunicipio($municipio);

            $this->assertSame($meta['family'], $config['provider_key'] ?? $meta['family']);
            $this->assertSame($meta['ibge'], $config['codigo_municipio']);
            $this->assertArrayHasKey('payload_defaults', $config);
            $this->assertSame('123', (string) ($config['payload_defaults']['rps']['numero'] ?? ''));
            $this->assertSame($meta['descricao'], $config['payload_defaults']['servico']['descricao'] ?? null);
        }
    }

    public function testGetByUnknownMunicipioReturnsNacionalProvider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('nao-existe');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }
}
