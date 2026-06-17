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

    public function testRecifeUsesNacionalProviderAfterMigration(): void
    {
        $adapter = new NFSeAdapter('recife');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('2611606', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function testCampoGrandeUsesAbrasfSharedProviderAfterDsfMigration(): void
    {
        $adapter = new NFSeAdapter('campo-grande');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ABRASF_SHARED', $info['provider_key']);
        $this->assertSame('5002704', $info['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $info['provider_class']);
        $this->assertContains('substituir_nfse', $info['supported_operations']);
    }

    public function testJoaoPessoaUsesAbrasfSharedProviderAfterDsfMigration(): void
    {
        $adapter = new NFSeAdapter('joao-pessoa');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ABRASF_SHARED', $info['provider_key']);
        $this->assertSame('2507507', $info['codigo_municipio']);
        $this->assertStringContainsString('AbrasfSharedProvider', $info['provider_class']);
        $this->assertContains('substituir_nfse', $info['supported_operations']);
    }

    public function testNatalUsesNacionalProviderAfterMigration(): void
    {
        $adapter = new NFSeAdapter('natal');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('2408102', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function testSouthPriorityCitiesUseExpectedFamilies(): void
    {
        $expectedMappings = [
            'joinville' => ['provider_key' => 'PUBLICA', 'ibge' => '4209102'],
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

    public function testRioBrancoAliasResolvesToNationalProvider(): void
    {
        $adapter = new NFSeAdapter('rio-branco');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1200401', $info['codigo_municipio']);
    }

    public function testAnanindeuaUsesNationalProviderAfterOfficialMigration(): void
    {
        $adapter = new NFSeAdapter('ananindeua');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1500800', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function testMarabaUsesNationalProviderAfterOfficialMigration(): void
    {
        $adapter = new NFSeAdapter('maraba');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertSame('1504208', $info['codigo_municipio']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }

    public function testWave3Lot1CapitalsUseExpectedFamilies(): void
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

    public function testWave3Lot2CapitalsUseExpectedFamilies(): void
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

    public function testWave4NordesteMunicipalLotUsesExpectedFamilies(): void
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

    public function testPresidenteFigueiredoUsesIsswebProvider(): void
    {
        $adapter = new NFSeAdapter('presidente-figueiredo');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertSame('1303536', $info['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
    }

    public function testRioPretoDaEvaUsesIsswebProvider(): void
    {
        $adapter = new NFSeAdapter('rio-preto-da-eva');

        $info = $adapter->getProviderInfo();

        $this->assertSame('ISSWEB_AM', $info['provider_key']);
        $this->assertSame('1303569', $info['codigo_municipio']);
        $this->assertStringContainsString('IsswebProvider', $info['provider_class']);
        $this->assertContains('consultar', $info['supported_operations']);
    }

    public function testUnknownUsesNacionalProvider(): void
    {
        $adapter = new NFSeAdapter('nao-existe');

        $info = $adapter->getProviderInfo();

        $this->assertSame('nfse_nacional', $info['provider_key']);
        $this->assertStringContainsString('NacionalProvider', $info['provider_class']);
    }
}
