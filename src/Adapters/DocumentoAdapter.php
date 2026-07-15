<?php

namespace sabbajohn\FiscalCore\Adapters;

use Brazanation\Documents\Cnpj;
use Brazanation\Documents\Cpf;
use sabbajohn\FiscalCore\Contracts\DocumentoInterface;

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
        try {
            $doc = Cnpj::createFromString($cnpj);

            // Validate by checking if the calculated digits match the expected format
            return $this->isCnpjValid($cnpj);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function formatarCNPJ(string $cnpj): string
    {
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

    private function isCnpjValid(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        // Check for same digits
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        // Calculate first digit
        $weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnpj[$i] * $weights[$i];
        }
        $digit1 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

        // Calculate second digit
        $weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $cnpj[$i] * $weights[$i];
        }
        $digit2 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);

        return $digit1 === (int) $cnpj[12] && $digit2 === (int) $cnpj[13];
    }
}
