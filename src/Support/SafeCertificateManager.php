<?php

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Exceptions\CertificateException;

/**
 * Wrapper safe para CertificateManager
 * Retorna FiscalResponse em vez de lançar exceções
 */
class SafeCertificateManager
{
    private static ResponseHandler $responseHandler;

    private static function getResponseHandler(): ResponseHandler
    {
        if (! isset(self::$responseHandler)) {
            self::$responseHandler = new ResponseHandler;
        }

        return self::$responseHandler;
    }

    /**
     * Carrega certificado de arquivo de forma safe
     *
     * @param  string  $pfxPath  Caminho para arquivo .pfx
     * @param  string  $password  Senha do certificado
     * @return FiscalResponse Resultado da operação
     */
    public static function loadFromFileSafe(string $pfxPath, string $password): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($pfxPath, $password) {
            if (! file_exists($pfxPath)) {
                throw CertificateException::fileNotFound($pfxPath);
            }

            $manager = CertificateManager::getInstance();
            $manager->loadFromFile($pfxPath, $password);

            return [
                'loaded' => true,
                'certificate_path' => $pfxPath,
                'certificate_info' => $manager->getCertificateInfo(),
            ];
        }, 'load_certificate_from_file');
    }

    /**
     * Carrega certificado de conteúdo de forma safe
     *
     * @param  string  $pfxContent  Conteúdo do certificado
     * @param  string  $password  Senha do certificado
     * @return FiscalResponse Resultado da operação
     */
    public static function loadFromContentSafe(string $pfxContent, string $password): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($pfxContent, $password) {
            if (empty($pfxContent)) {
                throw CertificateException::fileNotFound('conteúdo vazio');
            }

            $manager = CertificateManager::getInstance();
            $manager->loadFromContent($pfxContent, $password);

            return [
                'loaded' => true,
                'certificate_size' => strlen($pfxContent),
                'certificate_info' => $manager->getCertificateInfo(),
            ];
        }, 'load_certificate_from_content');
    }

    /**
     * Verifica status do certificado de forma safe
     *
     * @return FiscalResponse Status do certificado
     */
    public static function getStatusSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = CertificateManager::getInstance();

            if (! $manager::isLoaded()) {
                throw CertificateException::notLoaded();
            }

            if (! $manager->isValid()) {
                throw CertificateException::expired();
            }

            $info = $manager->getCertificateInfo();
            $daysLeft = $manager->getDaysUntilExpiration();

            return [
                'loaded' => true,
                'valid' => true,
                'certificate_info' => $info,
                'days_until_expiration' => $daysLeft,
                'expires_soon' => $daysLeft !== null && $daysLeft < 30,
            ];
        }, 'get_certificate_status');
    }

    /**
     * Valida certificado de forma safe
     *
     * @return FiscalResponse Resultado da validação
     */
    public static function validateSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = CertificateManager::getInstance();
            $warnings = [];
            $errors = [];

            if (! $manager::isLoaded()) {
                $errors[] = 'Certificado não carregado';
            } else {
                if (! $manager->isValid()) {
                    $errors[] = 'Certificado expirado ou inválido';
                } else {
                    $daysLeft = $manager->getDaysUntilExpiration();
                    if ($daysLeft !== null && $daysLeft < 30) {
                        $warnings[] = "Certificado expira em {$daysLeft} dias";
                    }
                    if ($daysLeft !== null && $daysLeft < 7) {
                        $warnings[] = 'Certificado expira em menos de uma semana!';
                    }
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'certificate_info' => $manager::isLoaded() ? $manager->getCertificateInfo() : null,
            ];
        }, 'validate_certificate');
    }

    /**
     * Recarrega certificado de forma safe
     *
     * @return FiscalResponse Resultado do reload
     */
    public static function reloadSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = CertificateManager::getInstance();
            $manager::reload();

            return [
                'reloaded' => true,
                'loaded' => $manager::isLoaded(),
                'certificate_info' => $manager::isLoaded() ? $manager->getCertificateInfo() : null,
            ];
        }, 'reload_certificate');
    }

    /**
     * Obtém informações do certificado de forma safe
     *
     * @return FiscalResponse Informações do certificado
     */
    public static function getCertificateInfoSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = CertificateManager::getInstance();

            if (! $manager::isLoaded()) {
                return ['loaded' => false, 'info' => null];
            }

            return [
                'loaded' => true,
                'info' => $manager->getCertificateInfo(),
                'valid' => $manager->isValid(),
                'days_until_expiration' => $manager->getDaysUntilExpiration(),
            ];
        }, 'get_certificate_info');
    }

    /**
     * Configura certificado a partir de variáveis de ambiente de forma safe
     *
     * @return FiscalResponse Resultado da configuração
     */
    public static function loadFromEnvironmentSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $certPath = $_ENV['FISCAL_CERT_PATH'] ?? getenv('FISCAL_CERT_PATH');
            $certPassword = $_ENV['FISCAL_CERT_PASSWORD'] ?? getenv('FISCAL_CERT_PASSWORD');

            if (! $certPath) {
                throw CertificateException::fileNotFound('Variável FISCAL_CERT_PATH não definida');
            }

            if (! $certPassword) {
                throw CertificateException::invalidPassword();
            }

            return self::loadFromFileSafe($certPath, $certPassword)->getData();
        }, 'load_certificate_from_environment');
    }

    /**
     * Obtém o certificado pronto para uso (Certificate object) de forma safe
     *
     * @return FiscalResponse Certificate object ou erro
     */
    public static function getCertificateSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = CertificateManager::getInstance();

            if (! $manager::isLoaded()) {
                throw CertificateException::notLoaded();
            }

            if (! $manager->isValid()) {
                throw CertificateException::expired();
            }

            return [
                'certificate' => $manager->getCertificate(),
                'loaded' => true,
                'valid' => true,
            ];
        }, 'get_certificate_object');
    }
}
