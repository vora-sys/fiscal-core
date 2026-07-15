<?php

namespace sabbajohn\FiscalCore\Exceptions;

use RuntimeException;

final class NfseNacionalPreflightException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message, public readonly array $details)
    {
        parent::__construct($message);
    }
}
