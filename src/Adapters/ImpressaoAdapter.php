<?php

namespace sabbajohn\FiscalCore\Adapters;

use sabbajohn\FiscalCore\Contracts\ImpressaoInterface;
use sabbajohn\FiscalCore\Renderers\NFCe\ThermalDanfceRenderer;
use NFePHP\DA\CTe\Dacte as DanfeCte;
use NFePHP\DA\MDFe\Damdfe as DanfeMdfe;
use NFePHP\DA\NFe\Danfe as DanfeNFe;
use NFePHP\NFe\Complements;

class ImpressaoAdapter implements ImpressaoInterface
{
    private const DANFE_SIMPLIFICADO_TIPO_2_CLASS = 'NFePHP\\DA\\NFe\\DanfeSimplificadoTipo2';

    public function __construct(
        private readonly ?ThermalDanfceRenderer $thermalDanfceRenderer = null,
    ) {}

    public function gerarDanfe(string $xml, array $context = []): string
    {
        $cancelled = (bool) ($context['cancelada'] ?? false);
        $cancellation = is_array($context['cancelamento'] ?? null)
            ? $context['cancelamento']
            : [];
        $cancellationResponseXml = $cancellation['response_xml']
            ?? $cancellation['processed_event_xml']
            ?? null;
        $renderXml = $xml;

        if ($cancelled && is_string($cancellationResponseXml) && trim($cancellationResponseXml) !== '') {
            try {
                $renderXml = Complements::cancelRegister($xml, $cancellationResponseXml);
            } catch (\Throwable) {
                $renderXml = $xml;
            }
        }

        $danfe = $this->makeDanfeRenderer($renderXml);
        if ($cancelled) {
            $danfe->setCancelFlag();
        }

        return $danfe->render();
    }

    public function gerarDanfce(string $xml, array $context = []): string
    {
        $renderer = $this->thermalDanfceRenderer ?? new ThermalDanfceRenderer;

        return $renderer->render($xml, $context);
    }

    public function gerarMdfe(string $xml): string
    {
        $danfe = new DanfeMdfe($xml);

        return $danfe->render();
    }

    public function gerarCte(string $xml): string
    {
        $danfe = new DanfeCte($xml);

        return $danfe->render();
    }

    private function makeDanfeRenderer(string $xml): object
    {
        if ($this->printType($xml) !== '6') {
            return new DanfeNFe($xml);
        }

        if (! class_exists(self::DANFE_SIMPLIFICADO_TIPO_2_CLASS)) {
            throw new \RuntimeException(
                'DANFE Simplificado Tipo 2 (tpImp=6) não é suportado pela versão instalada do nfephp-org/sped-da.'
            );
        }

        $rendererClass = self::DANFE_SIMPLIFICADO_TIPO_2_CLASS;

        return new $rendererClass($xml);
    }

    private function printType(string $xml): ?string
    {
        $dom = new \DOMDocument;
        $previousErrorMode = libxml_use_internal_errors();
        libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded) {
            return null;
        }

        $value = (new \DOMXPath($dom))->evaluate(
            "string((//*[local-name()='ide']/*[local-name()='tpImp'])[1])"
        );

        return is_string($value) && $value !== '' ? $value : null;
    }
}
