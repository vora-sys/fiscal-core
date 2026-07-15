<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Adapters\NF\NFeAdapter;
use sabbajohn\FiscalCore\Facade\NFeFacade;
use sabbajohn\FiscalCore\Support\ManifestationType;

class NFeFacadeInboundOperationsTest extends TestCase
{
    public function test_consultar_distribuicao_dfe_parses_documents(): void
    {
        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('consultaNotasEmitidasParaEstabelecimento')
            ->with(0, 0, null, 'AN')
            ->willReturn($this->distDfeXml());

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->consultarDistribuicaoDFe();

        $this->assertTrue($response->isSuccess());
        $this->assertSame('138', $response->getData('cstat'));
        $this->assertSame('138', $response->getData('operacao')['cstat']);
        $this->assertTrue($response->getData('operacao')['ok']);
        $this->assertSame($this->distDfeXml(), $response->getData('raw')['response_xml']);
        $this->assertCount(1, $response->getData('documents'));
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documents')[0]['chave']);
    }

    public function test_manifestar_destinatario_returns_protocol_and_type(): void
    {
        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('manifestarDestinatario')
            ->with(
                '35123456789012345678901234567890123456789012',
                ManifestationType::CONFIRMACAO,
                '',
                1
            )
            ->willReturn('<retEvento><infEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo><chNFe>35123456789012345678901234567890123456789012</chNFe><nProt>123</nProt></infEvento></retEvento>');

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->manifestarDestinatario(
            '35123456789012345678901234567890123456789012',
            ManifestationType::CONFIRMACAO
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame('confirmacao', $response->getData('manifestation_type'));
        $this->assertSame('123', $response->getData('protocolo'));
        $this->assertSame('135', $response->getData('cstat'));
        $this->assertSame('135', $response->getData('operacao')['cstat']);
        $this->assertSame('123', $response->getData('documento')['protocolo']);
        $this->assertSame('35123456789012345678901234567890123456789012', $response->getData('documento')['chave_acesso']);
    }

    public function test_carta_correcao_uses_inner_event_status(): void
    {
        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('cartaCorrecao')
            ->with(
                '35123456789012345678901234567890123456789012',
                'Correção de dados adicionais da operação',
                2,
                null,
                null
            )
            ->willReturn($this->eventResponseXml('128', '135'));

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->cartaCorrecao(
            '35123456789012345678901234567890123456789012',
            'Correção de dados adicionais da operação',
            2
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame('135', $response->getData('cstat'));
        $this->assertTrue($response->getData('operacao')['ok']);
        $this->assertSame('128', $response->getData('raw')['parsed_response']['lote']['cstat']);
        $this->assertCount(1, $response->getData('eventos'));
    }

    public function test_manifestar_destinatario_lote_returns_events(): void
    {
        $eventos = [
            [
                'chave' => '35123456789012345678901234567890123456789012',
                'tipo' => 'confirmacao',
                'sequencia' => 1,
            ],
        ];

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('manifestarDestinatarioLote')
            ->with($eventos, null, null)
            ->willReturn($this->eventResponseXml('128', '135'));

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->manifestarDestinatarioLote($eventos);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('135', $response->getData('operacao')['cstat']);
        $this->assertSame($eventos, $response->getData('eventos_enviados'));
    }

    public function test_registrar_evento_avancado_uses_allowlisted_bridge(): void
    {
        $payload = ['chNFe' => '35123456789012345678901234567890123456789012'];

        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->once())
            ->method('registrarEventoAvancado')
            ->with('sefazInfoPagtoIntegral', $payload, ['verAplic' => 'FiscalCore'])
            ->willReturn($this->eventResponseXml('128', '135'));

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->registrarEventoAvancado('sefazInfoPagtoIntegral', $payload, ['verAplic' => 'FiscalCore']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('sefazInfoPagtoIntegral', $response->getData('metodo'));
        $this->assertSame('135', $response->getData('cstat'));
    }

    public function test_registrar_evento_avancado_rejects_non_allowlisted_method(): void
    {
        $adapter = $this->createMock(NFeAdapter::class);
        $adapter->expects($this->never())->method('registrarEventoAvancado');

        $facade = new NFeFacade($adapter, $this->createMock(ImpressaoAdapter::class));
        $response = $facade->registrarEventoAvancado('sefazCsc', []);

        $this->assertTrue($response->isError());
        $this->assertSame('UNSUPPORTED_SEFAZ_METHOD', $response->getErrorCode());
    }

    private function distDfeXml(): string
    {
        $innerXml = '<resNFe><chNFe>35123456789012345678901234567890123456789012</chNFe></resNFe>';
        $docZip = base64_encode(gzencode($innerXml));

        return <<<XML
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
            <nProt>123</nProt>
        </infEvento>
    </retEvento>
</retEnvEvento>
XML;
    }
}
