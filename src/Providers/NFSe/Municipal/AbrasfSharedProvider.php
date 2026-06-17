<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

use sabbajohn\FiscalCore\Providers\NFSe\AbrasfV2Provider;

/**
 * Provider compartilhado para municípios ABRASF municipais.
 *
 * A diferenciação municipal deve ocorrer por catálogo/overrides, mantendo
 * uma base técnica única sem acoplamento a um município específico.
 */
class AbrasfSharedProvider extends AbrasfV2Provider
{
}

