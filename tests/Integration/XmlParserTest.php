<?php

namespace Tests\Integration;

use NFePHP\NFe\Make;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaFiscal;
use sabbajohn\FiscalCore\Adapters\NF\XmlParser;

/**
 * Testes para parsing de XML e reconstrução de NotaFiscal
 */
class XmlParserTest extends TestCase
{
    private string $xmlNFCeExemplo;

    protected function setUp(): void
    {
        // XML simplificado de NFCe para testes
        $this->xmlNFCeExemplo = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <NFe xmlns="http://www.portalfiscal.inf.br/nfe">
            <infNFe Id="NFe43231234567890001234650010000001231234567890" versao="4.00">
                <ide>
                <cUF>43</cUF>
                <cNF>12345678</cNF>
                <natOp>VENDA AO CONSUMIDOR</natOp>
                <mod>65</mod>
                <serie>1</serie>
                <nNF>123</nNF>
                <dhEmi>2023-11-27T10:30:00-03:00</dhEmi>
                <tpNF>1</tpNF>
                <idDest>1</idDest>
                <cMunFG>4314902</cMunFG>
                <tpImp>4</tpImp>
                <tpEmis>1</tpEmis>
                <cDV>9</cDV>
                <tpAmb>2</tpAmb>
                <finNFe>1</finNFe>
                <indFinal>1</indFinal>
                <indPres>1</indPres>
                <procEmi>0</procEmi>
                <verProc>1.0.0</verProc>
                </ide>
                <emit>
                <CNPJ>12345678000190</CNPJ>
                <xNome>EMPRESA TESTE LTDA</xNome>
                <xFant>TESTE</xFant>
                <enderEmit>
                    <xLgr>RUA TESTE</xLgr>
                    <nro>100</nro>
                    <xBairro>CENTRO</xBairro>
                    <cMun>4314902</cMun>
                    <xMun>PORTO ALEGRE</xMun>
                    <UF>RS</UF>
                    <CEP>90000000</CEP>
                    <cPais>1058</cPais>
                    <xPais>BRASIL</xPais>
                    <fone>5133334444</fone>
                </enderEmit>
                <IE>1234567890</IE>
                <CRT>1</CRT>
                </emit>
                <dest>
                <CPF>12345678901</CPF>
                <xNome>CONSUMIDOR FINAL</xNome>
                <indIEDest>9</indIEDest>
                </dest>
                <det nItem="1">
                <prod>
                    <cProd>PROD001</cProd>
                    <cEAN>SEM GTIN</cEAN>
                    <xProd>PRODUTO TESTE</xProd>
                    <NCM>12345678</NCM>
                    <CFOP>5102</CFOP>
                    <uCom>UN</uCom>
                    <qCom>2.0000</qCom>
                    <vUnCom>10.0000000000</vUnCom>
                    <vProd>20.00</vProd>
                    <cEANTrib>SEM GTIN</cEANTrib>
                    <uTrib>UN</uTrib>
                    <qTrib>2.0000</qTrib>
                    <vUnTrib>10.0000000000</vUnTrib>
                    <indTot>1</indTot>
                </prod>
                <imposto>
                    <ICMS>
                    <ICMSSN102>
                        <orig>0</orig>
                        <CSOSN>102</CSOSN>
                    </ICMSSN102>
                    </ICMS>
                    <PIS>
                    <PISOutr>
                        <CST>49</CST>
                    </PISOutr>
                    </PIS>
                    <COFINS>
                    <COFINSOutr>
                        <CST>49</CST>
                    </COFINSOutr>
                    </COFINS>
                </imposto>
                </det>
                <total>
                <ICMSTot>
                    <vBC>0.00</vBC>
                    <vICMS>0.00</vICMS>
                    <vICMSDeson>0.00</vICMSDeson>
                    <vFCP>0.00</vFCP>
                    <vBCST>0.00</vBCST>
                    <vST>0.00</vST>
                    <vFCPST>0.00</vFCPST>
                    <vFCPSTRet>0.00</vFCPSTRet>
                    <vProd>20.00</vProd>
                    <vFrete>0.00</vFrete>
                    <vSeg>0.00</vSeg>
                    <vDesc>0.00</vDesc>
                    <vII>0.00</vII>
                    <vIPI>0.00</vIPI>
                    <vIPIDevol>0.00</vIPIDevol>
                    <vPIS>0.00</vPIS>
                    <vCOFINS>0.00</vCOFINS>
                    <vOutro>0.00</vOutro>
                    <vNF>20.00</vNF>
                </ICMSTot>
                </total>
                <pag>
                <detPag>
                    <tPag>01</tPag>
                    <vPag>20.00</vPag>
                </detPag>
                </pag>
            </infNFe>
            </NFe>
            XML;
    }

