<?php

declare(strict_types=1);

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;

final class UninfeProviderCoverageTest extends TestCase
{
    public function testAllUninfeProvidersHaveFamilyAndClass(): void
    {
        $root = dirname(__DIR__, 3);
        $csvPath = $root . '/Uninfe/source/NFe.Components.Wsdl/NFse/WSDL/provedores_municipios_por_estado.csv';
        $familiesPath = $root . '/config/nfse/nfse-provider-families.json';

        $this->assertFileExists($familiesPath, 'Catálogo de famílias NFSe não encontrado.');

        if (!is_file($csvPath)) {
            $this->markTestSkipped('CSV de provedores do Uninfe não encontrado neste checkout.');
        }

        $familiesContent = file_get_contents($familiesPath);
        $this->assertNotFalse($familiesContent, 'Falha ao ler nfse-provider-families.json.');
        $families = json_decode((string) $familiesContent, true);
        $this->assertIsArray($families, 'Estrutura inválida em nfse-provider-families.json.');

        $providersFromUninfe = $this->loadUninfeProviders($csvPath);
        $this->assertNotEmpty($providersFromUninfe, 'Nenhum provider válido encontrado no CSV do Uninfe.');

        foreach ($providersFromUninfe as $providerKey) {
            $this->assertArrayHasKey(
                $providerKey,
                $families,
                "Provider do Uninfe sem família no catálogo: {$providerKey}"
            );

            $providerClass = $families[$providerKey]['provider_class'] ?? null;
            $this->assertIsString(
                $providerClass,
                "Provider {$providerKey} sem provider_class configurado."
            );
            $this->assertTrue(
                class_exists($providerClass),
                "Provider {$providerKey} referencia classe inexistente: {$providerClass}"
            );

            $supportedOperations = $families[$providerKey]['supported_operations'] ?? null;
            $this->assertIsArray(
                $supportedOperations,
                "Provider {$providerKey} sem supported_operations configurado."
            );
            $this->assertNotEmpty(
                $supportedOperations,
                "Provider {$providerKey} sem operacoes suportadas."
            );
            $this->assertContains(
                'emitir',
                $supportedOperations,
                "Provider {$providerKey} deve declarar suporte minimo a emissao."
            );
        }
    }

    /**
     * @return list<string>
     */
    private function loadUninfeProviders(string $csvPath): array
    {
        $handle = fopen($csvPath, 'r');
        $this->assertNotFalse($handle, 'Falha ao abrir CSV do Uninfe.');

        fgetcsv($handle, 0, ',', '"', '');

        $providers = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) < 1) {
                continue;
            }

            $provider = strtoupper(trim((string) $row[0]));
            if ($provider === '' || $provider === 'PROVEDOR') {
                continue;
            }

            $providers[$provider] = true;
        }

        fclose($handle);

        $keys = array_keys($providers);
        sort($keys);

        return array_values($keys);
    }
}
