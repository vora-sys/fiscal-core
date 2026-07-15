<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

interface NfseNacionalDistribuicaoInterface
{
    public function distribuirDfe(string $nsu, ?string $cnpjConsulta = null, bool $lote = true): NacionalApiResult;

    public function consultarEventosDistribuidos(string $chaveAcesso): NacionalApiResult;
}
