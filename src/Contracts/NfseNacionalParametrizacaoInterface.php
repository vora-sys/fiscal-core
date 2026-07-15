<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

interface NfseNacionalParametrizacaoInterface
{
    public function consultarAliquota(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult;

    public function consultarHistoricoAliquotas(string $municipio, string $servico, bool $forceRefresh = false): NacionalApiResult;

    public function consultarBeneficio(string $municipio, string $beneficio, string $competencia, bool $forceRefresh = false): NacionalApiResult;

    public function consultarConvenio(string $municipio, bool $forceRefresh = false): NacionalApiResult;

    public function consultarRegimesEspeciais(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult;

    public function consultarRetencoes(string $municipio, string $competencia, bool $forceRefresh = false): NacionalApiResult;
}
