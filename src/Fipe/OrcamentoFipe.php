<?php

namespace sabbajohn\FiscalCore\Fipe;

final class OrcamentoFipe
{
    private float $inicio;
    private int $chamadas = 0;
    private \Closure $relogio;

    public function __construct(private float $segundos = 10, private int $maximoChamadas = 4, ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): float => hrtime(true) / 1e9;
        $this->inicio = ($this->relogio)();
    }

    public function opcoes(): array
    {
        $restante = $this->segundos - (($this->relogio)() - $this->inicio);
        if ($restante <= 0.05 || ++$this->chamadas > $this->maximoChamadas) {
            throw new \RuntimeException('Orçamento de consulta FIPE esgotado.', 503);
        }

        return ['connect_timeout' => min(1, $restante), 'timeout' => min(2.5, $restante), 'allow_redirects' => false, 'http_errors' => false];
    }
}
