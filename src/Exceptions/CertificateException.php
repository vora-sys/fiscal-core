<?php

namespace sabbajohn\FiscalCore\Exceptions;

/**
 * Exceção para problemas relacionados a certificados digitais
 */
class CertificateException extends FiscalException
{
    protected ?string $errorCode = 'CERTIFICATE_ERROR';

    public static function notLoaded(): self
    {
        return new self(
            'Certificado digital não carregado. Use CertificateManager::getInstance()->loadFromFile()',
            0,
            null,
            [
                'suggestions' => [
                    'Carregue o certificado antes de usar operações fiscais',
                    'Verifique se o arquivo .pfx existe',
                    'Confirme se a senha está correta',
                ],
            ]
        );
    }

    public static function expired(): self
    {
        return new self(
            'Certificado digital expirou',
            0,
            null,
            [
                'suggestions' => [
                    'Renove seu certificado digital',
                    'Entre em contato com a Autoridade Certificadora',
                    'Verifique a data de validade',
                ],
            ]
        );
    }

    public static function invalidPassword(): self
    {
        return new self(
            'Senha do certificado digital inválida',
            0,
            null,
            [
                'suggestions' => [
                    'Verifique a senha do certificado',
                    'Confirme se não há caracteres especiais',
                    'Tente digitar a senha novamente',
                ],
            ]
        );
    }

    public static function fileNotFound(string $path): self
    {
        return new self(
            "Arquivo de certificado não encontrado: {$path}",
            0,
            null,
            [
                'path' => $path,
                'suggestions' => [
                    'Verifique se o caminho está correto',
                    'Confirme se o arquivo existe',
                    'Verifique as permissões de leitura',
                ],
            ]
        );
    }
}
