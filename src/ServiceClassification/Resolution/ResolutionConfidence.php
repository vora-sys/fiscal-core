<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

enum ResolutionConfidence: string
{
    case Exact = 'exact';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    public function isAutomatic(): bool
    {
        return $this === self::Exact || $this === self::High;
    }
}
