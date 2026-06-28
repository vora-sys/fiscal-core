<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

use DateTimeImmutable;
use DateTimeZone;

final class NacionalDpsTemporalNormalizer
{
    public const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function normalizePayload(array $payload, array $context = []): array
    {
        $rawDhEmi = is_scalar($payload['dhEmi'] ?? null) ? trim((string) $payload['dhEmi']) : '';
        $timezone = self::timezone($context['timezone'] ?? null);
        $now = ($context['now'] ?? null) instanceof DateTimeImmutable ? $context['now'] : null;
        $payload['dhEmi'] = self::normalizeDhEmi($payload['dhEmi'] ?? null, $timezone, $now);

        $normalizedCompetenceDate = substr((string) $payload['dhEmi'], 0, 10);
        $rawCompetenceDate = self::datePortion($rawDhEmi);
        $currentCompetenceDate = is_scalar($payload['dCompet'] ?? null) ? trim((string) $payload['dCompet']) : '';
        if (
            $currentCompetenceDate === ''
            || ($rawCompetenceDate !== null
                && $currentCompetenceDate === $rawCompetenceDate
                && $currentCompetenceDate !== $normalizedCompetenceDate)
            || $currentCompetenceDate > $normalizedCompetenceDate
        ) {
            $payload['dCompet'] = $normalizedCompetenceDate;
        }

        return $payload;
    }

    public static function normalizeDhEmi(mixed $value, ?DateTimeZone $timezone = null, ?DateTimeImmutable $now = null): string
    {
        $timezone ??= new DateTimeZone(self::DEFAULT_TIMEZONE);
        $latestAllowed = ($now ?? new DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone)
            ->modify('-5 seconds');

        if (is_scalar($value)) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                try {
                    $normalized = (new DateTimeImmutable($candidate, $timezone))->setTimezone($timezone);

                    return ($normalized > $latestAllowed ? $latestAllowed : $normalized)
                        ->format('Y-m-d\TH:i:sP');
                } catch (\Throwable) {
                    // Fallback para a data padrão quando o valor informado não puder ser normalizado.
                }
            }
        }

        return $latestAllowed->format('Y-m-d\TH:i:sP');
    }

    private static function timezone(mixed $value): DateTimeZone
    {
        if ($value instanceof DateTimeZone) {
            return $value;
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            try {
                return new DateTimeZone((string) $value);
            } catch (\Throwable) {
                // Usa timezone padrão quando a configuração for inválida.
            }
        }

        return new DateTimeZone(self::DEFAULT_TIMEZONE);
    }

    private static function datePortion(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
