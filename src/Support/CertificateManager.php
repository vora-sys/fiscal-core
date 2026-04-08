<?php

namespace sabbajohn\FiscalCore\Support;

use NFePHP\Common\Certificate;
use NFePHP\Common\Certificate\CertificationChain;
use NFePHP\Common\Certificate\PrivateKey;
use NFePHP\Common\Certificate\PublicKey;
use NFePHP\Common\Exception\InvalidArgumentException;
use RuntimeException;

/**
 * Singleton para gerenciamento de certificados digitais
 * 
 * Centraliza o carregamento e configuração de certificados A1 (.pfx)
 * para uso em operações fiscais (NFe, NFCe, NFSe)
 */
class CertificateManager
{
    private static ?self $instance = null;
    private ?Certificate $certificate = null;
    private ?string $certificateContent = null;
    private ?string $certificatePassword = null;
    private array $config = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->loadCertificate();
        }
        return self::$instance;
    }

    /**
     * Carrega certificado a partir de arquivo .pfx
     */
    public function loadFromFile(string $pfxPath, string $password): self
    {
        $resolvedPath = $this->resolvePath($pfxPath);
        if ($resolvedPath === null || !file_exists($resolvedPath)) {
            throw new InvalidArgumentException("Certificado não encontrado: {$pfxPath}");
        }

        $this->resolveOpenSslConf();

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            throw new InvalidArgumentException("Não foi possível ler o certificado: {$resolvedPath}");
        }

        try {
            $instance = $this->loadFromContent($content, $password);
            $this->config['cert_path'] = $resolvedPath;

            return $instance;
        } catch (\Throwable $e) {
            return $this->loadLegacyFromFile($resolvedPath, $password, $e);
        }
    }

    /**
     * Carrega certificado a partir do conteúdo em string
     */
    public function loadFromContent(string $pfxContent, string $password): self
    {
        $this->resolveOpenSslConf();
        try {
            $this->certificate = Certificate::readPfx($pfxContent, $password);
            $this->certificateContent = $pfxContent;
            $this->certificatePassword = $password;

            // Carrega informações do certificado
            $this->loadCertificateInfo();

            return $this;
        } catch (\Exception $e) {
            return $this->loadLegacyFromContent($pfxContent, $password, $e);
            // throw new InvalidArgumentException("Erro ao carregar certificado: " . $e->getMessage(), 0, $e);
        }
    }

    private function loadLegacyFromContent(string $pfxContent, string $password, \Throwable $previous): self
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cert_');
        if ($tmpFile === false) {
            throw new InvalidArgumentException(
                'Erro ao carregar certificado: ' . $previous->getMessage(),
                0,
                $previous
            );
        }

        try {
            file_put_contents($tmpFile, $pfxContent);
            return $this->loadLegacyFromFile($tmpFile, $password, $previous);
        } finally {
            @unlink($tmpFile);
        }
    }

    public static function isLoaded(): bool
    {
        $instance = self::getInstance();
        return $instance->certificate !== null;
    }
    
    public static function reload(): void
    {
        $instance = self::getInstance();
        $instance->clear();
        $instance->loadCertificate();
    }

    private function loadCertificate(): void
    {
        $certPath = $_ENV['FISCAL_CERT_PATH'] ?? getenv('FISCAL_CERT_PATH');
        $certPassword = $_ENV['FISCAL_CERT_PASSWORD'] ?? getenv('FISCAL_CERT_PASSWORD');

        if ($certPath && $certPassword) {
            try {
                $this->loadFromFile((string) $certPath, (string) $certPassword);
            } catch (\Exception $e) {
                $this->config['load_error'] = $e->getMessage();
            }
        }
    }

    /**
     * Retorna a instância do certificado NFePHP
     */
    public function getCertificate(): ?Certificate
    {
        return $this->certificate;
    }

    /**
     * Retorna o conteúdo original do certificado
     */
    public function getCertificateContent(): ?string
    {
        return $this->certificateContent;
    }

    /**
     * Retorna a senha do certificado
     */
    public function getCertificatePassword(): ?string
    {
        return $this->certificatePassword;
    }

    /**
     * Retorna informações do certificado
     */
    public function getCertificateInfo(): array
    {
        return $this->config;
    }

    /**
     * Retorna o CNPJ do certificado
     */
    public function getCnpj(): ?string
    {
        return $this->config['cnpj'] ?? null;
    }

    /**
     * Retorna a razão social do certificado
     */
    public function getRazaoSocial(): ?string
    {
        return $this->config['razao_social'] ?? null;
    }

    /**
     * Verifica se o certificado está válido (não expirado)
     */
    public function isValid(): bool
    {
        if (!$this->certificate) {
            return false;
        }

        $validTo = $this->config['valid_to'] ?? null;
        if (!$validTo) {
            return false;
        }

        return time() < $validTo;
    }

    /**
     * Retorna quantos dias restam para expiração
     */
    public function getDaysUntilExpiration(): ?int
    {
        $validTo = $this->config['valid_to'] ?? null;
        if (!$validTo) {
            return null;
        }

        $diff = $validTo - time();
        return max(0, (int) ceil($diff / 86400)); // 86400 = segundos em um dia
    }

    /**
     * Limpa o certificado carregado
     */
    public function clear(): self
    {
        $this->certificate = null;
        $this->certificateContent = null;
        $this->certificatePassword = null;
        $this->config = [];
        return $this;
    }

    /**
     * Carrega informações detalhadas do certificado
     */
    private function loadCertificateInfo(): void
    {
        if (!$this->certificate) {
            return;
        }

        try {
            
            $this->config = [
                'cnpj' => $this->certificate->getCnpj() ?? $this->certificate->getCpf(),
                'razao_social' => $this->certificate->getCompanyName(),
                'valid_from' => $this->certificate->getValidFrom()->getTimestamp(),
                'valid_to' => $this->certificate->getValidTo()->getTimestamp(),
                'issuer' => $this->certificate->getCSP(),
            ];
        } catch (\Exception $e) {
            // Se falhar, mantém array vazio
            $this->config = [];
        }
    }

    public function getExpirationDate(): ?\DateTime
    {
        if (!$this->certificate) {
            return null;
        }
        return $this->certificate->getValidTo();
    }

    private function loadLegacyFromFile(string $resolvedPath, string $password, \Throwable $previous): self
    {
        try {
            $publicCert = $this->runOpenSslPkcs12Command([
                'openssl', 'pkcs12', '-legacy', '-clcerts', '-nokeys',
                '-passin', 'pass:' . $password, '-in', $resolvedPath,
            ]);
            $privateKey = $this->runOpenSslPkcs12Command([
                'openssl', 'pkcs12', '-legacy', '-nocerts', '-nodes',
                '-passin', 'pass:' . $password, '-in', $resolvedPath,
            ]);
            $chain = $this->runOpenSslPkcs12Command([
                'openssl', 'pkcs12', '-legacy', '-cacerts', '-nokeys',
                '-passin', 'pass:' . $password, '-in', $resolvedPath,
            ], true);

            $this->certificate = new Certificate(
                new PrivateKey($this->extractPrivateKeyPem($privateKey)),
                new PublicKey($this->extractFirstPemBlock($publicCert, 'CERTIFICATE')),
                new CertificationChain($this->extractAllPemBlocks($chain, 'CERTIFICATE'))
            );
            $this->certificateContent = (string) file_get_contents($resolvedPath);
            $this->certificatePassword = $password;
            $this->loadCertificateInfo();
            $this->config['cert_path'] = $resolvedPath;

            return $this;
        } catch (\Throwable $legacyException) {
            throw new InvalidArgumentException(
                'Erro ao carregar certificado: ' . $previous->getMessage(),
                0,
                $legacyException
            );
        }
    }

    private function resolveOpenSslConf(): void
    {
        $opensslConf = $_ENV['OPENSSL_CONF'] ?? getenv('OPENSSL_CONF');
        if (!is_string($opensslConf) || trim($opensslConf) === '') {
            return;
        }

        $resolved = $this->resolvePath($opensslConf);
        if ($resolved === null) {
            return;
        }

        $_ENV['OPENSSL_CONF'] = $resolved;
        putenv('OPENSSL_CONF=' . $resolved);
    }

    private function resolvePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $candidates = [];

        if (str_starts_with($normalized, '/')) {
            $candidates[] = $normalized;
        } else {
            $candidates[] = getcwd() . '/' . $normalized;
            $candidates[] = $projectRoot . '/' . ltrim($normalized, './');
            if (str_contains($normalized, 'certs/')) {
                $candidates[] = $projectRoot . '/certs/' . basename($normalized);
            }
            if (basename($normalized) === 'openssl.cnf') {
                $candidates[] = $projectRoot . '/openssl.cnf';
            }
        }

        foreach ($candidates as $candidate) {
            $realPath = realpath($candidate);
            if (is_string($realPath) && $realPath !== '') {
                return $realPath;
            }
        }

        return null;
    }

    private function runOpenSslPkcs12Command(array $command, bool $allowEmptyOutput = false): string
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Nao foi possivel iniciar o comando openssl para ler o certificado legado.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Falha ao ler certificado legado com openssl: ' . trim((string) $stderr));
        }

        $output = trim((string) $stdout);
        if ($output === '' && !$allowEmptyOutput) {
            throw new RuntimeException('Saida vazia ao ler certificado legado com openssl.');
        }

        return $output;
    }

    private function extractPrivateKeyPem(string $content): string
    {
        foreach (['PRIVATE KEY', 'ENCRYPTED PRIVATE KEY', 'RSA PRIVATE KEY'] as $type) {
            $blocks = $this->extractPemBlocks($content, $type);
            if ($blocks !== []) {
                return $blocks[0];
            }
        }

        throw new RuntimeException('Bloco PEM de chave privada nao encontrado na saida do openssl.');
    }

    private function extractFirstPemBlock(string $content, string $type): string
    {
        $blocks = $this->extractPemBlocks($content, $type);
        if ($blocks === []) {
            throw new RuntimeException("Bloco PEM {$type} nao encontrado na saida do openssl.");
        }

        return $blocks[0];
    }

    private function extractAllPemBlocks(string $content, string $type): string
    {
        return implode('', $this->extractPemBlocks($content, $type));
    }

    /**
     * @return string[]
     */
    private function extractPemBlocks(string $content, string $type): array
    {
        $pattern = sprintf('/-----BEGIN %1$s-----(.*?)-----END %1$s-----/s', preg_quote($type, '/'));
        preg_match_all($pattern, $content, $matches);

        if (!isset($matches[0]) || !is_array($matches[0])) {
            return [];
        }

        return array_map(
            static fn (string $block): string => trim($block) . PHP_EOL,
            array_values(array_filter($matches[0], static fn (string $block): bool => trim($block) !== ''))
        );
    }

    // Previne clonagem e serialização
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
