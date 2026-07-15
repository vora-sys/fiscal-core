<?php

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\DocumentoAdapter;

class DocumentoAdapterTest extends TestCase
{
    private DocumentoAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new DocumentoAdapter;
    }

    public function test_valida_cpf_correto(): void
    {
        $this->assertTrue($this->adapter->validarCPF('123.456.789-09'));
        $this->assertTrue($this->adapter->validarCPF('12345678909'));
    }

    public function test_invalida_cpf_incorreto(): void
    {
        $this->assertFalse($this->adapter->validarCPF('123.456.789-00'));
        $this->assertFalse($this->adapter->validarCPF('11111111111'));
        $this->assertFalse($this->adapter->validarCPF('abc'));
    }

    public function test_formata_cpf(): void
    {
        $formatted = $this->adapter->formatarCPF('123.456.789-09');
        $this->assertStringContainsString('-', $formatted);
        $this->assertStringContainsString('.', $formatted);
        $this->assertMatchesRegularExpression('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/', $formatted);
    }

    public function test_valida_cnpj_correto(): void
    {
        $this->assertTrue($this->adapter->validarCNPJ('11.222.333/0001-81'));
        $this->assertTrue($this->adapter->validarCNPJ('11222333000181'));
    }

    public function test_invalida_cnpj_incorreto(): void
    {
        $this->assertFalse($this->adapter->validarCNPJ('11.222.333/0001-00'));
        $this->assertFalse($this->adapter->validarCNPJ('11111111111111'));
        $this->assertFalse($this->adapter->validarCNPJ('abc'));
    }

    public function test_formata_cnpj(): void
    {
        $formatted = $this->adapter->formatarCNPJ('11.222.333/0001-81');
        $this->assertStringContainsString('/', $formatted);
        $this->assertStringContainsString('-', $formatted);
        $this->assertStringContainsString('.', $formatted);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/', $formatted);
    }
}
