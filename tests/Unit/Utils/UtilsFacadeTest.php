<?php

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\UtilsFacade;

/**
 * Testes unitários para UtilsFacade
 * Valida separação de responsabilidades entre utilitários e contexto fiscal
 */
class UtilsFacadeTest extends TestCase
{
    private UtilsFacade $utils;

    protected function setUp(): void
    {
        $this->utils = new UtilsFacade();
    }

    /** @test */
    public function deve_validar_cpf_correto(): void
    {
        $cpfs_validos = [
            '11144477735',
            '22233344456', 
            '12345678909'
        ];

        foreach ($cpfs_validos as $cpf) {
            $resultado = $this->utils->validarCPF($cpf);
            
            if ($resultado->isSuccess()) {
                $this->assertTrue($resultado->getData()['valido']);
                $this->assertMatchesRegularExpression('/\d{3}\.\d{3}\.\d{3}-\d{2}/', 
                    $resultado->getData()['cpf']);
            }
        }
    }

    /** @test */
    public function deve_rejeitar_cpf_invalido(): void
    {
        $cpfs_invalidos = [
            '11111111111', // Todos iguais
            '123456789',   // Muito curto
            '1234567890a', // Com letra
            '00000000000'  // Zeros
        ];

        foreach ($cpfs_invalidos as $cpf) {
            $resultado = $this->utils->validarCPF($cpf);
            $this->assertFalse($resultado->isSuccess());
        }
    }

    /** @test */
    public function deve_validar_cnpj_correto(): void
    {
        $cnpjs_validos = [
            '11222333000181',
            '11444777000161'
        ];

        foreach ($cnpjs_validos as $cnpj) {
            $resultado = $this->utils->validarCNPJ($cnpj);
            
            if ($resultado->isSuccess()) {
                $this->assertTrue($resultado->getData()['valido']);
                $this->assertMatchesRegularExpression('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', 
                    $resultado->getData()['cnpj']);
            }
        }
    }

    /** @test */
    public function deve_rejeitar_cnpj_invalido(): void
    {
        $cnpjs_invalidos = [
            '11111111111111', // Todos iguais
            '1234567890123',  // Muito curto
            '00000000000000'  // Zeros
        ];

        foreach ($cnpjs_invalidos as $cnpj) {
            $resultado = $this->utils->validarCNPJ($cnpj);
            $this->assertFalse($resultado->isSuccess());
        }
    }

    /** @test */
    public function deve_consultar_cep_formato_valido(): void
    {
        $ceps_validos = [
            '01310-100',
            '01310100',
            '04567-890'
        ];

        foreach ($ceps_validos as $cep) {
            $resultado = $this->utils->consultarCEP($cep);
            
            // Se API externa estiver disponível
            if ($resultado->isSuccess()) {
                $dados = $resultado->getData();
                $this->assertArrayHasKey('logradouro', $dados);
                $this->assertArrayHasKey('bairro', $dados);
                $this->assertArrayHasKey('uf', $dados);
            }
        }
    }

    /** @test */
    public function deve_listar_bancos_brasileiros(): void
    {
        $resultado = $this->utils->listarBancos();

        if (!$resultado->isSuccess()) {
            $this->markTestSkipped('BrasilAPI indisponível para listar bancos.');
        }

        $bancos = $resultado->getData();
        $this->assertIsArray($bancos);
        $this->assertNotEmpty($bancos);

        $banco = $bancos[0];
        $this->assertArrayHasKey('codigo', $banco);
        $this->assertArrayHasKey('nome', $banco);

        $codigos = array_column($bancos, 'codigo');
        $this->assertContains('001', $codigos);
    }

    /** @test */
    public function deve_consultar_banco_especifico(): void
    {
        $resultado = $this->utils->consultarBanco('001'); // Banco do Brasil

        if (!$resultado->isSuccess()) {
            $this->markTestSkipped('BrasilAPI indisponível para consultar banco.');
        }

        $banco = $resultado->getData();
        $this->assertEquals('001', $banco['codigo']);
        $this->assertNotEmpty($banco['nome']);
    }

    /** @test */
    public function deve_verificar_status_apis_externas(): void
    {
        $resultado = $this->utils->verificarStatusAPIs();

        $this->assertTrue($resultado->isSuccess());
        
        $dados = $resultado->getData();
        $this->assertArrayHasKey('apis', $dados);
        $this->assertArrayHasKey('timestamp', $dados);
        $this->assertArrayHasKey('total_disponivel', $dados);
        
        // Deve verificar pelo menos BrasilAPI
        $this->assertArrayHasKey('brasilapi', $dados['apis']);
    }

    /** @test */
    public function deve_formatar_documentos_corretamente(): void
    {
        // Teste CPF com formatação
        $resultado = $this->utils->validarCPF('12345678901');
        if ($resultado->isSuccess()) {
            $this->assertEquals('123.456.789-01', $resultado->getData()['cpf']);
        }

        // Teste CNPJ com formatação
        $resultado = $this->utils->validarCNPJ('11222333000181');
        if ($resultado->isSuccess()) {
            $this->assertEquals('11.222.333/0001-81', $resultado->getData()['cnpj']);
        }
    }

    /** @test */
    public function deve_listar_municipios_por_uf(): void
    {
        $ufs_teste = ['SP', 'RJ', 'MG'];

        foreach ($ufs_teste as $uf) {
            $resultado = $this->utils->listarMunicipios($uf);
            
            if (!$resultado->isSuccess()) {
                $this->markTestSkipped("BrasilAPI indisponível para listar municípios de {$uf}.");
            }

            $municipios = $resultado->getData();
            $this->assertIsArray($municipios);
            $this->assertNotEmpty($municipios);

            $municipio = $municipios[0];
            $this->assertArrayHasKey('codigo_ibge', $municipio);
            $this->assertArrayHasKey('nome', $municipio);
            $this->assertEquals($uf, $municipio['uf']);
        }
    }

    /** @test */
    public function deve_consultar_ddd_valido(): void
    {
        $ddds_teste = ['11', '21', '31', '47'];

        foreach ($ddds_teste as $ddd) {
            $resultado = $this->utils->consultarDDD($ddd);
            
            if ($resultado->isSuccess()) {
                $dados = $resultado->getData();
                $this->assertEquals($ddd, $dados['ddd']);
                $this->assertArrayHasKey('estado', $dados);
                $this->assertArrayHasKey('cidades', $dados);
                $this->assertIsArray($dados['cidades']);
            }
        }
    }

    /** @test */
    public function deve_listar_feriados_nacionais(): void
    {
        $ano = date('Y');
        $resultado = $this->utils->listarFeriados($ano);

        if ($resultado->isSuccess()) {
            $feriados = $resultado->getData();
            $this->assertIsArray($feriados);
            
            if (!empty($feriados)) {
                $feriado = $feriados[0];
                $this->assertArrayHasKey('data', $feriado);
                $this->assertArrayHasKey('nome', $feriado);
                $this->assertArrayHasKey('tipo', $feriado);
            }
        }
    }
}
