<?php

namespace sabbajohn\FiscalCore\Contracts;

/** Consultas opcionais; preserva o contrato mínimo dos adapters existentes. */
interface ConsultaPublicaComplementarInterface
{
    public function consultarCEPv2(string $cep): array;

    public function listarTabelasReferenciaFipe(): array;

    public function consultarPrecoFipe(string $codigoFipe, ?int $tabelaDeReferencia = null): array;

    public function consultarMarcasPorTipoVeiculo(string $tipoVeiculo, ?int $tabelaDeReferencia = null): array;
}
