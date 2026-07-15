<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Contracts;

use sabbajohn\FiscalCore\ServiceClassification\Resolution\ResolveServiceClassificationInput;
use sabbajohn\FiscalCore\ServiceClassification\Resolution\ServiceClassificationResolution;

interface ServiceClassificationResolver
{
    public function resolve(ResolveServiceClassificationInput $input): ServiceClassificationResolution;
}
