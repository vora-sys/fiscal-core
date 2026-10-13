<?php

namespace Tests\Unit\Fiscal;

use sabbajohn\FiscalCore\Adapters\ImpressaoAdapter;
use sabbajohn\FiscalCore\Support\NFeCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImpressaoAdapterTest extends TestCase
{
    #[DataProvider('supportedDanfeContexts')]
    public function test_regular_danfe_profiles_render_as_pdf(array $context, bool $contingency): void
    {
        $xml = $this->fixtureXml();
        if ($contingency) {
            $xml = str_replace('<tpEmis>1</tpEmis>', '<tpEmis>2</tpEmis>', $xml);
            $xml = preg_replace('#<protNFe\b.*?</protNFe>#s', '', $xml) ?? $xml;
        }

        $pdf = (new ImpressaoAdapter)->gerarDanfe($xml, $context);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(10_000, strlen($pdf));
    }

    public function test_type_2_is_rejected_explicitly_when_installed_sped_da_has_no_renderer(): void
    {
        $xml = str_replace('<tpImp>1</tpImp>', '<tpImp>6</tpImp>', $this->fixtureXml());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DANFE Simplificado Tipo 2 (tpImp=6) não é suportado');

        (new ImpressaoAdapter)->gerarDanfe($xml);
    }

    public function test_runtime_capabilities_report_type_2_renderer_availability(): void
    {
        $capabilities = NFeCompatibility::runtimeCapabilities();

        $this->assertFalse($capabilities['supports_danfe_simplificado_tipo_2']);
        $this->assertNull($capabilities['danfe_simplificado_tipo_2_renderer']);
    }

    public function test_print_type_restores_previous_libxml_error_mode(): void
    {
        $previousErrorMode = libxml_use_internal_errors();
        libxml_use_internal_errors(false);

        try {
            $method = new \ReflectionMethod(ImpressaoAdapter::class, 'printType');
            $this->assertNull($method->invoke(new ImpressaoAdapter, '<invalid'));
            $this->assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    public function test_active_nfce_renderer_keeps_alpha_documents_and_qr_on_one_thermal_page(): void
    {
        $xml = str_replace('<mod>55</mod>', '<mod>65</mod>', $this->fixtureXml());
        $supplement = '<infNFeSupl>'
            .'<qrCode>https://consulta.exemplo.invalid/nfce?p=352609ABC12D34000107650010000003571123456780|3|2</qrCode>'
            .'<urlChave>https://consulta.exemplo.invalid/nfce</urlChave>'
            .'</infNFeSupl>';
        $xml = str_replace('</NFe>', $supplement.'</NFe>', $xml);

        $pdf = (new ImpressaoAdapter)->gerarDanfce($xml);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('/\/Type \/Pages\s.*?\/Count 1/s', $pdf);
    }

    public static function supportedDanfeContexts(): array
    {
        return [
            'authorized with installments and complement' => [[], false],
            'cancelled' => [['cancelada' => true], false],
            'FS-IA contingency without protocol' => [[], true],
        ];
    }

    private function fixtureXml(): string
    {
        $xml = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Fiscal/nfe/t0359-authorized-alpha.xml');

        $this->assertIsString($xml);

        return $xml;
    }
}
