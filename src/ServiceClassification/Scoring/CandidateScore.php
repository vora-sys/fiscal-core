<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Scoring;

use sabbajohn\FiscalCore\ServiceClassification\Resolution\ServiceClassificationCandidate;

final class CandidateScore
{
    /** @param array<string, int|float> $breakdown @param list<string> $rejections */
    public function __construct(
        public readonly ServiceClassificationCandidate $candidate,
        public readonly float $score,
        public readonly array $breakdown = [],
        public readonly array $rejections = [],
    ) {}

    public function accepted(): bool
    {
        return $this->rejections === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->candidate->toArray() + [
            'score' => $this->score,
            'score_breakdown' => $this->breakdown,
            'rejections' => $this->rejections,
        ];
    }
}
