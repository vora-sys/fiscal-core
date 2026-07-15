<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

interface NfseNacionalCncInterface
{
    public function consultarCadastroCnc(
        string $municipio,
        string $inscricaoFederal,
        ?string $inscricaoMunicipal = null,
        bool $forceRefresh = false,
    ): NacionalApiResult;
}
