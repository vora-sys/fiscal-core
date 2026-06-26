<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class DpsPayloadHelper
{
    public static function onlyDigits(mixed $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /**
     * @param list<mixed> $values
     */
    public static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $values
     * @return array<string,mixed>
     */
    public static function firstArray(array $values): array
    {
        foreach ($values as $value) {
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $values
     */
    public static function firstDecimal(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }

            return round((float) $value, 2);
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    public static function normalizeArrayList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    public static function normalizeNumeric(mixed $value, int $length, string $default): string
    {
        $digits = self::onlyDigits($value);
        if ($digits === '') {
            $digits = $default;
        }

        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    public static function normalizeCTribNac(mixed $value): string
    {
        $digits = self::onlyDigits($value);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 6) {
            return $digits;
        }

        if (strlen($digits) === 3) {
            return sprintf('%02d%02d01', (int) substr($digits, 0, 1), (int) substr($digits, 1, 2));
        }

        if (strlen($digits) === 4) {
            return sprintf('%02d%02d01', (int) substr($digits, 0, 2), (int) substr($digits, 2, 2));
        }

        return str_pad(substr($digits, 0, 6), 6, '0', STR_PAD_LEFT);
    }

    public static function normalizeCTribMun(mixed $value): string
    {
        $digits = self::onlyDigits($value);

        return strlen($digits) === 3 ? $digits : '';
    }

    public static function normalizeIssRetentionCode(array $data): string
    {
        if (array_key_exists('tpRetISSQN', $data) && is_scalar($data['tpRetISSQN'])) {
            $explicitCode = trim((string) $data['tpRetISSQN']);
            if (in_array($explicitCode, ['1', '2', '3'], true)) {
                return $explicitCode;
            }
        }

        foreach (['IssRetido', 'iss_retido', 'issRetido'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if (is_bool($value)) {
                return $value ? '2' : '1';
            }

            if (!is_scalar($value)) {
                continue;
            }

            $normalized = strtolower(trim((string) $value));
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if ($converted !== false) {
                $normalized = $converted;
            }

            if ($normalized === '1') {
                return '2';
            }
            if ($normalized === '2' || $normalized === '0') {
                return '1';
            }
            if ($normalized === '3') {
                return '3';
            }
            if (in_array($normalized, ['true', 't', 's', 'sim', 'yes', 'y', 'retido', 'r'], true)) {
                return '2';
            }
            if (in_array($normalized, ['false', 'f', 'n', 'nao', 'no', 'nao_retido', 'nao-retido'], true)) {
                return '1';
            }
        }

        return '1';
    }

    public static function normalizePercent(float $value): float
    {
        if ($value > 0 && $value <= 1) {
            return $value * 100;
        }

        return $value;
    }
}
