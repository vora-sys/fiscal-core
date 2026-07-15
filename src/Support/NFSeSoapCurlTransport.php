<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use RuntimeException;

final class NFSeSoapCurlTransport implements NFSeSoapTransportInterface
{
    public function send(string $endpoint, string $envelope, array $options = []): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('Extensão cURL é obrigatória para transporte SOAP municipal.');
        }

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: '.strlen($envelope),
        ];
        $soapAction = $options['soap_action'] ?? null;
        if (is_string($soapAction) && trim($soapAction) !== '') {
            $headers[] = 'SOAPAction: "'.$soapAction.'"';
        }
        $headers = array_merge($headers, $options['headers'] ?? []);

        $timeout = max(1, (int) ($options['timeout'] ?? 30));
        $responseHeaders = [];
        $currentHeaderBlock = [];

        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new RuntimeException("Falha ao inicializar cURL para '{$endpoint}'.");
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$responseHeaders, &$currentHeaderBlock): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    if (stripos($trimmed, 'HTTP/') === 0) {
                        $currentHeaderBlock = [$trimmed];
                    } else {
                        $currentHeaderBlock[] = $trimmed;
                    }
                    $responseHeaders = $currentHeaderBlock;
                }

                return strlen($headerLine);
            },
        ]);

        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException(
                $error !== '' ? "Falha no transporte SOAP municipal: {$error}" : 'Falha desconhecida no transporte SOAP municipal.'
            );
        }

        return [
            'request_xml' => $envelope,
            'response_xml' => (string) $response,
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'request_headers' => $headers,
            'response_headers' => $responseHeaders,
        ];
    }
}
