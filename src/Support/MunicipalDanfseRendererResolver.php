<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Support;

use freeline\FiscalCore\Contracts\MunicipalDanfseRendererInterface;
use freeline\FiscalCore\Renderers\NFSe\BelemMunicipalDanfseRenderer;
use RuntimeException;

final class MunicipalDanfseRendererResolver
{
    public function resolve(string $providerKey): MunicipalDanfseRendererInterface
    {
        return match ($providerKey) {
            'BELEM_MUNICIPAL_2025', 'DSF' => new BelemMunicipalDanfseRenderer(),
            default => throw new RuntimeException("Renderer de DANFSe ainda nao implementado para provider '{$providerKey}'."),
        };
    }
}
