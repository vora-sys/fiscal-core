<?php

namespace Tests\Unit;

use freeline\FiscalCore\Adapters\ImpressaoAdapter;
use freeline\FiscalCore\Adapters\NF\NFeAdapter;
use freeline\FiscalCore\Facade\NFeFacade;
use freeline\FiscalCore\Support\ManifestationType;
use PHPUnit\Framework\TestCase;

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
}
