<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Scoring;

use sabbajohn\FiscalCore\ServiceClassification\Contracts\ServiceCandidateScorer;
use sabbajohn\FiscalCore\ServiceClassification\Resolution\ResolveServiceClassificationInput;
use sabbajohn\FiscalCore\ServiceClassification\Resolution\ServiceClassificationCandidate;
use sabbajohn\FiscalCore\ServiceClassification\Validation\NormalizedCode;

final class WeightedServiceCandidateScorer implements ServiceCandidateScorer
{
    /** @param array<string, int|float> $weights */
    public function __construct(private readonly array $weights = []) {}

    public function score(ServiceClassificationCandidate $candidate, ResolveServiceClassificationInput $input): CandidateScore
    {
        $weights = array_replace([
            'national_tax_exact' => 40,
            'municipal_tax_exact' => 50,
            'lc116_exact' => 30,
            'nbs_exact' => 50,
            'description_similarity' => 25,
            'cnae_compatible' => 10,
            'same_municipality' => 20,
            'confirmed_history' => 35,
            'tax_regime_incompatible' => -50,
        ], $this->weights);
        $breakdown = [];
        $rejections = [];
        $competence = $input->competence->format('Y-m-d');

        if ($candidate->validFrom !== null && $competence < $candidate->validFrom->format('Y-m-d')) {
            $rejections[] = 'competence_before_validity';
        }
        if ($candidate->validUntil !== null && $competence > $candidate->validUntil->format('Y-m-d')) {
            $rejections[] = 'competence_after_validity';
        }
        if ($candidate->municipalityCode !== null && NormalizedCode::municipality($candidate->municipalityCode) !== NormalizedCode::municipality($input->municipalityCode)) {
            $rejections[] = 'municipality_incompatible';
        }

        $this->exact($breakdown, 'national_tax_exact', NormalizedCode::nationalTax($input->nationalTaxCode), NormalizedCode::nationalTax($candidate->nationalTaxCode), $weights);
        $this->exact($breakdown, 'municipal_tax_exact', NormalizedCode::municipalTax($input->municipalTaxCode), NormalizedCode::municipalTax($candidate->municipalTaxCode), $weights);
        $this->exact($breakdown, 'lc116_exact', NormalizedCode::lc116($input->lc116Code), NormalizedCode::lc116($candidate->lc116Code), $weights);
        $this->exact($breakdown, 'nbs_exact', NormalizedCode::nbs($input->nbsCode), NormalizedCode::nbs($candidate->nbsCode), $weights);

        if ($candidate->municipalityCode !== null) {
            $breakdown['same_municipality'] = $weights['same_municipality'];
        }
        if (($candidate->metadata['confirmed'] ?? false) === true) {
            $breakdown['confirmed_history'] = $weights['confirmed_history'];
        }
        $candidateRegimes = array_values(array_filter((array) ($candidate->metadata['tax_regimes'] ?? []), 'is_string'));
        if ($input->taxRegime !== null && $candidateRegimes !== [] && ! in_array($input->taxRegime, $candidateRegimes, true)) {
            $breakdown['tax_regime_incompatible'] = $weights['tax_regime_incompatible'];
        }
        if ($input->cnae !== null && in_array(NormalizedCode::cnae($input->cnae), (array) ($candidate->metadata['cnae_codes'] ?? []), true)) {
            $breakdown['cnae_compatible'] = $weights['cnae_compatible'];
        }

        $similarity = $this->descriptionSimilarity($input->serviceDescription, $candidate->description);
        if ($similarity > 0) {
            $breakdown['description_similarity'] = round($weights['description_similarity'] * $similarity, 2);
        }

        return new CandidateScore($candidate, array_sum($breakdown), $breakdown, $rejections);
    }

    /** @param array<string, int|float> $breakdown @param array<string, int|float> $weights */
    private function exact(array &$breakdown, string $key, ?string $provided, ?string $candidate, array $weights): void
    {
        if ($provided !== null && $candidate !== null && hash_equals($provided, $candidate)) {
            $breakdown[$key] = $weights[$key];
        }
    }

    private function descriptionSimilarity(?string $left, ?string $right): float
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $union = array_unique([...$leftTokens, ...$rightTokens]);

        return count($intersection) / max(1, count($union));
    }

    /** @return list<string> */
    private function tokens(?string $value): array
    {
        $normalized = mb_strtolower(trim((string) $value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => strlen($token) > 2)));
    }
}
