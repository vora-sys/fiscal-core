<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Facade\FiscalFacade;
use sabbajohn\FiscalCore\Facade\ImpressaoFacade;
use sabbajohn\FiscalCore\Facade\NFCeFacade;
use sabbajohn\FiscalCore\Facade\NFeFacade;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Facade\TributacaoFacade;
use sabbajohn\FiscalCore\Support\FiscalResponse;

class FiscalFacadeRoutingByChaveTest extends TestCase
{
    public function test_consultar_documento_por_chave_routes_nfe_and_nfce_by_model(): void
    {
        $nfe = $this->createMock(NFeFacade::class);
        $nfce = $this->createMock(NFCeFacade::class);

        $nfe->expects($this->once())
            ->method('consultar')
            ->with('42260483188342000104550020000000011276006050')
            ->willReturn(FiscalResponse::success(['modelo' => '55']));

        $nfce->expects($this->once())
            ->method('consultar')
            ->with('42260483188342000104650020000000011276006050')
            ->willReturn(FiscalResponse::success(['modelo' => '65']));

        $facade = new FiscalFacade(
            $nfe,
            $nfce,
            $this->createMock(NFSeFacade::class),
            $this->createMock(ImpressaoFacade::class),
            $this->createMock(TributacaoFacade::class)
        );

        $nfeResponse = $facade->consultarDocumentoPorChave('42260483188342000104550020000000011276006050');
        $nfceResponse = $facade->consultarDocumentoPorChave('42260483188342000104650020000000011276006050');

        $this->assertTrue($nfeResponse->isSuccess());
        $this->assertSame('55', $nfeResponse->getData('modelo'));
        $this->assertTrue($nfceResponse->isSuccess());
        $this->assertSame('65', $nfceResponse->getData('modelo'));
    }

    public function test_baixar_xml_documento_por_chave_routes_nfce_by_model_65(): void
    {
        $nfce = $this->createMock(NFCeFacade::class);
        $nfce->expects($this->once())
            ->method('baixarXml')
            ->with('42260483188342000104650020000000011276006050')
            ->willReturn(FiscalResponse::success([
                'documento' => ['modelo' => 'nfce'],
            ]));

        $facade = new FiscalFacade(
            $this->createMock(NFeFacade::class),
            $nfce,
            $this->createMock(NFSeFacade::class),
            $this->createMock(ImpressaoFacade::class),
            $this->createMock(TributacaoFacade::class)
        );

        $response = $facade->baixarXmlDocumentoPorChave('42260483188342000104650020000000011276006050');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('nfce', $response->getData('documento')['modelo']);
    }

    public function test_consultar_csc_nfce_delegates_to_nfce_facade(): void
    {
        $nfce = $this->createMock(NFCeFacade::class);
        $nfce->expects($this->once())
            ->method('consultarCsc')
            ->with(1)
            ->willReturn(FiscalResponse::success([
                'cstat' => '102',
                'xmotivo' => 'CSC consultado',
            ]));

        $facade = new FiscalFacade(
            $this->createMock(NFeFacade::class),
            $nfce,
            $this->createMock(NFSeFacade::class),
            $this->createMock(ImpressaoFacade::class),
            $this->createMock(TributacaoFacade::class)
        );

        $response = $facade->consultarCscNFCe();

        $this->assertTrue($response->isSuccess());
        $this->assertSame('102', $response->getData('cstat'));
    }

    public function test_detect_document_model_from_chave_returns_embedded_model(): void
    {
        $facade = new FiscalFacade(
            $this->createMock(NFeFacade::class),
            $this->createMock(NFCeFacade::class),
            $this->createMock(NFSeFacade::class),
            $this->createMock(ImpressaoFacade::class),
            $this->createMock(TributacaoFacade::class)
        );

        $this->assertSame('55', $facade->detectDocumentModelFromChave('42260483188342000104550020000000011276006050'));
        $this->assertSame('65', $facade->detectDocumentModelFromChave('42260483188342000104650020000000011276006050'));
    }
}
