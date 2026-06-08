<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NFSeFormPolicy
{
    private const FIELD_SERVICE_MUNICIPAL_CODE = 'servico.cTribMun';
    private const FIELD_SERVICE_NATIONAL_TAX_CODE = 'servico.cTribNac';
    private const FIELD_SERVICE_NBS = 'servico.cNBS';
    private const FIELD_SERVICE_CNAE_CODE = 'servico.codigoCnae';
    private const FIELD_SERVICE_ACTIVITY_CODE = 'servico.codigo_atividade';
    private const FIELD_SERVICE_BENEFIT_CODE = 'servico.benefit_code';
    private const FIELD_PRESTADOR_OP_SIMP_NAC = 'prestador.opSimpNac';
    private const FIELD_PRESTADOR_MEI = 'prestador.mei';

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function build(
        string $providerKey,
        string $layoutFamily,
        string $municipioIbge,
        string $municipioNome,
        array $config = []
    ): array {
        $labels = $this->defaultLabels();
        $hints = $this->defaultHints();
        $fieldSchema = $this->defaultFieldSchema($labels);
        $normalizedProviderKey = strtolower(trim($providerKey));
        $normalizedLayoutFamily = strtoupper(trim($layoutFamily));
        $isNational = $providerKey === ProviderRegistry::NFSE_NATIONAL_KEY
            || $normalizedProviderKey === ProviderRegistry::NFSE_NATIONAL_KEY
            || $normalizedLayoutFamily === 'NACIONAL';

        $policy = [
            'provider_key' => $providerKey,
            'layout_family' => $layoutFamily !== '' ? $layoutFamily : ($isNational ? 'NACIONAL' : null),
            'municipio_ibge' => $municipioIbge !== '' ? $municipioIbge : null,
            'municipio_nome' => $municipioNome !== '' ? $municipioNome : null,
            'policy_source' => $isNational ? 'nfse_nacional_policy' : 'default_provider_policy',
            'required_fields' => $isNational
                ? [self::FIELD_SERVICE_NATIONAL_TAX_CODE, self::FIELD_SERVICE_NBS, self::FIELD_PRESTADOR_OP_SIMP_NAC]
                : [self::FIELD_SERVICE_MUNICIPAL_CODE],
            'visible_fields' => $isNational
                ? [self::FIELD_SERVICE_MUNICIPAL_CODE, self::FIELD_SERVICE_NATIONAL_TAX_CODE, self::FIELD_SERVICE_NBS, self::FIELD_PRESTADOR_OP_SIMP_NAC]
                : [self::FIELD_SERVICE_MUNICIPAL_CODE],
            'default_values' => [],
            'field_schema' => $fieldSchema,
            'labels' => $labels,
            'hints' => $hints,
            'enum_fields' => [],
            'conditional_rules' => [],
            'extensions_supported' => [],
        ];

        if (is_array($config['form_policy'] ?? null)) {
            $policy = $this->merge($policy, $config['form_policy']);
        }

        return $this->normalizeFields($policy);
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function merge(array $base, array $override): array
    {
        $policy = [
            ...$base,
            ...array_filter($override, static fn ($value, string $key): bool => ! in_array($key, [
                'required_fields',
                'visible_fields',
                'labels',
                'hints',
                'default_values',
                'field_schema',
                'enum_fields',
                'conditional_rules',
                'extensions_supported',
            ], true), ARRAY_FILTER_USE_BOTH),
        ];

        foreach (['required_fields', 'visible_fields'] as $key) {
            if (array_key_exists($key, $override)) {
                $policy[$key] = (array) $override[$key];
            }
        }

        foreach (['labels', 'hints', 'default_values', 'field_schema', 'enum_fields'] as $key) {
            $policy[$key] = [
                ...(array) ($base[$key] ?? []),
                ...(array) ($override[$key] ?? []),
            ];
        }

        $policy['conditional_rules'] = array_values([
            ...(array) ($base['conditional_rules'] ?? []),
            ...(array) ($override['conditional_rules'] ?? []),
        ]);
        $policy['extensions_supported'] = array_values(array_unique([
            ...array_map('strval', (array) ($base['extensions_supported'] ?? [])),
            ...array_map('strval', (array) ($override['extensions_supported'] ?? [])),
        ]));

        return $policy;
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function normalizeFields(array $policy): array
    {
        $required = array_values(array_unique(array_map('strval', (array) ($policy['required_fields'] ?? []))));
        $visible = array_values(array_unique([
            ...array_map('strval', (array) ($policy['visible_fields'] ?? [])),
            ...$required,
        ]));

        $policy['required_fields'] = $required;
        $policy['visible_fields'] = $visible;

        return $policy;
    }

    /**
     * @return array<string,string>
     */
    private function defaultLabels(): array
    {
        return [
            self::FIELD_SERVICE_MUNICIPAL_CODE => 'Código Serviço Municipal',
            self::FIELD_SERVICE_NATIONAL_TAX_CODE => 'Código Tributação Nacional',
            self::FIELD_SERVICE_NBS => 'Código NBS',
            self::FIELD_SERVICE_CNAE_CODE => 'CNAE do Serviço',
            self::FIELD_SERVICE_ACTIVITY_CODE => 'Código de Atividade',
            self::FIELD_SERVICE_BENEFIT_CODE => 'Código Benefício Municipal',
            self::FIELD_PRESTADOR_OP_SIMP_NAC => 'Simples Nacional',
            self::FIELD_PRESTADOR_MEI => 'Emitente MEI',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function defaultHints(): array
    {
        return [
            self::FIELD_SERVICE_MUNICIPAL_CODE => 'Código municipal do serviço aceito pelo provider NFSe.',
            self::FIELD_SERVICE_NATIONAL_TAX_CODE => 'Código nacional de tributação do serviço.',
            self::FIELD_SERVICE_NBS => 'Nomenclatura Brasileira de Serviços exigida pelo layout nacional.',
            self::FIELD_SERVICE_CNAE_CODE => 'CNAE fiscal do serviço; alguns municípios validam esta tag no XML.',
            self::FIELD_SERVICE_ACTIVITY_CODE => 'Código de atividade municipal quando o provider exigir campo separado.',
            self::FIELD_SERVICE_BENEFIT_CODE => 'Código oficial do benefício municipal quando houver benefício permitido para o serviço.',
            self::FIELD_PRESTADOR_OP_SIMP_NAC => 'Opção do Simples Nacional exigida pelo layout nacional.',
            self::FIELD_PRESTADOR_MEI => 'Classificação explícita do emitente para roteamento municipal ou nacional da NFSe.',
        ];
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,array<string,mixed>>
     */
    private function defaultFieldSchema(array $labels): array
    {
        return [
            self::FIELD_SERVICE_MUNICIPAL_CODE => [
                'label' => $labels[self::FIELD_SERVICE_MUNICIPAL_CODE],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_MUNICIPAL_CODE],
            ],
            self::FIELD_SERVICE_NATIONAL_TAX_CODE => [
                'label' => $labels[self::FIELD_SERVICE_NATIONAL_TAX_CODE],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_NATIONAL_TAX_CODE],
            ],
            self::FIELD_SERVICE_NBS => [
                'label' => $labels[self::FIELD_SERVICE_NBS],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_NBS],
            ],
            self::FIELD_SERVICE_CNAE_CODE => [
                'label' => $labels[self::FIELD_SERVICE_CNAE_CODE],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_CNAE_CODE],
            ],
            self::FIELD_SERVICE_ACTIVITY_CODE => [
                'label' => $labels[self::FIELD_SERVICE_ACTIVITY_CODE],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_ACTIVITY_CODE],
            ],
            self::FIELD_SERVICE_BENEFIT_CODE => [
                'label' => $labels[self::FIELD_SERVICE_BENEFIT_CODE],
                'control' => 'text',
                'payload_paths' => [self::FIELD_SERVICE_BENEFIT_CODE],
            ],
            self::FIELD_PRESTADOR_OP_SIMP_NAC => [
                'label' => $labels[self::FIELD_PRESTADOR_OP_SIMP_NAC],
                'control' => 'select',
                'payload_paths' => [self::FIELD_PRESTADOR_OP_SIMP_NAC],
                'options' => [
                    ['value' => '1', 'label' => '1 - Não optante'],
                    ['value' => '2', 'label' => '2 - MEI'],
                    ['value' => '3', 'label' => '3 - ME/EPP'],
                ],
            ],
            self::FIELD_PRESTADOR_MEI => [
                'label' => $labels[self::FIELD_PRESTADOR_MEI],
                'control' => 'select',
                'payload_paths' => [self::FIELD_PRESTADOR_MEI],
                'options' => [
                    ['value' => 'false', 'label' => 'Não MEI'],
                    ['value' => 'true', 'label' => 'MEI'],
                ],
            ],
        ];
    }
}
