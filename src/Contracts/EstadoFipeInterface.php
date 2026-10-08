<?php

namespace sabbajohn\FiscalCore\Contracts;

interface EstadoFipeInterface
{
    public function obter(string $chave): ?array;
    public function guardar(string $chave, array $dados, int $segundos): void;
    /** Reserva atômica compartilhada; inclui quota e circuit breaker. */
    public function reservar(string $fonte): bool;
    public function falhou(string $fonte, int $segundos): void;
    public function recuperou(string $fonte): void;
}
