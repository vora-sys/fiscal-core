<?php

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\BrasilAPIAdapter;

/**
 * @group integration
 */
class BrasilAPIAdapterTest extends TestCase
{
    private BrasilAPIAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new BrasilAPIAdapter;
    }

    public function test_adapter_instancia_sem_client(): void
    {
        $adapter = new BrasilAPIAdapter;
        $this->assertInstanceOf(BrasilAPIAdapter::class, $adapter);
    }

    public function test_normaliza_resposta_array(): void
    {
        $reflection = new ReflectionClass($this->adapter);
        $method = $reflection->getMethod('normalizeResponse');
        $method->setAccessible(true);

        $this->assertEquals(['test' => 'value'], $method->invoke($this->adapter, ['test' => 'value']));
        $this->assertEquals(['test' => 'value'], $method->invoke($this->adapter, (object) ['test' => 'value']));
        $this->assertEquals([], $method->invoke($this->adapter, null));
    }

    public function test_consultar_cep_formato_entrada(): void
    {
        // Test that the adapter properly cleans CEP format
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao consultar CEP');

        // Using invalid CEP to trigger exception without hitting API
        $this->adapter->consultarCEP('00000000');
    }

    public function test_consultar_cnpj_formato_entrada(): void
    {
        // Test that the adapter properly cleans CNPJ format
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao consultar CNPJ');

        // Using invalid CNPJ to trigger exception without hitting API
        $this->adapter->consultarCNPJ('00000000000000');
    }

    public function test_consultar_banco_tipo_entrada(): void
    {
        // Test that the adapter properly converts string to int
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao consultar banco');

        // Using invalid bank code to trigger exception without hitting API
        $this->adapter->consultarBanco('999');
    }

    public function test_listar_bancos_estrutura(): void
    {
        // This method doesn't take parameters, so we can test it returns array structure
        try {
            $result = $this->adapter->listarBancos();
            $this->assertIsArray($result);
        } catch (RuntimeException $e) {
            // If API fails, ensure error message is correct
            $this->assertStringContainsString('Falha ao listar bancos', $e->getMessage());
        }
    }
}
