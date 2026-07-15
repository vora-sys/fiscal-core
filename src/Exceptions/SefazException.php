<?php

namespace sabbajohn\FiscalCore\Exceptions;

/**
 * Exceção para erros relacionados à comunicação com SEFAZ
 */
class SefazException extends FiscalException
{
    protected ?string $errorCode = 'SEFAZ_ERROR';

    public static function connectionFailed(string $uf = ''): self
    {
        return new self(
            'Falha na conexão com SEFAZ'.($uf ? " - UF: {$uf}" : ''),
            0,
            null,
            [
                'uf' => $uf,
                'suggestions' => [
                    'Verifique sua conexão com internet',
                    'Confirme se SEFAZ não está em manutenção',
                    'Tente novamente em alguns minutos',
                    'Verifique se URLs de webservice estão corretas',
                ],
            ]
        );
    }

    public static function serviceUnavailable(string $cStat = '', string $xMotivo = ''): self
    {
        $message = 'Serviço SEFAZ indisponível';
        if ($cStat && $xMotivo) {
            $message .= " - {$cStat}: {$xMotivo}";
        }

        return new self(
            $message,
            (int) $cStat,
            null,
            [
                'cStat' => $cStat,
                'xMotivo' => $xMotivo,
                'suggestions' => [
                    'SEFAZ pode estar em manutenção',
                    'Aguarde e tente novamente',
                    'Verifique avisos no portal da SEFAZ',
                    'Use modo de contingência se disponível',
                ],
            ]
        );
    }

    public static function invalidResponse(string $content = ''): self
    {
        return new self(
            'Resposta inválida da SEFAZ',
            0,
            null,
            [
                'response_preview' => substr($content, 0, 200),
                'suggestions' => [
                    'Verifique se os dados enviados estão corretos',
                    'Confirme se o ambiente (produção/homologação) está certo',
                    'Valide o XML antes do envio',
                ],
            ]
        );
    }

    public static function timeout(): self
    {
        return new self(
            'Timeout na comunicação com SEFAZ',
            0,
            null,
            [
                'suggestions' => [
                    'Sua conexão pode estar lenta',
                    'SEFAZ pode estar com alta demanda',
                    'Tente novamente em alguns minutos',
                    'Considere aumentar o timeout',
                ],
            ]
        );
    }
}
