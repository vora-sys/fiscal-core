<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Adapters\NF\NFeAdapter;
use sabbajohn\FiscalCore\Facade\NFeFacade;

class NFeFacadeResponseShapeTest extends TestCase
{
    public function test_emitir_returns_authorized_nfeproc_as_document_xml(): void
    {
        $signedXml = <<<XML
<NFe xmlns="http://www.portalfiscal.inf.br/nfe">
    <infNFe versao="4.00" Id="NFe35123456789012345678901234567890123456789012">
        <ide><cUF>35</cUF></ide>
        <emit><CNPJ>12345678000195</CNPJ></emit>
        <dest><CNPJ>12345678000195</CNPJ></dest>
        <det nItem="1"><prod><cProd>1</cProd><xProd>Teste</xProd></prod></det>
        <total><ICMSTot><vNF>1.00</vNF></ICMSTot></total>
    </infNFe>
    <Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference><DigestValue>ABC</DigestValue></Reference></SignedInfo><SignatureValue>ABC</SignatureValue><KeyInfo/></Signature>
</NFe>
XML;
        $responseXml = <<<XML
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
    <cStat>104</cStat>
    <xMotivo>Lote processado</xMotivo>
    <protNFe versao="4.00">
        <infProt Id="ID135123">
            <tpAmb>2</tpAmb>
            <verAplic>SVRS202</verAplic>
            <chNFe>35123456789012345678901234567890123456789012</chNFe>
            <dhRecbto>2026-04-20T10:00:00-03:00</dhRecbto>
            <nProt>135260000000001</nProt>
            <digVal>ABC</digVal>
            <cStat>100</cStat>
            <xMotivo>Autorizado o uso da NF-e</xMotivo>
        </infProt>
    </protNFe>
</retEnviNFe>
XML;

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('emitir')
            ->willReturn($responseXml);
        $adapter->expects($this->once())
            ->method('getLastSignedXml')
            ->willReturn($signedXml);
        $adapter->expects($this->once())
            ->method('getLastResponseXml')
            ->willReturn($responseXml);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->emitir([
            'identificacao' => ['tpAmb' => 2],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertStringContainsString('<nfeProc', (string) $response->getData('documento')['xml']);
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documento')['chave_acesso']);
        $this->assertSame('Autorizado o uso da NF-e', $response->getData('documento')['situacao']);
        $this->assertSame('135260000000001', $response->getData('documento')['protocolo']);
        $this->assertSame($response->getData('documento')['xml'], $response->getData('xml'));
        $this->assertSame($responseXml, $response->getData('raw')['response_xml']);
        $this->assertSame($responseXml, $response->getData('xml_retorno'));
        $this->assertSame($signedXml, $response->getData('raw')['parsed_response']['xml_assinado']);
    }

    public function test_emitir_builds_authorized_document_when_sefaz_response_is_wrapped_in_soap(): void
    {
        $signedXml = <<<XML
<NFe xmlns="http://www.portalfiscal.inf.br/nfe">
    <infNFe versao="4.00" Id="NFe42260483188342000104550010000000121304400319">
        <ide><cUF>42</cUF></ide>
        <emit><CNPJ>83188342000104</CNPJ></emit>
        <dest><CNPJ>83188342000104</CNPJ></dest>
        <det nItem="1"><prod><cProd>1</cProd><xProd>Teste</xProd></prod></det>
        <total><ICMSTot><vNF>1.00</vNF></ICMSTot></total>
    </infNFe>
    <Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference><DigestValue>hbczq+8hH5hC2yoP+UJwML0k9M0=</DigestValue></Reference></SignedInfo><SignatureValue>ABC</SignatureValue><KeyInfo/></Signature>
</NFe>
XML;
        $soapResponse = <<<XML
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <soap:Body>
        <nfeResultMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeAutorizacao4">
            <retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
                <tpAmb>2</tpAmb>
                <verAplic>SVRS2604020829</verAplic>
                <cStat>104</cStat>
                <xMotivo>Lote processado</xMotivo>
                <cUF>42</cUF>
                <dhRecbto>2026-04-20T23:45:10-03:00</dhRecbto>
                <protNFe versao="4.00">
                    <infProt>
                        <tpAmb>2</tpAmb>
                        <verAplic>SVRS2604020829</verAplic>
                        <chNFe>42260483188342000104550010000000121304400319</chNFe>
                        <dhRecbto>2026-04-20T23:45:10-03:00</dhRecbto>
                        <nProt>342260000395488</nProt>
                        <digVal>hbczq+8hH5hC2yoP+UJwML0k9M0=</digVal>
                        <cStat>100</cStat>
                        <xMotivo>Autorizado o uso da NF-e</xMotivo>
                    </infProt>
                </protNFe>
            </retEnviNFe>
        </nfeResultMsg>
    </soap:Body>
</soap:Envelope>
XML;

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('emitir')
            ->willReturn($soapResponse);
        $adapter->expects($this->once())
            ->method('getLastSignedXml')
            ->willReturn($signedXml);
        $adapter->expects($this->once())
            ->method('getLastResponseXml')
            ->willReturn($soapResponse);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->emitir([
            'identificacao' => ['tpAmb' => 2],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertStringContainsString('<nfeProc', (string) $response->getData('documento')['xml']);
        $this->assertStringContainsString('<nProt>342260000395488</nProt>', (string) $response->getData('documento')['xml']);
        $this->assertSame('42260483188342000104550010000000121304400319', $response->getData('documento')['chave_acesso']);
        $this->assertSame($soapResponse, $response->getData('raw')['response_xml']);
    }

    public function test_emitir_rejected_keeps_document_xml_null_and_preserves_raw_response(): void
    {
        $signedXml = '<NFe><infNFe Id="NFe35123456789012345678901234567890123456789012" versao="4.00" /></NFe>';
        $responseXml = <<<XML
<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
    <cStat>104</cStat>
    <xMotivo>Lote processado</xMotivo>
    <protNFe versao="4.00">
        <infProt Id="ID135123">
            <chNFe>35123456789012345678901234567890123456789012</chNFe>
            <cStat>539</cStat>
            <xMotivo>Duplicidade de NF-e, com diferença na Chave de Acesso</xMotivo>
        </infProt>
    </protNFe>
</retEnviNFe>
XML;

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('emitir')
            ->willReturn($responseXml);
        $adapter->expects($this->once())
            ->method('getLastSignedXml')
            ->willReturn($signedXml);
        $adapter->expects($this->once())
            ->method('getLastResponseXml')
            ->willReturn($responseXml);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->emitir([
            'identificacao' => ['tpAmb' => 2],
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getData('documento')['xml']);
        $this->assertSame('Duplicidade de NF-e, com diferença na Chave de Acesso', $response->getData('documento')['situacao']);
        $this->assertSame($responseXml, $response->getData('raw')['response_xml']);
    }

    public function test_consultar_returns_canonical_document_shape(): void
    {
        $xml = '<retConsSitNFe><xMotivo>Autorizado o uso da NF-e</xMotivo></retConsSitNFe>';

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('consultar')
            ->with('35123456789012345678901234567890123456789012')
            ->willReturn($xml);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->consultar('35123456789012345678901234567890123456789012');

        $this->assertTrue($response->isSuccess());
        $this->assertSame($xml, $response->getData('documento')['xml']);
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documento')['chave_acesso']);
        $this->assertSame('Autorizado o uso da NF-e', $response->getData('documento')['situacao']);
        $this->assertSame('indisponivel', $response->getData('impressao')['modo']);
        $this->assertSame($xml, $response->getData('raw')['response_xml']);
    }

    public function test_gerar_danfe_returns_xml_and_pdf_in_canonical_shape(): void
    {
        $xml = '<NFe><infNFe Id="NFe123" /></NFe>';
        $pdf = '%PDF-1.4 test';

        $impressao = $this->createMock(ImpressaoAdapter::class);
        $impressao->expects($this->once())
            ->method('gerarDanfe')
            ->with($xml)
            ->willReturn($pdf);

        $facade = new NFeFacade($this->createMock(NFeAdapter::class), $impressao);
        $response = $facade->gerarDanfe($xml);

        $this->assertTrue($response->isSuccess());
        $this->assertSame($xml, $response->getData('documento')['xml']);
        $this->assertSame('pdf_base64', $response->getData('impressao')['modo']);
        $this->assertSame(base64_encode($pdf), $response->getData('impressao')['pdf_base64']);
        $this->assertSame('application/pdf', $response->getData('impressao')['content_type']);
        $this->assertTrue(str_starts_with($response->getData('impressao')['filename'], 'danfe_'));
    }

    public function test_baixar_xml_returns_canonical_document_shape(): void
    {
        $documentXml = '<resNFe><chNFe>35123456789012345678901234567890123456789012</chNFe></resNFe>';
        $docZip = base64_encode(gzencode($documentXml));
        $distXml = <<<XML
<retDistDFeInt>
    <cStat>138</cStat>
    <xMotivo>Documento localizado</xMotivo>
    <ultNSU>12</ultNSU>
    <maxNSU>12</maxNSU>
    <loteDistDFeInt>
        <docZip NSU="12" schema="resNFe_v1.01">{$docZip}</docZip>
    </loteDistDFeInt>
</retDistDFeInt>
XML;

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('downloadNFe')
            ->with('35123456789012345678901234567890123456789012')
            ->willReturn($distXml);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->baixarXml('35123456789012345678901234567890123456789012');

        $this->assertTrue($response->isSuccess());
        $this->assertSame($documentXml, $response->getData('documento')['xml']);
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documento')['chave_acesso']);
        $this->assertSame('indisponivel', $response->getData('impressao')['modo']);
        $this->assertSame($distXml, $response->getData('raw')['response_xml']);
        $this->assertCount(1, $response->getData('documents'));
    }

    public function test_cancelar_returns_canonical_operation_shape_and_legacy_aliases(): void
    {
        $xml = '<retEvento><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo><chNFe>35123456789012345678901234567890123456789012</chNFe><nProt>999</nProt></infEvento></retEvento>';

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('cancelar')
            ->with('35123456789012345678901234567890123456789012', 'Cancelamento por erro operacional', '123')
            ->willReturn($xml);

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->cancelar(
            '35123456789012345678901234567890123456789012',
            'Cancelamento por erro operacional',
            '123'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('cancelado'));
        $this->assertSame('135', $response->getData('operacao')['cstat']);
        $this->assertSame('Evento registrado', $response->getData('documento')['situacao']);
        $this->assertSame('999', $response->getData('documento')['protocolo']);
        $this->assertSame($xml, $response->getData('raw')['response_xml']);
        $this->assertSame('123', $response->getData('protocolo'));
    }
}
