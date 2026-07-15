<?php

namespace sabbajohn\FiscalCore\Exceptions;

/**
 * Exception base para todas as exceções do sistema fiscal
 */
abstract class FiscalException extends \RuntimeException
{
    protected array $context = [];

    protected ?string $errorCode = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }
}
