<?php

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\NFSe\DefaultProviderRegistrar;
use sabbajohn\FiscalCore\NFSe\ProviderRegistry;

class DefaultProviderRegistrarTest extends TestCase
{
    public function test_register_defaults_adds_expected_keys(): void
    {
        $registry = new ProviderRegistry;
        DefaultProviderRegistrar::registerDefaults($registry);

        $this->assertTrue($registry->has('abrasf-v2-soap'));
        $this->assertTrue($registry->has('sped-nfse'));
        $this->assertTrue($registry->has('php-nfse'));
    }
}
