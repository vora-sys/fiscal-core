<?php

namespace sabbajohn\FiscalCore\Fipe;

/** Diagnóstico sanitizado; nunca contém corpo HTTP, tokens ou mensagens do SDK. */
final class FalhaConsultaFipe extends \RuntimeException
{
    public function __construct(string $mensagem, int $status, public readonly array $alertas = [], public readonly array $tentativas = [])
    {
        parent::__construct($mensagem, $status);
    }
}
