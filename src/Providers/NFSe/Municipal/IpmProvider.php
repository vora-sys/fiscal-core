<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

/**
 * Base compartilhada para municipios IPM/AtendeNet.
 *
 * Mantemos o comportamento ABRASF compartilhado como fallback operacional
 * ate a especializacao por municipio quando houver homologacao real.
 */
class IpmProvider extends AbrasfSharedProvider {}
