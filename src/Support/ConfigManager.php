<?php

namespace freeline\FiscalCore\Support;

/**
 * Singleton para gerenciamento de configurações fiscais
 * 
 * Centraliza configurações compartilhadas entre adapters e carrega
 * automaticamente certificados e configurações de diferentes fontes.
 */
class ConfigManager
{
    const AMBIENTE_PRODUCAO = 1;
    const AMBIENTE_HOMOLOGACAO = 2;

    private static ?self $instance = null;
    private array $config = [];
    private bool $autoLoaded = false;
    private array $defaults = [
        'ambiente' => self::AMBIENTE_PRODUCAO, // 2=homologação, 1=produção
        'timeout' => 60,
        'proxy' => [
            'ip' => '',
            'port' => '',
            'user' => '',
            'pass' => ''
        ],
        'versao_nfe' => '4.00',
        'versao_nfce' => '4.00',
        'serie_nfe' => '1',
        'serie_nfce' => '1',
        'schemas' => 'PL_009_V4',
        'csc' => '',
        'csc_id' => '000001',
        'uf' => 'SP',
        'municipio_ibge' => '3550308', // São Paulo
        'token_ibpt' => '',
        'nfse' => [
            'provider' => 'abrasf-v2-soap',
            'versao' => '2.02',
            'timeout' => 30
        ],
    ];

