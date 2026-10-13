<?php

namespace sabbajohn\FiscalCore\Support;

use InvalidArgumentException;

class CnpjIdentity
{
    public static function normalize(mixed $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        $normalized = preg_replace('/[.\/\-\s]+/', '', $normalized) ?? '';

        if (! preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $normalized)) {
            throw new InvalidArgumentException('CNPJ deve conter 14 posições, com dígitos verificadores numéricos.');
        }

        return $normalized;
    }

    public static function tryNormalize(mixed $value): ?string
    {
        try {
            return self::normalize($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function root(mixed $value): string
    {
        return substr(self::normalize($value), 0, 8);
    }

    public static function sameRoot(mixed $left, mixed $right): bool
    {
        $leftNormalized = self::tryNormalize($left);
        $rightNormalized = self::tryNormalize($right);

        return $leftNormalized !== null
            && $rightNormalized !== null
            && substr($leftNormalized, 0, 8) === substr($rightNormalized, 0, 8);
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        $leftNormalized = self::tryNormalize($left);
        $rightNormalized = self::tryNormalize($right);

        return $leftNormalized !== null && $leftNormalized === $rightNormalized;
    }

    public static function isAlphanumeric(mixed $value): bool
    {
        $normalized = self::tryNormalize($value);

        return $normalized !== null && preg_match('/[A-Z]/', $normalized) === 1;
    }

    public static function hasValidCheckDigits(mixed $value): bool
    {
        $normalized = self::tryNormalize($value);
        if ($normalized === null || preg_match('/^(.)\1{13}$/', $normalized) === 1) {
            return false;
        }

        $base = substr($normalized, 0, 12);
        $firstDigit = self::calculateCheckDigit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $secondDigit = self::calculateCheckDigit($base.$firstDigit, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return substr($normalized, -2) === $firstDigit.$secondDigit;
    }

    /**
     * @param  array<int,int>  $weights
     */
    private static function calculateCheckDigit(string $base, array $weights): string
    {
        $sum = 0;
        foreach (str_split($base) as $index => $character) {
            $sum += (ord($character) - 48) * $weights[$index];
        }

        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
