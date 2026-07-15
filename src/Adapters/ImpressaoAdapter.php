<?php

namespace sabbajohn\FiscalCore\Adapters;

use NFePHP\DA\CTe\Dacte as DanfeCte;
use NFePHP\DA\MDFe\Damdfe as DanfeMdfe;
use NFePHP\DA\NFe\Danfe as DanfeNFe;
use sabbajohn\FiscalCore\Contracts\ImpressaoInterface;
use sabbajohn\FiscalCore\Renderers\NFCe\ThermalDanfceRenderer;

class ImpressaoAdapter implements ImpressaoInterface
{
    public function __construct(
        private readonly ?ThermalDanfceRenderer $thermalDanfceRenderer = null,
    ) {}

    public function gerarDanfe(string $xml): string
    {
        $danfe = new DanfeNFe($xml);

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
