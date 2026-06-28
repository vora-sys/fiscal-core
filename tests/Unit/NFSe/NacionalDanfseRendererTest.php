<?php

declare(strict_types=1);

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use sabbajohn\FiscalCore\Renderers\NFSe\NacionalDanfseRenderer;

final class NacionalDanfseRendererTest extends TestCase
{
    public function testRenderGeraPdfValidoComLayoutNacional(): void
    {
        $renderer = new NacionalDanfseRenderer();
        $xml = $this->fixture('nfse_nacional_completa.xml');

        $pdf = $renderer->render($xml);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testBuildHtmlIncluiCamposObrigatoriosQrCodeEHomologacao(): void
    {
        $renderer = new NacionalDanfseRenderer();
        $xml = $this->fixture('nfse_nacional_completa.xml');

        $data = $this->invokePrivate($renderer, 'extractDocumentData', [$xml]);
        $html = $this->invokePrivate($renderer, 'buildHtml', [$data]);

        $this->assertStringContainsString('Documento Auxiliar da NFS-e', $html);
        $this->assertStringContainsString('NFS-e SEM VALIDADE JURIDICA', $html);
        $this->assertStringContainsString('Tributacao IBS / CBS', $html);
        $this->assertStringContainsString('TOTAIS APROXIMADOS TRIBUTOS', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('ConsultaPublica', $data['qr_code_url']);
    }

    public function testBuildHtmlPreservaContratoVisualDoLayoutNacional(): void
    {
        $renderer = new NacionalDanfseRenderer();
        $xml = $this->fixture('nfse_nacional_completa.xml');

        $data = $this->invokePrivate($renderer, 'extractDocumentData', [$xml]);
        $html = $this->invokePrivate($renderer, 'buildHtml', [$data]);

        $this->assertStringContainsString('@page { margin: 0.15cm; }', $html);
        $this->assertStringContainsString('.page', $html);
        $this->assertStringContainsString('border: 1pt solid #111827', $html);
        $this->assertStringContainsString('<div class="title">Documento Auxiliar da NFS-e</div>', $html);
        $this->assertStringContainsString('<div class="subtitle">DANFSe padrao nacional</div>', $html);
        $this->assertStringContainsString('<td class="qr-cell">', $html);
        $this->assertStringContainsString('A autenticidade desta NFS-e pode ser verificada', $html);

        $this->assertSectionOrder($html, [
            'Identificacao da NFS-e' => 'three',
            'Prestador / Fornecedor' => 'three',
            'Tomador / Adquirente da Operacao' => 'three',
            'Destinatario da Operacao' => 'three',
            'Intermediario da Operacao' => 'three',
            'Servico Prestado' => 'two',
            'Tributacao Municipal (ISSQN)' => 'three',
            'Tributacao Federal (Exceto CBS)' => 'two',
            'Tributacao IBS / CBS' => 'two',
            'Valor Total da NFS-e' => 'three',
            'Informacoes Complementares' => 'one',
        ]);

        foreach ([
            'RETENCOES',
            'DESCRICAO RETENCOES',
            'CST CLASSIFICACAO',
            'VALOR TOTAL IBS',
            'VALOR TOTAL CBS',
            'VALOR LIQUIDO MAIS IBS CBS',
            'TOTAIS APROXIMADOS TRIBUTOS',
        ] as $label) {
            $this->assertStringContainsString('<span class="field-label">' . $label . '</span>', $html);
        }

        $this->assertStringContainsString('<td class="highlight"><span class="field-label">SITUACAO</span>', $html);
        $this->assertStringContainsString('<td class="highlight"><span class="field-label">VALOR LIQUIDO MAIS IBS CBS</span>', $html);
    }

    public function testBuildHtmlSuprimeBlocosOpcionaisAusentes(): void
    {
        $renderer = new NacionalDanfseRenderer();
        $xml = $this->fixture('nfse_nacional_minima.xml');

        $data = $this->invokePrivate($renderer, 'extractDocumentData', [$xml]);
        $html = $this->invokePrivate($renderer, 'buildHtml', [$data]);

        $this->assertStringNotContainsString('Destinatario da Operacao', $html);
        $this->assertStringNotContainsString('Intermediario da Operacao', $html);
        $this->assertStringContainsString('Tomador / Adquirente da Operacao', $html);
    }

    public function testExtractDocumentDataReconheceEstadosCanceladaESubstituida(): void
    {
        $renderer = new NacionalDanfseRenderer();

        $cancelada = str_replace('</infNFSe>', '<dhCanc>2026-06-08T15:00:00-03:00</dhCanc></infNFSe>', $this->fixture('nfse_nacional_minima.xml'));
        $substituida = str_replace('</infNFSe>', '<nNFSSubst>9999</nNFSSubst></infNFSe>', $this->fixture('nfse_nacional_minima.xml'));

        $cancelData = $this->invokePrivate($renderer, 'extractDocumentData', [$cancelada]);
        $substData = $this->invokePrivate($renderer, 'extractDocumentData', [$substituida]);

        $this->assertContains('NFS-E CANCELADA', $cancelData['status_badges']);
        $this->assertContains('NFS-E SUBSTITUIDA', $substData['status_badges']);
    }

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);

        return $method->invokeArgs($object, $args);
    }

    /**
     * @param array<string,string> $expectedSections
     */
    private function assertSectionOrder(string $html, array $expectedSections): void
    {
        preg_match_all(
            '/<div class="section-title">([^<]+)<\/div><table class="grid ([^"]+)">/',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $actual = [];
        foreach ($matches as $match) {
            $actual[$match[1]] = $match[2];
        }

        $this->assertSame(array_keys($expectedSections), array_keys($actual));
        $this->assertSame($expectedSections, $actual);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/' . $name);
        $this->assertNotFalse($contents);

        return (string) $contents;
    }
}
