<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Adapters\GTIN;

interface TransporteConsultaGtin
{
    public function enviar(string $envelope, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $senha): string;
}
