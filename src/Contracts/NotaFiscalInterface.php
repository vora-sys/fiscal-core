<?php

namespace sabbajohn\FiscalCore\Contracts;

interface NotaFiscalInterface
{
    public function emitir(array $dados): string;

    public function consultar(string $chave): string;

    public function cancelar(string $chave, string $motivo, string $protocolo): string;

    public function inutilizar(int $ano, int $cnpj, int $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa): string;

    public function consultaNotasEmitidasParaEstabelecimento(int $ultimoNsu = 0, int $numNSU = 0, ?string $chave = null, string $fonte = 'AN'): string;

    public function sefazStatus(string $uf = '', ?int $ambiente = null, bool $ignorarContigencia = true): string;
}
