<?php

namespace sabbajohn\FiscalCore\Support;

use RuntimeException;

final class NfseLayoutCapabilityException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $details
     */
    public function __construct(string $message, private readonly array $details)
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
