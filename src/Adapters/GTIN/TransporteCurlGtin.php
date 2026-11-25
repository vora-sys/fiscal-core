<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Adapters\GTIN;

use RuntimeException;

final class TransporteCurlGtin implements TransporteConsultaGtin
{
    private const URL = 'https://dfe-servico.svrs.rs.gov.br/ws/ccgConsGTIN/ccgConsGTIN.asmx';

    public const LIMITE_RESPOSTA = 65536;

    public function enviar(string $envelope, #[\SensitiveParameter] string $pfx, #[\SensitiveParameter] string $senha): string
    {
        if (! function_exists('curl_init') || ! defined('CURLOPT_SSLCERT_BLOB')) {
            throw new RuntimeException('O transporte seguro da consulta GTIN não está disponível.');
        }

        $curl = curl_init(self::URL);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar a consulta GTIN.');
        }

        try {
            $resposta = '';
            $opcoes = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/soap+xml',
                    'Content-Type: application/soap+xml; charset=utf-8; action="'.ConsultaGtinCcg::ACAO_SOAP.'"',
                ],
                CURLOPT_CONNECTTIMEOUT_MS => 2000,
                CURLOPT_TIMEOUT_MS => 5000,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_SSLCERT_BLOB => $pfx,
                CURLOPT_SSLCERTTYPE => 'P12',
                CURLOPT_KEYPASSWD => $senha,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $bloco) use (&$resposta): int {
                    if (strlen($resposta) + strlen($bloco) > self::LIMITE_RESPOSTA) {
                        return 0;
                    }

                    $resposta .= $bloco;

                    return strlen($bloco);
                },
            ];

            if (! curl_setopt_array($curl, $opcoes)
                || curl_exec($curl) === false
                || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('O Cadastro Centralizado de GTIN está indisponível.');
            }

            return $resposta;
        } finally {
            unset($curl);
        }
    }
}
