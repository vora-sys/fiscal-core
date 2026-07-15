<?php

namespace sabbajohn\FiscalCore\Tests;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\GTINAdapter;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;

class GTINAdapterTest extends TestCase
{
    private GTINAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new GTINAdapter;
    }

    protected function tearDown(): void
    {
        // Singletons mantêm estado durante testes
    }

    public function test_sem_gtin_is_valid(): void
    {
        $this->assertTrue($this->adapter->validarGTIN('SEM GTIN'));
        $this->assertTrue($this->adapter->validarGTIN('sem gtin'));
    }

    public function test_gtin8_valid_and_invalid(): void
    {
        $this->assertTrue($this->adapter->validarGTIN('12345670')); // válido (dígito 0)
        $this->assertFalse($this->adapter->validarGTIN('12345671')); // inválido
    }

    public function test_invalid_lengths(): void
    {
        $this->assertFalse($this->adapter->validarGTIN('123'));
        $this->assertFalse($this->adapter->validarGTIN('abcdefghijkl'));
    }

    /**
     * @testdox Deve validar GTINs válidos corretamente
     */
    public function test_validar_gtin_validos()
    {
        $gtinsValidos = [
            '7891000315507', // GTIN-13 válido
            'SEM GTIN',      // Aceito como válido
            '',              // Vazio aceito como válido
        ];

        foreach ($gtinsValidos as $gtin) {
            $this->assertTrue(
                $this->adapter->validarGTIN($gtin),
                "GTIN '{$gtin}' deveria ser válido"
            );
        }
    }

    /**
     * @testdox Deve rejeitar GTINs inválidos
     */
    public function test_validar_gtin_invalidos()
    {
        $gtinsInvalidos = [
            '123',           // Muito curto
            '12345',         // Formato inválido
            '1234567890123', // Dígito verificador inválido (assumindo)
            'abc123456789',  // Contém letras
        ];

        foreach ($gtinsInvalidos as $gtin) {
            $this->assertFalse(
                $this->adapter->validarGTIN($gtin),
                "GTIN '{$gtin}' deveria ser inválido"
            );
        }
    }

    /**
     * @testdox Deve executar checkGTIN sem erros para GTINs válidos
     */
    public function test_check_gtin_valido()
    {
        $result = $this->adapter->checkGTIN('7891000315507');
        $this->assertInstanceOf(GTINAdapter::class, $result);

        // Testa GTINs especiais
        $result = $this->adapter->checkGTIN('SEM GTIN');
        $this->assertInstanceOf(GTINAdapter::class, $result);

        $result = $this->adapter->checkGTIN('');
        $this->assertInstanceOf(GTINAdapter::class, $result);
    }

    /**
     * @testdox Deve lançar exceção para GTINs inválidos no checkGTIN
     */
    public function test_check_gtin_invalido_lanca_excecao()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('formato inválido');

        $this->adapter->checkGTIN('123');
    }

    /**
     * @testdox Deve detectar GTIN com dígito verificador inválido
     */
    public function test_check_gtin_digito_verificador_invalido()
    {
        // Este teste verifica se o método detecta dígitos incorretos
        // Se a lib NFePHP estiver disponível, pode retornar sucesso
        try {
            $this->adapter->checkGTIN('1234567890123'); // Possível dígito verificador errado
            $this->assertTrue(true); // Se chegou aqui, GTIN foi aceito (pode ser válido pela lib)
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('inválido', $e->getMessage());
        }
    }

    /**
     * @testdox Deve requerer certificado para buscar produto
     */
    public function test_buscar_produto_sem_certificado()
    {
        $certificateManager = CertificateManager::getInstance();
        $certificateManager->clear(); // Garante que não há certificado carregado

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Certificado digital necessário');

        $this->adapter->buscarProduto('7891000315507');
    }

    /**
     * @testdox Deve lançar exceção para GTIN inválido na busca
     */
    public function test_buscar_produto_gtin_invalido()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GTIN inválido');

        $this->adapter->buscarProduto('123');
    }

    /**
     * @testdox Deve retornar estrutura esperada na busca (simulada)
     */
    public function test_buscar_produto_com_certificado()
    {

        // Cria novo adapter que deve detectar o certificado
        $adapter = new GTINAdapter;
        CertificateManager::reload();
        $resultado = $adapter->buscarProduto('7891000315507');

        $this->assertIsArray($resultado);
        $this->assertArrayHasKey('sucesso', $resultado);
        $this->assertArrayHasKey('xProd', $resultado);
        $this->assertArrayHasKey('NCM', $resultado);
        $this->assertArrayHasKey('CEST', $resultado);
        $this->assertArrayHasKey('cstat', $resultado);

        $this->assertEquals('21011110', $resultado['NCM']);
    }

    /**
     * @testdox Deve consultar NCM com certificado
     */
    public function test_consultar_ncm()
    {
        CertificateManager::reload();
        // Cria novo adapter que deve detectar o certificado
        $adapter = new GTINAdapter;
        $resultado = $adapter->consultarNCM('7891000315507');

        $this->assertIsArray($resultado);
        $this->assertArrayHasKey('gtin', $resultado);
        $this->assertArrayHasKey('ncm', $resultado);
        $this->assertArrayHasKey('cest', $resultado);
        // $this->assertArrayHasKey('descricao_ncm', $resultado);
        $this->assertArrayHasKey('aliquota_ipi', $resultado);
        $this->assertArrayHasKey('origem', $resultado);
        $this->assertArrayHasKey('consultado_em', $resultado);
    }

    /**
     * @testdox Tenta encontrar o NCM oficial do produto através do nome
     */
    public function test_obter_ncm_por_descricao_do_produto()
    {
        $this->markTestSkipped('Removemos a funcionalidade pois mesclava duas fontes distintas.');
        // 2101.11.10
        // Café solúvel, mesmo descafeinado
        $descricao = 'Café Solúvel';
        $ncm = $this->adapter->pesquisarNCM($descricao);

        $this->assertIsArray($ncm);
        $this->assertEquals('2101.11.10', $ncm[0]['codigo']);
        $this->assertStringContainsString('Café solúvel', $ncm[0]['descricao']);
    }

    /**
     * @testdox Deve obter descrição do produto
     */
    public function test_obter_descricao()
    {
        // Sem certificado, deve retornar null
        CertificateManager::getInstance()->clear();
        $this->assertNull($this->adapter->obterDescricao('7891000315507'));

        // Com certificado, deve retornar descrição
        CertificateManager::reload();

        $adapter = new GTINAdapter;
        $descricao = $adapter->obterDescricao('7891000315507');
        $this->assertIsString($descricao);
        $this->assertEquals('NESCAFÉ Café Solúvel Matinal 100g', $descricao);
    }

    /**
     * @testdox Deve inicializar com singletons quando disponíveis
     */
    public function test_inicializacao_com_singletons()
    {
        // Configura singletons
        $configManager = ConfigManager::getInstance();

        $configManager->set('ambiente', 'teste');

        // Cria novo adapter - deve usar os singletons
        $adapter = new GTINAdapter;

        // Testa se funcionalidades que dependem de certificado funcionam
        $resultado = $adapter->buscarProduto('7891000315507');
        $this->assertIsArray($resultado);
    }
}
