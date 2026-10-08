<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\Fipe\ConsultaFipe;
use sabbajohn\FiscalCore\Fipe\OrcamentoFipe;

interface ProvedorFipeInterface
{
    public function fonte(): string;
    /** Retorna uma linha normalizada, do mês exato solicitado. */
    public function consultar(ConsultaFipe $consulta, OrcamentoFipe $orcamento): array;
}
