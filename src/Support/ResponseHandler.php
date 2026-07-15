<?php

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Exceptions\CertificateException;
use sabbajohn\FiscalCore\Exceptions\FiscalException;
use sabbajohn\FiscalCore\Exceptions\SefazException;
use sabbajohn\FiscalCore\Exceptions\ValidationException;
use sabbajohn\FiscalCore\Exceptions\XmlException;

/**
 * Handler centralizado para tratamento de exceções e responses
 * Evita que aplicações recebam erros 500 incontroláveis
 */
class ResponseHandler
{
    private array $errorMappings = [
        'RuntimeException' => 'RUNTIME_ERROR',
        'InvalidArgumentException' => 'INVALID_ARGUMENT',
        'LogicException' => 'LOGIC_ERROR',
        'Exception' => 'GENERAL_ERROR',
    ];

    /**
     * Executa função e retorna FiscalResponse tratado
     *
     * @param  callable|\Exception  $callbackOrException  Função a ser executada OU exceção a ser processada
     * @param  string  $operation  Nome da operação (para logs/debug)
     * @return FiscalResponse Response padronizado
     */
    public function handle($callbackOrException, string $operation = 'unknown'): FiscalResponse
    {
        $start = microtime(true);

        // Se o primeiro parâmetro é uma exceção, processa diretamente
        if ($callbackOrException instanceof \Exception || $callbackOrException instanceof \Throwable) {
            return $this->withDiagnostics($this->handleException($callbackOrException, $operation), $operation, $start);
        }

        // Caso contrário, trata como callable (comportamento original)
        try {
            $result = $callbackOrException();

            // Se callback já retornou FiscalResponse, usa ele
            if ($result instanceof FiscalResponse) {
                return $this->withDiagnostics($result, $operation, $start);
            }

            // Se retornou array ou dados, cria resposta de sucesso
            return $this->withDiagnostics(FiscalResponse::success(
                is_array($result) ? $result : ['result' => $result],
                $operation,
                ['execution_time' => $this->elapsed($start)]
            ), $operation, $start);

        } catch (\Exception|\Throwable $e) {
            return $this->withDiagnostics($this->handleException($e, $operation), $operation, $start);
        }
    }

    /**
     * Processa uma exceção e retorna FiscalResponse
     */
    private function handleException(\Exception|\Throwable $e, string $operation): FiscalResponse
    {
        if ($e instanceof CertificateException) {
            return $this->handleCertificateException($e, $operation);
        } elseif ($e instanceof SefazException) {
            return $this->handleSefazException($e, $operation);
        } elseif ($e instanceof ValidationException) {
            return $this->handleValidationException($e, $operation);
        } elseif ($e instanceof XmlException) {
            return $this->handleXmlException($e, $operation);
        } elseif ($e instanceof FiscalException) {
            return $this->handleFiscalException($e, $operation);
        } elseif ($e instanceof \InvalidArgumentException) {
            return $this->handleValidationError($e, $operation);
        } elseif ($e instanceof \RuntimeException) {
            return $this->handleRuntimeError($e, $operation);
        } elseif ($e instanceof \LogicException) {
            return $this->handleLogicError($e, $operation);
        } else {
            return $this->handleGenericError($e, $operation);
        }
    }

