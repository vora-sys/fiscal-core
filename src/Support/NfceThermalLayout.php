<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NfceThermalLayout
{
    /** @var list<string> */
    public const REQUIRED_SECTION_TYPES = [
        'header',
        'items',
        'totals',
        'payments',
        'consultation',
        'qr_code',
        'protocol_footer',
    ];

    /** @var list<string> */
    public const OPTIONAL_SECTION_TYPES = [
        'logo',
        'recipient',
        'ibpt',
        'messages',
    ];

    /** @var list<string> */
    public const SECTION_ORDER = [
        'logo',
        'header',
        'recipient',
        'items',
        'totals',
        'payments',
        'ibpt',
        'messages',
        'consultation',
        'qr_code',
        'protocol_footer',
    ];

    public static function default(): array
    {
        return self::normalize([]);
    }

    /**
     * @param array<string,mixed>|null $raw
     * @return array<string,mixed>
     */
    public static function normalize(?array $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return self::normalizeV2([]);
        }

        $isV2 = (int) ($raw['schema_version'] ?? 0) === 2
            || is_array($raw['paper'] ?? null)
            || is_array($raw['typography'] ?? null)
            || is_array($raw['sections'] ?? null);

        return $isV2
            ? self::normalizeV2($raw)
            : self::normalizeLegacy($raw);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function normalizeV2(array $raw): array
    {
        $paper = is_array($raw['paper'] ?? null) ? $raw['paper'] : [];
        $typography = is_array($raw['typography'] ?? null) ? $raw['typography'] : [];
        $rawSections = is_array($raw['sections'] ?? null) ? $raw['sections'] : [];

        $provided = [];
        $providedOrder = [];

        foreach ($rawSections as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = self::sanitizeSectionType($entry['type'] ?? null);
            if ($type === null) {
                continue;
            }

            $provided[$type] = $entry;
            $providedOrder[$type] = [
                'order' => self::toInt($entry['order'] ?? null, $index + 1),
                'index' => $index,
            ];
        }

        $orderedTypes = array_keys($providedOrder);
        usort($orderedTypes, static function (string $left, string $right) use ($providedOrder): int {
            $leftOrder = $providedOrder[$left];
            $rightOrder = $providedOrder[$right];

            return [$leftOrder['order'], $leftOrder['index']] <=> [$rightOrder['order'], $rightOrder['index']];
        });

        foreach (self::SECTION_ORDER as $type) {
            if (!in_array($type, $orderedTypes, true)) {
                $orderedTypes[] = $type;
            }
        }

        $sections = [];
        foreach ($orderedTypes as $index => $type) {
            $default = self::defaultSection($type, $index + 1);
            $source = is_array($provided[$type] ?? null) ? $provided[$type] : [];
            $required = in_array($type, self::REQUIRED_SECTION_TYPES, true);

            $sections[] = [
                'type' => $type,
                'required' => $required,
                'enabled' => $required ? true : self::toBool($source['enabled'] ?? null, $default['enabled']),
                'order' => $index + 1,
                'align' => self::sanitizeAlign($source['align'] ?? null, $default['align']),
                'spacing_before_mm' => self::toFloat($source['spacing_before_mm'] ?? null, $default['spacing_before_mm']),
                'spacing_after_mm' => self::toFloat($source['spacing_after_mm'] ?? null, $default['spacing_after_mm']),
                'padding_left_mm' => self::toFloat($source['padding_left_mm'] ?? null, $default['padding_left_mm']),
                'padding_right_mm' => self::toFloat($source['padding_right_mm'] ?? null, $default['padding_right_mm']),
                'emphasis' => self::sanitizeEmphasis($source['emphasis'] ?? null, $default['emphasis']),
            ];
        }

        return [
            'schema_version' => 2,
            'renderer' => 'nfce_pdf_thermal',
            'paper' => [
                'width_mm' => self::sanitizeWidth($paper['width_mm'] ?? null),
                'margin_top_mm' => self::toFloat($paper['margin_top_mm'] ?? null, 2.5),
                'margin_right_mm' => self::toFloat($paper['margin_right_mm'] ?? null, 2.0),
                'margin_bottom_mm' => self::toFloat($paper['margin_bottom_mm'] ?? null, 3.0),
                'margin_left_mm' => self::toFloat($paper['margin_left_mm'] ?? null, 2.0),
                'qr_size_mm' => max(25.0, self::toFloat($paper['qr_size_mm'] ?? null, 28.0)),
            ],
            'typography' => [
                'base_font_pt' => self::toFloat($typography['base_font_pt'] ?? null, 8.0),
                'mono_font_pt' => self::toFloat($typography['mono_font_pt'] ?? null, 7.0),
                'total_font_pt' => self::toFloat($typography['total_font_pt'] ?? null, 10.0),
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function normalizeLegacy(array $raw): array
    {
        $legacySections = is_array($raw['secoes'] ?? null) ? $raw['secoes'] : [];
        $legacyByType = [];
        $orderedTypes = [];

        foreach ($legacySections as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $mappedType = self::mapLegacySectionType($entry['type'] ?? null);
            if ($mappedType === null) {
                continue;
            }

            $legacyByType[$mappedType] = $entry;
            $orderedTypes[$mappedType] = $index;
        }

        $showLogo = self::toBool($raw['mostrarLogo'] ?? null, true);
        $highlightTotal = self::toBool($raw['destacarTotal'] ?? null, true);
        $font = self::toFloat($raw['fonte'] ?? null, 8.0);

        $sections = [];
        $seenTypes = array_keys($orderedTypes);
        usort($seenTypes, static fn (string $left, string $right): int => ($orderedTypes[$left] ?? 0) <=> ($orderedTypes[$right] ?? 0));

        foreach (self::SECTION_ORDER as $type) {
            if (!in_array($type, $seenTypes, true)) {
                $seenTypes[] = $type;
            }
        }

        foreach ($seenTypes as $index => $type) {
            $legacy = is_array($legacyByType[$type] ?? null) ? $legacyByType[$type] : [];
            $default = self::defaultSection($type, $index + 1);
            $required = in_array($type, self::REQUIRED_SECTION_TYPES, true);
            $legacyAlignment = self::sanitizeAlign($legacy['alignment'] ?? null, $default['align']);
            $offsetPx = self::toFloat($legacy['offsetPx'] ?? null, 0.0);

            $enabled = $required ? true : self::toBool($legacy['enabled'] ?? null, $default['enabled']);
            if ($type === 'logo') {
                $enabled = $showLogo && $enabled;
            }

            $sections[] = [
                'type' => $type,
                'required' => $required,
                'enabled' => $enabled,
                'order' => $index + 1,
                'align' => $legacyAlignment,
                'spacing_before_mm' => self::legacySpacingToMm($legacy['spacingBefore'] ?? null, $default['spacing_before_mm']),
                'spacing_after_mm' => self::legacySpacingToMm($legacy['spacingAfter'] ?? null, $default['spacing_after_mm']),
                'padding_left_mm' => $offsetPx > 0 ? round($offsetPx / 3.78, 2) : $default['padding_left_mm'],
                'padding_right_mm' => $default['padding_right_mm'],
                'emphasis' => $type === 'totals' && $highlightTotal ? 'strong' : $default['emphasis'],
            ];
        }

        return self::normalizeV2([
            'schema_version' => 2,
            'renderer' => 'nfce_pdf_thermal',
            'paper' => [
                'width_mm' => self::sanitizeWidth($raw['largura'] ?? null),
                'margin_top_mm' => 2.5,
                'margin_right_mm' => 2.0,
                'margin_bottom_mm' => 3.0,
                'margin_left_mm' => 2.0,
                'qr_size_mm' => 28.0,
            ],
            'typography' => [
                'base_font_pt' => $font,
                'mono_font_pt' => max(6.0, $font - 1.0),
                'total_font_pt' => $highlightTotal ? $font + 2.0 : max($font, 9.0),
            ],
            'sections' => $sections,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultSection(string $type, int $order): array
    {
        $required = in_array($type, self::REQUIRED_SECTION_TYPES, true);

        return [
            'type' => $type,
            'required' => $required,
            'enabled' => $required || in_array($type, ['logo', 'recipient', 'ibpt', 'messages'], true),
            'order' => $order,
            'align' => match ($type) {
                'logo', 'header', 'consultation', 'qr_code', 'protocol_footer' => 'center',
                'totals', 'payments' => 'right',
                default => 'left',
            },
            'spacing_before_mm' => in_array($type, ['header', 'items'], true) ? 0.0 : 1.5,
            'spacing_after_mm' => $type === 'protocol_footer' ? 0.0 : 1.5,
            'padding_left_mm' => 0.0,
            'padding_right_mm' => 0.0,
            'emphasis' => $type === 'totals' ? 'strong' : 'normal',
        ];
    }

    private static function mapLegacySectionType(mixed $type): ?string
    {
        $normalized = is_string($type) ? trim($type) : '';

        return match ($normalized) {
            'logo', 'header', 'items', 'totals', 'ibpt' => $normalized,
            'fiscal_footer' => 'protocol_footer',
            default => null,
        };
    }

    private static function sanitizeSectionType(mixed $type): ?string
    {
        if (!is_string($type)) {
            return null;
        }

        $normalized = trim($type);

        return in_array($normalized, array_merge(self::SECTION_ORDER, ['fiscal_footer']), true)
            ? self::mapLegacySectionType($normalized) ?? $normalized
            : null;
    }

    private static function sanitizeAlign(mixed $align, string $fallback): string
    {
        if (!is_string($align)) {
            return $fallback;
        }

        return match (trim($align)) {
            'left', 'center', 'right' => trim($align),
            'custom' => 'left',
            default => $fallback,
        };
    }

    private static function sanitizeEmphasis(mixed $emphasis, string $fallback): string
    {
        if (!is_string($emphasis)) {
            return $fallback;
        }

        return in_array($emphasis, ['normal', 'strong'], true) ? $emphasis : $fallback;
    }

    private static function sanitizeWidth(mixed $width): int
    {
        $numeric = self::toInt($width, 80);

        return $numeric <= 69 ? 58 : 80;
    }

    private static function legacySpacingToMm(mixed $value, float $fallback): float
    {
        return round(max(0.0, self::toFloat($value, $fallback)) * 1.5, 2);
    }

    private static function toBool(mixed $value, bool $fallback): bool
    {
        return is_bool($value) ? $value : $fallback;
    }

    private static function toInt(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    private static function toFloat(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? round((float) $value, 2) : $fallback;
    }
}
