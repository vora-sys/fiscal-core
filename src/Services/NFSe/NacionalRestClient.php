<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Exceptions\NacionalApiException;
use sabbajohn\FiscalCore\Support\CertificateManager;

final class NacionalRestClient
{
    private $httpClient;

    private $httpBatchClient;

    private ?int $budgetDeadlineNanoseconds = null;

    public function __construct(
        private readonly int $timeout = 30,
        ?callable $httpClient = null,
        private readonly int $maxAttempts = 3,
        ?callable $httpBatchClient = null,
    ) {
        $this->httpClient = $httpClient;
        $this->httpBatchClient = $httpBatchClient;
    }

    public function startBudget(int $seconds): void
    {
        $this->budgetDeadlineNanoseconds = hrtime(true) + (max(1, $seconds) * 1_000_000_000);
    }

    public function clearBudget(): void
    {
        $this->budgetDeadlineNanoseconds = null;
    }

    public function supportsParallelRequests(): bool
    {
        return is_callable($this->httpBatchClient) || ! is_callable($this->httpClient);
    }

    /** @return array{status:int,body:string,request_id:string,headers:array<string,string>} */
    public function get(string $url, array $query = []): array
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $last = null;
        $delays = [0, 250000, 750000];
        $attempts = max(1, min(count($delays), $this->maxAttempts));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $delay = $delays[$attempt];
            if ($delay > 0) {
                $this->sleepWithinBudget($delay + random_int(0, 75000));
            }
            try {
                $response = $this->request('GET', $url, $this->requestTimeout());
                if (! in_array($response['status'], [429, 502, 503, 504], true) || $attempt === $attempts - 1) {
                    return $response;
                }
                $last = new NacionalApiException('Serviço Nacional temporariamente indisponível.', $response['status'], $response['request_id'], true);
            } catch (NacionalApiException $e) {
                $last = $e;
                if (! $e->retryable || $attempt === $attempts - 1) {
                    throw $e;
                }
            }
        }

        throw $last ?? new NacionalApiException('Falha ao consultar serviço Nacional.', retryable: true);
    }

    /**
     * @param  array<string,array{url:string,query?:array<string,mixed>}>  $requests
     * @return array<string,array{status:int,body:string,request_id:string,headers:array<string,string>}|\Throwable>
     */
    public function getMany(array $requests): array
    {
        $prepared = [];
        foreach ($requests as $key => $request) {
            $url = (string) ($request['url'] ?? '');
            $query = array_filter((array) ($request['query'] ?? []), static fn (mixed $value): bool => $value !== null && $value !== '');
            if ($query !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
            $prepared[(string) $key] = $url;
        }

        if ($prepared === []) {
            return [];
        }

        if (is_callable($this->httpClient) && ! is_callable($this->httpBatchClient)) {
            $responses = [];
            foreach ($prepared as $key => $url) {
                try {
                    $responses[$key] = $this->get($url);
                } catch (\Throwable $exception) {
                    $responses[$key] = $exception;
                }
            }

            return $responses;
        }

        $responses = [];
        $pending = $prepared;
        $delays = [0, 250000, 750000];
        $attempts = max(1, min(count($delays), $this->maxAttempts));
        for ($attempt = 0; $attempt < $attempts && $pending !== []; $attempt++) {
            $delay = $delays[$attempt];
            if ($delay > 0) {
                try {
                    $this->sleepWithinBudget($delay + random_int(0, 75000));
                } catch (\Throwable $exception) {
                    foreach (array_keys($pending) as $key) {
                        $responses[$key] = $exception;
                    }

                    break;
                }
            }

            try {
                $round = $this->requestMany($pending, $this->requestTimeout());
            } catch (\Throwable $exception) {
                foreach (array_keys($pending) as $key) {
                    $responses[$key] = $exception;
                }

                break;
            }

            $retry = [];
            foreach ($pending as $key => $url) {
                $response = $round[$key] ?? new NacionalApiException('Resposta ausente no lote do serviço Nacional.', retryable: true);
                $retryable = $response instanceof NacionalApiException
                    ? $response->retryable
                    : (is_array($response) && in_array($response['status'], [429, 502, 503, 504], true));
                if ($retryable && $attempt < $attempts - 1) {
                    $retry[$key] = $url;

                    continue;
                }
                $responses[$key] = $response;
            }
            $pending = $retry;
        }

        return $responses;
    }

    /**
     * @param  array<string,string>  $requests
     * @return array<string,array{status:int,body:string,request_id:string,headers:array<string,string>}|\Throwable>
     */
    private function requestMany(array $requests, int $timeout): array
    {
        if (is_callable($this->httpBatchClient)) {
            $batch = [];
            foreach ($requests as $key => $url) {
                $requestId = 'nfse_'.bin2hex(random_bytes(8));
                $batch[$key] = [
                    'method' => 'GET',
                    'url' => $url,
                    'headers' => ['Accept: application/json', 'X-Request-Id: '.$requestId],
                    'request_id' => $requestId,
                ];
            }

            try {
                $received = call_user_func($this->httpBatchClient, $batch, $timeout);
            } catch (\Throwable $exception) {
                return array_fill_keys(array_keys($requests), $exception);
            }

            $responses = [];
            foreach ($batch as $key => $request) {
                $result = is_array($received) ? ($received[$key] ?? null) : null;
                try {
                    $responses[$key] = $this->normalizeResponse($result, $request['request_id']);
                } catch (\Throwable $exception) {
                    $responses[$key] = $exception;
                }
            }

            return $responses;
        }

        return $this->requestManyWithCurl($requests, $timeout);
    }

    /**
     * @param  array<string,string>  $requests
     * @return array<string,array{status:int,body:string,request_id:string,headers:array<string,string>}|\Throwable>
     */
    private function requestManyWithCurl(array $requests, int $timeout): array
    {
        if (! function_exists('curl_multi_init')) {
            $exception = new NacionalApiException('Extensão cURL é obrigatória para mTLS Nacional.');

            return array_fill_keys(array_keys($requests), $exception);
        }

        $multi = curl_multi_init();
        $handles = [];
        $certFile = null;
        $keyFile = null;

        try {
            [$certFile, $keyFile] = $this->prepareMutualTlsFiles();
            foreach ($requests as $key => $url) {
                $requestId = 'nfse_'.bin2hex(random_bytes(8));
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Request-Id: '.$requestId],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $this->applyMutualTlsFiles($ch, $certFile, $keyFile);
                curl_multi_add_handle($multi, $ch);
                $handles[$key] = ['handle' => $ch, 'request_id' => $requestId];
            }

            do {
                $status = curl_multi_exec($multi, $active);
                if ($status !== CURLM_OK) {
                    return array_fill_keys(
                        array_keys($requests),
                        new NacionalApiException('Falha no lote de transporte do serviço Nacional.', retryable: true),
                    );
                }
                if ($active > 0) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) {
                        usleep(1000);
                    }
                }
            } while ($active > 0);

            $responses = [];
            foreach ($handles as $key => $entry) {
                $ch = $entry['handle'];
                $raw = curl_multi_getcontent($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                if ($raw === false || curl_errno($ch) !== CURLE_OK) {
                    $responses[$key] = new NacionalApiException(
                        'Falha de transporte no serviço Nacional.',
                        $status ?: null,
                        $entry['request_id'],
                        true,
                    );

                    continue;
                }
                $responses[$key] = [
                    'status' => $status,
                    'body' => substr((string) $raw, $headerSize),
                    'request_id' => $entry['request_id'],
                    'headers' => $this->parseHeaders(substr((string) $raw, 0, $headerSize)),
                ];
            }

            return $responses;
        } catch (\Throwable $exception) {
            return array_fill_keys(array_keys($requests), $exception);
        } finally {
            foreach ($handles as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
            curl_multi_close($multi);
            $this->cleanupMutualTlsFiles($certFile, $keyFile);
        }
    }

    /** @return array{status:int,body:string,request_id:string,headers:array<string,string>} */
    private function request(string $method, string $url, int $timeout): array
    {
        $requestId = 'nfse_'.bin2hex(random_bytes(8));
        $headers = ['Accept: application/json', 'X-Request-Id: '.$requestId];

        if (is_callable($this->httpClient)) {
            $result = call_user_func($this->httpClient, $method, $url, null, $headers);

            return $this->normalizeResponse($result, $requestId);
        }

        if (! function_exists('curl_init')) {
            throw new NacionalApiException('Extensão cURL é obrigatória para mTLS Nacional.', requestId: $requestId);
        }

        $ch = curl_init($url);
        $certFile = null;
        $keyFile = null;
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            [$certFile, $keyFile] = $this->prepareMutualTlsFiles();
            $this->applyMutualTlsFiles($ch, $certFile, $keyFile);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            if ($raw === false) {
                throw new NacionalApiException('Falha de transporte no serviço Nacional.', $status ?: null, $requestId, true);
            }

            return [
                'status' => $status,
                'body' => substr((string) $raw, $headerSize),
                'request_id' => $requestId,
                'headers' => $this->parseHeaders(substr((string) $raw, 0, $headerSize)),
            ];
        } finally {
            if (is_resource($ch) || $ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $this->cleanupMutualTlsFiles($certFile, $keyFile);
        }
    }

    private function requestTimeout(): int
    {
        $timeout = max(1, $this->timeout);
        if ($this->budgetDeadlineNanoseconds === null) {
            return $timeout;
        }

        $remaining = $this->budgetDeadlineNanoseconds - hrtime(true);
        if ($remaining <= 0) {
            throw new NacionalApiException('Orçamento do preflight Nacional esgotado.', retryable: true);
        }

        return max(1, min($timeout, (int) ceil($remaining / 1_000_000_000)));
    }

    private function sleepWithinBudget(int $microseconds): void
    {
        if ($this->budgetDeadlineNanoseconds !== null) {
            $remainingMicroseconds = intdiv(max(0, $this->budgetDeadlineNanoseconds - hrtime(true)), 1000);
            if ($remainingMicroseconds <= $microseconds) {
                throw new NacionalApiException('Orçamento do preflight Nacional esgotado.', retryable: true);
            }
        }

        usleep($microseconds);
    }

    /** @return array{0:string,1:string} */
    private function prepareMutualTlsFiles(): array
    {
        $certificate = CertificateManager::getInstance()->getCertificate();
        if ($certificate === null) {
            throw new NacionalApiException('Certificado digital não carregado para o serviço Nacional.');
        }
        $certFile = tempnam(sys_get_temp_dir(), 'nfse_api_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'nfse_api_key_');
        if (! is_string($certFile) || ! is_string($keyFile)) {
            $this->cleanupMutualTlsFiles(
                is_string($certFile) ? $certFile : null,
                is_string($keyFile) ? $keyFile : null,
            );
            throw new NacionalApiException('Não foi possível preparar o certificado mTLS.');
        }
        file_put_contents($certFile, (string) $certificate);
        file_put_contents($keyFile, (string) $certificate->privateKey);
        @chmod($certFile, 0600);
        @chmod($keyFile, 0600);

        return [$certFile, $keyFile];
    }

    private function applyMutualTlsFiles(mixed $ch, string $certFile, string $keyFile): void
    {
        curl_setopt_array($ch, [
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEYTYPE => 'PEM',
        ]);
    }

    private function cleanupMutualTlsFiles(?string $certFile, ?string $keyFile): void
    {
        foreach ([$certFile, $keyFile] as $file) {
            if (is_string($file) && $file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return array{status:int,body:string,request_id:string,headers:array<string,string>} */
    private function normalizeResponse(mixed $result, string $requestId): array
    {
        if (is_string($result)) {
            return ['status' => 200, 'body' => $result, 'request_id' => $requestId, 'headers' => []];
        }
        if (is_array($result)) {
            return [
                'status' => (int) ($result['status'] ?? 200),
                'body' => (string) ($result['body'] ?? ''),
                'request_id' => (string) ($result['request_id'] ?? $requestId),
                'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            ];
        }

        throw new NacionalApiException('Cliente HTTP retornou resposta inválida.', requestId: $requestId);
    }

    /** @return array<string,string> */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
