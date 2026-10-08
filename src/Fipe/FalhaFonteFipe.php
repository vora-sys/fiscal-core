<?php

namespace sabbajohn\FiscalCore\Fipe;

use Psr\Http\Message\ResponseInterface;

final class FalhaFonteFipe extends \RuntimeException
{
    public function __construct(int $status, public readonly int $espera = 0)
    {
        parent::__construct('Fonte FIPE retornou erro HTTP.', $status);
    }

    public static function resposta(ResponseInterface $resposta): self
    {
        $valor = trim($resposta->getHeaderLine('Retry-After'));
        $espera = ctype_digit($valor) ? (int) $valor : max(0, (strtotime($valor) ?: time()) - time());

        return new self($resposta->getStatusCode(), min(86400, $espera));
    }
}
