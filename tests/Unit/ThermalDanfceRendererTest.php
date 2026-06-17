<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Renderers\NFCe\ThermalDanfceRenderer;

class ThermalDanfceRendererTest extends TestCase
{
    public function test_build_html_respects_required_sections_header_and_width(): void
    {
        $renderer = new ThermalDanfceRenderer();

        $html = $renderer->buildHtml($this->sampleXml(), [
            'layout_cupom' => [
                'schema_version' => 2,
                'renderer' => 'nfce_pdf_thermal',
                'paper' => [
                    'width_mm' => 80,
                    'margin_top_mm' => 2,
                    'margin_right_mm' => 2,
                    'margin_bottom_mm' => 3,
                    'margin_left_mm' => 2,
                    'qr_size_mm' => 30,
                ],
                'typography' => [
                    'base_font_pt' => 8,
                    'mono_font_pt' => 7,
                    'total_font_pt' => 10,
                ],
                'sections' => [
                    ['type' => 'header', 'required' => true, 'enabled' => true, 'order' => 1, 'align' => 'center', 'spacing_before_mm' => 0, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                    ['type' => 'items', 'required' => true, 'enabled' => true, 'order' => 2, 'align' => 'left', 'spacing_before_mm' => 0, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                    ['type' => 'totals', 'required' => true, 'enabled' => true, 'order' => 3, 'align' => 'right', 'spacing_before_mm' => 1, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0, 'emphasis' => 'strong'],
                    ['type' => 'payments', 'required' => true, 'enabled' => true, 'order' => 4, 'align' => 'right', 'spacing_before_mm' => 1, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                    ['type' => 'consultation', 'required' => true, 'enabled' => true, 'order' => 5, 'align' => 'center', 'spacing_before_mm' => 1, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                    ['type' => 'qr_code', 'required' => true, 'enabled' => true, 'order' => 6, 'align' => 'center', 'spacing_before_mm' => 1, 'spacing_after_mm' => 1, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                    ['type' => 'protocol_footer', 'required' => true, 'enabled' => true, 'order' => 7, 'align' => 'center', 'spacing_before_mm' => 1, 'spacing_after_mm' => 0, 'padding_left_mm' => 0, 'padding_right_mm' => 0],
                ],
            ],
            'nome_fantasia' => 'FREELINE INFORMATICA LTDA',
        ]);

        $this->assertStringContainsString('width: 76.00mm', $html);
        $this->assertStringContainsString('data-section="header"', $html);
        $this->assertStringContainsString('data-section="recipient"', $html);
        $this->assertStringContainsString('data-section="qr_code"', $html);
        $this->assertStringContainsString('FREELINE INFORMATICA LTDA', $html);
        $this->assertStringContainsString('Padaria Bom Trigo', $html);
        $this->assertStringContainsString('33.440.118/0001-47', $html);
        $this->assertStringContainsString('Rua Benjamin Constant, 4135', $html);
        $this->assertStringContainsString('width: 30.00mm; height: 30.00mm', $html);
        $this->assertLessThan(
            strpos($html, 'data-section="protocol_footer"'),
            strpos($html, 'data-section="totals"')
        );
    }

    private function sampleXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe42123456789012345678901234567890123456789012" versao="4.00">
      <ide>
        <cUF>42</cUF>
        <nNF>12345</nNF>
        <serie>1</serie>
        <dhEmi>2026-05-22T10:45:00-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>83188342000104</CNPJ>
        <xNome>FREELINE INFORMATICA LTDA</xNome>
        <xFant>FREELINE INFORMATICA LTDA</xFant>
        <IE>252290720</IE>
        <enderEmit>
          <xLgr>Rua Benjamin Constant</xLgr>
          <nro>4135</nro>
          <xBairro>Glória</xBairro>
          <xMun>Joinville</xMun>
          <UF>SC</UF>
        </enderEmit>
      </emit>
      <dest>
        <CNPJ>33440118000147</CNPJ>
        <xNome>Padaria Bom Trigo</xNome>
        <enderDest>
          <xMun>Belo Horizonte</xMun>
          <UF>MG</UF>
        </enderDest>
        <indIEDest>9</indIEDest>
      </dest>
      <det nItem="1">
        <prod>
          <xProd>Produto premium automotivo</xProd>
          <qCom>1.0000</qCom>
          <uCom>UN</uCom>
          <vUnCom>10.00</vUnCom>
          <vProd>10.00</vProd>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vProd>10.00</vProd>
          <vDesc>0.00</vDesc>
          <vNF>10.00</vNF>
          <vTotTrib>1.40</vTotTrib>
        </ICMSTot>
      </total>
      <pag>
        <detPag>
          <tPag>17</tPag>
          <vPag>10.00</vPag>
        </detPag>
      </pag>
      <infAdic>
        <infCpl>Mensagem adicional de teste</infCpl>
      </infAdic>
      <infNFeSupl>
        <qrCode>https://www.nfce.fazenda.gov.br/consulta?chNFe=42123456789012345678901234567890123456789012</qrCode>
      </infNFeSupl>
    </infNFe>
  </NFe>
  <protNFe>
    <infProt>
      <chNFe>42123456789012345678901234567890123456789012</chNFe>
      <nProt>135260000000001</nProt>
      <dhRecbto>2026-05-22T10:45:10-03:00</dhRecbto>
    </infProt>
  </protNFe>
</nfeProc>
XML;
    }
}
