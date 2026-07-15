<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;

final class NFSeImpressaoResult implements NFSeImpressaoResultInterface
{
    public function __construct(
        private readonly array $impressao,
        private readonly array $provider = [],
        private readonly array $raw = []
    ) {}

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
            'impressao' => $this->impressao,
            'provider' => $this->provider,
            'raw' => $this->raw,
        ];
    }
}
