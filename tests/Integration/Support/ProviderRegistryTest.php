<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\AbrasfSharedProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\ElProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\IsswebProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\WebissProvider;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

final class ProviderRegistryTest extends TestCase
{
    public function test_get_by_municipio_joinville_returns_nacional_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('joinville');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
        $this->assertSame('4209102', $provider->getCodigoMunicipio());
    }

    public function test_get_by_municipio_belem_returns_current_municipal_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('belem');

        $this->assertInstanceOf(BelemMunicipalProvider::class, $provider);
    }

    public function test_get_by_municipio_manaus_returns_nacional_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('manaus');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }

    public function test_get_by_municipio_sao_luis_returns_nacional_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('sao-luis');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }

    public function test_get_by_municipio_campo_grande_returns_abrasf_shared_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('campo-grande');

        $this->assertInstanceOf(AbrasfSharedProvider::class, $provider);
        $this->assertSame('5002704', $provider->getCodigoMunicipio());
    }

    public function test_get_by_municipio_castanhal_returns_abrasf_shared_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('castanhal');

        $this->assertInstanceOf(AbrasfSharedProvider::class, $provider);
        $this->assertSame('1502400', $provider->getCodigoMunicipio());
    }

    public function test_get_by_municipio_presidente_figueiredo_returns_issweb_provider(): void
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

    public function test_get_by_municipio_rio_preto_da_eva_returns_issweb_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('rio-preto-da-eva');

        $this->assertInstanceOf(IsswebProvider::class, $provider);
        $this->assertSame('1303569', $provider->getCodigoMunicipio());
    }

    public function test_get_by_municipio_wave4_nordeste_cities_return_shared_providers(): void
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

    public function test_get_config_for_municipio_applies_municipal_overrides_without_affecting_shared_family(): void
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

    public function test_get_config_for_municipio_includes_wave4_payload_defaults(): void
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

    public function test_get_by_unknown_municipio_returns_nacional_provider(): void
    {
        $registry = ProviderRegistry::getInstance();

        $provider = $registry->getByMunicipio('nao-existe');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }
}
