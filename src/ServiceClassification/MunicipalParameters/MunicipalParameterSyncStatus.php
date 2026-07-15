<?php

namespace sabbajohn\FiscalCore\ServiceClassification\MunicipalParameters;

enum MunicipalParameterSyncStatus: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unavailable = 'unavailable';
    case Invalid = 'invalid';
}
