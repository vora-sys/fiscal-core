<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class NfseNacionalCanonicalContract
{
    public const INVALID_MESSAGE = 'Payload inválido para NFSe Nacional. Use somente o contrato canônico PT-BR.';

    /**
     * @return list<string>
     */
    public static function expectedFields(): array
    {
        return NfseNacionalCanonicalPayload::expectedRootFields();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function assertCanonical(array $payload): void
    {
        $issues = NfseNacionalCanonicalPayload::validationIssues($payload);
        if ($issues === []) {
            return;
        }

        throw new NfseNacionalContractException(
            self::expectedFields(),
            array_column($issues, 'path'),
            $issues,
        );
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public static function canonicalizeProviderPolicy(array $policy): array
    {
        $policy = NfseNacionalCanonicalFormPolicy::canonicalize($policy);
        $allowed = array_flip([
            NfseNacionalCanonicalFormPolicy::FIELD_SERVICE_MUNICIPAL_CODE,
            NfseNacionalCanonicalFormPolicy::FIELD_SERVICE_NATIONAL_TAX_CODE,
            NfseNacionalCanonicalFormPolicy::FIELD_SERVICE_NBS,
            NfseNacionalCanonicalFormPolicy::FIELD_PRESTADOR_OP_SIMP_NAC,
        ]);

        foreach (['required_fields', 'visible_fields'] as $key) {
            $policy[$key] = array_values(array_filter(
                array_map('strval', (array) ($policy[$key] ?? [])),
                static fn (string $field): bool => isset($allowed[$field])
            ));
        }

        foreach (['default_values', 'field_schema', 'labels', 'hints', 'enum_fields'] as $key) {
            $policy[$key] = array_intersect_key((array) ($policy[$key] ?? []), $allowed);
        }

        return $policy;
    }
}
