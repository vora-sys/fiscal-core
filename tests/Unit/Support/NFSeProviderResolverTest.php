<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use PHPUnit\Framework\TestCase;

final class NFSeProviderResolverTest extends TestCase
{
    private function makeResolver(): NFSeProviderResolver
    {
        $catalog = new NFSeMunicipalCatalog(dirname(__DIR__, 3) . '/config/nfse/providers-catalog.json');

        return new NFSeProviderResolver($catalog);
    }

    public function testResolveJoinvilleToPublica(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('PUBLICA', $resolver->resolveKey('joinville'));
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

    public function testResolvePresidenteFigueiredoToIssweb(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('presidente-figueiredo'));
    }

    public function testResolveRioPretoDaEvaToIssweb(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('ISSWEB_AM', $resolver->resolveKey('rio-preto-da-eva'));
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
