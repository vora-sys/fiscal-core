<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\NFSeMunicipalCatalog;

final class NFSeCapitalsPriorityMatrixTest extends TestCase
{
    private function catalog(): NFSeMunicipalCatalog
    {
        return new NFSeMunicipalCatalog(dirname(__DIR__, 3) . '/config/nfse/providers-catalog.json');
    }

    public function testCapitalsStayInExpectedFamiliesByPriorityWave(): void
    {
        $catalog = $this->catalog();

        $expected = [
            // Onda 1 - nacional
            ['ibge' => '1200401', 'family' => 'nfse_nacional'], // Rio Branco/AC
            ['ibge' => '1302603', 'family' => 'nfse_nacional'], // Manaus/AM
            ['ibge' => '1400100', 'family' => 'nfse_nacional'], // Boa Vista/RR
            ['ibge' => '2111300', 'family' => 'nfse_nacional'], // Sao Luis/MA
            ['ibge' => '2408102', 'family' => 'nfse_nacional'], // Natal/RN
            ['ibge' => '2611606', 'family' => 'nfse_nacional'], // Recife/PE
            ['ibge' => '3106200', 'family' => 'nfse_nacional'], // Belo Horizonte/MG
            ['ibge' => '3205309', 'family' => 'nfse_nacional'], // Vitoria/ES
            ['ibge' => '3304557', 'family' => 'nfse_nacional'], // Rio de Janeiro/RJ
            ['ibge' => '4106902', 'family' => 'nfse_nacional'], // Curitiba/PR
            ['ibge' => '4205407', 'family' => 'nfse_nacional'], // Florianopolis/SC
            ['ibge' => '4314902', 'family' => 'nfse_nacional'], // Porto Alegre/RS

            // Onda 2 - ABRASF
            ['ibge' => '1501402', 'family' => 'BELEM_MUNICIPAL_2025'], // Belem/PA
            ['ibge' => '2211001', 'family' => 'ABRASF_SHARED'], // Teresina/PI
            ['ibge' => '2507507', 'family' => 'ABRASF_SHARED'], // Joao Pessoa/PB
            ['ibge' => '5002704', 'family' => 'ABRASF_SHARED'], // Campo Grande/MS

            // Onda 3 - Lote 1
            ['ibge' => '2304400', 'family' => 'GINFES'], // Fortaleza/CE
            ['ibge' => '2704302', 'family' => 'GINFES'], // Maceio/AL
            ['ibge' => '5103403', 'family' => 'ISSNET'], // Cuiaba/MT
            ['ibge' => '5208707', 'family' => 'ISSNET'], // Goiania/GO
            ['ibge' => '5300108', 'family' => 'ISSNET'], // Brasilia/DF

            // Onda 3 - Lote 2
            ['ibge' => '1100205', 'family' => 'EL'], // Porto Velho/RO
            ['ibge' => '1702109', 'family' => 'WEBISS'], // Palmas/TO
            ['ibge' => '2800308', 'family' => 'WEBISS'], // Aracaju/SE
            ['ibge' => '2927408', 'family' => 'SALVADOR_BA'], // Salvador/BA
            ['ibge' => '3550308', 'family' => 'PAULISTANA'], // Sao Paulo/SP

            // Onda 3 - Lote 3
            ['ibge' => '1600303', 'family' => 'ABRASF_SHARED'], // Macapa/AP
        ];

        foreach ($expected as $row) {
            $ibge = $row['ibge'];
            $familyKey = $row['family'];
            $municipio = $catalog->getByIbge((string) $ibge);
            $this->assertNotNull($municipio, "Capital {$ibge} ausente no catalogo");
            $this->assertTrue((bool) $municipio['active'], "Capital {$ibge} marcada como inativa");
            $this->assertSame(
                $familyKey,
                $municipio['provider_family_key'],
                "Family inesperada para capital {$ibge}"
            );
        }
    }

    public function testNationalWaveCapitalsExposeMigrationCutoffPolicy(): void
    {
        $catalog = $this->catalog();

        $nationalCapitals = [
            '1200401', // Rio Branco/AC
            '1302603', // Manaus/AM
            '1400100', // Boa Vista/RR
            '2111300', // Sao Luis/MA
            '2408102', // Natal/RN
            '2611606', // Recife/PE
            '3106200', // Belo Horizonte/MG
            '3205309', // Vitoria/ES
            '3304557', // Rio de Janeiro/RJ
            '4106902', // Curitiba/PR
            '4205407', // Florianopolis/SC
            '4314902', // Porto Alegre/RS
        ];

        foreach ($nationalCapitals as $ibge) {
            $municipio = $catalog->getByIbge($ibge);
            $this->assertNotNull($municipio, "Capital {$ibge} ausente no catalogo");
            $this->assertSame('nfse_nacional', $municipio['provider_family_key']);
            $policy = $municipio['national_migration_policy'] ?? [];
            $this->assertIsArray($policy);
            $this->assertTrue(
                (bool) ($policy['enforce_emission_block_before_effective_date'] ?? false),
                "Capital {$ibge} sem enforce_emission_block_before_effective_date"
            );
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) ($policy['effective_from'] ?? ''),
                "Capital {$ibge} sem effective_from valido"
            );
        }
    }
}
