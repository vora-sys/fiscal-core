<?php

namespace sabbajohn\FiscalCore\Contracts;

interface ConsultaPublicaInterface
{
    public function consultarCEP(string $cep): array;

    public function consultarCNPJ(string $cnpj): array;

    public function consultarBanco(string $codigo): array;

    public function listarBancos(): array;

    public function consultaNcm(string $ncm): array;

    public function pesquisarNcm(string $descricao = ''): array;

    public function listarNcms(): array;
}
