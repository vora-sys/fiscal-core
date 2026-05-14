<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NFSeFormPolicy
{
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
                ? ['service.national_tax_code', 'service.nbs', 'prestador.op_simp_nac']
                : ['service.municipal_code'],
            'visible_fields' => $isNational
                ? ['service.municipal_code', 'service.national_tax_code', 'service.nbs', 'prestador.op_simp_nac']
                : ['service.municipal_code'],
            'default_values' => [],
            'field_schema' => $fieldSchema,
            'labels' => $labels,
            'hints' => $hints,
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
            ], true), ARRAY_FILTER_USE_BOTH),
        ];

        foreach (['required_fields', 'visible_fields'] as $key) {
            if (array_key_exists($key, $override)) {
                $policy[$key] = (array) $override[$key];
            }
        }

        foreach (['labels', 'hints', 'default_values', 'field_schema'] as $key) {
            $policy[$key] = [
                ...(array) ($base[$key] ?? []),
                ...(array) ($override[$key] ?? []),
            ];
        }

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
            'service.municipal_code' => 'Código Serviço Municipal',
            'service.national_tax_code' => 'Código Tributação Nacional',
            'service.nbs' => 'Código NBS',
            'service.cnae_code' => 'CNAE do Serviço',
            'service.activity_code' => 'Código de Atividade',
            'prestador.op_simp_nac' => 'Simples Nacional',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function defaultHints(): array
    {
        return [
            'service.municipal_code' => 'Código municipal do serviço aceito pelo provider NFSe.',
            'service.national_tax_code' => 'Código nacional de tributação do serviço.',
            'service.nbs' => 'Nomenclatura Brasileira de Serviços exigida pelo layout nacional.',
            'service.cnae_code' => 'CNAE fiscal do serviço; alguns municípios validam esta tag no XML.',
            'service.activity_code' => 'Código de atividade municipal quando o provider exigir campo separado.',
            'prestador.op_simp_nac' => 'Opção do Simples Nacional exigida pelo layout nacional.',
        ];
    }

    /**
     * @param array<string,string> $labels
     * @return array<string,array<string,mixed>>
     */
    private function defaultFieldSchema(array $labels): array
    {
        return [
            'service.municipal_code' => [
                'label' => $labels['service.municipal_code'],
                'control' => 'text',
                'payload_paths' => ['items.*.codigo_servico_municipal', 'items.*.manual_overrides.codigo_tributacao_municipal', 'nota.itens.*.codigoServico', 'nota.itens.*.cTribMun'],
            ],
            'service.national_tax_code' => [
                'label' => $labels['service.national_tax_code'],
                'control' => 'text',
                'payload_paths' => ['items.*.manual_overrides.codigo_tributacao_nacional', 'nota.itens.*.cTribNac', 'nota.itens.*.codigoServicoNacional'],
            ],
            'service.nbs' => [
                'label' => $labels['service.nbs'],
                'control' => 'text',
                'payload_paths' => ['items.*.manual_overrides.codigo_nbs', 'nota.itens.*.cNBS', 'nota.itens.*.codigoNbs'],
            ],
            'service.cnae_code' => [
                'label' => $labels['service.cnae_code'],
                'control' => 'text',
                'payload_paths' => ['items.*.codigo_cnae', 'items.*.codigoCnae', 'items.*.manual_overrides.codigo_cnae', 'nota.itens.*.codigo_cnae', 'nota.itens.*.codigoCnae'],
            ],
            'service.activity_code' => [
                'label' => $labels['service.activity_code'],
                'control' => 'text',
                'payload_paths' => ['items.*.codigo_atividade', 'items.*.manual_overrides.codigo_atividade', 'nota.itens.*.codigo_atividade'],
            ],
            'prestador.op_simp_nac' => [
                'label' => $labels['prestador.op_simp_nac'],
                'control' => 'select',
                'payload_paths' => ['document_data.op_simp_nac', 'nota.emitente.opSimpNac', 'prestador.opSimpNac'],
                'options' => [
                    ['value' => '1', 'label' => '1 - Não optante'],
                    ['value' => '2', 'label' => '2 - MEI'],
                    ['value' => '3', 'label' => '3 - ME/EPP'],
                ],
            ],
        ];
    }
}
