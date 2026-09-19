<?php

namespace sabbajohn\FiscalCore\Adapters;

use NFePHP\Ibpt\RestInterface;
use RuntimeException;

final class IbptRestClient implements RestInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly int $connectTimeoutSeconds = 2,
    ) {}

    public function pull($uri): string
    {
        $curl = curl_init((string) $uri);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar a consulta IBPT.');
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
                CURLOPT_CONNECTTIMEOUT => max(1, min($this->connectTimeoutSeconds, $this->timeoutSeconds)),
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);

            $response = curl_exec($curl);
            $error = curl_error($curl);
            $errorCode = curl_errno($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($response === false || $errorCode !== 0) {
                throw new RuntimeException("Erro cURL [{$errorCode}] {$error}");
            }

            if ($httpCode !== 200) {
                return json_encode([
                    'error' => $error !== '' ? $error : 'HTTP '.$httpCode,
                    'response' => $response,
                    'httpcode' => $httpCode,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }

            return (string) $response;
        } finally {
            curl_close($curl);
        }
    }
}
