<?php

namespace Tests\Unit\Tributacao;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\TributacaoFacade;

/**
 * Testes unitários para validação de NCM
 * Valida estrutura, formato e regras de negócio
 */
class NCMValidationTest extends TestCase
{
    private TributacaoFacade $tributacao;

    protected function setUp(): void
    {
        $this->tributacao = new TributacaoFacade();
    }

    /** @test */
    public function deve_validar_ncm_formato_correto(): void
    {
        $ncms_validos = [
            '84715010',
            '22071000',
            '01012100',
            '95030000'
        ];

        foreach ($ncms_validos as $ncm) {
            $resultado = $this->tributacao->validarNCM($ncm);
            $this->assertTrue($resultado->isSuccess(), "NCM {$ncm} deveria ser válido");
        }
    }

    /** @test */
    public function deve_rejeitar_ncm_formato_invalido(): void
    {
        $ncms_invalidos = [
            '1234567',      // 7 dígitos
            '123456789',    // 9 dígitos
            'abcd1234',     // letras
            '84715010a',    // letra no final
            '',             // vazio
            '00000000'      // zeros
        ];

        foreach ($ncms_invalidos as $ncm) {
            $resultado = $this->tributacao->validarNCM($ncm);
            $this->assertFalse($resultado->isSuccess(), "NCM {$ncm} deveria ser inválido");
        }
    }

    /** @test */
    public function deve_consultar_dados_ncm_valido(): void
    {
        $resultado = $this->tributacao->consultarNCM('84715010');

        if ($resultado->isSuccess()) {
            $dados = $resultado->getData();
            $this->assertArrayHasKey('codigo', $dados);
            $this->assertArrayHasKey('descricao', $dados);
            $this->assertEquals('84715010', $dados['codigo']);
            $this->assertNotEmpty($dados['descricao']);
        } else {
            // Se API externa falhar, testa fallback local
            $this->assertStringContainsString('API indisponível', $resultado->getError());
        }
    }

    /** @test */
    public function deve_identificar_produtos_sujeitos_substituicao_tributaria(): void
    {
        $ncms_com_st = [
            '22071000', // Bebidas alcoólicas
            '27101129', // Combustíveis
            '27101199'  // Derivados petróleo
        ];

        foreach ($ncms_com_st as $ncm) {
            $resultado = $this->tributacao->verificarSubstituicaoTributaria(['ncm' => $ncm]);
            $this->assertTrue($resultado->isSuccess());
            $this->assertTrue($resultado->getData()['sujeito_st']);
        }
    }

    /** @test */
    public function deve_identificar_produtos_nao_sujeitos_st(): void
    {
        $ncms_sem_st = [
            '84715010', // Equipamentos eletrônicos
            '01012100', // Animais vivos
            '09012100'  // Café
        ];

        foreach ($ncms_sem_st as $ncm) {
            $resultado = $this->tributacao->verificarSubstituicaoTributaria(['ncm' => $ncm]);
            $this->assertTrue($resultado->isSuccess());
            $this->assertFalse($resultado->getData()['sujeito_st']);
        }
    }

    /** @test */
    public function deve_obter_aliquota_ipi_por_ncm(): void
    {
        $casos_teste = [
            '84715010' => 0.0,  // Isento IPI
            '22071000' => 20.0, // Bebidas - 20%
            '27101129' => 0.0   // Combustíveis - isento
        ];

        foreach ($casos_teste as $ncm => $aliquota_esperada) {
            $resultado = $this->tributacao->consultarAliquotaIPI($ncm);
            
            if ($resultado->isSuccess()) {
                $this->assertEquals($aliquota_esperada, $resultado->getData()['aliquota']);
            }
        }
    }

    /** @test */
    public function deve_validar_hierarquia_ncm(): void
    {
        $ncm = '84715010';
        $resultado = $this->tributacao->analisarHieraquiaNCM($ncm);

        $this->assertTrue($resultado->isSuccess());
        
        $dados = $resultado->getData();
        $this->assertEquals('84', $dados['capitulo']);
        $this->assertEquals('8471', $dados['posicao']);
        $this->assertEquals('847150', $dados['subposicao']);
        $this->assertEquals('84715010', $dados['item']);
    }

    /** @test */
    public function deve_expor_diagnostico_canonico_quando_ibpt_nao_esta_configurado(): void
    {
        $oldCnpj = $_ENV['IBPT_CNPJ'] ?? null;
        $oldToken = $_ENV['IBPT_TOKEN'] ?? null;
        unset($_ENV['IBPT_CNPJ'], $_ENV['IBPT_TOKEN']);

        try {
            $resultado = (new TributacaoFacade())->calcular([
                'ncm' => '84715010',
                'valor' => 100,
            ]);
        } finally {
            if ($oldCnpj !== null) {
                $_ENV['IBPT_CNPJ'] = $oldCnpj;
            }
            if ($oldToken !== null) {
                $_ENV['IBPT_TOKEN'] = $oldToken;
            }
        }

        $this->assertFalse($resultado->isSuccess());
        $this->assertSame('IBPT_CONFIG_MISSING', $resultado->getErrorCode());
        $this->assertSame('configuration', $resultado->getMetadata('category'));
        $this->assertSame('tributacao_initialization', $resultado->getMetadata('operation'));
        $this->assertNotEmpty($resultado->getMetadata('trace_id'));
    }
}
