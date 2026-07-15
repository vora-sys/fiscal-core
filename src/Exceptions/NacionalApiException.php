<?php

namespace sabbajohn\FiscalCore\Exceptions;

use RuntimeException;

final class NacionalApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
