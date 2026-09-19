<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

final class NfseTakerPolicyResolver
{
    public const POLICY_VERSION = 'nfse-taker-policy-v1';

    public function __construct(
        private readonly NfseNacionalIssIncidenceResolver $incidence = new NfseNacionalIssIncidenceResolver,
        private readonly NfseOperationIndicatorPolicy $operationIndicators = new NfseOperationIndicatorPolicy,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resolve(array $input): array
    {
        $provider = $this->provider((string) ($input['provider_key'] ?? ''));
        $services = array_values(array_filter((array) ($input['services'] ?? []), 'is_array'));
        $municipalPolicies = array_values(array_filter((array) ($input['municipal_policies'] ?? []), 'is_array'));
        $decisions = [];

        if ($provider !== 'nfse_nacional') {
            $decisions[] = $this->blocked(
                null,
                'NFSE_UNIDENTIFIED_TAKER_UNSUPPORTED_PROVIDER',
                'O provedor de NFS-e selecionado ainda não possui suporte homologado para emissão sem tomador.',
                'provider_capability',
            );
        } elseif ($services === []) {
            $decisions[] = $this->undetermined(null, 'O serviço ainda não foi informado para resolver a exigência de tomador.');
        } else {
            foreach ($services as $index => $service) {
                $decisions[] = $this->resolveService($service, $municipalPolicies[$index] ?? []);
            }
        }

        $blocked = array_values(array_filter($decisions, static fn (array $decision): bool => $decision['status'] === 'bloqueado'));
        $undetermined = array_values(array_filter($decisions, static fn (array $decision): bool => $decision['status'] === 'indeterminado'));
        $status = $blocked !== [] ? 'bloqueado' : ($undetermined !== [] ? 'indeterminado' : 'permitido');
        $primary = $blocked[0] ?? $undetermined[0] ?? $decisions[0];
        $warnings = [];
        foreach ($decisions as $decision) {
            $warnings = [...$warnings, ...(array) ($decision['avisos'] ?? [])];
        }
        if ($status !== 'bloqueado') {
            $warnings[] = [
                'codigo' => 'E0056',
                'mensagem' => 'Uma NFS-e sem identificação do tomador pode ter a substituição impedida conforme a parametrização municipal.',
            ];
        }
        $replacementRequirement = $this->replacementRequirement($municipalPolicies);
        $identificationRequired = array_filter(
            $decisions,
            static fn (array $decision): bool => ($decision['identificacao_obrigatoria'] ?? false) === true,
        ) !== [];
        $addressRequired = array_filter(
            $decisions,
            static fn (array $decision): bool => ($decision['endereco_obrigatorio'] ?? false) === true,
        ) !== [];

        $result = [
            'situacao' => 'nao_informado',
            'status' => $status,
            'permite_nao_informado' => $status !== 'bloqueado',
            'identificacao_obrigatoria' => $identificationRequired,
            'endereco_obrigatorio' => $addressRequired,
            'codigo_motivo' => $primary['codigo_motivo'] ?? null,
            'motivo' => $primary['motivo'] ?? null,
            'fonte' => [
                'tipo' => (string) ($primary['fonte'] ?? 'regras_oficiais_nfse_nacional'),
                'versao' => NfseNacionalIssIncidenceResolver::OFFICIAL_SOURCE_VERSION,
                'politica' => self::POLICY_VERSION,
                'parametrizacoes_municipais' => array_values(array_filter(array_map(
                    static fn (array $policy): ?array => is_array($policy['_source'] ?? null) ? $policy['_source'] : null,
                    $municipalPolicies,
                ))),
            ],
            'provider_key' => $provider,
            'fiscal_environment' => $this->environment($input['fiscal_environment'] ?? $input['ambiente_fiscal'] ?? null),
            'municipality_code' => $this->municipality($input['municipality_code'] ?? $input['codigo_municipio_prestacao'] ?? null),
            'competence' => $this->date($input['competence'] ?? $input['competencia'] ?? null),
            'avisos' => $this->uniqueWarnings($warnings),
            'servicos' => $decisions,
            'substituicao' => [
                'status' => $status === 'bloqueado'
                    ? 'nao_aplicavel'
                    : ($replacementRequirement === true
                        ? 'bloqueada_sem_identificacao'
                        : ($replacementRequirement === false ? 'permitida' : 'sujeita_parametrizacao')),
                'codigo' => 'E0056',
                'restricao' => $replacementRequirement,
                'identificacao_obrigatoria' => $replacementRequirement,
                'motivo' => $replacementRequirement === true
                    ? 'A parametrização municipal vigente exige identificação do tomador para substituir a NFS-e.'
                    : ($replacementRequirement === false
                        ? 'A parametrização municipal vigente não exige identificação do tomador para substituição.'
                        : 'A substituição de NFS-e sem tomador depende da parametrização do município emissor.'),
            ],
        ];
        $result['hash_decisao'] = hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return $result;
    }

    /** @param array<string,mixed> $service @param array<string,mixed> $municipalPolicy @return array<string,mixed> */
    private function resolveService(array $service, array $municipalPolicy): array
    {
        $code = substr($this->digits($service['codigo_tributacao_nacional'] ?? $service['cTribNac'] ?? null), 0, 6);
        $operationIndicator = substr($this->digits($service['codigo_indicador_operacao'] ?? $service['cIndOp'] ?? null), 0, 6);
        $retention = trim((string) ($service['tipo_retencao_iss'] ?? $service['tpRetISSQN'] ?? '1'));

        if ($retention === '2') {
            return $this->blocked($code, 'NFSE_TAKER_REQUIRED_FOR_RETENTION', 'A retenção do ISS pelo tomador exige sua identificação e endereço.', 'regra_retencao_iss');
        }
        if ($this->operationIndicators->requiresTaker($operationIndicator)) {
            return $this->allowedWithTakerWarning($code, $operationIndicator);
        }
        if ($code !== '' && $this->incidence->ruleForService($code) === 'tomador') {
            return $this->blocked($code, 'E0234', 'A incidência do ISS deste serviço ocorre no estabelecimento ou endereço do tomador.', 'mun_incid_info_serv');
        }
        if (($municipalPolicy['permite_nao_informado'] ?? $municipalPolicy['allows_unidentified'] ?? null) === false) {
            return $this->blocked(
                $code !== '' ? $code : null,
                (string) ($municipalPolicy['codigo_motivo'] ?? 'NFSE_TAKER_REQUIRED_BY_MUNICIPAL_POLICY'),
                (string) ($municipalPolicy['motivo'] ?? 'A parametrização municipal vigente exige identificação do tomador.'),
                'parametrizacao_municipal',
            );
        }
        if ($code === '' || $this->incidence->ruleForService($code) === null) {
            return $this->undetermined($code !== '' ? $code : null, 'Não foi possível determinar a incidência do serviço na versão vigente do catálogo oficial.');
        }

        return [
            'codigo_tributacao_nacional' => $code,
            'status' => 'permitido',
            'permite_nao_informado' => true,
            'identificacao_obrigatoria' => false,
            'endereco_obrigatorio' => false,
            'codigo_motivo' => 'NFSE_UNIDENTIFIED_TAKER_ALLOWED',
            'motivo' => 'Nenhuma regra vigente exige identificação ou endereço do tomador para o serviço informado.',
            'fonte' => 'mun_incid_info_serv',
            'avisos' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function blocked(?string $code, string $reasonCode, string $reason, string $source): array
    {
        return [
            'codigo_tributacao_nacional' => $code,
            'status' => 'bloqueado',
            'permite_nao_informado' => false,
            'identificacao_obrigatoria' => true,
            'endereco_obrigatorio' => true,
            'codigo_motivo' => $reasonCode,
            'motivo' => $reason,
            'fonte' => $source,
            'avisos' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function allowedWithTakerWarning(?string $code, string $operationIndicator): array
    {
        $requirement = $this->operationIndicators->takerRequirement($operationIndicator);

        return [
            'codigo_tributacao_nacional' => $code,
            'codigo_indicador_operacao' => $operationIndicator,
            'status' => 'permitido',
            'permite_nao_informado' => true,
            'identificacao_obrigatoria' => true,
            'endereco_obrigatorio' => true,
            'codigo_motivo' => 'E0234',
            'motivo' => $requirement['message'],
            'fonte' => 'anexo_e0234_cindop',
            'avisos' => [[
                'codigo' => 'E0234',
                'mensagem' => $requirement['message'].' A plataforma permitirá a transmissão, mas o autorizador poderá rejeitá-la.',
                'severidade' => 'warning',
                'enforcement' => 'warning',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function undetermined(?string $code, string $reason): array
    {
        return [
            'codigo_tributacao_nacional' => $code,
            'status' => 'indeterminado',
            'permite_nao_informado' => true,
            'identificacao_obrigatoria' => false,
            'endereco_obrigatorio' => false,
            'codigo_motivo' => 'NFSE_TAKER_POLICY_UNRESOLVED',
            'motivo' => $reason,
            'fonte' => 'fallback_conservado_com_aviso',
            'avisos' => [[
                'codigo' => 'NFSE_TAKER_POLICY_UNRESOLVED',
                'mensagem' => $reason.' A SEFIN ou o provedor fará a validação final.',
            ]],
        ];
    }

    private function provider(string $value): string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');

        return in_array($normalized, ['nfse_nacional', 'nfs_e_nacional', 'nacional', 'manaus'], true)
            ? 'nfse_nacional'
            : $normalized;
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', is_scalar($value) ? (string) $value : '') ?? '';
    }

    private function municipality(mixed $value): ?string
    {
        $digits = $this->digits($value);

        return strlen($digits) === 7 ? $digits : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $date = substr(trim((string) $value), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function environment(mixed $value): ?string
    {
        $environment = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($environment, ['homologacao', 'producao'], true) ? $environment : null;
    }

    /** @param list<array<string,mixed>> $policies */
    private function replacementRequirement(array $policies): ?bool
    {
        foreach ($policies as $policy) {
            foreach (['requires_identified_taker_for_replacement', 'replacement_requires_identified_taker'] as $key) {
                if (array_key_exists($key, $policy) && is_bool($policy[$key])) {
                    return $policy[$key];
                }
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $warnings @return list<array<string,mixed>> */
    private function uniqueWarnings(array $warnings): array
    {
        $unique = [];
        foreach ($warnings as $warning) {
            $key = (string) ($warning['codigo'] ?? '').'|'.(string) ($warning['mensagem'] ?? '');
            $unique[$key] = $warning;
        }

        return array_values($unique);
    }
}