    public function test_parse_identificacao()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $ide = $parser->parseIdentificacao();

        $this->assertEquals(43, $ide->cUF);
        $this->assertEquals(65, $ide->mod);
        $this->assertEquals(1, $ide->serie);
        $this->assertEquals(123, $ide->nNF);
        $this->assertEquals('VENDA AO CONSUMIDOR', $ide->natOp);
        $this->assertEquals(1, $ide->indFinal);
    }

    public function test_parse_emitente()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $emit = $parser->parseEmitente();

        $this->assertEquals('12345678000190', $emit->cnpj);
        $this->assertEquals('EMPRESA TESTE LTDA', $emit->razaoSocial);
        $this->assertEquals('TESTE', $emit->nomeFantasia);
        $this->assertEquals('RUA TESTE', $emit->logradouro);
        $this->assertEquals('PORTO ALEGRE', $emit->nomeMunicipio);
        $this->assertEquals('RS', $emit->uf);
        $this->assertEquals(1, $emit->crt);
    }

    public function test_parse_destinatario()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $dest = $parser->parseDestinatario();

        $this->assertNotNull($dest);
        $this->assertEquals('12345678901', $dest->cpfCnpj);
        $this->assertEquals('CONSUMIDOR FINAL', $dest->nome);
        $this->assertEquals(9, $dest->indIEDest);
    }

    public function test_parse_produtos()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $produtos = $parser->parseProdutos();

        $this->assertCount(1, $produtos);

        $prod = $produtos[0];
        $this->assertEquals(1, $prod->item);
        $this->assertEquals('PROD001', $prod->codigo);
        $this->assertEquals('PRODUTO TESTE', $prod->descricao);
        $this->assertEquals('12345678', $prod->ncm);
        $this->assertEquals('5102', $prod->cfop);
        $this->assertEquals(2.0, $prod->quantidadeComercial);
        $this->assertEquals(10.0, $prod->valorUnitario);
        $this->assertEquals(20.0, $prod->valorTotal);
    }

    public function test_parse_impostos()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $impostos = $parser->parseImpostos(1);

        $this->assertArrayHasKey('icms', $impostos);
        $this->assertEquals('102', $impostos['icms']['cst']);
        $this->assertEquals(0, $impostos['icms']['orig']);

        $this->assertArrayHasKey('pis', $impostos);
        $this->assertEquals('49', $impostos['pis']['cst']);

        $this->assertArrayHasKey('cofins', $impostos);
        $this->assertEquals('49', $impostos['cofins']['cst']);
    }

    public function test_parse_pagamentos()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $pagamentos = $parser->parsePagamentos();

        $this->assertCount(1, $pagamentos);

        $pag = $pagamentos[0];
        $this->assertEquals('01', $pag->tPag);
        $this->assertEquals(20.0, $pag->vPag);
    }

    public function test_to_array_completo()
    {
        $parser = new XmlParser($this->xmlNFCeExemplo);
        $data = $parser->toArray();

        $this->assertArrayHasKey('identificacao', $data);
        $this->assertArrayHasKey('emitente', $data);
        $this->assertArrayHasKey('destinatario', $data);
        $this->assertArrayHasKey('itens', $data);
        $this->assertArrayHasKey('pagamentos', $data);

        $this->assertCount(1, $data['itens']);
        $this->assertCount(1, $data['pagamentos']);
    }

    public function test_builder_from_xml()
    {
        $nota = NotaFiscalBuilder::fromXml($this->xmlNFCeExemplo)->build();

        $this->assertInstanceOf(NotaFiscal::class, $nota);
        $this->assertTrue($nota->hasNode('identificacao'));
        $this->assertTrue($nota->hasNode('emitente'));
        $this->assertTrue($nota->hasNode('destinatario'));
        $this->assertTrue($nota->hasNode('produto'));
        $this->assertTrue($nota->hasNode('imposto'));
        $this->assertTrue($nota->hasNode('pagamento'));
    }

    public function test_builder_from_xml_validate()
    {
        $nota = NotaFiscalBuilder::fromXml($this->xmlNFCeExemplo)->build();

        $this->assertTrue($nota->validate());
    }

    public function test_builder_from_xml_get_make()
    {
        $nota = NotaFiscalBuilder::fromXml($this->xmlNFCeExemplo)->build();

        $make = $nota->getMake();
        $this->assertInstanceOf(Make::class, $make);
    }

    public function test_xml_invalido_lanca_excecao()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('XML inválido');

        $xmlInvalido = '<?xml version="1.0"?><root></root>';
        new XmlParser($xmlInvalido);
    }

    public function test_builder_from_xml_arquivo_nao_existente()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Arquivo XML não encontrado');

        NotaFiscalBuilder::fromXml('/caminho/inexistente/nota.xml', true);
    }
}
