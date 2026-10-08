<?php

namespace sabbajohn\FiscalCore\Fipe;

use sabbajohn\FiscalCore\Contracts\EstadoFipeInterface;

/** Standalone: estado por instância. Web workers devem injetar armazenamento compartilhado. */
final class EstadoFipeMemoria implements EstadoFipeInterface
{
    private array $cache = [];
    private array $circuitos = [];
    private array $contadores = [];

    public function obter(string $chave): ?array
    {
        return ($this->cache[$chave]['ate'] ?? 0) > time() ? $this->cache[$chave]['dados'] : null;
    }

    public function guardar(string $chave, array $dados, int $segundos): void
    {
        $this->cache[$chave] = ['dados' => $dados, 'ate' => time() + $segundos];
    }

    public function reservar(string $fonte): bool
    {
        if (($this->circuitos[$fonte] ?? 0) > time()) {
            return false;
        }
        $agora = microtime(true);
        $chamadas = array_values(array_filter($this->contadores[$fonte] ?? [], static fn ($t): bool => $t > $agora - 86400));
        if (count($chamadas) >= ($fonte === 'fipe_direta' ? 60 : 450)
            || count(array_filter($chamadas, static fn ($t): bool => $t > $agora - 60)) >= ($fonte === 'fipe_direta' ? 6 : 30)) {
            return false;
        }
        $chamadas[] = $agora;
        $this->contadores[$fonte] = $chamadas;

        return true;
    }

    public function falhou(string $fonte, int $segundos): void
    {
        $this->circuitos[$fonte] = time() + $segundos;
    }
    public function recuperou(string $fonte): void
    {
        unset($this->circuitos[$fonte]);
    }
}
