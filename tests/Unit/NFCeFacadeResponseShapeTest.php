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

    public function test_cancelar_por_substituicao_returns_canonical_event_shape(): void
    {
        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('cancelarPorSubstituicao')
            ->with(
                '35123456789012345678901234567890123456789012',
                'Cancelamento por erro operacional',
                '123',
                '35123456789012345678901234567890123456789013',
                'FiscalCore',
                null,
                null
            )
            ->willReturn($this->eventResponseXml('128', '135'));

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->cancelarPorSubstituicao(
            '35123456789012345678901234567890123456789012',
            'Cancelamento por erro operacional',
            '123',
            '35123456789012345678901234567890123456789013',
            'FiscalCore'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('cancelado'));
        $this->assertSame('135', $response->getData('cstat'));
        $this->assertSame('35123456789012345678901234567890123456789013', $response->getData('chave_substituta'));
    }

    public function test_registrar_evento_sefaz_returns_inner_event_status(): void
    {
        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('registrarEventoSefaz')
            ->with(
                'SP',
                '35123456789012345678901234567890123456789012',
                110110,
                1,
                '<xCampo>valor</xCampo>',
                null,
                null
            )
            ->willReturn($this->eventResponseXml('128', '135'));

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->registrarEventoSefaz(
            'SP',
            '35123456789012345678901234567890123456789012',
            110110,
            1,
            '<xCampo>valor</xCampo>'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame('135', $response->getData('operacao')['cstat']);
        $this->assertSame('128', $response->getData('raw')['parsed_response']['lote']['cstat']);
    }

    public function test_registrar_evento_avancado_rejects_nfe_only_rtc_event(): void
    {
        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->never())->method('registrarEventoAvancado');

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->registrarEventoAvancado('sefazInfoPagtoIntegral', []);

        $this->assertTrue($response->isError());
        $this->assertSame('UNSUPPORTED_SEFAZ_METHOD', $response->getErrorCode());
    }

    public function test_registrar_epec_returns_event_and_adjusted_xml(): void
    {
        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('registrarEpec')
            ->with('<NFe />', 'FiscalCore')
            ->willReturn([
                'response_xml' => $this->eventResponseXml('128', '135'),
                'xml' => '<NFe contingencia="1" />',
            ]);

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->registrarEpec('<NFe />', 'FiscalCore');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('<NFe contingencia="1" />', $response->getData('xml_contingencia'));
        $this->assertSame('135', $response->getData('cstat'));
    }

    public function test_verificar_status_epec_returns_status_shape(): void
    {
        $xml = '<retConsStatServ><cStat>107</cStat><xMotivo>Servico em operacao</xMotivo></retConsStatServ>';

        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('verificarStatusEpec')
            ->with('SP', 2, true)
            ->willReturn($xml);

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->verificarStatusEpec('SP', 2);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('operacao')['ok']);
        $this->assertSame('107', $response->getData('cstat'));
        $this->assertSame($xml, $response->getData('xml_response'));
    }

    public function test_consultar_csc_returns_common_sefaz_shape(): void
    {
        $xml = '<retCscNFCe><cStat>102</cStat><xMotivo>CSC consultado</xMotivo></retCscNFCe>';

        $adapter = $this->createMock(NFCeAdapter::class);
        $adapter->expects($this->once())
            ->method('consultarCsc')
            ->with(1)
            ->willReturn($xml);

        $facade = new NFCeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->consultarCsc(1);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('102', $response->getData('cstat'));
        $this->assertSame(1, $response->getData('ind_operacao'));
    }

    private function eventResponseXml(string $batchCstat, string $eventCstat): string
    {
        return <<<XML
<retEnvEvento>
    <cStat>{$batchCstat}</cStat>
    <xMotivo>Lote de Evento Processado</xMotivo>
    <retEvento>
        <infEvento>
            <cStat>{$eventCstat}</cStat>
            <xMotivo>Evento registrado</xMotivo>
            <chNFe>35123456789012345678901234567890123456789012</chNFe>
            <tpEvento>110110</tpEvento>
            <nSeqEvento>1</nSeqEvento>
            <nProt>321</nProt>
        </infEvento>
    </retEvento>
</retEnvEvento>
XML;
    }
}
