<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;
use sabbajohn\FiscalCore\Support\NFSeMunicipalProviderOverrides;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;

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
        $catalog = new NFSeMunicipalCatalog(dirname(__DIR__, 3).'/config/nfse/providers-catalog.json');

        if ($overrides === null) {
            return new NFSeProviderResolver($catalog);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nfse-override-');
        if (! is_string($tmp) || $tmp === '') {
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

    public function test_resolve_joinville_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('joinville'));
    }

    public function test_resolve_belem_to_current_municipal_family(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('BELEM_MUNICIPAL_2025', $resolver->resolveKey('belem'));
    }

    public function test_resolve_direct_provider_family_key(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('BELEM_MUNICIPAL_2025', $resolver->resolveKey('BELEM_MUNICIPAL_2025'));

        $metadata = $resolver->buildMetadata('BELEM_MUNICIPAL_2025');
        $this->assertSame('BELEM_MUNICIPAL_2025', $metadata['provider_key']);
        $this->assertSame('provider_key', $metadata['routing_mode']);
        $this->assertFalse($metadata['municipio_ignored']);
        $this->assertSame([], $metadata['warnings']);
    }

    public function test_resolve_manaus_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('manaus'));
    }

    public function test_resolve_rio_branco_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('rio-branco'));
    }

    public function test_resolve_ananindeua_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('ananindeua'));
    }

    public function test_resolve_maraba_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('maraba'));
    }

    public function test_resolve_sao_luis_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('sao-luis'));
    }

    public function test_resolve_campo_grande_to_abrasf_shared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('campo-grande'));
    }

    public function test_resolve_joao_pessoa_to_abrasf_shared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('joao-pessoa'));
    }

    public function test_resolve_castanhal_to_abrasf_shared(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('castanhal'));
        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('Castanhal/PA'));
        $this->assertSame('ABRASF_SHARED', $resolver->resolveKey('1502400'));
    }

    public function test_resolve_natal_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('natal'));
    }

    public function test_resolve_campo_alegre_sc_to_ipm(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('IPM', $resolver->resolveKey('campo-alegre'));
    }

    public function test_resolve_jaragua_short_alias_to_national_jaragua_do_sul(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('jaragua'));
    }

    public function test_resolve_north_coast_sc_municipios(): void
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

    public function test_resolve_brasilia_to_issnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('brasilia'));
    }

    public function test_resolve_fortaleza_to_ginfes(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('GINFES', $resolver->resolveKey('fortaleza'));
    }

    public function test_resolve_maceio_to_ginfes(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('GINFES', $resolver->resolveKey('maceio'));
    }

    public function test_resolve_goiania_to_issnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('goiania'));
    }

    public function test_resolve_cuiaba_to_issnet(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSNET', $resolver->resolveKey('cuiaba'));
    }

    public function test_resolve_sao_paulo_to_paulistana(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('PAULISTANA', $resolver->resolveKey('sao-paulo'));
    }

    public function test_resolve_salvador_to_salvador_ba(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('SALVADOR_BA', $resolver->resolveKey('salvador'));
    }

    public function test_resolve_porto_velho_to_el(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('EL', $resolver->resolveKey('porto-velho'));
    }

    public function test_resolve_aracaju_to_webiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('aracaju'));
    }

    public function test_resolve_feira_de_santana_to_webiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('feira-de-santana'));
        $this->assertSame('WEBISS', $resolver->resolveKey('2910800'));
    }

    public function test_resolve_itabuna_to_webiss(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('WEBISS', $resolver->resolveKey('itabuna'));
        $this->assertSame('WEBISS', $resolver->resolveKey('2914802'));
    }

    public function test_resolve_vitoria_da_conquista_to_el(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('EL', $resolver->resolveKey('vitoria-da-conquista'));
        $this->assertSame('EL', $resolver->resolveKey('2933307'));
    }

    public function test_resolve_presidente_figueiredo_to_issweb_am(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('presidente-figueiredo'));
    }

    public function test_resolve_rio_preto_da_eva_to_issweb_am(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('rio-preto-da-eva'));
    }

    public function test_resolve_municipio_with_operational_override(): void
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

    public function test_ignore_invalid_operational_override_provider(): void
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

    public function test_unknown_falls_back_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey('nao-existe'));
    }

    public function test_null_falls_back_to_national(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame(NFSeProviderResolver::NATIONAL_KEY, $resolver->resolveKey(null));
    }
}
