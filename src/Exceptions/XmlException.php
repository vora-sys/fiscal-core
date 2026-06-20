<?php

namespace sabbajohn\FiscalCore\Exceptions;

/**
 * Exceção para erros relacionados ao processamento de XML
 */
class XmlException extends FiscalException
{
    protected ?string $errorCode = 'XML_ERROR';

    public static function malformed(string $details = ''): self
    {
        $message = 'XML malformado';
        if ($details) {
            $message .= ": {$details}";
        }

        return new self(
            $message,
            0,
            null,
            [
                'details' => $details,
                'suggestions' => [
                    'Verifique a estrutura do XML',
                    'Confirme se todas as tags estão fechadas',
                    'Valide caracteres especiais (encode)',
                    'Use um validador XML'
                ]
            ]
        );
    }

    public static function signatureFailed(string $reason = ''): self
    {
        $message = 'Falha na assinatura do XML';
        if ($reason) {
            $message .= ": {$reason}";
        }

        return new self(
            $message,
            0,
            null,
            [
                'reason' => $reason,
                'suggestions' => [
                    'Verifique se o certificado está carregado',
                    'Confirme se o XML está bem formado',
                    'Valide as configurações de assinatura',
                    'Verifique se o certificado não expirou'
                ]
            ]
        );
    }

    public static function invalidSchema(string $schema, array $errors = []): self
    {
        return new self(
            "XML não atende ao schema {$schema}",
            0,
            null,
            [
                'schema' => $schema,
                'schema_errors' => $errors,
                'suggestions' => [
                    'Corrija os erros do schema listados',
                    'Verifique a versão do layout',
                    'Confirme se todos campos obrigatórios estão presentes',
                    'Valide tipos de dados'
                ]
            ]
        );
    }

    public static function parsingFailed(string $content = '', ?\Throwable $previous = null): self
    {
        return new self(
            'Falha ao processar XML',
            0,
            $previous,
            [
                'content_preview' => substr($content, 0, 200),
                'suggestions' => [
                    'Verifique se o conteúdo é um XML válido',
                    'Confirme a codificação (UTF-8)',
                    'Valide caracteres especiais',
                    'Remova caracteres de controle'
                ]
            ]
        );
    }

    public static function encodingError(string $encoding = 'UTF-8'): self
    {
        return new self(
            "Erro de codificação XML: {$encoding}",
            0,
            null,
            [
                'encoding' => $encoding,
                'suggestions' => [
                    'Converta o arquivo para UTF-8',
                    'Remova caracteres especiais inválidos',
                    'Valide acentos e símbolos',
                    'Use mb_convert_encoding se necessário'
                ]
            ]
        );
    }
}
