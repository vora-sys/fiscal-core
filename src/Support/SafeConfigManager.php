<?php

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Exceptions\ValidationException;

/**
 * Wrapper safe para ConfigManager
 * Retorna FiscalResponse em vez de lançar exceções
 */
class SafeConfigManager
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
     * Carrega configuração de forma safe
     *
     * @param  array  $config  Configurações a serem carregadas
     * @return FiscalResponse Resultado da operação
     */
    public static function loadConfigSafe(array $config): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($config) {
            $manager = ConfigManager::getInstance();
            $manager->load($config);

            return [
                'loaded' => true,
                'config_count' => count($config),
                'environment' => $manager->isProduction() ? 'production' : 'development',
                'config_preview' => array_keys($config),
            ];
        }, 'load_config');
    }

    /**
     * Valida configuração obrigatória de forma safe
     *
     * @param  array  $requiredKeys  Chaves obrigatórias
     * @return FiscalResponse Resultado da validação
     */
    public static function validateRequiredConfigSafe(array $requiredKeys): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($requiredKeys) {
            $manager = ConfigManager::getInstance();
            $missing = [];
            $present = [];

            foreach ($requiredKeys as $key) {
                $value = $manager->get($key);
                if ($value === null || $value === '') {
                    $missing[] = $key;
                } else {
                    $present[] = $key;
                }
            }

            if (! empty($missing)) {
                throw ValidationException::multipleErrors(
                    array_fill_keys($missing, 'Configuração obrigatória não definida'),
                    'validação de configuração'
                );
            }

            return [
                'valid' => true,
                'required_keys' => $requiredKeys,
                'present_keys' => $present,
                'missing_keys' => $missing,
            ];
        }, 'validate_required_config');
    }

    /**
     * Obtém configuração NFe de forma safe
     *
     * @return FiscalResponse Configuração NFe ou erro
     */
    public static function getNFeConfigSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = ConfigManager::getInstance();

            // Valida configurações mínimas para NFe
            $requiredForNFe = ['uf', 'municipio_ibge'];
            $missing = [];

            foreach ($requiredForNFe as $key) {
                if (! $manager->get($key)) {
                    $missing[] = $key;
                }
            }

            if (! empty($missing)) {
                throw ValidationException::multipleErrors(
                    array_fill_keys($missing, 'Configuração obrigatória para NFe'),
                    'configuração NFe'
                );
            }

            $config = $manager->getNFeConfig();

            return [
                'config' => $config,
                'environment' => $manager->isProduction() ? 'production' : 'development',
                'uf' => $manager->get('uf'),
                'municipio' => $manager->get('municipio_ibge'),
            ];
        }, 'get_nfe_config');
    }

    /**
     * Obtém configuração de forma safe
     *
     * @param  string  $key  Chave da configuração
     * @param  mixed  $default  Valor padrão
     * @return FiscalResponse Valor da configuração
     */
    public static function getConfigSafe(string $key, $default = null): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($key, $default) {
            $manager = ConfigManager::getInstance();
            $value = $manager->get($key, $default);

            return [
                'key' => $key,
                'value' => $value,
                'has_value' => $value !== null,
                'used_default' => $value === $default,
            ];
        }, 'get_config');
    }

    /**
     * Define configuração de forma safe
     *
     * @param  string  $key  Chave da configuração
     * @param  mixed  $value  Valor da configuração
     * @return FiscalResponse Resultado da operação
     */
    public static function setConfigSafe(string $key, $value): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () use ($key, $value) {
            $manager = ConfigManager::getInstance();
            $oldValue = $manager->get($key);
            $manager->set($key, $value);

            return [
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $value,
                'changed' => $oldValue !== $value,
            ];
        }, 'set_config');
    }

    /**
     * Verifica se está em produção de forma safe
     *
     * @return FiscalResponse Status do ambiente
     */
    public static function isProductionSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = ConfigManager::getInstance();
            $isProduction = $manager->isProduction();
            $ambiente = $manager->get('ambiente', 2);

            return [
                'is_production' => $isProduction,
                'environment' => $isProduction ? 'production' : 'development',
                'ambiente_code' => $ambiente,
                'ambiente_name' => $ambiente === 1 ? 'Produção' : 'Homologação',
            ];
        }, 'check_production');
    }

    /**
     * Obtém toda a configuração de forma safe
     *
     * @return FiscalResponse Toda a configuração
     */
    public static function getAllConfigSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = ConfigManager::getInstance();
            $allConfig = $manager->all();

            // Remove dados sensíveis do retorno
            $safeConfig = $allConfig;
            if (isset($safeConfig['certificado']['cert_password'])) {
                $safeConfig['certificado']['cert_password'] = '[HIDDEN]';
            }
            if (isset($safeConfig['csc'])) {
                $safeConfig['csc'] = '[HIDDEN]';
            }

            return [
                'config' => $safeConfig,
                'config_count' => count($allConfig),
                'environment' => $manager->isProduction() ? 'production' : 'development',
                'has_certificate_config' => isset($allConfig['certificado']),
                'has_nfe_config' => isset($allConfig['uf'], $allConfig['municipio_ibge']),
            ];
        }, 'get_all_config');
    }

    /**
     * Valida configuração completa de forma safe
     *
     * @return FiscalResponse Resultado da validação completa
     */
    public static function validateCompleteConfigSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function () {
            $manager = ConfigManager::getInstance();
            $errors = [];
            $warnings = [];

            // Validações básicas
            $requiredBasic = ['uf', 'municipio_ibge'];
            foreach ($requiredBasic as $key) {
                if (! $manager->get($key)) {
                    $errors[] = "Configuração obrigatória não definida: {$key}";
                }
            }

            // Validações específicas para produção
            if ($manager->isProduction()) {
                $requiredProd = ['csc', 'csc_id'];
                foreach ($requiredProd as $key) {
                    if (! $manager->get($key)) {
                        $errors[] = "Configuração obrigatória para produção: {$key}";
                    }
                }
            } else {
                if (! $manager->get('csc')) {
                    $warnings[] = 'CSC não configurado - necessário para NFCe';
                }
            }

            // Validações de formato
            $uf = $manager->get('uf');
            if ($uf && ! preg_match('/^[A-Z]{2}$/', $uf)) {
                $errors[] = 'UF deve ter formato XX (ex: SP, RJ, SC)';
            }

            $municipio = $manager->get('municipio_ibge');
            if ($municipio && ! preg_match('/^\d{7}$/', $municipio)) {
                $errors[] = 'Código IBGE do município deve ter 7 dígitos';
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'environment' => $manager->isProduction() ? 'production' : 'development',
                'config_summary' => [
                    'uf' => $manager->get('uf'),
                    'municipio' => $manager->get('municipio_ibge'),
                    'has_csc' => ! empty($manager->get('csc')),
                    'ambiente' => $manager->get('ambiente', 2),
                ],
            ];
        }, 'validate_complete_config');
    }
}
