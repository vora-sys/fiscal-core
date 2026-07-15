<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Contracts;

use sabbajohn\FiscalCore\ServiceClassification\Resolution\ResolveServiceClassificationInput;
use sabbajohn\FiscalCore\ServiceClassification\Resolution\ServiceClassificationCandidate;

interface ServiceClassificationCatalog
{
    /** @return list<ServiceClassificationCandidate> */
    public function candidates(ResolveServiceClassificationInput $input): array;
}
