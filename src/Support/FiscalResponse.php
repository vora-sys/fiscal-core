<?php

namespace sabbajohn\FiscalCore\Support;

/**
 * Classe de resposta padronizada para operações fiscais
 * Evita que aplicações recebam erros 500 fornecendo estrutura consistente
 */
class FiscalResponse
{
    private bool $success;

    private array $data;

    private ?string $error;

    private ?string $errorCode;

    private string $operation;

    private array $metadata;

    public function __construct(
        bool $success,
        array $data = [],
        ?string $error = null,
        ?string $errorCode = null,
        string $operation = 'unknown',
        array $metadata = []
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
        $this->errorCode = $errorCode;
        $this->operation = $operation;
        $this->metadata = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'trace_id' => self::newTraceId(),
            'operation' => $operation,
            'category' => $success ? 'success' : 'runtime',
            'severity' => $success ? 'info' : 'error',
            'recoverable' => ! $success,
        ], $metadata);
        $this->metadata['operation'] = $this->metadata['operation'] ?? $operation;
    }

    /**
     * Cria resposta de sucesso
     */
    public static function success(array $data = [], string $operation = 'unknown', array $metadata = []): self
    {
        return new self(true, $data, null, null, $operation, $metadata);
    }

    /**
     * Cria resposta de erro
     */
    public static function error(
        string $message,
        ?string $code = null,
        string $operation = 'unknown',
        array $metadata = []
    ): self {
        return new self(false, [], $message, $code, $operation, $metadata);
    }

    /**
     * Cria resposta de erro a partir de Exception
     */
    public static function fromException(\Throwable $exception, string $operation = 'unknown'): self
    {
        $metadata = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace_id' => self::newTraceId(),
            'exception_type' => get_class($exception),
            'error_code' => $exception->getCode(),
            'timestamp' => date('Y-m-d H:i:s'),
            'operation' => $operation,
            'category' => 'runtime',
            'severity' => 'error',
            'recoverable' => false,
        ];

        return new self(
            false,
            [],
            $exception->getMessage(),
            get_class($exception),
            $operation,
            $metadata
        );
    }

    // Getters
    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isError(): bool
    {
        return ! $this->success;
    }

    public function getData(?string $key = null)
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getMetadata(?string $key = null)
    {
        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    /**
     * Converte para array para serialização
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
            'operation' => $this->operation,
            'timestamp' => $this->getMetadata('timestamp'),
        ];

        if ($this->success) {
            $result['data'] = $this->data;
        } else {
            $result['error'] = [
                'message' => $this->error,
                'code' => $this->errorCode,
            ];
        }

        if (! empty($this->metadata)) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }

    /**
     * Converte para JSON
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Verifica se tem dados específicos
     */
    public function hasData(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Adiciona metadata
     */
    public function withMetadata(string $key, $value): self
    {
        $clone = clone $this;
        $clone->metadata[$key] = $value;

        return $clone;
    }

    /**
     * Adiciona dados (apenas para responses de sucesso)
     */
    public function withData(string $key, $value): self
    {
        if (! $this->success) {
            return $this;
        }

        $clone = clone $this;
        $clone->data[$key] = $value;

        return $clone;
    }

    private static function newTraceId(): string
    {
        try {
            return 'fc_'.bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid('fc_', true);
        }
    }
}
