<?php

namespace Tests\Unit\NFe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\NFeFacade;

/**
 * Testes unitários para validação de XML NFe
 * Valida estrutura, campos obrigatórios e regras fiscais
 */
class XMLValidationTest extends TestCase
{
    private NFeFacade $nfe;

    protected function setUp(): void
    {
        $this->nfe = new NFeFacade;
    }

    /** @test */
    public function deve_validar_xml_nfe_estrutura_basica(): void
    {
        $xml = $this->criarXMLNFeValido();

        $resultado = $this->nfe->validarXML($xml);
        $this->assertTrue($resultado->isSuccess());
    }

    /** @test */
    public function deve_rejeitar_xml_sem_elementos_obrigatorios(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><NFe></NFe>';

        $resultado = $this->nfe->validarXML($xml);
        $this->assertFalse($resultado->isSuccess());
        $this->assertStringContainsString('infNFe obrigatório', $resultado->getError());
    }

    /** @test */
    public function deve_validar_chave_nfe_formato(): void
    {
        $chaves_validas = [
            '35210315123456789012345678901234567890123456',
            '43200211222333000181550010000000011123456789',
        ];

        foreach ($chaves_validas as $chave) {
            $resultado = $this->nfe->validarChave($chave);
            $this->assertTrue($resultado->isSuccess(), "Chave {$chave} deveria ser válida");
        }
    }

    /** @test */
    public function deve_rejeitar_chave_nfe_invalida(): void
    {
        $chaves_invalidas = [
            '123456789',                                    // muito curta
            '35210315123456789012345678901234567890123456a', // letra
            '',                                             // vazia
            '00000000000000000000000000000000000000000000',   // zeros
        ];

        foreach ($chaves_invalidas as $chave) {
            $resultado = $this->nfe->validarChave($chave);
            $this->assertFalse($resultado->isSuccess(), "Chave {$chave} deveria ser inválida");
        }
    }

    /** @test */
    public function deve_validar_cnpj_emitente(): void
    {
        $emitente = [
            'CNPJ' => '11222333000181',
            'xNome' => 'Empresa Teste LTDA',
            'IE' => '123456789',
            'endereco' => [
                'xLgr' => 'Rua Teste',
                'nro' => '123',
                'xMun' => 'São Paulo',
                'UF' => 'SP',
                'CEP' => '01234567',
            ],
        ];

        $resultado = $this->nfe->validarEmitente($emitente);
        $this->assertTrue($resultado->isSuccess());
    }

    /** @test */
    public function deve_rejeitar_cnpj_emitente_invalido(): void
    {
        $emitente = [
            'CNPJ' => '12345678000199', // CNPJ inválido
            'xNome' => 'Empresa Teste LTDA',
            'IE' => '123456789',
            'endereco' => [
                'xLgr' => 'Rua Teste',
                'nro' => '123',
                'xMun' => 'São Paulo',
                'UF' => 'SP',
                'CEP' => '01234567',
            ],
        ];

        $resultado = $this->nfe->validarEmitente($emitente);
        $this->assertFalse($resultado->isSuccess());
        $this->assertStringContainsString('CNPJ inválido', $resultado->getError());
    }

    /** @test */
    // public function deve_validar_totais_nfe_consistencia(): void
    // {
    //     $itens = [
    //         ['valor' => 100.00, 'quantidade' => 2],
    //         ['valor' => 50.00, 'quantidade' => 1]];
    //     }
    /** @test */
    public function deve_validar_totais_nfe_consistencia(): void
    {
        $totais = [
            'vBC' => 0, 'vICMS' => 45, 'vBCST' => 0, 'vST' => 0,
            'vProd' => 250, 'vFrete' => 0, 'vSeg' => 0, 'vDesc' => 0,
            'vII' => 0, 'vIPI' => 0, 'vPIS' => 0, 'vCOFINS' => 0,
            'vOutro' => 0, 'vNF' => 250,
        ];

        $resultado = $this->nfe->validarTotais($totais);
        $this->assertTrue($resultado->isSuccess());
    }

    /** @test */
    public function deve_detectar_inconsistencia_totais(): void
    {
        $totais = [
            'vBC' => 0, 'vICMS' => 45, 'vBCST' => 0, 'vST' => 0,
            'vProd' => 250, 'vFrete' => 0, 'vSeg' => 0, 'vDesc' => 0,
            'vII' => 0, 'vIPI' => 0, 'vPIS' => 0, 'vCOFINS' => 0,
            'vOutro' => 0, 'vNF' => 300, // Total inconsistente
        ];

        $resultado = $this->nfe->validarTotais($totais);
        $this->assertFalse($resultado->isSuccess());
        $this->assertStringContainsString('Total inconsistente', $resultado->getError());
    }

    /** @test */
    public function deve_validar_cst_icms_validos(): void
    {
        $csts_validos = ['00', '10', '20', '30', '40', '41', '50', '51', '60', '70', '90'];

        foreach ($csts_validos as $cst) {
            $resultado = $this->nfe->validarCST($cst);
            $this->assertTrue($resultado->isSuccess(), "CST {$cst} deveria ser válido");
        }
    }

    /** @test */
    public function deve_rejeitar_cst_icms_invalidos(): void
    {
        $csts_invalidos = ['05', '15', '25', '35', '99', 'AB', ''];

        foreach ($csts_invalidos as $cst) {
            $resultado = $this->nfe->validarCST($cst);
            $this->assertFalse($resultado->isSuccess(), "CST {$cst} deveria ser inválido");
        }
    }

    private function criarXMLNFeValido(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <NFe>
                <infNFe Id="NFe35210315123456789012345678901234567890123456">
                    <ide>
                        <cUF>35</cUF>
                        <cNF>12345678</cNF>
                        <natOp>Venda</natOp>
                        <mod>55</mod>
                        <serie>1</serie>
                        <nNF>123</nNF>
                    </ide>
                    <emit>
                        <CNPJ>11222333000181</CNPJ>
                        <xNome>Empresa Teste</xNome>
                    </emit>
                </infNFe>
            </NFe>';
    }

    private function criarXMLComCNPJ(string $cnpj): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <NFe>
                <infNFe>
                    <emit>
                        <CNPJ>'.$cnpj.'</CNPJ>
                        <xNome>Empresa Teste</xNome>
                    </emit>
                </infNFe>
            </NFe>';
    }

    private function criarXMLComItens(array $itens, float $total): string
    {
        $itensXML = '';
        foreach ($itens as $i => $item) {
            $num = $i + 1;
            $itensXML .= "
                <det nItem=\"{$num}\">
                    <prod>
                        <vProd>{$item['valor']}</vProd>
                        <qCom>{$item['quantidade']}</qCom>
                    </prod>
                </det>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>
            <NFe>
                <infNFe>
                    '.$itensXML.'
                    <total>
                        <ICMSTot>
                            <vNF>'.$total.'</vNF>
                        </ICMSTot>
                    </total>
                </infNFe>
            </NFe>';
    }
}
