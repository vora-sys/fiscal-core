<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Exceptions\NacionalApiException;
use sabbajohn\FiscalCore\Support\CertificateManager;

final class NacionalRestClient
{
    private $httpClient;

    public function __construct(
        private readonly int $timeout = 30,
        ?callable $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    /** @return array{status:int,body:string,request_id:string,headers:array<string,string>} */
    public function get(string $url, array $query = []): array
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $last = null;
        foreach ([0, 250000, 750000] as $attempt => $delay) {
            if ($delay > 0) {
                usleep($delay + random_int(0, 75000));
            }
            try {
                $response = $this->request('GET', $url);
                if (! in_array($response['status'], [429, 502, 503, 504], true) || $attempt === 2) {
                    return $response;
                }
                $last = new NacionalApiException('Serviço Nacional temporariamente indisponível.', $response['status'], $response['request_id'], true);
            } catch (NacionalApiException $e) {
                $last = $e;
                if (! $e->retryable || $attempt === 2) {
                    throw $e;
                }
            }
        }

        throw $last ?? new NacionalApiException('Falha ao consultar serviço Nacional.', retryable: true);
    }

    /** @return array{status:int,body:string,request_id:string,headers:array<string,string>} */
    private function request(string $method, string $url): array
    {
        $requestId = 'nfse_'.bin2hex(random_bytes(8));
        $headers = ['Accept: application/json', 'X-Request-Id: '.$requestId];

        if (is_callable($this->httpClient)) {
            $result = call_user_func($this->httpClient, $method, $url, null, $headers);
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
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(15, max(5, $this->timeout)),
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $this->applyMutualTls($ch, $certFile, $keyFile);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $error = curl_error($ch);
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
            foreach ([$certFile, $keyFile] as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function applyMutualTls(mixed $ch, ?string &$certFile, ?string &$keyFile): void
    {
        $certificate = CertificateManager::getInstance()->getCertificate();
        if ($certificate === null) {
            throw new NacionalApiException('Certificado digital não carregado para o serviço Nacional.');
        }
        $certFile = tempnam(sys_get_temp_dir(), 'nfse_api_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'nfse_api_key_');
        if (! is_string($certFile) || ! is_string($keyFile)) {
            throw new NacionalApiException('Não foi possível preparar o certificado mTLS.');
        }
        file_put_contents($certFile, (string) $certificate);
        file_put_contents($keyFile, (string) $certificate->privateKey);
        @chmod($certFile, 0600);
        @chmod($keyFile, 0600);
        curl_setopt_array($ch, [
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEYTYPE => 'PEM',
        ]);
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
