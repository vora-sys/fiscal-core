<?php

namespace sabbajohn\FiscalCore\Contracts;

interface NotaServicoInterface
{
    public function emitir(array $dados): string;

    public function consultar(string $chave): NFSeConsultaResultInterface;

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool;

    public function substituir(string $chave, array $dados): string;
}
