<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

enum TaxRateSource: string
{
    case Explicit = 'explicit';
    case MunicipalParameter = 'municipal_parameter';
    case CompanyConfiguration = 'company_configuration';
    case ServiceConfiguration = 'service_configuration';
    case PreviousConfirmedResolution = 'previous_confirmed_resolution';
    case Manual = 'manual';
}
