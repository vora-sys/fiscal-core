<?php

namespace Tests\Unit\Adapter;

use NFePHP\NFe\Tools;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFCe\NFCeAdapter;
use sabbajohn\FiscalCore\Adapters\NF\NFeAdapter;

class NFeToolsAdapterV526Test extends TestCase
{
    public function test_nfe_adapter_delegates_generic_event_to_tools(): void
    {
        $tools = $this->getMockBuilder(Tools::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sefazEvento'])
            ->getMock();

        $tools->expects($this->once())
            ->method('sefazEvento')
            ->with(
                'SP',
                '35123456789012345678901234567890123456789012',
                110110,
                2,
                '<xCampo>valor</xCampo>',
                null,
                '99'
            )
            ->willReturn('<retEvento />');

        $adapter = new NFeAdapter($tools);
        $response = $adapter->registrarEventoSefaz(
            'SP',
            '35123456789012345678901234567890123456789012',
            110110,
            2,
            '<xCampo>valor</xCampo>',
            null,
            '99'
        );

        $this->assertSame('<retEvento />', $response);
    }

    public function test_nfe_adapter_delegates_rtc_bridge_to_tools(): void
    {
        $tools = $this->getMockBuilder(Tools::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sefazInfoPagtoIntegral'])
            ->getMock();

        $tools->expects($this->once())
            ->method('sefazInfoPagtoIntegral')
            ->with(
                $this->callback(static fn (\stdClass $std): bool => $std->chNFe === '35123456789012345678901234567890123456789012'),
                'FiscalCore'
            )
            ->willReturn('<retEvento />');

        $adapter = new NFeAdapter($tools);
        $response = $adapter->registrarEventoAvancado(
            'sefazInfoPagtoIntegral',
            ['chNFe' => '35123456789012345678901234567890123456789012'],
            ['verAplic' => 'FiscalCore']
        );

        $this->assertSame('<retEvento />', $response);
    }

    public function test_nfce_adapter_delegates_cancel_by_substitution_to_tools(): void
    {
        $tools = $this->getMockBuilder(Tools::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['model', 'sefazCancelaPorSubstituicao'])
            ->getMock();

        $tools->expects($this->once())
            ->method('model')
            ->with(65)
            ->willReturn(65);
        $tools->expects($this->once())
            ->method('sefazCancelaPorSubstituicao')
            ->with(
                '35123456789012345678901234567890123456789012',
                'Cancelamento por erro operacional',
                '123',
                '35123456789012345678901234567890123456789013',
                'FiscalCore',
                null,
                null
            )
            ->willReturn('<retEvento />');

        $adapter = new NFCeAdapter($tools);
        $response = $adapter->cancelarPorSubstituicao(
            '35123456789012345678901234567890123456789012',
            'Cancelamento por erro operacional',
            '123',
            '35123456789012345678901234567890123456789013',
            'FiscalCore'
        );

        $this->assertSame('<retEvento />', $response);
    }

    public function test_nfce_adapter_delegates_epec_status_to_tools(): void
    {
        $tools = $this->getMockBuilder(Tools::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['model', 'sefazStatusEpecNfce'])
            ->getMock();

        $tools->expects($this->once())
            ->method('model')
            ->with(65)
            ->willReturn(65);
        $tools->expects($this->once())
            ->method('sefazStatusEpecNfce')
            ->with('SP', 2, true)
            ->willReturn('<retConsStatServ />');

        $adapter = new NFCeAdapter($tools);
        $response = $adapter->verificarStatusEpec('SP', 2);

        $this->assertSame('<retConsStatServ />', $response);
    }
}
