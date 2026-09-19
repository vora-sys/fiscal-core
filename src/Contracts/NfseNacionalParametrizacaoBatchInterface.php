<?php

namespace sabbajohn\FiscalCore\Contracts;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

interface NfseNacionalParametrizacaoBatchInterface extends NfseNacionalParametrizacaoInterface
{
    /**
     * @return array{
     *     convenio:NacionalApiResult,
     *     aliquota:NacionalApiResult,
     *     retencoes:NacionalApiResult,
     *     beneficio?:NacionalApiResult,
     *     regimes_especiais?:NacionalApiResult
     * }
     */
    public function consultarPreflight(
        string $municipioEmissao,
        string $municipioIncidencia,
        string $servico,
        string $competencia,
        ?string $beneficio = null,
        bool $incluirRegimesEspeciais = false,
    ): array;
}
