<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

interface NfseNacionalPreflightInterface
{
    /**
     * @return array{
     *     convenio:NacionalApiResult,
     *     aliquota:NacionalApiResult,
     *     retencoes:NacionalApiResult,
     *     beneficio?:NacionalApiResult,
     *     regimes_especiais?:NacionalApiResult,
     *     cnc?:NacionalApiResult
     * }
     */
    public function consultar(
        string $municipioEmissao,
        string $municipioIncidencia,
        string $servico,
        string $competencia,
        ?string $beneficio = null,
        bool $incluirRegimesEspeciais = false,
        ?string $inscricaoFederal = null,
        ?string $inscricaoMunicipal = null,
    ): array;
}
