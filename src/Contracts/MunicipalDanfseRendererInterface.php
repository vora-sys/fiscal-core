<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Contracts;

interface MunicipalDanfseRendererInterface
{
    public function render(string $xmlNfse, array $context = []): string;

    public function renderHtml(string $xmlNfse, array $context = []): string;
}
