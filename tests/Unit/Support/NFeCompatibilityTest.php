<?php

namespace Tests\Unit\Support;

use NFePHP\NFe\Make;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\NFeCompatibility;

class NFeCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigManager::getInstance()->reload();
    }

    public function test_resolves_pl010_alias_to_installed_schema(): void
    {
        $schema = NFeCompatibility::schema('PL_010');
        $schemas = array_values(array_filter(
            NFeCompatibility::installedSchemas(),
            static fn (string $installedSchema): bool => str_starts_with($installedSchema, 'PL_010')
        ));
        natsort($schemas);
        $latestSchema = array_values($schemas)[count($schemas) - 1];

        $this->assertSame($latestSchema, $schema);
    }

    public function test_runtime_capabilities_expose_sped_nfe_schema_support(): void
    {
        $capabilities = NFeCompatibility::runtimeCapabilities();

        $this->assertArrayHasKey('nfephp_sped_nfe_version', $capabilities);
        $this->assertContains(NFeCompatibility::DEFAULT_SCHEMA, $capabilities['installed_schemas']);
        $this->assertTrue($capabilities['supports_pl_010']);
        $this->assertTrue($capabilities['supports_ibscbs_tags']);
    }

    public function test_explicit_missing_schema_fails_instead_of_falling_back(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Schema NFe/NFCe nao encontrado');

        NFeCompatibility::schema('PL_010_V999');
    }

    public function test_config_manager_resolves_model_specific_schema(): void
    {
        $manager = ConfigManager::getInstance();
        $manager->load([
            'schema_nfe' => 'PL_009_V4',
            'schema_nfce' => 'PL_010',
            'versao_nfe' => '4.00',
            'versao_nfce' => '4.00',
        ]);

        $this->assertSame('PL_009_V4', $manager->getNFeConfig(55)['schemes']);
        $this->assertStringStartsWith('PL_010', $manager->getNFeConfig(65)['schemes']);
        $this->assertSame('4.00', $manager->getNFeConfig(65)['versao']);
    }

    public function test_nota_fiscal_make_uses_configured_schema(): void
    {
        ConfigManager::getInstance()->load([
            'schema_nfe' => 'PL_010',
            'versao_nfe' => '4.00',
        ]);

        $make = NotaFiscalBuilder::fromArray($this->minimalNFeData())->build()->getMake();

        $this->assertSame(10, $this->makeProperty($make, 'schema'));
        $this->assertSame('4.00', $this->makeProperty($make, 'infNFe')->getAttribute('versao'));
    }

    public function test_payload_layout_overrides_global_schema(): void
    {
        ConfigManager::getInstance()->load([
            'schema_nfe' => 'PL_009_V4',
        ]);

        $data = $this->minimalNFeData();
        $data['layout'] = [
            'schema' => 'PL_010',
            'xml_version' => '4.00',
        ];

        $make = NotaFiscalBuilder::fromArray($data)->build()->getMake();

        $this->assertSame(10, $this->makeProperty($make, 'schema'));
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalNFeData(): array
    {
        return [
            'identificacao' => [
                'cUF' => 35,
                'cNF' => 12345678,
                'natOp' => 'VENDA',
                'mod' => 55,
                'serie' => 1,
                'nNF' => 1,
                'cMunFG' => 3550308,
            ],
            'emitente' => [
                'cnpj' => '12345678000190',
                'razaoSocial' => 'EMPRESA TESTE',
                'inscricaoEstadual' => '123456789',
                'logradouro' => 'Rua Teste',
                'numero' => '1',
                'bairro' => 'Centro',
                'codigoMunicipio' => '3550308',
                'municipio' => 'Sao Paulo',
                'uf' => 'SP',
                'cep' => '01001000',
            ],
        ];
    }

    private function makeProperty(Make $make, string $property): mixed
    {
        $reflection = new \ReflectionClass($make);
        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);

        return $propertyReflection->getValue($make);
    }
}
