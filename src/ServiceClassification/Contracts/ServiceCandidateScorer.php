<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Contracts;

use sabbajohn\FiscalCore\ServiceClassification\Resolution\ResolveServiceClassificationInput;
use sabbajohn\FiscalCore\ServiceClassification\Resolution\ServiceClassificationCandidate;
use sabbajohn\FiscalCore\ServiceClassification\Scoring\CandidateScore;

interface ServiceCandidateScorer
{
    public function score(ServiceClassificationCandidate $candidate, ResolveServiceClassificationInput $input): CandidateScore;
}
