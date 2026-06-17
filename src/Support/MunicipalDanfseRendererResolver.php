<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Contracts\MunicipalDanfseRendererInterface;
use sabbajohn\FiscalCore\Renderers\NFSe\BelemMunicipalDanfseRenderer;
use sabbajohn\FiscalCore\Renderers\NFSe\JoinvilleMunicipalDanfseRenderer;
use sabbajohn\FiscalCore\Renderers\NFSe\NacionalDanfseRenderer;
use RuntimeException;

final class MunicipalDanfseRendererResolver
{
    public function resolve(string $providerKey): MunicipalDanfseRendererInterface
    {
        return match ($providerKey) {
            'BELEM_MUNICIPAL_2025' => new BelemMunicipalDanfseRenderer(),
            'PUBLICA' => new JoinvilleMunicipalDanfseRenderer(),
            'nfse_nacional', 'NFSE_NACIONAL', 'Manaus' => new NacionalDanfseRenderer(),
            default => throw new RuntimeException("Renderer de DANFSe ainda nao implementado para provider '{$providerKey}'."),
        };
    }
}
