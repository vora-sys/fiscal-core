<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;
use PHPUnit\Framework\TestCase;

final class NFSeMunicipalCatalogTest extends TestCase
{
    private function fixturePath(): string
    {
        return dirname(__DIR__, 3) . '/config/nfse/providers-catalog.json';
    }

    public function testResolveJoinvilleBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('joinville');

        $this->assertNotNull($result);
        $this->assertSame('4209102', $result['ibge']);
        $this->assertSame('PUBLICA', $result['provider_family_key']);
    }

    public function testResolveBelemByAccentedName(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('Belém');

        $this->assertNotNull($result);
        $this->assertSame('1501402', $result['ibge']);
        $this->assertSame('BELEM_MUNICIPAL_2025', $result['provider_family_key']);
    }

    public function testResolveManausByIbge(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('1302603');

        $this->assertNotNull($result);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
    }

    public function testResolveRioBrancoBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('rio-branco');

        $this->assertNotNull($result);
        $this->assertSame('1200401', $result['ibge']);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
    }

    public function testResolveAnanindeuaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('ananindeua');

        $this->assertNotNull($result);
        $this->assertSame('1500800', $result['ibge']);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
        $this->assertSame('2026-01-01', $result['national_migration_policy']['effective_from'] ?? null);
    }

    public function testResolveMarabaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('maraba');

        $this->assertNotNull($result);
        $this->assertSame('1504208', $result['ibge']);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
        $this->assertSame('2023-01-23', $result['national_migration_policy']['effective_from'] ?? null);
    }

    public function testResolveMacapaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('macapa');

        $this->assertNotNull($result);
        $this->assertSame('1600303', $result['ibge']);
        $this->assertSame('ABRASF_SHARED', $result['provider_family_key']);
    }

    public function testResolveJoaoPessoaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('joao-pessoa');

        $this->assertNotNull($result);
        $this->assertSame('2507507', $result['ibge']);
        $this->assertSame('ABRASF_SHARED', $result['provider_family_key']);
    }

    public function testResolveCastanhalByNameAndIbge(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $byName = $catalog->resolveMunicipio('Castanhal/PA');
        $byIbge = $catalog->resolveMunicipio('1502400');

        $this->assertNotNull($byName);
        $this->assertNotNull($byIbge);
        $this->assertSame('1502400', $byName['ibge']);
        $this->assertSame('1502400', $byIbge['ibge']);
        $this->assertSame('ABRASF_SHARED', $byName['provider_family_key']);
        $this->assertSame('ABRASF_SHARED', $byIbge['provider_family_key']);
    }

    public function testResolveNatalBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('natal');

        $this->assertNotNull($result);
        $this->assertSame('2408102', $result['ibge']);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
        $this->assertSame('2026-01-01', $result['national_migration_policy']['effective_from'] ?? null);
    }

    public function testResolveCampoAlegreScBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('campo-alegre');

        $this->assertNotNull($result);
        $this->assertSame('4203303', $result['ibge']);
        $this->assertSame('IPM', $result['provider_family_key']);
    }

    public function testResolveJaraguaShortAliasToJaraguaDoSul(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('jaragua');

        $this->assertNotNull($result);
        $this->assertSame('4208906', $result['ibge']);
        $this->assertSame('nfse_nacional', $result['provider_family_key']);
        $this->assertSame('2025-12-10', $result['national_migration_policy']['effective_from'] ?? null);
    }

    public function testResolveNorthCoastScMunicipiosBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $expected = [
            'itajai' => ['ibge' => '4208203', 'family' => 'PUBLICA'],
            'barra-do-sul' => ['ibge' => '4202057', 'family' => 'IPM'],
            'sao-francisco' => ['ibge' => '4216206', 'family' => 'IPM'],
            'garuva' => ['ibge' => '4205803', 'family' => 'IPM'],
            'itapoa' => ['ibge' => '4208450', 'family' => 'IPM'],
        ];

        foreach ($expected as $slug => $target) {
            $result = $catalog->resolveMunicipio($slug);

            $this->assertNotNull($result, "Municipio {$slug} nao resolvido");
            $this->assertSame($target['ibge'], $result['ibge'], "IBGE inesperado para {$slug}");
            $this->assertSame($target['family'], $result['provider_family_key'], "Family inesperada para {$slug}");
        }
    }

    public function testResolveFortalezaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('fortaleza');

        $this->assertNotNull($result);
        $this->assertSame('2304400', $result['ibge']);
        $this->assertSame('GINFES', $result['provider_family_key']);
    }

    public function testResolveMaceioBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('maceio');

        $this->assertNotNull($result);
        $this->assertSame('2704302', $result['ibge']);
        $this->assertSame('GINFES', $result['provider_family_key']);
    }

    public function testResolveBrasiliaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('brasilia');

        $this->assertNotNull($result);
        $this->assertSame('5300108', $result['ibge']);
        $this->assertSame('ISSNET', $result['provider_family_key']);
    }

    public function testResolveGoianiaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('goiania');

        $this->assertNotNull($result);
        $this->assertSame('5208707', $result['ibge']);
        $this->assertSame('ISSNET', $result['provider_family_key']);
    }

    public function testResolveCuiabaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('cuiaba');

        $this->assertNotNull($result);
        $this->assertSame('5103403', $result['ibge']);
        $this->assertSame('ISSNET', $result['provider_family_key']);
    }

    public function testResolvePresidenteFigueiredoBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('presidente-figueiredo');

        $this->assertNotNull($result);
        $this->assertSame('1303536', $result['ibge']);
        $this->assertSame('ISSWEB_AM', $result['provider_family_key']);
    }

    public function testResolveRioPretoDaEvaBySlug(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $result = $catalog->resolveMunicipio('rio-preto-da-eva');

        $this->assertNotNull($result);
        $this->assertSame('1303569', $result['ibge']);
        $this->assertSame('ISSWEB_AM', $result['provider_family_key']);
    }

    public function testUnknownMunicipioReturnsNull(): void
    {
        $catalog = new NFSeMunicipalCatalog($this->fixturePath());

        $this->assertNull($catalog->resolveMunicipio('nao-existe'));
    }
}
