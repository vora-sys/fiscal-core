<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceCandidateScorer;
use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceClassificationCatalog;
use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceClassificationResolver;
use sabbajohn\FiscalCore\ServiceClassification\Scoring\CandidateScore;
use sabbajohn\FiscalCore\ServiceClassification\Validation\NormalizedCode;

final class DefaultServiceClassificationResolver implements ServiceClassificationResolver
{
    public function __construct(
        private readonly ServiceClassificationCatalog $catalog,
        private readonly ServiceCandidateScorer $scorer,
        private readonly float $automaticThreshold = 60,
        private readonly float $minimumLead = 20,
    ) {}

    public function resolve(ResolveServiceClassificationInput $input): ServiceClassificationResolution
    {
        $resolutionId = $this->uuid();
        $catalogCandidates = $this->territoriallyCompatible($this->catalog->candidates($input), $input);
        $scores = array_map(fn (ServiceClassificationCandidate $candidate): CandidateScore => $this->scorer->score($candidate, $input), $catalogCandidates);
        $accepted = array_values(array_filter($scores, static fn (CandidateScore $score): bool => $score->accepted()));
        usort($accepted, static fn (CandidateScore $left, CandidateScore $right): int => $right->score <=> $left->score);

        if ($accepted === []) {
            return new ServiceClassificationResolution(
                ResolutionStatus::Unresolved,
                ResolutionConfidence::Unknown,
                null,
                [],
                array_map(static fn (CandidateScore $score): array => $score->toArray(), $scores),
                [],
                [['code' => 'SERVICE_CLASSIFICATION_NOT_FOUND', 'message' => 'Não foi encontrada classificação vigente para os dados informados.', 'severity' => 'warning']],
                $this->missing($input),
                $resolutionId,
            );
        }

        $first = $accepted[0];
        $isSingle = count($accepted) === 1;
        $second = $accepted[1] ?? null;
        $explicitExact = $this->hasExplicitExactMatch($first);
        $automatic = $isSingle && $first->score >= $this->automaticThreshold;
        $confidence = $explicitExact && $isSingle
            ? ResolutionConfidence::Exact
            : ($automatic ? ResolutionConfidence::High : ($first->score >= 30 ? ResolutionConfidence::Medium : ResolutionConfidence::Low));

        if (! $isSingle || (! $automatic && $confidence !== ResolutionConfidence::Exact)) {
            return new ServiceClassificationResolution(
                ResolutionStatus::SelectionRequired,
                $confidence,
                null,
                [],
                array_map(static fn (CandidateScore $score): array => $score->toArray(), $accepted),
                [['method' => 'weighted_candidate_scoring', 'top_score' => $first->score, 'lead' => $second === null ? null : $first->score - $second->score]],
                [['code' => 'SERVICE_CLASSIFICATION_AMBIGUOUS', 'message' => 'Há mais de uma classificação possível para o serviço informado.', 'severity' => 'warning']],
                $this->missingForCandidates($input, array_map(static fn (CandidateScore $score): ServiceClassificationCandidate => $score->candidate, $accepted)),
                $resolutionId,
            );
        }

        $candidate = $first->candidate;
        $missingCandidateFields = $this->missingCandidateFields($candidate);
        if ($missingCandidateFields !== []) {
            return new ServiceClassificationResolution(
                ResolutionStatus::Unresolved,
                ResolutionConfidence::Unknown,
                null,
                [],
                [$first->toArray()],
                [['method' => 'incomplete_catalog_candidate', 'candidate_id' => $candidate->id]],
                [['code' => 'SERVICE_CLASSIFICATION_INCOMPLETE', 'message' => 'A correlação encontrada não contém NBS, IndOp e cClassTrib completos.', 'severity' => 'warning']],
                $missingCandidateFields,
                $resolutionId,
            );
        }
        $fields = $this->fields($candidate, $confidence, $first);
        $warnings = [...$this->sourceWarnings($candidate), ...$this->rateWarnings($input, $candidate)];

        return new ServiceClassificationResolution(
            ResolutionStatus::Resolved,
            $confidence,
            $candidate,
            $fields,
            [],
            [['method' => 'weighted_candidate_scoring', 'score' => $first->score, 'score_breakdown' => $first->breakdown]],
            $warnings,
            [],
            $resolutionId,
        );
    }

    /**
     * @param  list<ServiceClassificationCandidate>  $candidates
     * @return list<ServiceClassificationCandidate>
     */
    private function territoriallyCompatible(array $candidates, ResolveServiceClassificationInput $input): array
    {
        if (NormalizedCode::municipality($input->executionMunicipalityCode) === null) {
            return $candidates;
        }

        return array_values(array_filter($candidates, function (ServiceClassificationCandidate $candidate) use ($input): bool {
            $role = $candidate->metadata['operation_indicator']['location_role'] ?? null;
            if (! is_string($role) || $role === '' || $role === 'other') {
                return true;
            }
            $execution = NormalizedCode::municipality($input->executionMunicipalityCode);
            $expected = match ($role) {
                'provider' => NormalizedCode::municipality($input->providerMunicipalityCode),
                'taker' => NormalizedCode::municipality($input->customerMunicipalityCode),
                'recipient' => NormalizedCode::municipality($input->recipientMunicipalityCode),
                'property' => NormalizedCode::municipality($input->propertyMunicipalityCode),
                default => null,
            };
            if ($role === 'service_location') {
                $provider = NormalizedCode::municipality($input->providerMunicipalityCode);

                return $provider === null || $execution !== $provider;
            }

            return $expected === null || $execution === $expected;
        }));
    }

