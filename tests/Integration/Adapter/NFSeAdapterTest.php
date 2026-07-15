<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;

final class NFSeAdapterTest extends TestCase
{
    public function test_joinville_uses_nacional_provider(): void
    {
        $adapter = new NFSeAdapter('joinville');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('4209102', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('baixar_danfse', $info['supported_operations']);
    }

    public function test_belem_uses_current_municipal_provider(): void
    {
        $adapter = new NFSeAdapter('belem');

        $info = $adapter->getProviderInfo();

        $this->assertSame('BELEM_MUNICIPAL_2025', $info['provider_key']);
        $this->assertStringContainsString('BelemMunicipalProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('cancelar_nfse', $info['supported_operations']);
    }

    public function test_direct_belem_provider_key_uses_current_municipal_provider(): void
    {
        $adapter = new NFSeAdapter('BELEM_MUNICIPAL_2025');

        $info = $adapter->getProviderInfo();

        $this->assertSame('BELEM_MUNICIPAL_2025', $info['provider_key']);
        $this->assertStringContainsString('BelemMunicipalProvider', $info['provider_class']);
    }

    public function test_manaus_uses_nacional_provider(): void
    {
        $adapter = new NFSeAdapter('manaus');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
        $this->assertContains('consultar_lote', $info['supported_operations']);
        $this->assertContains('baixar_danfse', $info['supported_operations']);
    }

    public function test_recife_uses_nacional_provider_after_migration(): void
    {
        $adapter = new NFSeAdapter('recife');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('2611606', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function test_campo_grande_uses_abrasf_shared_provider_after_dsf_migration(): void
    {
        $adapter = new NFSeAdapter('campo-grande');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ABRASF_SHARED', $info['provider_key']);
        $this->assertSame('5002704', $info['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $info['provider_class']);
        $this->assertContains('substituir_nfse', $info['supported_operations']);
    }

    public function test_joao_pessoa_uses_abrasf_shared_provider_after_dsf_migration(): void
    {
        $adapter = new NFSeAdapter('joao-pessoa');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ABRASF_SHARED', $info['provider_key']);
        $this->assertSame('2507507', $info['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $info['provider_class']);
        $this->assertContains('substituir_nfse', $info['supported_operations']);
    }

    public function test_castanhal_uses_abrasf_shared_provider(): void
    {
        $adapter = new NFSeAdapter('castanhal');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ABRASF_SHARED', $info['provider_key']);
        $this->assertSame('1502400', $info['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $info['provider_class']);
        $this->assertContains('substituir_nfse', $info['supported_operations']);
    }

    public function test_natal_uses_nacional_provider_after_migration(): void
    {
        $adapter = new NFSeAdapter('natal');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('2408102', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function test_south_priority_cities_use_expected_families(): void
    {
        $expectedMappings = [
            'joinville' => ['provider_key' => 'nfse_nacional', 'ibge' => '4209102'],
            'curitiba' => ['provider_key' => 'nfse_nacional', 'ibge' => '4106902'],
            'balneario-camboriu' => ['provider_key' => 'nfse_nacional', 'ibge' => '4202008'],
            'balneario-barra-do-sul' => ['provider_key' => 'IPM', 'ibge' => '4202057'],
            'itajai' => ['provider_key' => 'PUBLICA', 'ibge' => '4208203'],
            'campo-alegre' => ['provider_key' => 'IPM', 'ibge' => '4203303'],
            'sao-bento-do-sul' => ['provider_key' => 'IPM', 'ibge' => '4215802'],
            'sao-francisco' => ['provider_key' => 'IPM', 'ibge' => '4216206'],
            'garuva' => ['provider_key' => 'IPM', 'ibge' => '4205803'],
            'itapoa' => ['provider_key' => 'IPM', 'ibge' => '4208450'],
            'jaragua' => ['provider_key' => 'nfse_nacional', 'ibge' => '4208906'],
        ];

        foreach ($expectedMappings as $municipio => $expected) {
            $adapter = new NFSeAdapter($municipio);
            $info = $adapter->getProviderInfo();

            $this->assertSame($expected['provider_key'], $info['provider_key'], "family invalida para {$municipio}");
            $this->assertSame($expected['ibge'], $info['codigo_municipio'], "ibge invalido para {$municipio}");
        }
    }

    public function test_rio_branco_alias_resolves_to_national_provider(): void
    {
        $adapter = new NFSeAdapter('rio-branco');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1200401', $info['codigo_municipio']);
    }

    public function test_ananindeua_uses_national_provider_after_official_migration(): void
    {
        $adapter = new NFSeAdapter('ananindeua');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1500800', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function test_maraba_uses_national_provider_after_official_migration(): void
    {
        $adapter = new NFSeAdapter('maraba');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1504208', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function test_wave3_lot1_capitals_use_expected_families(): void
    {
        $expectedMappings = [
            'brasilia' => ['provider_key' => 'ISSNET', 'ibge' => '5300108'],
            'goiania' => ['provider_key' => 'ISSNET', 'ibge' => '5208707'],
            'cuiaba' => ['provider_key' => 'ISSNET', 'ibge' => '5103403'],
            'fortaleza' => ['provider_key' => 'GINFES', 'ibge' => '2304400'],
            'maceio' => ['provider_key' => 'GINFES', 'ibge' => '2704302'],
        ];

        foreach ($expectedMappings as $municipio => $expected) {
            $adapter = new NFSeAdapter($municipio);
            $info = $adapter->getProviderInfo();

            $this->assertSame($expected['provider_key'], $info['provider_key'], "family invalida para {$municipio}");
            $this->assertSame($expected['ibge'], $info['codigo_municipio'], "ibge invalido para {$municipio}");
        }
    }

    public function test_wave3_lot2_capitals_use_expected_families(): void
    {
        $expectedMappings = [
            'sao-paulo' => ['provider_key' => 'PAULISTANA', 'ibge' => '3550308'],
            'salvador' => ['provider_key' => 'SALVADOR_BA', 'ibge' => '2927408'],
            'porto-velho' => ['provider_key' => 'EL', 'ibge' => '1100205'],
            'aracaju' => ['provider_key' => 'WEBISS', 'ibge' => '2800308'],
            'palmas' => ['provider_key' => 'WEBISS', 'ibge' => '1721000'],
        ];

        foreach ($expectedMappings as $municipio => $expected) {
            $adapter = new NFSeAdapter($municipio);
            $info = $adapter->getProviderInfo();

            $this->assertSame($expected['provider_key'], $info['provider_key'], "family invalida para {$municipio}");
            $this->assertSame($expected['ibge'], $info['codigo_municipio'], "ibge invalido para {$municipio}");
        }
    }

    public function test_wave4_nordeste_municipal_lot_uses_expected_families(): void
    {
        $expectedMappings = [
            'aracaju' => ['provider_key' => 'WEBISS', 'ibge' => '2800308'],
            'feira-de-santana' => ['provider_key' => 'WEBISS', 'ibge' => '2910800'],
            'itabuna' => ['provider_key' => 'WEBISS', 'ibge' => '2914802'],
            'vitoria-da-conquista' => ['provider_key' => 'EL', 'ibge' => '2933307'],
        ];

        foreach ($expectedMappings as $municipio => $expected) {
            $adapter = new NFSeAdapter($municipio);
            $info = $adapter->getProviderInfo();

            $this->assertSame($expected['provider_key'], $info['provider_key'], "family invalida para {$municipio}");
            $this->assertSame($expected['ibge'], $info['codigo_municipio'], "ibge invalido para {$municipio}");
        }
    }

    public function test_presidente_figueiredo_uses_issweb_provider(): void
    {
        $adapter = new NFSeAdapter('presidente-figueiredo');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertSame('1303536', $info['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
    }

    public function test_rio_preto_da_eva_uses_issweb_provider(): void
    {
        $adapter = new NFSeAdapter('rio-preto-da-eva');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertSame('1303569', $info['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
    }

    public function test_unknown_uses_nacional_provider(): void
    {
        $adapter = new NFSeAdapter('nao-existe');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }
}
