<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Validation;

final class NormalizedCode
{
    public static function lc116(?string $value): ?string
    {
        $digits = self::digits($value);
        if ($digits === null) {
            return null;
        }

        // Some municipal systems expose a six-digit breakdown (for example
        // 140101) even when the user only knows the parent LC 116 item (14.01).
        // The LC catalog is keyed by the four-digit parent code.
        if (strlen($digits) === 6) {
            $digits = substr($digits, 0, 4);
        }

        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;

        return str_pad($digits, 4, '0', STR_PAD_LEFT);
    }

    public static function nbs(?string $value): ?string
    {
        return self::fixedDigits($value, 9);
    }

    public static function nationalTax(?string $value): ?string
    {
        return self::fixedDigits($value, 6);
    }

    public static function municipalTax(?string $value): ?string
    {
        return self::digits($value);
    }

    public static function municipality(?string $value): ?string
    {
        return self::fixedDigits($value, 7);
    }

    public static function cnae(?string $value): ?string
    {
        return self::fixedDigits($value, 7);
    }

    public static function operationIndicator(?string $value): ?string
    {
        return self::fixedDigits($value, 6);
    }

    public static function taxClassification(?string $value): ?string
    {
        return self::fixedDigits($value, 6);
    }

    public static function displayNbs(?string $value): ?string
    {
        $digits = self::nbs($value);
        if ($digits === null) {
            return null;
        }

        return substr($digits, 0, 1).'.'.substr($digits, 1, 4).'.'.substr($digits, 5, 2).'.'.substr($digits, 7, 2);
    }

    private static function fixedDigits(?string $value, int $length): ?string
    {
        $digits = self::digits($value);

        return $digits !== null && strlen($digits) === $length ? $digits : null;
    }

    private static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $value)) ?? '';

        return $digits === '' ? null : $digits;
    }
}
