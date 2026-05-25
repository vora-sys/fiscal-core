<?php

declare(strict_types=1);

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;

final class ProviderFamiliesClassCoverageTest extends TestCase
{
    public function testEveryProviderFamilyClassExists(): void
    {
        $path = dirname(__DIR__, 3) . '/config/nfse/nfse-provider-families.json';
        $content = file_get_contents($path);
        $this->assertNotFalse($content, 'Falha ao ler nfse-provider-families.json.');

        $families = json_decode((string) $content, true);
        $this->assertIsArray($families, 'Estrutura inválida de nfse-provider-families.json.');

        foreach ($families as $familyKey => $config) {
            $providerClass = $config['provider_class'] ?? null;
            $this->assertIsString(
                $providerClass,
                "Family {$familyKey} sem provider_class definido."
            );

            $this->assertTrue(
                class_exists($providerClass),
                "Family {$familyKey} referencia provider inexistente: {$providerClass}"
            );
        }
    }
}
