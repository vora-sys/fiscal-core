<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Support;

use freeline\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use InvalidArgumentException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Certificate\CertificationChain;
use NFePHP\Common\Certificate\PrivateKey;
use NFePHP\Common\Certificate\PublicKey;
use RuntimeException;

final class NFSeMunicipalHomologationService
{
    private const MANAGED_ENV_KEYS = [
        'FISCAL_ENVIRONMENT',
        'FISCAL_IM',
        'FISCAL_RAZAO_SOCIAL',
        'FISCAL_CNPJ',
        'FISCAL_UF',
        'FISCAL_CERT_PATH',
        'FISCAL_CERT_PASSWORD',
        'OPENSSL_CONF',
        'FISCAL_SIMPLES_NACIONAL',
    ];

    /** @var null|callable */
    private $tomadorLookup;

    public function __construct(
        private readonly string $projectRoot,
        ?callable $tomadorLookup = null,
        private readonly ?NFSeMunicipalPayloadFactory $payloadFactory = null
    ) {
        $this->tomadorLookup = $tomadorLookup;
    }

    public function bootstrapEnvironment(?string $envPath = null, array $envOverrides = []): array
    {
        $envPath ??= $this->projectRoot . '/.env';
        $configManager = ConfigManager::getInstance();
        if (is_file($envPath)) {
            $this->resetManagedEnvironment();
            $this->applyEnvFile($envPath);
        } else {
            $configManager->reload();
        }

        foreach ($envOverrides as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $_ENV[$key] = (string) $value;
            putenv($key . '=' . (string) $value);
        }

        $resolved = [];
        foreach (['FISCAL_CERT_PATH', 'OPENSSL_CONF'] as $pathKey) {
            $value = $_ENV[$pathKey] ?? getenv($pathKey);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $resolvedPath = $this->resolvePath(trim($value), dirname($envPath));
            if ($resolvedPath === null) {
                continue;
            }

            $_ENV[$pathKey] = $resolvedPath;
            putenv($pathKey . '=' . $resolvedPath);
            $resolved[$pathKey] = $resolvedPath;
        }

        $configManager->reloadFromCurrentEnvironment();
        CertificateManager::reload();
        ProviderRegistry::getInstance()->reload();

        return [
            'env_path' => $envPath,
            'resolved_paths' => $resolved,
            'empresa' => $configManager->getEmpresaConfig(),
            'ambiente' => $configManager->isHomologation() ? 'homologacao' : 'producao',
            'certificate_loaded' => CertificateManager::isLoaded(),
        ];
    }

    public function preview(string $municipio, string $tomadorDocumento, array $options = []): array
    {
        $context = $this->buildContext($municipio, $tomadorDocumento, $options);
        $provider = $this->makeProvider($context, true);
        $provider->emitir($context['payload']);

        return $this->buildResultPayload($context, $provider, 'preview');
    }

    public function send(string $municipio, string $tomadorDocumento, array $options = []): array
    {
        $context = $this->buildContext($municipio, $tomadorDocumento, $options);
        $provider = $this->makeProvider($context, false);
        $provider->emitir($context['payload']);

        return $this->buildResultPayload($context, $provider, 'send');
    }

