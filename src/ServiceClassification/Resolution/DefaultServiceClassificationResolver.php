<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceCandidateScorer;
use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceClassificationCatalog;
use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceClassificationResolver;
use sabbajohn\FiscalCore\ServiceClassification\Scoring\CandidateScore;

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
        $scores = array_map(fn (ServiceClassificationCandidate $candidate): CandidateScore => $this->scorer->score($candidate, $input), $this->catalog->candidates($input));
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
        $second = $accepted[1] ?? null;
        $isSingle = count($accepted) === 1;
        $hasLead = $second === null || ($first->score - $second->score) >= $this->minimumLead;
        $explicitExact = $this->hasExplicitExactMatch($first);
        $automatic = ($isSingle && $first->score >= $this->automaticThreshold) || ($first->score >= $this->automaticThreshold && $hasLead);
        $confidence = $explicitExact && $isSingle
            ? ResolutionConfidence::Exact
            : ($automatic ? ResolutionConfidence::High : ($first->score >= 30 ? ResolutionConfidence::Medium : ResolutionConfidence::Low));

        if (! $automatic && $confidence !== ResolutionConfidence::Exact) {
            return new ServiceClassificationResolution(
                ResolutionStatus::SelectionRequired,
                $confidence,
                null,
                [],
                array_map(static fn (CandidateScore $score): array => $score->toArray(), $accepted),
                [['method' => 'weighted_candidate_scoring', 'top_score' => $first->score, 'lead' => $second === null ? null : $first->score - $second->score]],
                [['code' => 'SERVICE_CLASSIFICATION_AMBIGUOUS', 'message' => 'Há mais de uma classificação possível para o serviço informado.', 'severity' => 'warning']],
                [],
                $resolutionId,
            );
        }

        $candidate = $first->candidate;
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

    private function hasExplicitExactMatch(CandidateScore $score): bool
    {
        return isset($score->breakdown['national_tax_exact'])
            || isset($score->breakdown['municipal_tax_exact'])
            || isset($score->breakdown['lc116_exact'])
            || isset($score->breakdown['nbs_exact']);
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
