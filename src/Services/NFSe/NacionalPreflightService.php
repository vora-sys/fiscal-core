<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Contracts\NfseNacionalPreflightInterface;

final class NacionalPreflightService implements NfseNacionalPreflightInterface
{
    public function __construct(
        private readonly NacionalParametrizacaoService $parametrizacao,
        private readonly NacionalCncService $cnc,
        private readonly NacionalPreflightBatchExecutor $batch,
    ) {}

    public function consultar(
        string $municipioEmissao,
        string $municipioIncidencia,
        string $servico,
        string $competencia,
        ?string $beneficio = null,
        bool $incluirRegimesEspeciais = false,
        ?string $inscricaoFederal = null,
        ?string $inscricaoMunicipal = null,
    ): array {
        $requests = $this->parametrizacao->preflightRequests(
            $municipioEmissao,
            $municipioIncidencia,
            $servico,
            $competencia,
            $beneficio,
            $incluirRegimesEspeciais,
        );

        if ($inscricaoFederal !== null) {
            $requests['cnc'] = $this->cnc->preflightRequest(
                $municipioEmissao,
                $inscricaoFederal,
                $inscricaoMunicipal,
            );
        }

        return $this->batch->execute($requests);
    }
}
