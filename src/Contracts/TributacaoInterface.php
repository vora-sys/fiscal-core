<?php

namespace sabbajohn\FiscalCore\Contracts;

interface TributacaoInterface
{
    public function calcularImpostos(array $produto): array;

    public function consultarAliquota(string $ncm): array;
}