    /**
     * Trata exceções fiscais específicas (classe base)
     */
    private function handleFiscalException(FiscalException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            $e->getMessage(),
            $e->getErrorCode() ?: 'FISCAL_ERROR',
            $operation,
            array_merge([
                'severity' => 'error',
                'recoverable' => true,
                'category' => 'runtime',
                'exception_type' => get_class($e),
            ], $e->getContext())
        );
    }

    /**
     * Trata erros de certificado
     */
    private function handleCertificateException(CertificateException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            $e->getMessage(),
            $e->getErrorCode(),
            $operation,
            array_merge([
                'severity' => 'critical',
                'recoverable' => true,
                'category' => 'certificate',
            ], $e->getContext())
        );
    }

    /**
     * Trata erros de SEFAZ
     */
    private function handleSefazException(SefazException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            $e->getMessage(),
            $e->getErrorCode(),
            $operation,
            array_merge([
                'severity' => 'error',
                'recoverable' => true,
                'category' => 'sefaz',
            ], $e->getContext())
        );
    }

    /**
     * Trata erros de validação
     */
    private function handleValidationException(ValidationException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            $e->getMessage(),
            $e->getErrorCode(),
            $operation,
            array_merge([
                'severity' => 'warning',
                'recoverable' => true,
                'category' => 'validation',
                'validation_errors' => $e->getValidationErrors(),
            ], $e->getContext())
        );
    }

    /**
     * Trata erros de XML
     */
    private function handleXmlException(XmlException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            $e->getMessage(),
            $e->getErrorCode(),
            $operation,
            array_merge([
                'severity' => 'error',
                'recoverable' => true,
                'category' => 'xml',
            ], $e->getContext())
        );
    }

    /**
     * Trata erros de validação (parâmetros inválidos)
     */
    private function handleValidationError(\InvalidArgumentException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            'Dados inválidos: '.$e->getMessage(),
            'VALIDATION_ERROR',
            $operation,
            [
                'severity' => 'warning',
                'recoverable' => true,
                'category' => 'validation',
                'suggestions' => $this->getValidationSuggestions($e->getMessage()),
            ]
        );
    }

    /**
     * Trata erros de runtime (certificado, conexão, etc.)
     */
    private function handleRuntimeError(\RuntimeException $e, string $operation): FiscalResponse
    {
        $errorCode = 'RUNTIME_ERROR';
        $metadata = ['severity' => 'error', 'recoverable' => false, 'category' => 'runtime'];

        // Mapeia erros específicos
        if (str_contains($e->getMessage(), 'certificado')) {
            $errorCode = 'CERTIFICATE_ERROR';
            $metadata['category'] = 'certificate';
            $metadata['suggestions'] = [
                'Verifique se o certificado está carregado',
                'Confirme se o certificado não expirou',
                'Valide a senha do certificado',
            ];
        } elseif (str_contains($e->getMessage(), 'SEFAZ')) {
            $errorCode = 'SEFAZ_ERROR';
            $metadata['category'] = 'sefaz';
            $metadata['suggestions'] = [
                'Verifique conexão com internet',
                'Confirme se SEFAZ está operacional',
                'Tente novamente em alguns minutos',
            ];
        } elseif (str_contains($e->getMessage(), 'XML')) {
            $errorCode = 'XML_ERROR';
            $metadata['category'] = 'xml';
            $metadata['suggestions'] = [
                'Verifique estrutura dos dados',
                'Confirme campos obrigatórios',
                'Valide formato dos valores',
            ];
        }

        return FiscalResponse::error(
            $e->getMessage(),
            $errorCode,
            $operation,
            $metadata
        );
    }

    /**
     * Trata erros de lógica (fluxo incorreto)
     */
    private function handleLogicError(\LogicException $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            'Erro de fluxo: '.$e->getMessage(),
            'LOGIC_ERROR',
            $operation,
            [
                'severity' => 'error',
                'recoverable' => true,
                'category' => 'runtime',
                'suggestions' => [
                    'Verifique sequência de operações',
                    'Confirme estado dos objetos',
                    'Revise lógica de negócio',
                ],
            ]
        );
    }

    /**
     * Trata erros genéricos
     */
    private function handleGenericError(\Throwable $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            'Erro na operação: '.$e->getMessage(),
            'GENERAL_ERROR',
            $operation,
            [
                'severity' => 'error',
                'recoverable' => false,
                'category' => 'runtime',
                'exception_type' => get_class($e),
                'trace_summary' => $this->getTraceSummary($e),
            ]
        );
    }

    /**
     * Trata erros críticos (Throwable)
     */
    private function handleCriticalError(\Throwable $e, string $operation): FiscalResponse
    {
        return FiscalResponse::error(
            'Erro crítico no sistema fiscal',
            'CRITICAL_ERROR',
            $operation,
            [
                'severity' => 'critical',
                'recoverable' => false,
                'exception_type' => get_class($e),
                'message' => $e->getMessage(),
                'trace_id' => $this->newTraceId('critical_'),
                'category' => 'runtime',
                'support_info' => 'Entre em contato com o suporte técnico',
            ]
        );
    }

    /**
     * Gera sugestões baseadas na mensagem de validação
     */
    private function getValidationSuggestions(string $message): array
    {
        $suggestions = [];

        if (str_contains($message, 'chave')) {
            $suggestions[] = 'Chave de acesso deve ter exatamente 44 dígitos';
        }

        if (str_contains($message, 'CNPJ')) {
            $suggestions[] = 'CNPJ deve ter 14 dígitos';
        }

        if (str_contains($message, 'motivo') || str_contains($message, 'justificativa')) {
            $suggestions[] = 'Motivo deve ter pelo menos 15 caracteres';
        }

        if (str_contains($message, 'valor')) {
            $suggestions[] = 'Verifique formato numérico dos valores';
        }

        return $suggestions ?: ['Verifique os dados informados'];
    }

    /**
     * Gera resumo do trace para debug
     */
    private function getTraceSummary(\Throwable $e): array
    {
        $trace = $e->getTrace();
        $summary = [];

        // Pega apenas os 3 primeiros níveis do trace
        for ($i = 0; $i < min(3, count($trace)); $i++) {
            $frame = $trace[$i];
            $summary[] = [
                'file' => basename($frame['file'] ?? 'unknown'),
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
            ];
        }

        return $summary;
    }

    /**
     * Calcula tempo de execução (mock para agora)
     */
    private function getExecutionTime(): float
    {
        return microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    private function withDiagnostics(FiscalResponse $response, string $operation, float $start): FiscalResponse
    {
        $response = $response
            ->withMetadata('operation', $operation)
            ->withMetadata('execution_time', $response->getMetadata('execution_time') ?? $this->elapsed($start))
            ->withMetadata('trace_id', $response->getMetadata('trace_id') ?? $this->newTraceId());

        if ($response->getMetadata('category') === null) {
            $response = $response->withMetadata('category', $response->isSuccess() ? 'success' : 'runtime');
        }
        if ($response->getMetadata('severity') === null) {
            $response = $response->withMetadata('severity', $response->isSuccess() ? 'info' : 'error');
        }
        if ($response->getMetadata('recoverable') === null) {
            $response = $response->withMetadata('recoverable', $response->isError());
        }

        $this->logResponse($response);

        return $response;
    }

    private function elapsed(float $start): float
    {
        return microtime(true) - $start;
    }

    private function newTraceId(string $prefix = 'fc_'): string
    {
        try {
            return $prefix.bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid($prefix, true);
        }
    }

    private function logResponse(FiscalResponse $response): void
    {
        $enabled = $_ENV['FISCAL_CORE_LOG_DIAGNOSTICS'] ?? getenv('FISCAL_CORE_LOG_DIAGNOSTICS') ?: '0';
        if (! in_array(strtolower((string) $enabled), ['1', 'true', 'yes', 'on'], true)) {
            return;
        }

        error_log(json_encode([
            'trace_id' => $response->getMetadata('trace_id'),
            'operation' => $response->getOperation(),
            'success' => $response->isSuccess(),
            'category' => $response->getMetadata('category'),
            'severity' => $response->getMetadata('severity'),
            'error_code' => $response->getErrorCode(),
            'error' => $response->getError(),
            'execution_time' => $response->getMetadata('execution_time'),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Executa callback com timeout
     */
    public function handleWithTimeout(callable $callback, int $timeoutSeconds, string $operation): FiscalResponse
    {
        $startTime = time();

        try {
            // PHP não tem timeout nativo para operações, mas podemos simular
            $result = $callback();

            if (time() - $startTime > $timeoutSeconds) {
                return FiscalResponse::error(
                    'Operação excedeu tempo limite de '.$timeoutSeconds.' segundos',
                    'TIMEOUT_ERROR',
                    $operation
                );
            }

            return $this->handle(fn () => $result, $operation);

        } catch (\Throwable $e) {
            return $this->handle(fn () => throw $e, $operation);
        }
    }

    /**
     * Valida resposta de API externa (SEFAZ, etc.)
     */
    public function validateApiResponse(string $xmlResponse, string $operation): FiscalResponse
    {
        try {
            if (empty($xmlResponse)) {
                return FiscalResponse::error(
                    'Resposta vazia da API',
                    'EMPTY_RESPONSE',
                    $operation
                );
            }

            // Verifica se é XML válido
            $dom = new \DOMDocument;
            if (! $dom->loadXML($xmlResponse)) {
                return FiscalResponse::error(
                    'Resposta inválida da API (XML malformado)',
                    'INVALID_XML_RESPONSE',
                    $operation
                );
            }

            // Verifica se tem erro na resposta
            $xpath = new \DOMXPath($dom);
            $errorNodes = $xpath->query('//erro | //error | //fault');

            if ($errorNodes->length > 0) {
                $errorMessage = $errorNodes->item(0)->textContent;

                return FiscalResponse::error(
                    'Erro retornado pela API: '.$errorMessage,
                    'API_ERROR',
                    $operation
                );
            }

            return FiscalResponse::success([
                'xml' => $xmlResponse,
                'validated' => true,
            ], $operation);

        } catch (\Throwable $e) {
            return FiscalResponse::fromException($e, $operation);
        }
    }

    /**
     * Normaliza retorno XML da SEFAZ para estrutura amigável.
     *
     * @return array{
     *   lote: ?array{cStat:?string,xMotivo:?string,cUF:?string,dhRecbto:?string},
     *   protocolo: ?array{cStat:?string,xMotivo:?string,chNFe:?string,nProt:?string,dhRecbto:?string},
     *   autorizado: bool,
     *   status: string
     * }
     */
    public static function parseSefazRetorno(string $xml): array
    {
        return XmlUtils::parseSefazRetorno($xml);
    }

    /**
     * Retorna parse da SEFAZ em JSON.
     */
    public function parseSefazRetornoAsJson(string $xml): string
    {
        return XmlUtils::parseSefazRetornoAsJson($xml);
    }

    /**
     * Converte exceções comuns em exceções fiscais específicas
     */
    public function convertToFiscalException(\Exception $e): FiscalException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'certificado') || str_contains($message, 'certificate')) {
            return CertificateException::notLoaded()->withContext(['original_exception' => get_class($e)]);
        }

        if (str_contains($message, 'SEFAZ') || str_contains($message, 'webservice')) {
            return SefazException::connectionFailed()->withContext(['original_exception' => get_class($e)]);
        }

        if (str_contains($message, 'XML') || str_contains($message, 'malformed')) {
            return XmlException::malformed($message)->withContext(['original_exception' => get_class($e)]);
        }

        if (str_contains($message, 'chave') || str_contains($message, 'CNPJ') || str_contains($message, 'CPF')) {
            return ValidationException::invalidValue('unknown', $message)->withContext(['original_exception' => get_class($e)]);
        }

        // Se não conseguir mapear, retorna FiscalException genérica
        return (new class($message, $e->getCode(), $e) extends FiscalException {})
            ->setErrorCode('UNKNOWN_ERROR')
            ->withContext(['original_exception' => get_class($e)]);
    }

    /**
     * Executa callback simples
     */
    public function execute(callable $callback): FiscalResponse
    {
        return $this->handle($callback);
    }

    /**
     * Executa callback com timeout
     */
    public function executeWithTimeout(callable $callback, int $timeoutSeconds): FiscalResponse
    {
        $startTime = microtime(true);

        // Usar pcntl_alarm se disponível
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm($timeoutSeconds);
        }

        try {
            $result = $callback();

            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0); // Cancela o alarm
            }

            $executionTime = microtime(true) - $startTime;

            if ($executionTime > $timeoutSeconds) {
                return FiscalResponse::error(
                    "Operação excedeu tempo limite de {$timeoutSeconds}s",
                    'TIMEOUT',
                    'executeWithTimeout',
                    ['execution_time' => $executionTime]
                );
            }

            return FiscalResponse::success($result, 'executeWithTimeout', ['execution_time' => $executionTime]);

        } catch (\Exception $e) {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }

            if (strpos($e->getMessage(), 'timeout') !== false) {
                return FiscalResponse::error(
                    "Timeout de {$timeoutSeconds}s excedido",
                    'TIMEOUT',
                    'executeWithTimeout',
                    ['execution_time' => microtime(true) - $startTime]
                );
            }

            return $this->handleException($e, 'executeWithTimeout');
        }
    }

    /**
     * Executa callback com retry automático
     */
    public function executeWithRetry(callable $callback, int $maxAttempts = 3, float $delaySec = 0.5): FiscalResponse
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $result = $callback();

                return FiscalResponse::success($result, 'executeWithRetry', ['retry_attempts' => $attempts]);

            } catch (\Exception $e) {
                $lastException = $e;

                // Se não há mais tentativas, falha
                if ($attempts >= $maxAttempts) {
                    break;
                }

                // Delay antes da próxima tentativa
                if ($delaySec > 0) {
                    usleep($delaySec * 1000000);
                }
            }
        }

        return FiscalResponse::error(
            $lastException->getMessage(),
            'RETRY_EXHAUSTED',
            'executeWithRetry',
            [
                'retry_attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'last_error' => $lastException->getMessage(),
            ]
        );
    }

    /**
     * Executa callback com cache simples
     */
    public function executeWithCache(string $cacheKey, callable $callback, int $ttlSeconds = 300): FiscalResponse
    {
        static $cache = [];
        static $cacheTimestamps = [];

        // Verifica se existe cache válido
        if (isset($cache[$cacheKey]) && isset($cacheTimestamps[$cacheKey])) {
            $age = time() - $cacheTimestamps[$cacheKey];
            if ($age < $ttlSeconds) {
                return FiscalResponse::success(
                    $cache[$cacheKey],
                    'executeWithCache',
                    ['from_cache' => true, 'cache_age' => $age]
                );
            }
        }

        try {
            $result = $callback();

            // Armazena no cache
            $cache[$cacheKey] = $result;
            $cacheTimestamps[$cacheKey] = time();

            return FiscalResponse::success($result, 'executeWithCache', ['from_cache' => false]);

        } catch (\Exception $e) {
            return $this->handleException($e, 'executeWithCache');
        }
    }

    /**
     * Converte XML em array associativo no formato chave => valor.
     *
     * Exemplo de chave: "retDistDFeInt.cStat"
     * Nós repetidos viram array no mesmo índice.
     *
     * @return array<string, mixed>
     */
    public static function xmlToKeyValueArray(string $xml): array
    {
        return XmlUtils::xmlToKeyValueArray($xml);
    }
}
