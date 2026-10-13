<?php

namespace sabbajohn\FiscalCore\Adapters;

use sabbajohn\FiscalCore\Contracts\DocumentoInterface;
use sabbajohn\FiscalCore\Support\CnpjIdentity;
use Brazanation\Documents\Cnpj;
use Brazanation\Documents\Cpf;

class DocumentoAdapter implements DocumentoInterface
{
    public function validarCPF(string $cpf): bool
    {
        try {
            $doc = Cpf::createFromString($cpf);

            // Validate by checking if the calculated digits match the expected format
            return $this->isCpfValid($cpf);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function formatarCPF(string $cpf): string
    {
        try {
            $doc = Cpf::createFromString($cpf);

            return $doc->format();
        } catch (\Throwable $e) {
            return $cpf;
        }
    }

    public function validarCNPJ(string $cnpj): bool
    {
        return CnpjIdentity::hasValidCheckDigits($cnpj);
    }

    public function formatarCNPJ(string $cnpj): string
    {
        $normalized = CnpjIdentity::tryNormalize($cnpj);
        if ($normalized !== null) {
            return preg_replace('/(.{2})(.{3})(.{3})(.{4})(.{2})/', '$1.$2.$3/$4-$5', $normalized) ?? $normalized;
        }

        try {
            $doc = Cnpj::createFromString($cnpj);

            return $doc->format();
        } catch (\Throwable $e) {
            return $cnpj;
        }
    }

    private function isCpfValid(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        // Check for same digits
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        // Calculate first digit
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $cpf[$i] * (10 - $i);
        }
        $digit1 = ($sum * 10) % 11;
        if ($digit1 === 10) {
            $digit1 = 0;
        }

        // Calculate second digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int) $cpf[$i] * (11 - $i);
        }
        $digit2 = ($sum * 10) % 11;
        if ($digit2 === 10) {
            $digit2 = 0;
        }

        return $digit1 === (int) $cpf[9] && $digit2 === (int) $cpf[10];
    }
}
