<?php

namespace sabbajohn\FiscalCore\Support;

final class NfseNacionalIbscbsClassificationRules
{
    /**
     * Pares CST/cClassTrib confirmados nos exemplos locais como sem tributacao regular.
     *
     * @var array<string,true>
     */
    private const WITHOUT_TRIB_REGULAR = [
        '000|000001' => true,
    ];

    /**
     * Pares CST/cClassTrib confirmados nos exemplos locais como sem diferimento.
     *
     * @var array<string,true>
     */
    private const WITHOUT_DIFERIMENTO = [
        '000|000001' => true,
    ];

    /** @var array<string,true> */
    private const WITHOUT_CREDITO_PRESUMIDO = [
        '000|000001' => true,
    ];

    public static function normalizeCst(?string $value): ?string
    {
        $digits = self::onlyDigits($value ?? '');
        if ($digits === '') {
            return null;
        }

        return str_pad(substr($digits, 0, 3), 3, '0', STR_PAD_LEFT);
    }

    public static function normalizeClass(?string $value): ?string
    {
        $digits = self::onlyDigits($value ?? '');
        if ($digits === '') {
            return null;
        }

        return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT);
    }

    public static function allowsTribRegular(?string $cClassTrib, ?string $cst = null): bool
    {
        $key = self::situationClassKey($cst, $cClassTrib);

        return $key === null || ! isset(self::WITHOUT_TRIB_REGULAR[$key]);
    }

    public static function allowsDiferimento(?string $cClassTrib, ?string $cst = null): bool
    {
        $key = self::situationClassKey($cst, $cClassTrib);

        return $key === null || ! isset(self::WITHOUT_DIFERIMENTO[$key]);
    }

    public static function allowsCreditoPresumido(?string $cClassTrib, ?string $cst = null): bool
    {
        $key = self::situationClassKey($cst, $cClassTrib);

        return $key === null || ! isset(self::WITHOUT_CREDITO_PRESUMIDO[$key]);
    }

    public static function hasEffectiveDiferimento(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if (is_numeric($value) && abs((float) $value) > 0.000001) {
                return true;
            }
        }

        return false;
    }

    private static function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function situationClassKey(?string $cst, ?string $cClassTrib): ?string
    {
        $normalizedCst = self::normalizeCst($cst);
        $normalizedClass = self::normalizeClass($cClassTrib);
        if ($normalizedCst === null || $normalizedClass === null) {
            return null;
        }

        return $normalizedCst.'|'.$normalizedClass;
    }
}