    /** @return list<string> */
    private function missingCandidateFields(ServiceClassificationCandidate $candidate): array
    {
        $missing = [];
        foreach ([
            'nbs_code' => $candidate->nbsCode,
            'operation_indicator_code' => $candidate->operationIndicatorCode,
            'tax_classification_code' => $candidate->taxClassificationCode,
        ] as $field => $value) {
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** @param list<ServiceClassificationCandidate> $candidates @return list<string> */
    private function missingForCandidates(ResolveServiceClassificationInput $input, array $candidates): array
    {
        $missing = [];
        $roles = array_values(array_unique(array_filter(array_map(
            static fn (ServiceClassificationCandidate $candidate): ?string => is_string($candidate->metadata['operation_indicator']['location_role'] ?? null)
                ? $candidate->metadata['operation_indicator']['location_role']
                : null,
            $candidates,
        ))));
        if (count($roles) > 1 && NormalizedCode::municipality($input->executionMunicipalityCode) === null) {
            $missing[] = 'execution_municipality_code';
        }
        foreach ([
            'provider' => ['provider_municipality_code', $input->providerMunicipalityCode],
            'taker' => ['customer_municipality_code', $input->customerMunicipalityCode],
            'recipient' => ['recipient_municipality_code', $input->recipientMunicipalityCode],
            'property' => ['property_municipality_code', $input->propertyMunicipalityCode],
        ] as $role => [$field, $value]) {
            if (in_array($role, $roles, true) && NormalizedCode::municipality($value) === null) {
                $missing[] = $field;
            }
        }

        return array_values(array_unique($missing));
    }

    private function hasExplicitExactMatch(CandidateScore $score): bool
    {
        return isset($score->breakdown['national_tax_exact'])
            || isset($score->breakdown['municipal_tax_exact'])
            || isset($score->breakdown['lc116_exact'])
            || isset($score->breakdown['nbs_exact'])
            || isset($score->breakdown['operation_indicator_exact'])
            || isset($score->breakdown['tax_classification_exact']);
    }

    /** @return array<string, ResolvedField> */
    private function fields(ServiceClassificationCandidate $candidate, ResolutionConfidence $confidence, CandidateScore $score): array
    {
        $values = [
            'lc116_code' => $candidate->lc116Code,
            'national_tax_code' => $candidate->nationalTaxCode,
            'municipal_tax_code' => $candidate->municipalTaxCode,
            'original_municipal_code' => $candidate->originalMunicipalCode,
            'nbs_code' => $candidate->nbsCode,
            'operation_indicator_code' => $candidate->operationIndicatorCode,
            'tax_classification_code' => $candidate->taxClassificationCode,
            'iss_rate' => $candidate->issRate,
            'iss_withholding' => $candidate->issWithholding,
            'iss_exigibility' => $candidate->issExigibility,
        ];
        $fields = [];
        foreach ($values as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $fieldSource = is_array($candidate->metadata['field_sources'][$name] ?? null)
                ? $candidate->metadata['field_sources'][$name]
                : [];
            $fields[$name] = new ResolvedField(
                $name,
                $value,
                $confidence,
                (string) ($fieldSource['source'] ?? $candidate->source),
                isset($fieldSource['version']) ? (string) $fieldSource['version'] : $candidate->sourceVersion,
                'weighted_candidate_scoring',
                $confidence->isAutomatic(),
                ['candidate_id' => $candidate->id, 'score_breakdown' => $score->breakdown],
            );
        }

        return $fields;
    }

    /** @return list<array<string, mixed>> */
    private function rateWarnings(ResolveServiceClassificationInput $input, ServiceClassificationCandidate $candidate): array
    {
        if ($input->issRate === null || $candidate->issRate === null || abs($input->issRate - $candidate->issRate) < 0.0001) {
            return [];
        }

        return [[
            'code' => 'ISS_RATE_CONFLICT',
            'message' => sprintf('A alíquota informada é %s%%, mas a parametrização vigente indica %s%%.', $input->issRate, $candidate->issRate),
            'severity' => 'warning',
            'provided' => $input->issRate,
            'expected' => $candidate->issRate,
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function sourceWarnings(ServiceClassificationCandidate $candidate): array
    {
        $status = $candidate->metadata['sync_status'] ?? null;
        if (! in_array($status, ['stale', 'unavailable', 'invalid'], true)) {
            return [];
        }

        if ($status !== 'stale') {
            return [[
                'code' => 'MUNICIPAL_PARAMETERS_UNAVAILABLE',
                'message' => 'A parametrização municipal não está disponível para esta fonte; somente os demais catálogos foram considerados.',
                'severity' => 'warning',
            ]];
        }

        return [[
            'code' => 'MUNICIPAL_PARAMETERS_STALE',
            'message' => 'A última parametrização municipal válida foi usada como fallback e está defasada.',
            'severity' => 'warning',
            'fetched_at' => $candidate->metadata['fetched_at'] ?? null,
        ]];
    }

    /** @return list<string> */
    private function missing(ResolveServiceClassificationInput $input): array
    {
        $missing = [];
        if ($input->serviceDescription === null || trim($input->serviceDescription) === '') {
            $missing[] = 'service_description';
        }
        if ($input->lc116Code === null && $input->nationalTaxCode === null && $input->nbsCode === null) {
            $missing[] = 'lc116_code_or_national_tax_code_or_nbs_code';
        }

        return $missing;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
