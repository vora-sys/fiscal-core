<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Contracts;

interface MunicipalDanfseRendererInterface
{
    public function render(string $xmlNfse): string;
}