    private function __construct()
    {
        $this->config = $this->defaults;
        $this->autoLoadConfiguration();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Define uma configuração usando notação de ponto
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$this->config;

        foreach ($keys as $k) {
            if (!is_array($current)) {
                $current = [];
            }
            if (!array_key_exists($k, $current)) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    /**
     * Obtém uma configuração usando notação de ponto
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Obtém todas as configurações
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Obtém configurações específicas para NFe
     */
    public function getNFeConfig(): array
    {

        
        return [
            'tpAmb' => $this->get('ambiente'),
            'versao' => $this->get('versao_nfe'),
            'serie' => $this->get('serie_nfe'),
            'timeout' => $this->get('timeout'),
            'proxy' => $this->get('proxy'),
            'siglaUF' => $this->get('uf'),
            'schemes' => $this->get('schemas'),
            'cnpj' => CertificateManager::getInstance()->getCnpj(),
            'razaosocial' => CertificateManager::getInstance()->getRazaoSocial(),
        ];
    }

    /**
     * Obtém configurações específicas para NFSe
     */
    public function getNFSeConfig(): array
    {
        return [
            'ambiente' => $this->get('ambiente'),
            'timeout' => $this->get('nfse.timeout'),
            'provider' => $this->get('nfse.provider'),
            'versao' => $this->get('nfse.versao'),
            'proxy' => $this->get('proxy'),
            'municipio_ibge' => $this->get('municipio_ibge')
        ];
    }

    /**
     * Verifica se está em ambiente de produção
     */
    public function isProduction(): bool
    {
        return $this->get('ambiente') === 1;
    }

    /**
     * Verifica se está em ambiente de homologação
     */
    public function isHomologation(): bool
    {
        return $this->get('ambiente') === 2;
    }

    /**
     * Carrega configurações automaticamente de diferentes fontes
     */
    private function autoLoadConfiguration(): void
    {
        if ($this->autoLoaded) {
            return;
        }
        
        // 0. Carregar arquivo .env primeiro
        $this->loadEnvFile();
        
        // 1. Carregar de variáveis de ambiente
        $this->loadFromEnvironment();
        
        // 2. Carregar de arquivo de configuração Laravel (se existir)
        $this->loadFromLaravelConfig();
        
        // 3. Carregar certificado automaticamente
        $this->autoLoadCertificate();
        
        $this->autoLoaded = true;
    }
    
    /**
     * Carrega configurações das variáveis de ambiente
     */
    private function loadFromEnvironment(): void
    {
        $envMappings = [
            'FISCAL_ENVIRONMENT' => 'ambiente',
            'FISCAL_TIMEOUT' => 'timeout',
            'FISCAL_CNPJ' => 'empresa.cnpj',
            'FISCAL_RAZAO_SOCIAL' => 'empresa.razao_social',
            'FISCAL_IE' => 'empresa.inscricao_estadual',
            'FISCAL_IM' => 'empresa.inscricao_municipal',
            'FISCAL_NFE_SERIE' => 'serie_nfe',
            'FISCAL_XML_VERSION' => 'versao_nfe',
            'FISCAL_UF' => 'uf',
            'FISCAL_CERT_PATH' => 'certificado.cert_path',
            'FISCAL_CERT_PASSWORD' => 'certificado.cert_password'
        ];
        
        foreach ($envMappings as $envKey => $configKey) {
            $value = $_ENV[$envKey] ?? getenv($envKey);
            if ($value !== false && $value !== '') {
                // Converter ambiente para número
                if ($envKey === 'FISCAL_ENVIRONMENT') {
                    $value = ($value === 'producao' || $value === 'production') ? 1 : 2;
                } elseif (in_array($envKey, ['FISCAL_TIMEOUT', 'FISCAL_NFE_SERIE'])) {
                    $value = (int) $value;
                }
                $this->set($configKey, $value);
            }
        }
    }
    
    /**
     * Carrega configurações do Laravel (se disponível)
     * TODO: Implementar quando necessário
     */
    private function loadFromLaravelConfig(): void
    {
        // Implementação futura para integração Laravel
        // Por enquanto, usa apenas .env e variáveis de ambiente
    }
    
    /**
     * Carrega certificado automaticamente se configurado
     */
    private function autoLoadCertificate(): void
    {
        $certPath = $_ENV['FISCAL_CERT_PATH'] ?? getenv('FISCAL_CERT_PATH');
        $certPassword = $_ENV['FISCAL_CERT_PASSWORD'] ?? getenv('FISCAL_CERT_PASSWORD');
        
        // Sempre armazenar o caminho e senha se disponíveis
        // if ($certPath) {
        //     $this->set('certificado.cert_path', $certPath);
        // }
        // if ($certPassword) {
        //     $this->set('certificado.cert_password', $certPassword);
        // }
        
        if ($certPath && $certPassword && file_exists($certPath)) {
            try {
                // Usar CertificateManager para carregar o certificado
                $certManager = CertificateManager::getInstance();
                $certManager->loadFromFile($certPath, $certPassword);
                
                // Certificado carregado com sucesso
                // $this->set('certificado.carregado', true);
                // $this->set('certificado.erro', null);
                
                // Armazenar informações do certificado
                // $this->set('certificado.cnpj', $certManager->getCnpj());
                // $this->set('certificado.razao_social', $certManager->getRazaoSocial());
                
                $expirationDate = $certManager->getExpirationDate();
                if ($expirationDate) {
                    // $this->set('certificado.valido_ate', $expirationDate->format('Y-m-d'));
                    // $this->set('certificado.dias_restantes', $certManager->getDaysUntilExpiration());
                }
                
                $this->set('certificado.valido', $certManager->isValid());
                
            } catch (\Exception $e) {
                // Log silencioso do erro - não quebra a aplicação
                // $this->set('certificado.erro', $e->getMessage());
                // $this->set('certificado.carregado', false);
            }
        } else {
            // Certificado não configurado ou arquivo não existe
            // if ($certPath && !file_exists($certPath)) {
            //     // $this->set('certificado.erro', "Arquivo de certificado não encontrado: {$certPath}");
            // } elseif (!$certPath) {
            //     $this->set('certificado.erro', 'FISCAL_CERT_PATH não configurado');
            // } elseif (!$certPassword) {
            //     $this->set('certificado.erro', 'FISCAL_CERT_PASSWORD não configurado');
            // }
            // $this->set('certificado.carregado', false);
        }
    }
    
    /**
     * Verifica se o certificado foi carregado com sucesso
     */
    // public function isCertificateLoaded(): bool
    // {
    //     return (bool) $this->get('certificado.carregado', false);
    // }
    
    /**
     * Obtém erro do certificado, se houver
     */
    // public function getCertificateError(): ?string
    // {
    //     return $this->get('certificado.erro');
    // }
    
    public function load(array $config = []): void
    {
        $this->autoLoaded = false;
        $this->defaults = array_merge($this->defaults, $config);
        // $this->config = $this->defaults;
        if (!empty($config)) {
            foreach ($config as $key => $value) {
                $this->set($key, $value);
            }
            return;
        }
        $this->autoLoadConfiguration();
    }
    

    /**
     * Força recarregamento das configurações
     */
    public function reload(): void
    {
        $this->autoLoaded = false;
        $this->config = $this->defaults;
        $this->autoLoadConfiguration();
    }

    public function reloadFromCurrentEnvironment(): void
    {
        $this->autoLoaded = false;
        $this->config = $this->defaults;
        $this->loadFromEnvironment();
        $this->loadFromLaravelConfig();
        $this->autoLoadCertificate();
        $this->autoLoaded = true;
    }
    
    /**
     * Obtém a instância do CertificateManager pronta para uso
     */
    // public function getCertificateManager(): ?CertificateManager
    // {
    //     if (!$this->isCertificateLoaded()) {
    //         return null;
    //     }
        
    //     return CertificateManager::getInstance();
    // }
    
    /**
     * Obtém informações completas do certificado
     */
    // public function getCertificateInfo(): array
    // {
    //     $certManager = $this->getCertificateManager();
    //     if (!$certManager) {
    //         return [
    //             'carregado' => false,
    //             'erro' => $this->getCertificateError()
    //         ];
    //     }
        
    //     return [
    //         'carregado' => true,
    //         'cnpj' => $certManager->getCnpj(),
    //         'razao_social' => $certManager->getRazaoSocial(),
    //         'valido' => $certManager->isValid(),
    //         'dias_restantes' => $certManager->getDaysUntilExpiration(),
    //         'expiracao' => $certManager->getExpirationDate()?->format('d/m/Y H:i:s'),
    //         'emissor' => $certManager->getCertificateInfo()['issuer'] ?? null
    //     ];
    // }
    
    /**
     * Obtém configurações da empresa
     */
    public function getEmpresaConfig(): array
    {
        return [
            'cnpj' => $this->get('empresa.cnpj'),
            'razao_social' => $this->get('empresa.razao_social'),
            'inscricao_estadual' => $this->get('empresa.inscricao_estadual'),
            'inscricao_municipal' => $this->get('empresa.inscricao_municipal'),
        ];
    }
    
    /**
     * Carrega arquivo .env automaticamente
     */
    private function loadEnvFile(): void
    {
        $possiblePaths = [
            getcwd() . '/.env',                    // Diretório atual
            dirname(__DIR__, 3) . '/.env',         // 3 níveis acima (projeto)
            dirname(__DIR__, 4) . '/.env',         // 4 níveis acima (Laravel)
            dirname(__DIR__, 2) . '/.env',         // 2 níveis acima
            __DIR__ . '/../../.env',               // Relativo ao Support
        ];
        
        foreach ($possiblePaths as $envPath) {
            if (file_exists($envPath)) {
                $this->parseEnvFile($envPath);
                break;
            }
        }
    }
    
    /**
     * Parse do arquivo .env
     */
    private function parseEnvFile(string $envPath): void
    {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Pular comentários
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Encontrar variáveis no formato KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remover aspas se existirem
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                // Definir como variável de ambiente se ainda não existe
                if (!isset($_ENV[$key]) && getenv($key) === false) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
    
    /**
     * Carrega arquivo .env específico manualmente
     */
    public function loadEnv(string $envPath): bool
    {
        if (!file_exists($envPath)) {
            return false;
        }
        
        $this->parseEnvFile($envPath);
        
        // Recarregar configurações após carregar .env
        $this->autoLoaded = false;
        $this->autoLoadConfiguration();
        
        return true;
    }
}
