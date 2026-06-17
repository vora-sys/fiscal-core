<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Adapters\NF\NFCe\NFCeAdapter;
use sabbajohn\FiscalCore\Facade\NFCeFacade;
use sabbajohn\FiscalCore\Renderers\NFCe\ThermalDanfceRenderer;

class NFCeFacadeResponseShapeTest extends TestCase
{
    public function test_consultar_returns_canonical_document_shape(): void
    {
        $xml = '<retConsSitNFe><xMotivo>Autorizado o uso da NFC-e</xMotivo></retConsSitNFe>';

        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('consultar')
            ->with('35123456789012345678901234567890123456789012')
            ->willReturn($xml);

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->consultar('35123456789012345678901234567890123456789012');

        $this->assertTrue($response->isSuccess());
        $this->assertSame($xml, $response->getData('documento')['xml']);
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documento')['chave_acesso']);
        $this->assertSame('Autorizado o uso da NFC-e', $response->getData('documento')['situacao']);
        $this->assertSame('indisponivel', $response->getData('impressao')['modo']);
        $this->assertSame($xml, $response->getData('raw')['response_xml']);
    }

    public function test_gerar_danfce_returns_xml_and_pdf_in_canonical_shape(): void
    {
        $xml = '<NFe><infNFe Id="NFe123" /></NFe>';
        $pdf = '%PDF-1.4 test';
        $context = ['nome_fantasia' => 'Freeline'];

        $impressao = $this->createMock(ImpressaoAdapter::class);
        $impressao->expects($this->once())
            ->method('gerarDanfce')
            ->with($xml, $context)
            ->willReturn($pdf);

        $facade = new NFCeFacade($this->createMock(NFCeAdapter::class), $impressao);
        $response = $facade->gerarDanfce($xml, $context);

        $this->assertTrue($response->isSuccess());
        $this->assertSame($xml, $response->getData('documento')['xml']);
        $this->assertSame('pdf_base64', $response->getData('impressao')['modo']);
        $this->assertSame(base64_encode($pdf), $response->getData('impressao')['pdf_base64']);
        $this->assertSame('application/pdf', $response->getData('impressao')['content_type']);
        $this->assertSame('custom_thermal_layout', $response->getData('impressao')['source']);
        $this->assertTrue(str_starts_with($response->getData('impressao')['filename'], 'danfce_'));
    }

    public function test_cancelar_returns_canonical_operation_shape(): void
    {
        $xml = '<retEvento><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo><chNFe>35123456789012345678901234567890123456789012</chNFe><nProt>321</nProt></infEvento></retEvento>';

        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('cancelar')
            ->willReturn($xml);

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->cancelar(
            '35123456789012345678901234567890123456789012',
            'Cancelamento por erro operacional',
            '123'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('operacao')['ok']);
        $this->assertSame('135', $response->getData('operacao')['cstat']);
        $this->assertSame('321', $response->getData('documento')['protocolo']);
        $this->assertSame($xml, $response->getData('raw')['response_xml']);
    }
}
