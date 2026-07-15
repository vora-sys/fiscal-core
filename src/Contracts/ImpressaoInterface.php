<?php

namespace sabbajohn\FiscalCore\Contracts;

interface ImpressaoInterface
{
    public function gerarDanfe(string $xml): string;

    public function gerarDanfce(string $xml, array $context = []): string;

    public function gerarMdfe(string $xml): string;

    public function gerarCte(string $xml): string;
}
