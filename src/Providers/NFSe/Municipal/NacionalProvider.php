<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Providers\NFSe\Municipal;

use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider as BaseNacionalProvider;

/**
 * Alias municipal para o fluxo nacional da NFS-e.
 *
 * Mantido por compatibilidade com layouts do Uninfe enquanto
 * os municipios usam a chave canonica `nfse_nacional`.
 */
final class NacionalProvider extends BaseNacionalProvider
{
}
