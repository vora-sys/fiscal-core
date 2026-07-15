<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;

final class NFSeConsultaResult implements NFSeConsultaResultInterface
{
    public function __construct(
        private readonly array $consulta,
        private readonly array $documento,
        private readonly array $impressao,
        private readonly array $provider = [],
        private readonly array $raw = []
    ) {}

    public function getConsulta(): array
    {
        return $this->consulta;
    }

    public function getDocumento(): array
    {
        return $this->documento;
    }

    public function getImpressao(): array
    {
        return $this->impressao;
    }

    public function getProvider(): array
    {
        return $this->provider;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function toArray(): array
    {
        return [
            'consulta' => $this->consulta,
            'documento' => $this->documento,
            'impressao' => $this->impressao,
            'provider' => $this->provider,
            'raw' => $this->raw,
        ];
    }
}
