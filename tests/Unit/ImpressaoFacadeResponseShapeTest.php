<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Facade\ImpressaoFacade;

class ImpressaoFacadeResponseShapeTest extends TestCase
{
    public function test_gerar_danfe_preserves_pdf_aliases_and_adds_canonical_print_shape(): void
    {
        $xml = '<NFe><infNFe Id="NFe123" /></NFe>';
        $pdf = '%PDF-1.4 test';

        $adapter = $this->createMock(ImpressaoAdapter::class);
        $adapter->expects($this->once())
            ->method('gerarDanfe')
            ->with($xml)
            ->willReturn($pdf);

        $response = (new ImpressaoFacade($adapter))->gerarDanfe($xml);

        $this->assertTrue($response->isSuccess());
        $this->assertSame($pdf, $response->getData('pdf'));
        $this->assertSame(strlen($pdf), $response->getData('size'));
        $this->assertSame('pdf_base64', $response->getData('impressao')['modo']);
        $this->assertSame(base64_encode($pdf), $response->getData('impressao')['pdf_base64']);
        $this->assertSame($xml, $response->getData('documento')['xml']);
        $this->assertSame($xml, $response->getData('raw')['response_xml']);
    }
}
