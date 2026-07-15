<?php

namespace sabbajohn\FiscalCore\Contracts;

interface ProdutoInterface
{
    public function validarGTIN(string $codigo): bool;

    public function checkGTIN(string $codigo): self;

    public function buscarProduto(string $gtin): array;

    public function consultarNCM(string $gtin): array;

    public function obterDescricao(string $gtin): ?string;
}
