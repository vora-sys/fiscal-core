<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use NFePHP\Common\Certificate;
use RuntimeException;

final class NFSeRuntimeBootstrap
{
    public function __construct(
        private readonly ?ProviderRegistry $registry = null,
        private readonly ?NFSeProviderResolver $resolver = null,
        private readonly ?ConfigManager $configManager = null,
        private readonly ?CertificateManager $certificateManager = null
    ) {
    }

    public function makeProvider(string $municipio, bool $requireOperationalCredentials = true): array
    {
        $configManager = $this->configManager ?? ConfigManager::getInstance();

        $certificateManager = $this->certificateManager ?? CertificateManager::getInstance();
        $certificate = $certificateManager->getCertificate();
        if (!$certificate instanceof Certificate) {
            CertificateManager::reload();
            $certificateManager = $this->certificateManager ?? CertificateManager::getInstance();
            $certificate = $certificateManager->getCertificate();
        }

        $registry = $this->registry ?? ProviderRegistry::getInstance();
        $resolver = $this->resolver ?? new NFSeProviderResolver();
        $providerKey = $resolver->resolveKey($municipio);
        $metadata = $resolver->buildMetadata($municipio);
        $config = $registry->getConfigForMunicipio($municipio);

        $config['ambiente'] = $configManager->isProduction() ? 'producao' : 'homologacao';
        $config['timeout'] = (int) ($configManager->get('nfse.timeout') ?? $configManager->get('timeout') ?? $config['timeout'] ?? 30);

        if ($certificate instanceof Certificate) {
            $config['certificate'] = $certificate;
        }

        $signatureMode = strtolower((string) ($config['signature_mode'] ?? 'optional'));
        if ($signatureMode === 'required' && $requireOperationalCredentials) {
            if (!$certificate instanceof Certificate) {
                throw new RuntimeException("Provider '{$providerKey}' requer certificado digital valido.");
            }

            if (!$certificateManager->isValid()) {
                throw new RuntimeException("Certificado digital invalido ou expirado para provider '{$providerKey}'.");
            }
        }

        if ($requireOperationalCredentials && $certificate instanceof Certificate) {
            $matchMode = $providerKey === ProviderRegistry::NFSE_NATIONAL_KEY
                ? strtolower((string) ($configManager->get('empresa.certificate_match_mode') ?? 'exact'))
                : 'exact';
            $this->assertCertificateCompatibility(
                (string) ($configManager->getEmpresaConfig()['cnpj'] ?? ''),
                (string) ($certificate->getCnpj() ?? $certificate->getCpf() ?? ''),
                $matchMode
            );
        }

        if ($providerKey !== ProviderRegistry::NFSE_NATIONAL_KEY && $requireOperationalCredentials) {
            $config['prestador'] = $this->buildPrestadorContext(
                $configManager->getEmpresaConfig(),
                $certificate,
                (string) ($config['codigo_municipio'] ?? '')
            );
        }

        $providerClass = $config['provider_class'] ?? null;
        if (!is_string($providerClass) || $providerClass === '' || !class_exists($providerClass)) {
            throw new RuntimeException("Provider class nao encontrada para '{$providerKey}'.");
        }

        $provider = new $providerClass($config);
        if (!$provider instanceof NFSeProviderConfigInterface) {
            throw new RuntimeException("Provider '{$providerClass}' deve implementar NFSeProviderConfigInterface.");
        }

        return [
            'provider' => $provider,
            'provider_key' => $providerKey,
            'metadata' => $metadata,
            'config' => $config,
            'certificate_loaded' => $certificate instanceof Certificate,
        ];
    }

    private function buildPrestadorContext(array $empresaConfig, ?Certificate $certificate, string $codigoMunicipio): array
    {
        $configCnpj = $this->normalizeCnpj((string) ($empresaConfig['cnpj'] ?? ''));
        $certificateCnpj = $this->normalizeCnpj((string) ($certificate?->getCnpj() ?? $certificate?->getCpf() ?? ''));

        $cnpj = $configCnpj !== '' ? $configCnpj : $certificateCnpj;
        if ($cnpj === '') {
            throw new RuntimeException('Nao foi possivel determinar o CNPJ do prestador para NFSe.');
        }

        $inscricaoMunicipal = trim((string) ($empresaConfig['inscricao_municipal'] ?? ''));
        if ($inscricaoMunicipal === '') {
            throw new RuntimeException('FISCAL_IM e obrigatorio para providers municipais de NFSe.');
        }

        $razaoSocial = trim((string) ($empresaConfig['razao_social'] ?? ''));
        if ($razaoSocial === '') {
            $razaoSocial = trim((string) ($certificate?->getCompanyName() ?? ''));
        }

        if ($razaoSocial === '') {
            throw new RuntimeException('Nao foi possivel determinar a razao social do prestador para NFSe.');
        }

        return [
            'cnpj' => $cnpj,
            'inscricaoMunicipal' => $inscricaoMunicipal,
            'inscricao_municipal' => $inscricaoMunicipal,
            'razao_social' => $razaoSocial,
            'codigo_municipio' => $codigoMunicipio,
        ];
    }

    private function assertCertificateCompatibility(string $configured, string $certificate, string $matchMode): void
    {
        $configured = $this->normalizeCnpj($configured);
        $certificate = $this->normalizeCnpj($certificate);
        if ($configured === '' || $certificate === '') {
            throw new RuntimeException('Nao foi possivel validar o CNPJ do certificado digital.');
        }

        $matches = $matchMode === 'root'
            ? substr($configured, 0, 8) === substr($certificate, 0, 8)
            : $configured === $certificate;

        if (!$matches) {
            throw new RuntimeException(
                "CNPJ configurado ({$configured}) diverge do certificado carregado ({$certificate}) no modo {$matchMode}."
            );
        }
    }

    private function normalizeCnpj(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[.\/\-\s]+/', '', $normalized) ?? '';

        return preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $normalized) === 1 ? $normalized : '';
    }
}
