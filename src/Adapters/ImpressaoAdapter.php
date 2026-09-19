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
    public function __construct(
        private readonly ?ThermalDanfceRenderer $thermalDanfceRenderer = null,
    ) {}

    public function gerarDanfe(string $xml, array $context = []): string
    {
        $cancelled = (bool) ($context['cancelada'] ?? false);
        $cancellationResponseXml = data_get($context, 'cancelamento.response_xml')
            ?? data_get($context, 'cancelamento.processed_event_xml');
        $renderXml = $xml;

        if ($cancelled && is_string($cancellationResponseXml) && trim($cancellationResponseXml) !== '') {
            try {
                $renderXml = Complements::cancelRegister($xml, $cancellationResponseXml);
            } catch (\Throwable) {
                $renderXml = $xml;
            }
        }

        $danfe = new DanfeNFe($renderXml);
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
}
