<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class NfseNacionalTaxRegimeResolver
{
    /**
     * @var list<string>
     */
    private const MEI_REGIME_KEYS = [
        'mei',
        'microempreendedor individual',
        'microempreendedor-individual',
        'microempreendedor_individual',
    ];

    /**
     * @var list<string>
     */
    private const SIMPLES_REGIME_KEYS = [
        'simples',
        'simples nacional',
        'simples-nacional',
        'simples_nacional',
        ...self::MEI_REGIME_KEYS,
    ];

    /**
     * @var list<string>
     */
    private const SIMPLES_NON_MEI_REGIME_KEYS = [
        'simples',
        'simples nacional',
        'simples-nacional',
        'simples_nacional',
    ];

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    public static function opSimpNac(
        array $source,
        array $empresaConfig = [],
        ?bool $mei = null,
        ?bool $simplesNacional = null
    ): string {
        $explicit = self::firstOpSimpNacCode([
            $source['opSimpNac'] ?? null,
            $source['op_simp_nac'] ?? null,
            $source['opcao_simples_nacional'] ?? null,
            self::dataGet($empresaConfig, 'nfse.op_simp_nac'),
            self::dataGet($empresaConfig, 'op_simp_nac'),
        ]);
        if ($explicit !== null) {
            return $explicit;
        }

        $regimeTributario = self::regimeTributario($source, $empresaConfig);
        $crt = self::crt($source, $empresaConfig);
        $mei ??= self::mei($source, $empresaConfig, $regimeTributario, $crt);
        $simplesNacional ??= self::simplesNacional($source, $empresaConfig, $regimeTributario, $crt, $mei);

        if ($mei === true) {
            return '2';
        }

        return $simplesNacional ? '3' : '1';
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    public static function mei(
        array $source,
        array $empresaConfig = [],
        ?string $regimeTributario = null,
        ?string $crt = null
    ): ?bool {
        $explicit = self::nullableBoolean(self::firstBooleanCandidate([
            $source['mei'] ?? null,
            $source['is_mei'] ?? null,
            $source['microempreendedor_individual'] ?? null,
            $source['opcao_pelo_mei'] ?? null,
            $source['opcaoPeloMei'] ?? null,
            self::dataGet($empresaConfig, 'is_mei'),
            self::dataGet($empresaConfig, 'mei'),
            self::dataGet($empresaConfig, 'microempreendedor_individual'),
            self::dataGet($empresaConfig, 'opcao_pelo_mei'),
            self::dataGet($empresaConfig, 'opcaoPeloMei'),
        ]));
        if ($explicit !== null) {
            return $explicit;
        }

        $regimeKey = self::normalizeKey($regimeTributario ?? self::regimeTributario($source, $empresaConfig));
        if (in_array($regimeKey, self::MEI_REGIME_KEYS, true)) {
            return true;
        }

        return (int) ($crt ?? self::crt($source, $empresaConfig)) === 4 ? true : null;
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    public static function simplesNacional(
        array $source,
        array $empresaConfig = [],
        ?string $regimeTributario = null,
        ?string $crt = null,
        ?bool $mei = null
    ): bool {
        if ($mei === true) {
            return true;
        }

        $explicit = self::nullableBoolean(self::firstBooleanCandidate([
            $source['simples_nacional'] ?? null,
            $source['is_simple_national'] ?? null,
            $source['simple_national'] ?? null,
            $source['optante_simples_nacional'] ?? null,
            $source['opcao_pelo_simples'] ?? null,
            $source['opcaoPeloSimples'] ?? null,
            self::booleanLikeSimpleOption($source['opcao_simples_nacional'] ?? null),
            self::dataGet($empresaConfig, 'is_simple_national'),
            self::dataGet($empresaConfig, 'simples_nacional'),
            self::dataGet($empresaConfig, 'simple_national'),
            self::dataGet($empresaConfig, 'optante_simples_nacional'),
            self::dataGet($empresaConfig, 'opcao_pelo_simples'),
            self::dataGet($empresaConfig, 'opcaoPeloSimples'),
        ]));
        if ($explicit !== null) {
            return $explicit;
        }

        $explicitOptionCode = self::firstOpSimpNacCode([
            $source['opSimpNac'] ?? null,
            $source['op_simp_nac'] ?? null,
            $source['opcao_simples_nacional'] ?? null,
            self::dataGet($empresaConfig, 'nfse.op_simp_nac'),
            self::dataGet($empresaConfig, 'op_simp_nac'),
        ]);
        if ($explicitOptionCode !== null) {
            return in_array($explicitOptionCode, ['2', '3', '4'], true);
        }

        $crtValue = (int) ($crt ?? self::crt($source, $empresaConfig));
        if (in_array($crtValue, [1, 2], true)) {
            return true;
        }

        return in_array(
            self::normalizeKey($regimeTributario ?? self::regimeTributario($source, $empresaConfig)),
            self::SIMPLES_REGIME_KEYS,
            true
        );
    }

    public static function shouldUseSimplesNacionalRegime(?bool $mei, bool $simplesNacional, string $regimeTributario): bool
    {
        return $mei !== true
            && $simplesNacional
            && ! in_array(self::normalizeKey($regimeTributario), self::SIMPLES_NON_MEI_REGIME_KEYS, true);
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    public static function regApTribSN(array $source, array $empresaConfig = [], ?string $opSimpNac = null): ?string
    {
        $explicit = self::firstRegApTribSNCode([
            $source['regApTribSN'] ?? null,
            $source['reg_ap_trib_sn'] ?? null,
            $source['regime_apuracao_sn'] ?? null,
            self::dataGet($empresaConfig, 'nfse.regApTribSN'),
            self::dataGet($empresaConfig, 'nfse.reg_ap_trib_sn'),
            self::dataGet($empresaConfig, 'nfse.regime_apuracao_sn'),
            self::dataGet($empresaConfig, 'regApTribSN'),
            self::dataGet($empresaConfig, 'reg_ap_trib_sn'),
            self::dataGet($empresaConfig, 'regime_apuracao_sn'),
        ]);
        if ($explicit !== null) {
            return $explicit;
        }

        return ($opSimpNac ?? self::opSimpNac($source, $empresaConfig)) === '3' ? '1' : null;
    }

    public static function shouldSuppressPAliq(?string $opSimpNac, ?string $regApTribSN, ?string $tpRetISSQN, mixed $benefitType = null): bool
    {
        $opSimpNac = trim((string) $opSimpNac);
        $regApTribSN = trim((string) ($regApTribSN ?? ''));
        if ($regApTribSN === '' && $opSimpNac === '3') {
            $regApTribSN = '1';
        }

        if ($opSimpNac !== '3' || $regApTribSN !== '1' || trim((string) ($tpRetISSQN ?? '1')) !== '1') {
            return false;
        }

        return ! in_array(self::normalizeBenefitType($benefitType), ['1', '4', 'isencao', 'aliquota_diferenciada'], true);
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    public static function regimeTributario(array $source, array $empresaConfig = []): string
    {
        return self::firstString([
            $source['regimeTributario'] ?? null,
            $source['regime_tributario'] ?? null,
            self::dataGet($empresaConfig, 'regime_tributario'),
            self::dataGet($empresaConfig, 'tax_regime'),
        ]) ?? '';
    }

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $empresaConfig
     */
    private static function crt(array $source, array $empresaConfig): string
    {
        return self::firstString([
            $source['crt'] ?? null,
            self::dataGet($empresaConfig, 'crt'),
        ]) ?? '';
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private static function firstOpSimpNacCode(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_bool($candidate) || ! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if (in_array($value, ['1', '2', '3', '4'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private static function firstRegApTribSNCode(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_bool($candidate) || ! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if (in_array($value, ['1', '2', '3'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private static function firstBooleanCandidate(array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $candidates
     */
    private static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_bool($candidate) || ! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private static function booleanLikeSimpleOption(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (self::normalizeKey($value)) {
            'true', 'yes', 'sim' => true,
            'false', 'no', 'nao' => false,
            default => null,
        };
    }

    private static function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        return str_replace(["'", '`', '´'], '', $converted !== false ? $converted : $normalized);
    }

    private static function normalizeBenefitType(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = self::normalizeKey((string) $value);
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'isencao_iss' => 'isencao',
            'aliquota', 'aliquota_dif', 'aliquota_diferenciada' => 'aliquota_diferenciada',
            default => $normalized,
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function dataGet(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
