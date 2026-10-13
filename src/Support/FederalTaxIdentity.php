<?php

namespace sabbajohn\FiscalCore\Support;

use InvalidArgumentException;

class FederalTaxIdentity
{
    public const CPF = 'cpf';

    public const CNPJ = 'cnpj';

    public static function normalize(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        $normalized = preg_replace('/[.\/\-\s]+/', '', $normalized) ?? '';

        if (preg_match('/^[0-9]{11}$/', $normalized) === 1) {
            return $normalized;
        }

        $cnpj = CnpjIdentity::tryNormalize($normalized);
        if ($cnpj === null) {
            throw new InvalidArgumentException('CPF deve conter 11 dígitos ou CNPJ deve conter 14 posições válidas.');
        }

        return $cnpj;
    }

    public static function tryNormalize(mixed $value): ?string
    {
        try {
            return self::normalize($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        $left = self::tryNormalize($left);
        $right = self::tryNormalize($right);

        return $left !== null && $left === $right;
    }

    public static function documentType(mixed $value): ?string
    {
        $normalized = self::tryNormalize($value);

        if ($normalized === null) {
            return null;
        }

        return strlen($normalized) === 11 ? self::CPF : self::CNPJ;
    }

    public static function personType(mixed $value): ?string
    {
        return match (self::documentType($value)) {
            self::CPF => 'pf',
            self::CNPJ => 'pj',
            default => null,
        };
    }

    public static function isCpf(mixed $value): bool
    {
        return self::documentType($value) === self::CPF;
    }

    public static function isCnpj(mixed $value): bool
    {
        return self::documentType($value) === self::CNPJ;
    }
}
