<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

final class NFSeProviderTranslationPolicy
{
    /**
     * @param  array<string,mixed>  $rules
     */
    private function __construct(
        private readonly string $providerKey,
        private readonly string $layoutFamily,
        private readonly array $rules
    ) {}

    /**
     * @param  array<string,mixed>  $config
     */
    public static function fromProviderContext(string $providerKey, string $layoutFamily = '', array $config = []): self
    {
        $normalizedProviderKey = self::normalizeKey($providerKey);
        $normalizedLayoutFamily = self::normalizeKey($layoutFamily);

        $rules = [
            'service.iss_withheld' => self::defaultIssWithheldRule($normalizedProviderKey, $normalizedLayoutFamily),
        ];

        if (is_array($config['field_translations'] ?? null)) {
            $rules = self::mergeRules($rules, $config['field_translations']);
        }

        return new self($providerKey, $layoutFamily, $rules);
    }

    public function issRetentionCode(bool $issRetido): string
    {
        $rule = is_array($this->rules['service.iss_withheld'] ?? null)
            ? $this->rules['service.iss_withheld']
            : [];
        $codes = is_array($rule['codes'] ?? null) ? $rule['codes'] : [];
        $key = $issRetido ? 'true' : 'false';
        $value = $codes[$key] ?? null;

        return is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : ($issRetido ? '1' : '2');
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'layout_family' => $this->layoutFamily !== '' ? $this->layoutFamily : null,
            'field_translations' => $this->rules,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultIssWithheldRule(string $providerKey, string $layoutFamily): array
    {
        $isBelem = str_contains($providerKey, 'belem');
        $isNacional = $layoutFamily === 'nacional'
            || $providerKey === ProviderRegistry::NFSE_NATIONAL_KEY
            || $providerKey === 'manaus';

        if ($isNacional) {
            return [
                'semantic_field' => 'service.iss_withheld',
                'payload_path' => 'servico.tpRetISSQN',
                'codes' => ['true' => '2', 'false' => '1'],
                'description' => 'NFSe Nacional usa 1 para ISS nao retido, 2 para ISS retido pelo tomador e 3 para ISS retido pelo intermediario.',
            ];
        }

        return [
            'semantic_field' => 'service.iss_withheld',
            'payload_path' => $isBelem ? 'servico.tpRetISSQN' : 'servico.iss_retido',
            'codes' => ['true' => '1', 'false' => '2'],
            'description' => $isBelem
                ? 'Belém usa 1 para ISS retido e 2 para ISS não retido.'
                : 'NFSe Nacional usa 1 para ISS retido e 2 para ISS não retido.',
        ];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private static function mergeRules(array $base, array $overrides): array
    {
        foreach ($overrides as $field => $override) {
            if (! is_array($override)) {
                continue;
            }

            $current = is_array($base[$field] ?? null) ? $base[$field] : [];
            $base[$field] = [
                ...$current,
                ...array_filter($override, static fn ($value, string $key): bool => $key !== 'codes', ARRAY_FILTER_USE_BOTH),
            ];

            if (is_array($override['codes'] ?? null)) {
                $base[$field]['codes'] = [
                    ...(is_array($current['codes'] ?? null) ? $current['codes'] : []),
                    ...$override['codes'],
                ];
            }
        }

        return $base;
    }

    private static function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return str_replace(["'", '`', '´'], '', $converted !== false ? $converted : $normalized);
    }
}
