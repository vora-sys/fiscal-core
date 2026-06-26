<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;
use sabbajohn\FiscalCore\Support\NFSeMunicipalProviderOverrides;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use PHPUnit\Framework\TestCase;

final class NFSeProviderResolverTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    private function makeResolver(?array $overrides = null): NFSeProviderResolver
    {
        $catalog = new NFSeMunicipalCatalog(dirname(__DIR__, 3) . '/config/nfse/providers-catalog.json');

        if ($overrides === null) {
            return new NFSeProviderResolver($catalog);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nfse-override-');
        if (!is_string($tmp) || $tmp === '') {
            throw new RuntimeException('Não foi possível criar arquivo temporário de override.');
        }

        $this->tempFiles[] = $tmp;
        file_put_contents(
            $tmp,
            json_encode([
                'version' => 1,
                'overrides' => $overrides,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );

        return new NFSeProviderResolver($catalog, new NFSeMunicipalProviderOverrides($tmp));
    }

    public function testResolveJoinvilleToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('joinville'));
    }

    public function testResolveBelemToCurrentMunicipalFamily(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('BELEM_MUNICIPAL_2025', $resolver->resolveKey('belem'));
    }

    public function testResolveDirectProviderFamilyKey(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('BELEM_MUNICIPAL_2025', $resolver->resolveKey('BELEM_MUNICIPAL_2025'));

        $metadata = $resolver->buildMetadata('BELEM_MUNICIPAL_2025');
        $this->assertSame('BELEM_MUNICIPAL_2025', $metadata['provider_key']);
        $this->assertSame('provider_key', $metadata['routing_mode']);
        $this->assertFalse($metadata['municipio_ignored']);
        $this->assertSame([], $metadata['warnings']);
    }

    public function testResolveManausToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('manaus'));
    }

    public function testResolveRioBrancoToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('rio-branco'));
    }

    public function testResolveAnanindeuaToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('ananindeua'));
    }

    public function testResolveMarabaToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('maraba'));
    }

    public function testResolveSaoLuisToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('sao-luis'));
    }

    public function testResolveCampoGrandeToAbrasfShared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('campo-grande'));
    }

    public function testResolveJoaoPessoaToAbrasfShared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('joao-pessoa'));
    }

    public function testResolveCastanhalToAbrasfShared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('castanhal'));
        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('Castanhal/PA'));
        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('1502400'));
    }

    public function testResolveNatalToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('natal'));
    }

    public function testResolveCampoAlegreScToIpm(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('IPM', $resolver->resolveKey('campo-alegre'));
    }

    public function testResolveJaraguaShortAliasToNationalJaraguaDoSul(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('jaragua'));
    }

    public function testResolveNorthCoastScMunicipios(): void
    {
        $resolver = $this->makeResolver();

        $expected = [
            'itajai' => 'PUBLICA',
            'barra-do-sul' => 'IPM',
            'sao-francisco' => 'IPM',
            'garuva' => 'IPM',
            'itapoa' => 'IPM',
        ];

        foreach ($expected as $municipio => $family) {
            $this->assertSame($family, $resolver->resolveKey($municipio), "Family inesperada para {$municipio}");
        }
    }

    public function testResolveBrasiliaToIssnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('brasilia'));
    }

    public function testResolveFortalezaToGinfes(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('GINFES', $resolver->resolveKey('fortaleza'));
    }

    public function testResolveMaceioToGinfes(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('GINFES', $resolver->resolveKey('maceio'));
    }

    public function testResolveGoianiaToIssnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('goiania'));
    }

    public function testResolveCuiabaToIssnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('cuiaba'));
    }

    public function testResolveSaoPauloToPaulistana(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('PAULISTANA', $resolver->resolveKey('sao-paulo'));
    }

    public function testResolveSalvadorToSalvadorBa(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('SALVADOR_BA', $resolver->resolveKey('salvador'));
    }

    public function testResolvePortoVelhoToEl(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('EL', $resolver->resolveKey('porto-velho'));
    }

    public function testResolveAracajuToWebiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('aracaju'));
    }

    public function testResolveFeiraDeSantanaToWebiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('feira-de-santana'));
        $this->assertSame('WEBISS', $resolver->resolveKey('2910800'));
    }

    public function testResolveItabunaToWebiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('itabuna'));
        $this->assertSame('WEBISS', $resolver->resolveKey('2914802'));
    }

    public function testResolveVitoriaDaConquistaToEl(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('EL', $resolver->resolveKey('vitoria-da-conquista'));
        $this->assertSame('EL', $resolver->resolveKey('2933307'));
    }

    public function testResolvePresidenteFigueiredoToIsswebAm(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('presidente-figueiredo'));
    }

    public function testResolveRioPretoDaEvaToIsswebAm(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('rio-preto-da-eva'));
    }

    public function testResolveMunicipioWithOperationalOverride(): void
    {
        $resolver = $this->makeResolver([
            '1303536' => [
                'provider_family' => 'ABRASF_SHARED',
                'reason' => 'Fallback temporário por instabilidade no endpoint original',
                'ticket' => 'NFSE-1234',
                'active' => true,
            ],
        ]);

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('presidente-figueiredo'));

        $metadata = $resolver->buildMetadata('presidente-figueiredo');
        $this->assertSame('municipal_override', $metadata['routing_mode']);
        $this->assertSame('ABRASF_SHARED', $metadata['provider_key']);
        $this->assertFalse($metadata['municipio_ignored']);
        $this->assertSame('1303536', $metadata['municipio_provider_override']['source_key'] ?? null);
        $this->assertSame('ABRASF_SHARED', $metadata['municipio_provider_override']['provider_key'] ?? null);
        $this->assertTrue($metadata['municipio_provider_override']['applied'] ?? false);
        $this->assertNotEmpty($metadata['warnings']);
    }

    public function testIgnoreInvalidOperationalOverrideProvider(): void
    {
        $resolver = $this->makeResolver([
            '1303536' => [
                'provider_family' => 'PROVIDER_INEXISTENTE',
                'active' => true,
            ],
        ]);

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('presidente-figueiredo'));

        $metadata = $resolver->buildMetadata('presidente-figueiredo');
        $this->assertSame('municipal', $metadata['routing_mode']);
        $this->assertSame('ISSWEB_AM', $metadata['provider_key']);
        $this->assertFalse($metadata['municipio_provider_override']['applied'] ?? true);
        $this->assertNotEmpty($metadata['warnings']);
    }

    public function testUnknownFallsBackToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('nao-existe'));
    }

    public function testNullFallsBackToNational(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey(null));
    }
}
