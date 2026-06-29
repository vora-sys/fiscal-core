<?php

namespace sabbajohn\FiscalCore\Support;

use NFePHP\NFe\Tools as NFeTools;
use NFePHP\DA\NFe\Danfe as DanfeNFe;
use NFePHP\DA\NFe\Danfe as DanfeNFCe;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\ResponseHandler;
use sabbajohn\FiscalCore\Exceptions\CertificateException;
use sabbajohn\FiscalCore\Exceptions\ValidationException;

/**
 * Factory para criação de Tools NFePHP com configuração centralizada
 * 
 * Utiliza os singletons CertificateManager e ConfigManager
 * para fornecer instâncias pré-configuradas com tratamento de erros
 */
class ToolsFactory
{
    private static ResponseHandler $responseHandler;
    
    private static function getResponseHandler(): ResponseHandler
    {
        if (!isset(self::$responseHandler)) {
            self::$responseHandler = new ResponseHandler();
        }
        return self::$responseHandler;
    }

    /**
     * Cria instância de Tools para NFe (versão safe)
     * @return FiscalResponse Com Tools ou erro tratado
     */
    public static function createNFeToolsSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function() {
            return self::createNFeTools();
        }, 'create_nfe_tools');
    }

    /**
     * Cria instância de Tools para NFCe (versão safe)
     * @return FiscalResponse Com Tools ou erro tratado
     */
    public static function createNFCeToolsSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function() {
            return self::createNFCeTools();
        }, 'create_nfce_tools');
    }
    /**
     * Cria instância de Tools para NFe
     * @throws CertificateException Se certificado não estiver carregado ou válido
     * @throws ValidationException Se configuração inválida
     */
    public static function createNFeTools(): NFeTools
    {
        return self::createToolsForModel(55);
    }

    /**
     * Cria instância de Tools para NFCe
     */
    public static function createNFCeTools(): NFeTools
    {
        $tools = self::createToolsForModel(65);

        $qrCodeVersion = ConfigManager::getInstance()->get('nfce_qrcode_version');
        if (in_array((string) $qrCodeVersion, ['200', '300'], true)) {
            $tools->forceQRCodeVersion((string) $qrCodeVersion);
        }

        return $tools;
    }

    private static function createToolsForModel(int $model): NFeTools
    {
        $configManager = ConfigManager::getInstance();
        $certManager = CertificateManager::getInstance();

        if (!$certManager->isLoaded()) {
            throw CertificateException::notLoaded();
        }

        if (!$certManager->isValid()) {
            throw CertificateException::expired();
        }

        try {
            $config = json_encode(
                NFeCompatibility::normalizeToolsConfig($configManager->getNFeConfig($model)),
                JSON_THROW_ON_ERROR
            );
            $certificate = $certManager->getCertificate();

            $tools = new NFeTools($config, $certificate);
            $tools->model($model);

            return $tools;
        } catch (\Exception $e) {
            throw new ValidationException(
                'Erro ao criar NFeTools: ' . $e->getMessage(),
                0,
                $e,
                [
                    'suggestions' => [
                        'Verifique as configurações de NFe',
                        'Confirme se o certificado está válido',
                        'Valide o formato da configuração JSON'
                    ]
                ]
            );
        }
    }

    /**
     * Cria instância de DANFE para NFe
     */
    public static function createDanfeNFe(string $xml): DanfeNFe
    {
        return new DanfeNFe($xml);
    }

    /**
     * Cria instância de DANFCE para NFCe
     */
    public static function createDanfeNFCe(string $xml): DanfeNFCe
    {
        return new DanfeNFCe($xml);
    }

    /**
     * Verifica se o ambiente está configurado corretamente (versão safe)
     * @return FiscalResponse Com resultado da validação
     */
    public static function validateEnvironmentSafe(): FiscalResponse
    {
        return self::getResponseHandler()->handle(function() {
            return self::validateEnvironment();
        }, 'validate_environment');
    }

    /**
     * Verifica se o ambiente está configurado corretamente
     */
    public static function validateEnvironment(): array
    {
        $errors = [];
        $warnings = [];

        $certManager = CertificateManager::getInstance();
        $configManager = ConfigManager::getInstance();

        // Verifica certificado
        if (!$certManager->isLoaded()) {
            $errors[] = 'Certificado digital não carregado';
        } elseif (!$certManager->isValid()) {
            $errors[] = 'Certificado digital expirado';
        } else {
            $daysLeft = $certManager->getDaysUntilExpiration();
            if ($daysLeft !== null && $daysLeft < 30) {
                $warnings[] = "Certificado expira em {$daysLeft} dias";
            }
        }

        // Verifica configurações obrigatórias
        $requiredConfigs = ['uf', 'municipio_ibge'];
        foreach ($requiredConfigs as $config) {
            if (!$configManager->get($config)) {
                $errors[] = "Configuração obrigatória não definida: {$config}";
            }
        }

        // Verifica CSC para NFCe
        if ($configManager->isProduction() && !$configManager->get('csc')) {
            $warnings[] = 'CSC não configurado - necessário para NFCe em produção';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'certificate_info' => $certManager->getCertificateInfo(),
            'environment' => $configManager->isProduction() ? 'Produção' : 'Homologação'
        ];
    }

    /**
     * Configuração rápida para desenvolvimento/testes (versão safe)
     * @return FiscalResponse Resultado da configuração
     */
    public static function setupForDevelopmentSafe(array $config = []): FiscalResponse
    {
        return self::getResponseHandler()->handle(function() use ($config) {
            self::setupForDevelopment($config);
            return ['configured' => true, 'environment' => 'development'];
        }, 'setup_development');
    }

    /**
     * Configuração rápida para produção (versão safe)
     * @return FiscalResponse Resultado da configuração
     */
    public static function setupForProductionSafe(array $config = []): FiscalResponse
    {
        return self::getResponseHandler()->handle(function() use ($config) {
            self::setupForProduction($config);
            return ['configured' => true, 'environment' => 'production'];
        }, 'setup_production');
    }

    /**
     * Configuração rápida para desenvolvimento/testes
     */
    public static function setupForDevelopment(array $config = []): void
    {
        $configManager = ConfigManager::getInstance();
        
        $defaultConfig = [
            'ambiente' => 2, // homologação
            'uf' => 'SP',
            'municipio_ibge' => '3550308',
            'versao_nfe' => NFeCompatibility::DEFAULT_XML_VERSION,
            'versao_nfce' => NFeCompatibility::DEFAULT_XML_VERSION,
            'schemas' => NFeCompatibility::DEFAULT_SCHEMA,
            'serie_nfe' => '1',
            'serie_nfce' => '1',
            'csc' => 'GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G', // CSC de exemplo
            'csc_id' => '000001',
        ];

        $configManager->load(array_merge($defaultConfig, $config));
    }

    /**
     * Configuração rápida para produção
     */
    public static function setupForProduction(array $config = []): void
    {
        $configManager = ConfigManager::getInstance();
        
        $requiredConfigs = ['csc', 'csc_id', 'uf', 'municipio_ibge'];
        $missingConfigs = [];
        
        foreach ($requiredConfigs as $required) {
            if (!isset($config[$required])) {
                $missingConfigs[] = $required;
            }
        }
        
        if (!empty($missingConfigs)) {
            throw ValidationException::multipleErrors(
                array_fill_keys($missingConfigs, 'Campo obrigatório para produção'),
                'configuração de produção'
            );
        }

        $productionConfig = array_merge([
            'ambiente' => 1, // produção
            'versao_nfe' => NFeCompatibility::DEFAULT_XML_VERSION,
            'versao_nfce' => NFeCompatibility::DEFAULT_XML_VERSION,
            'schemas' => NFeCompatibility::DEFAULT_SCHEMA,
            'serie_nfe' => '1',
            'serie_nfce' => '1',
        ], $config);

        $configManager->load($productionConfig);
    }
}