    private function buildContext(string $municipio, string $tomadorDocumento, array $options): array
    {
        $factory = $this->payloadFactory ?? new NFSeMunicipalPayloadFactory();
        $meta = $factory->providerMeta($municipio);

        $bootstrap = $this->bootstrapEnvironment(
            $options['env_path'] ?? null,
            $options['env_overrides'] ?? []
        );

        $configManager = ConfigManager::getInstance();
        if (
            !$configManager->isHomologation()
            && !($options['allow_production'] ?? false)
        ) {
            throw new RuntimeException('Execucao em producao exige allow_production=true no script real.');
        }

        $empresaConfig = $configManager->getEmpresaConfig();
        if (
            $meta['provider_key'] !== ProviderRegistry::NFSE_NATIONAL_KEY
            && trim((string) ($empresaConfig['inscricao_municipal'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException('FISCAL_IM é obrigatório para emissão NFSe municipal.');
        }

        $certificate = $this->resolveCertificate(
            $bootstrap['resolved_paths']['FISCAL_CERT_PATH'] ?? null,
            (string) (
                $options['env_overrides']['FISCAL_CERT_PASSWORD']
                ?? $_ENV['FISCAL_CERT_PASSWORD']
                ?? getenv('FISCAL_CERT_PASSWORD')
                ?? ''
            )
        );

        $prestador = $factory->buildPrestador(
            $municipio,
            $certificate,
            $empresaConfig,
            $options['prestador_options'] ?? []
        );

        $tomadorLookupData = $this->lookupTomador(
            $tomadorDocumento,
            $options['tomador_defaults'] ?? []
        );
        $tomador = $factory->buildTomadorFromLookup($municipio, $tomadorDocumento, $tomadorLookupData);
        $warnings = $this->validateTomador($tomador, $meta);

        $payload = $factory->real(
            $municipio,
            $prestador,
            $tomador,
            $options['payload_overrides'] ?? []
        );

        return [
            'bootstrap' => $bootstrap,
            'meta' => $meta,
            'prestador' => $prestador,
            'tomador' => $tomador,
            'payload' => $payload,
            'certificate' => $certificate,
            'warnings' => $warnings,
            'debug_http' => (bool) ($options['debug_http'] ?? false),
            'provider_config_overrides' => is_array($options['provider_config_overrides'] ?? null)
                ? $options['provider_config_overrides']
                : [],
        ];
    }

    private function buildResultPayload(array $context, object $provider, string $mode): array
    {
        if (!$provider instanceof NFSeOperationalIntrospectionInterface) {
            throw new RuntimeException('Provider municipal sem suporte a introspeccao operacional.');
        }

        return [
            'mode' => $mode,
            'provider' => [
                'key' => $context['meta']['provider_key'],
                'class' => $provider::class,
                'municipio' => $context['meta']['slug'],
                'codigo_municipio' => $context['meta']['codigo_municipio'],
                'ambiente' => method_exists($provider, 'getAmbiente') ? $provider->getAmbiente() : 'homologacao',
                'wsdl' => method_exists($provider, 'getWsdlUrl') ? $provider->getWsdlUrl() : '',
            ],
            'certificate' => [
                'cnpj' => $context['certificate']->getCnpj() ?: $context['certificate']->getCpf(),
                'razao_social' => $context['certificate']->getCompanyName(),
                'valid_to' => $context['certificate']->getValidTo()->format('Y-m-d H:i:s'),
            ],
            'prestador' => $context['prestador'],
            'tomador' => $context['tomador'],
            'payload' => $context['payload'],
            'request_xml' => $provider->getLastRequestXml(),
            'soap_envelope' => $provider->getLastSoapEnvelope(),
            'parsed_response' => $provider->getLastResponseData(),
            'warnings' => $context['warnings'],
            'resolved_paths' => $context['bootstrap']['resolved_paths'],
        ];
    }

    private function makeProvider(array $context, bool $preview): object
    {
        $registry = ProviderRegistry::getInstance();
        $config = $registry->getConfig($context['meta']['provider_key']);
        $config['certificate'] = $context['certificate'];
        $config['prestador'] = $context['prestador'];
        $config['ambiente'] = $context['bootstrap']['ambiente'];

        if ($context['debug_http']) {
            $config['debug_http'] = true;
        }

        if ($preview) {
            $config['soap_transport'] = NFSeMunicipalPreviewSupport::makeTransport($context['meta']['slug']);
        }

        foreach ($context['provider_config_overrides'] as $key => $value) {
            $config[$key] = $value;
        }

        $providerClass = $config['provider_class'] ?? null;
        if (!is_string($providerClass) || $providerClass === '' || !class_exists($providerClass)) {
            throw new RuntimeException('Provider class municipal nao encontrada para execucao real.');
        }

        return new $providerClass($config);
    }

    private function lookupTomador(string $tomadorDocumento, array $defaults = []): array
    {
        $documento = preg_replace('/\D+/', '', $tomadorDocumento) ?? '';
        if (!in_array(strlen($documento), [11, 14], true)) {
            throw new InvalidArgumentException('O documento do tomador deve conter 11 digitos (CPF) ou 14 digitos (CNPJ).');
        }

        $lookup = [
            'razao_social' => trim((string) ($defaults['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($defaults['nome_fantasia'] ?? '')),
            'email' => trim((string) ($defaults['email'] ?? '')),
            'telefone' => trim((string) ($defaults['telefone'] ?? '')),
            'endereco' => is_array($defaults['endereco'] ?? null) ? $defaults['endereco'] : [],
        ];

        if ($this->tomadorLookup !== null) {
            $result = ($this->tomadorLookup)($documento);
            if (!is_array($result)) {
                throw new RuntimeException('Tomador lookup customizado deve retornar array.');
            }

            $lookup = $this->mergeRecursiveDistinct($lookup, $result);
        }

        $utils = new \freeline\FiscalCore\Facade\UtilsFacade();

        if (strlen($documento) === 14 && !$this->hasMinimumTomadorIdentity($lookup)) {
            $response = $utils->consultarCNPJ($documento);
            if (!$response->isSuccess()) {
                throw new RuntimeException('Falha ao consultar CNPJ do tomador: ' . $response->getError());
            }

            $data = $response->getData();
            if (!is_array($data)) {
                throw new RuntimeException('Resposta invalida da consulta de CNPJ do tomador.');
            }

            $lookup = $this->mergeRecursiveDistinct($lookup, $data);
        }

        $cep = preg_replace(
            '/\D+/',
            '',
            (string) (($lookup['endereco']['cep'] ?? '') !== '' ? $lookup['endereco']['cep'] : ($defaults['cep'] ?? ''))
        ) ?? '';

        if ($cep !== '' && !$this->hasMinimumTomadorAddress($lookup)) {
            $cepResponse = $utils->consultarCEP($cep);
            if ($cepResponse->isSuccess()) {
                $cepData = $cepResponse->getData();
                if (!is_array($cepData)) {
                    throw new RuntimeException('Resposta invalida da consulta de CEP do tomador.');
                }

                $lookup = $this->mergeRecursiveDistinct($lookup, [
                    'endereco' => [
                        'logradouro' => trim((string) ($cepData['logradouro'] ?? '')),
                        'bairro' => trim((string) ($cepData['bairro'] ?? '')),
                        'municipio' => trim((string) ($cepData['localidade'] ?? '')),
                        'uf' => trim((string) ($cepData['uf'] ?? '')),
                        'codigo_municipio' => trim((string) ($cepData['ibge'] ?? '')),
                        'cep' => preg_replace('/\D+/', '', (string) ($cepData['cep'] ?? $cep)) ?? $cep,
                    ],
                ]);
            }

            if (!$this->hasMinimumTomadorAddress($lookup)) {
                $lookup = $this->mergeRecursiveDistinct($lookup, [
                    'endereco' => $this->knownAddressFallbackByCep($cep),
                ]);
            }

            if (!$this->hasMinimumTomadorAddress($lookup) && !$cepResponse->isSuccess()) {
                throw new RuntimeException('Falha ao consultar CEP do tomador: ' . $cepResponse->getError());
            }
        }

        return $lookup;
    }

    private function validateTomador(array $tomador, array $meta): array
    {
        $missing = [];
        if (trim((string) ($tomador['razao_social'] ?? '')) === '') {
            $missing[] = 'razao_social';
        }

        $endereco = is_array($tomador['endereco'] ?? null) ? $tomador['endereco'] : [];
        foreach (['logradouro', 'bairro', 'cep'] as $field) {
            if (trim((string) ($endereco[$field] ?? '')) === '') {
                $missing[] = 'endereco.' . $field;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Endereco minimo do tomador incompleto para homologacao: ' . implode(', ', $missing)
            );
        }

        $warnings = [];
        if (trim((string) ($endereco['codigo_municipio'] ?? '')) === $meta['codigo_municipio']) {
            $warnings[] = 'codigo_municipio do tomador preenchido com fallback do municipio-alvo.';
        }

        return $warnings;
    }

    private function resolveCertificate(?string $preferredPath = null, ?string $preferredPassword = null): Certificate
    {
        $certificateManager = CertificateManager::getInstance();
        $certificate = $certificateManager->getCertificate();
        if ($certificate instanceof Certificate && $certificateManager->isValid()) {
            return $certificate;
        }

        $path = $preferredPath ?: ($_ENV['FISCAL_CERT_PATH'] ?? getenv('FISCAL_CERT_PATH'));
        $password = $preferredPassword ?: ($_ENV['FISCAL_CERT_PASSWORD'] ?? getenv('FISCAL_CERT_PASSWORD'));
        if (!is_string($path) || trim($path) === '' || !is_string($password) || $password === '') {
            throw new RuntimeException('Certificado digital obrigatorio para emissao NFSe municipal.');
        }

        try {
            $certificateManager->loadFromFile($path, $password);
            $loaded = $certificateManager->getCertificate();
            if ($loaded instanceof Certificate && $certificateManager->isValid()) {
                return $loaded;
            }
        } catch (\Throwable) {
            // tenta fallback legacy abaixo
        }

        $legacy = $this->loadLegacyCertificate($path, $password);
        if (!$legacy instanceof Certificate || $legacy->isExpired()) {
            throw new RuntimeException('Certificado digital expirado ou invalido para emissao NFSe municipal.');
        }

        return $legacy;
    }

    private function loadLegacyCertificate(string $path, string $password): Certificate
    {
        if (!is_file($path)) {
            throw new RuntimeException("Arquivo de certificado nao encontrado: {$path}");
        }

        $publicCert = $this->runOpenSslPkcs12Command(
            ['openssl', 'pkcs12', '-legacy', '-clcerts', '-nokeys', '-passin', 'pass:' . $password, '-in', $path]
        );
        $privateKey = $this->runOpenSslPkcs12Command(
            ['openssl', 'pkcs12', '-legacy', '-nocerts', '-nodes', '-passin', 'pass:' . $password, '-in', $path]
        );
        $chain = $this->runOpenSslPkcs12Command(
            ['openssl', 'pkcs12', '-legacy', '-cacerts', '-nokeys', '-passin', 'pass:' . $password, '-in', $path],
            true
        );

        return new Certificate(
            new PrivateKey($this->extractPrivateKeyPem($privateKey)),
            new PublicKey($this->extractFirstPemBlock($publicCert, 'CERTIFICATE')),
            new CertificationChain($this->extractAllPemBlocks($chain, 'CERTIFICATE'))
        );
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
            throw new RuntimeException(
                'Falha ao ler certificado legado com openssl: ' . trim((string) $stderr)
            );
        }

        $output = trim((string) $stdout);
        if ($output === '' && !$allowEmptyOutput) {
            throw new RuntimeException('Saida vazia ao ler certificado legado com openssl.');
        }

        return $output;
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

    /**
     * @return string[]
     */
    private function extractPemBlocks(string $content, string $type): array
    {
        $pattern = sprintf(
            '/-----BEGIN %1$s-----(.*?)-----END %1$s-----/s',
            preg_quote($type, '/')
        );

        preg_match_all($pattern, $content, $matches);
        if (!isset($matches[0]) || !is_array($matches[0])) {
            return [];
        }

        return array_map(
            static fn (string $block): string => trim($block) . PHP_EOL,
            array_values(array_filter($matches[0], static fn (string $block): bool => trim($block) !== ''))
        );
    }

    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function hasMinimumTomadorIdentity(array $lookup): bool
    {
        return trim((string) ($lookup['razao_social'] ?? '')) !== '';
    }

    private function hasMinimumTomadorAddress(array $lookup): bool
    {
        $endereco = is_array($lookup['endereco'] ?? null) ? $lookup['endereco'] : [];

        foreach (['logradouro', 'bairro', 'cep', 'municipio', 'uf'] as $field) {
            if (trim((string) ($endereco[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function knownAddressFallbackByCep(string $cep): array
    {
        return match (preg_replace('/\D+/', '', $cep) ?? '') {
            '66065112' => [
                'logradouro' => 'Avenida Jose Bonifacio',
                'bairro' => 'Guama',
                'municipio' => 'Belem',
                'uf' => 'PA',
                'codigo_municipio' => '1501402',
                'cep' => '66065112',
            ],
            '89220650' => [
                'logradouro' => 'Rua Walmor Harger',
                'bairro' => 'Costa e Silva',
                'municipio' => 'Joinville',
                'uf' => 'SC',
                'codigo_municipio' => '4209102',
                'cep' => '89220650',
            ],
            default => [],
        };
    }

    private function resolvePath(string $path, string $envDirectory): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '') {
            return null;
        }

        $candidates = [];
        if (str_starts_with($normalized, '/')) {
            $candidates[] = $normalized;
        } else {
            $candidates[] = $envDirectory . '/' . $normalized;
            $candidates[] = $this->projectRoot . '/' . ltrim($normalized, './');
            $candidates[] = getcwd() . '/' . $normalized;
            if (str_contains($normalized, 'certs/')) {
                $candidates[] = $this->projectRoot . '/certs/' . basename($normalized);
            }
            if (basename($normalized) === 'openssl.cnf') {
                $candidates[] = $this->projectRoot . '/openssl.cnf';
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

    private function resetManagedEnvironment(): void
    {
        foreach (self::MANAGED_ENV_KEYS as $key) {
            $_ENV[$key] = '';
            putenv($key . '=');
        }
    }

    private function applyEnvFile(string $envPath): void
    {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}
