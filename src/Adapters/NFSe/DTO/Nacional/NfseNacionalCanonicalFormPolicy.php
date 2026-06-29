<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class NfseNacionalCanonicalFormPolicy
{
    public const FIELD_SERVICE_MUNICIPAL_CODE = 'servico.cTribMun';
    public const FIELD_SERVICE_NATIONAL_TAX_CODE = 'servico.cTribNac';
    public const FIELD_SERVICE_NBS = 'servico.cNBS';
    public const FIELD_PRESTADOR_OP_SIMP_NAC = 'prestador.opSimpNac';

    /**
     * @var array<string,string>
     */
    private const SUPPORTED_FIELD_MAP = [
        self::FIELD_SERVICE_MUNICIPAL_CODE => self::FIELD_SERVICE_MUNICIPAL_CODE,
        self::FIELD_SERVICE_NATIONAL_TAX_CODE => self::FIELD_SERVICE_NATIONAL_TAX_CODE,
        self::FIELD_SERVICE_NBS => self::FIELD_SERVICE_NBS,
        self::FIELD_PRESTADOR_OP_SIMP_NAC => self::FIELD_PRESTADOR_OP_SIMP_NAC,
    ];

    /**
     * @return list<string>
     */
    public static function allowedFields(): array
    {
        return array_values(self::SUPPORTED_FIELD_MAP);
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function canonicalize(array $policy): array
    {
        $requiredFields = self::normalizeFieldList((array) ($policy['required_fields'] ?? []));
        $visibleFields = self::normalizeFieldList((array) ($policy['visible_fields'] ?? []));
        $schema = self::normalizeFieldMap((array) ($policy['field_schema'] ?? []));
        $activeFields = array_values(array_unique([
            ...$requiredFields,
            ...$visibleFields,
            ...array_keys($schema),
        ]));

        $policy['required_fields'] = $requiredFields;
        $policy['visible_fields'] = $visibleFields;
        $policy['default_values'] = self::normalizeFieldMap((array) ($policy['default_values'] ?? []), $activeFields);
        $policy['labels'] = self::normalizeFieldMap((array) ($policy['labels'] ?? []), $activeFields);
        $policy['hints'] = self::normalizeFieldMap((array) ($policy['hints'] ?? []), $activeFields);
        $policy['enum_fields'] = self::normalizeEnumFields((array) ($policy['enum_fields'] ?? []), $activeFields);
        $policy['conditional_rules'] = self::normalizeConditionalRules((array) ($policy['conditional_rules'] ?? []));
        $policy['extensions_supported'] = array_values(array_map('strval', (array) ($policy['extensions_supported'] ?? [])));
        $policy['field_schema'] = self::canonicalFieldSchema($schema, $activeFields);

        foreach ($policy['field_schema'] as $field => $entry) {
            if (!isset($policy['labels'][$field]) && isset($entry['label'])) {
                $policy['labels'][$field] = $entry['label'];
            }

            if (!isset($policy['hints'][$field])) {
                $defaultHints = self::defaultHints();
                if (isset($defaultHints[$field])) {
                    $policy['hints'][$field] = $defaultHints[$field];
                }
            }

            if (!isset($policy['enum_fields'][$field]) && is_array($entry['options'] ?? null)) {
                $policy['enum_fields'][$field] = array_values(array_map(
                    static fn (array $option): string => (string) ($option['value'] ?? ''),
                    array_filter((array) $entry['options'], static fn ($option): bool => is_array($option) && isset($option['value']))
                ));
            }
        }

        return $policy;
    }

    /**
     * @return array<string,string>
     */
    public static function defaultLabels(): array
    {
        return [
            self::FIELD_SERVICE_MUNICIPAL_CODE => 'Código Serviço Municipal',
            self::FIELD_SERVICE_NATIONAL_TAX_CODE => 'Código Tributação Nacional',
            self::FIELD_SERVICE_NBS => 'Código NBS',
            self::FIELD_PRESTADOR_OP_SIMP_NAC => 'Simples Nacional',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function defaultHints(): array
    {
        return [
            self::FIELD_SERVICE_MUNICIPAL_CODE => 'Código municipal do serviço aceito pelo provider NFSe.',
            self::FIELD_SERVICE_NATIONAL_TAX_CODE => 'Código nacional de tributação do serviço.',
            self::FIELD_SERVICE_NBS => 'Nomenclatura Brasileira de Serviços exigida pelo layout nacional.',
            self::FIELD_PRESTADOR_OP_SIMP_NAC => 'Opção do Simples Nacional exigida pelo layout nacional.',
        ];
    }

    /**
     * @param list<string> $fields
     * @return array<string,string>
     */
    public static function labelsFor(array $fields): array
    {
        return array_intersect_key(self::defaultLabels(), array_flip($fields));
    }

    /**
     * @param list<string> $fields
     * @return array<string,string>
     */
    public static function hintsFor(array $fields): array
    {
        return array_intersect_key(self::defaultHints(), array_flip($fields));
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string> $activeFields
     * @return array<string,array<string,mixed>>
     */
    private static function canonicalFieldSchema(array $schema, array $activeFields): array
    {
        $normalized = [];
        foreach ($activeFields as $field) {
            $defaults = self::fieldDefaults($field);
            if ($defaults === null) {
                continue;
            }

            $normalized[$field] = self::canonicalFieldEntry(
                (array) ($schema[$field] ?? []),
                $defaults['label'],
                $defaults['control'],
                [$field],
                $defaults['options'] ?? [],
            );
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $entry
     * @param list<string> $payloadPaths
     * @param list<array{value:string,label:string}> $options
     * @return array<string,mixed>
     */
    private static function canonicalFieldEntry(
        array $entry,
        string $label,
        string $control,
        array $payloadPaths,
        array $options = [],
    ): array {
        $normalized = [
            ...$entry,
            'label' => $entry['label'] ?? $label,
            'control' => $entry['control'] ?? $control,
            'payload_paths' => $payloadPaths,
        ];

        if ($options !== []) {
            $normalized['options'] = $options;
        }

        return $normalized;
    }

    /**
     * @return array{label:string,control:string,options?:list<array{value:string,label:string}>}|null
     */
    private static function fieldDefaults(string $field): ?array
    {
        $labels = self::defaultLabels();

        return match ($field) {
            self::FIELD_SERVICE_MUNICIPAL_CODE,
            self::FIELD_SERVICE_NATIONAL_TAX_CODE,
            self::FIELD_SERVICE_NBS => [
                'label' => $labels[$field],
                'control' => 'text',
            ],
            self::FIELD_PRESTADOR_OP_SIMP_NAC => [
                'label' => $labels[$field],
                'control' => 'select',
                'options' => [
                    ['value' => '1', 'label' => '1 - Não optante'],
                    ['value' => '2', 'label' => '2 - MEI'],
                    ['value' => '3', 'label' => '3 - ME/EPP'],
                ],
            ],
            default => null,
        };
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function normalizeFieldList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $field = self::canonicalFieldKey($value);
            if ($field !== null) {
                $normalized[] = $field;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $values
     * @param list<string>|null $activeFields
     * @return array<string,mixed>
     */
    private static function normalizeFieldMap(array $values, ?array $activeFields = null): array
    {
        $allowed = $activeFields !== null ? array_flip($activeFields) : null;
        $normalized = [];
        foreach ($values as $key => $value) {
            $field = self::canonicalFieldKey($key);
            if ($field === null) {
                continue;
            }

            if ($allowed !== null && !isset($allowed[$field])) {
                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $values
     * @param list<string> $activeFields
     * @return array<string,list<string>>
     */
    private static function normalizeEnumFields(array $values, array $activeFields): array
    {
        $normalized = [];
        $allowed = array_flip($activeFields);
        foreach ($values as $key => $enumValues) {
            $field = self::canonicalFieldKey($key);
            if ($field === null || !isset($allowed[$field]) || !is_array($enumValues)) {
                continue;
            }

            $normalized[$field] = array_values(array_map('strval', $enumValues));
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $rules
     * @return list<array<string,mixed>>
     */
    private static function normalizeConditionalRules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $when = is_array($rule['when'] ?? null) ? $rule['when'] : null;
            if ($when !== null && isset($when['field'])) {
                $canonicalWhen = self::canonicalFieldKey($when['field']);
                if ($canonicalWhen !== null) {
                    $when['field'] = $canonicalWhen;
                }
            }

            $normalized[] = array_filter([
                ...$rule,
                'when' => $when,
                'require' => isset($rule['require']) && is_array($rule['require'])
                    ? self::normalizeFieldList($rule['require'])
                    : ($rule['require'] ?? null),
                'show' => isset($rule['show']) && is_array($rule['show'])
                    ? self::normalizeFieldList($rule['show'])
                    : ($rule['show'] ?? null),
                'hide' => isset($rule['hide']) && is_array($rule['hide'])
                    ? self::normalizeFieldList($rule['hide'])
                    : ($rule['hide'] ?? null),
            ], static fn ($value): bool => $value !== null);
        }

        return $normalized;
    }

    private static function canonicalFieldKey(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $key = trim((string) $value);
        if ($key === '') {
            return null;
        }

        return self::SUPPORTED_FIELD_MAP[$key] ?? null;
    }
}
