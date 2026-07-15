<?php

namespace sabbajohn\FiscalCore\Contracts;

interface DocumentoInterface
{
    public function validarCPF(string $cpf): bool;

    public function formatarCPF(string $cpf): string;

    public function validarCNPJ(string $cnpj): bool;

    public function formatarCNPJ(string $cnpj): string;
}
