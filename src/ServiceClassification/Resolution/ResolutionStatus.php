<?php

namespace sabbajohn\FiscalCore\ServiceClassification\Resolution;

enum ResolutionStatus: string
{
    case Resolved = 'resolved';
    case SelectionRequired = 'selection_required';
    case Unresolved = 'unresolved';
    case Conflict = 'conflict';
}
