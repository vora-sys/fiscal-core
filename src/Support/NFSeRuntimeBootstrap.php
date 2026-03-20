<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Support;

use freeline\FiscalCore\Contracts\NFSeProviderConfigInterface;
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

    public function makeProvider(string $municipio): array
    {
        $configManager = $this->configManager ?? ConfigManager::getInstance();
        $configManager->reload();

        CertificateManager::reload();
        $certificateManager = $this->certificateManager ?? CertificateManager::getInstance();

        $registry = $this->registry ?? ProviderRegistry::getInstance();
        $resolver = $this->resolver ?? new NFSeProviderResolver();
        $providerKey = $resolver->resolveKey($municipio);
        $metadata = $resolver->buildMetadata($municipio);
        $config = $registry->getConfig($providerKey);

        $config['ambiente'] = $configManager->isProduction() ? 'producao' : 'homologacao';
        $config['timeout'] = (int) ($configManager->get('nfse.timeout') ?? $configManager->get('timeout') ?? $config['timeout'] ?? 30);

        $certificate = $certificateManager->getCertificate();
        if ($certificate instanceof Certificate) {
            $config['certificate'] = $certificate;
        }

        $signatureMode = strtolower((string) ($config['signature_mode'] ?? 'optional'));
        if ($signatureMode === 'required') {
            if (!$certificate instanceof Certificate) {
                throw new RuntimeException("Provider '{$providerKey}' requer certificado digital valido.");
            }

            if (!$certificateManager->isValid()) {
                throw new RuntimeException("Certificado digital invalido ou expirado para provider '{$providerKey}'.");
            }
        }

        if ($providerKey !== ProviderRegistry::NFSE_NATIONAL_KEY) {
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
        $configCnpj = preg_replace('/\D+/', '', (string) ($empresaConfig['cnpj'] ?? '')) ?? '';
        $certificateCnpj = $certificate?->getCnpj() ?? $certificate?->getCpf() ?? '';

        if ($configCnpj !== '' && $certificateCnpj !== '' && $configCnpj !== $certificateCnpj) {
            throw new RuntimeException(
                "CNPJ configurado ({$configCnpj}) diverge do certificado carregado ({$certificateCnpj})."
            );
        }

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
}
