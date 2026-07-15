<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;
use sabbajohn\FiscalCore\Support\CertificateManager;

class NacionalCatalogService
{
    private string $apiBaseUrl;

    private int $timeout;

    private FileCacheStore $cache;

    private int $ttl;

    private $httpClient;

    public function __construct(
        string $apiBaseUrl,
        int $timeout = 30,
        ?FileCacheStore $cache = null,
        int $ttl = 86400,
        ?callable $httpClient = null
    ) {
        $this->apiBaseUrl = $this->normalizeCatalogBaseUrl($apiBaseUrl);
        $this->timeout = $timeout;
        $this->cache = $cache ?? new FileCacheStore;
        $this->ttl = $ttl;
        $this->httpClient = $httpClient;
    }

    /**
     * @return array{data: array, metadata: array}
     */
    public function listarMunicipios(bool $forceRefresh = false): array
    {
        $cacheKey = 'municipios';

        return $this->fetchWithCache(
            $cacheKey,
            '/catalogos/municipios',
            $forceRefresh
        );
    }

    /**
     * @return array{data: array, metadata: array}
     */
    public function consultarAliquotasMunicipio(
        string $codigoMunicipio,
        ?string $codigoServico = null,
        ?string $competencia = null,
        bool $forceRefresh = false
    ): array {
        if (! preg_match('/^\d{7}$/', $codigoMunicipio)) {
            throw new \InvalidArgumentException('Código do município deve conter 7 dígitos');
        }

        $codigoServicoNorm = $this->normalizeCodigoServicoForParamApi($codigoServico);
        if ($codigoServicoNorm === '') {
            throw new \InvalidArgumentException(
                'Código do serviço é obrigatório para consulta de alíquota (/{codigoMunicipio}/{codigoServico}/{competencia}/aliquota).'
            );
        }

        $competenciaNorm = $this->normalizeCompetencia($competencia);
        $cacheKey = "aliquota:{$codigoMunicipio}:{$codigoServicoNorm}:{$competenciaNorm}";
        $codigoMunicipioPath = rawurlencode($codigoMunicipio);
        $codigoServicoPath = rawurlencode($codigoServicoNorm);
        $competenciaPath = rawurlencode($competenciaNorm);

        return $this->fetchWithCache(
            $cacheKey,
            "/{$codigoMunicipioPath}/{$codigoServicoPath}/{$competenciaPath}/aliquota",
            $forceRefresh
        );
    }

    /**
     * @return array{data: array, metadata: array}
     */
    public function consultarHistoricoAliquotasMunicipio(
        string $codigoMunicipio,
        string $codigoServico,
        bool $forceRefresh = false
    ): array {
        if (! preg_match('/^\d{7}$/', $codigoMunicipio)) {
            throw new \InvalidArgumentException('Código do município deve conter 7 dígitos');
        }
        $codigoServicoNorm = $this->normalizeCodigoServicoForParamApi($codigoServico);
        if ($codigoServicoNorm === '') {
            throw new \InvalidArgumentException('Código do serviço é obrigatório');
        }

        $cacheKey = "historico_aliquotas:{$codigoMunicipio}:{$codigoServicoNorm}";

        return $this->fetchWithCache(
            $cacheKey,
            "/{$codigoMunicipio}/{$codigoServicoNorm}/historicoaliquotas",
            $forceRefresh
        );
    }

    /**
     * @return array{data: array, metadata: array}
     */
    public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
    {
        if (! preg_match('/^\d{7}$/', $codigoMunicipio)) {
            throw new \InvalidArgumentException('Código do município deve conter 7 dígitos');
        }

        $cacheKey = "convenio:{$codigoMunicipio}";

        return $this->fetchWithCache(
            $cacheKey,
            "/{$codigoMunicipio}/convenio",
            $forceRefresh
        );
    }

    /**
     * @return array{data: array, metadata: array}
     */
    private function fetchWithCache(string $cacheKey, string $path, bool $forceRefresh): array
    {
        $cached = $this->cache->get($cacheKey, $this->ttl);
        if (! $forceRefresh && $cached !== null && $cached['stale'] === false) {
            return [
                'data' => is_array($cached['value']) ? $cached['value'] : [],
                'metadata' => [
                    'source' => 'cache',
                    'stale' => false,
                    'cache_key' => $cacheKey,
                ],
            ];
        }

        try {
            $json = $this->requestJson($path);
            $data = $json['data'] ?? $json;
            if (! is_array($data)) {
                $data = [];
            }

            $this->cache->put($cacheKey, $data);

            return [
                'data' => $data,
                'metadata' => [
                    'source' => 'remote',
                    'stale' => false,
                    'cache_key' => $cacheKey,
                ],
            ];
        } catch (\Throwable $e) {
            $legacyPath = $this->toLegacyPath($path);
            if ($legacyPath !== null) {
                try {
                    $json = $this->requestJson($legacyPath);
                    $data = $json['data'] ?? $json;
                    if (! is_array($data)) {
                        $data = [];
                    }
                    $this->cache->put($cacheKey, $data);

                    return [
                        'data' => $data,
                        'metadata' => [
                            'source' => 'remote',
                            'stale' => false,
                            'cache_key' => $cacheKey,
                            'fallback_legacy_path' => $legacyPath,
                        ],
                    ];
                } catch (\Throwable $legacyError) {
                    $e = $legacyError;
                }
            }

            if ($cached !== null) {
                return [
                    'data' => is_array($cached['value']) ? $cached['value'] : [],
                    'metadata' => [
                        'source' => 'cache',
                        'stale' => true,
                        'cache_key' => $cacheKey,
                        'fallback_error' => $e->getMessage(),
                    ],
                ];
            }

            throw new \RuntimeException("Falha ao obter catálogo nacional: {$e->getMessage()}", 0, $e);
        }
    }

    private function normalizeCompetencia(?string $competencia): string
    {
        $raw = trim((string) $competencia);
        if ($raw === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw.'T00:00:00Z';
        }

        return $raw;
    }

    private function normalizeCodigoServicoForParamApi(?string $codigoServico): string
    {
        $raw = preg_replace('/\s+/', '', trim((string) $codigoServico)) ?? '';
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{3}$/', $raw) === 1) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 6) {
            return substr($digits, 0, 2).'.'
                .substr($digits, 2, 2).'.'
                .substr($digits, 4, 2).'.000';
        }
        if (strlen($digits) === 9) {
            return substr($digits, 0, 2).'.'
                .substr($digits, 2, 2).'.'
                .substr($digits, 4, 2).'.'
                .substr($digits, 6, 3);
        }

        return $raw;
    }

    private function toLegacyPath(string $path): ?string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));

        // Endpoints de alíquotas migraram para o padrão raiz no ADN Parametrização.
        // Não tentar fallback legado /catalogos para evitar chamadas inválidas.
        if (count($parts) === 4 && $parts[3] === 'aliquota') {
            return null;
        }
        if (count($parts) === 3 && $parts[2] === 'historicoaliquotas') {
            return null;
        }

        if (count($parts) === 2 && $parts[1] === 'convenio') {
            return "/catalogos/municipios/{$parts[0]}/convenio";
        }

        return null;
    }

    private function requestJson(string $path): array
    {
        if (is_callable($this->httpClient)) {
            $result = call_user_func($this->httpClient, $path);
            if (is_array($result)) {
                return $result;
            }
            throw new \RuntimeException('Cliente HTTP mock retornou payload inválido');
        }

        $url = $this->apiBaseUrl.$path;
        $headers = ['Accept: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $tempCertFile = null;
            $tempKeyFile = null;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $this->applyMutualTlsCurlOptions($ch, $tempCertFile, $tempKeyFile);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            $this->cleanupTemporaryFiles($tempCertFile, $tempKeyFile);

            if ($response === false) {
                throw new \RuntimeException("Erro cURL: {$curlErr}");
            }

            if ($status >= 400) {
                throw new \RuntimeException("HTTP {$status} ao consultar {$url}");
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $this->timeout,
                    'header' => implode("\r\n", $headers),
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new \RuntimeException("Falha HTTP ao consultar {$url}");
            }
        }

        $decoded = json_decode($response, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Resposta JSON inválida do catálogo nacional');
        }

        return $decoded;
    }

    private function applyMutualTlsCurlOptions($ch, ?string &$tempCertFile = null, ?string &$tempKeyFile = null): void
    {
        $certManager = CertificateManager::getInstance();
        $certificate = $certManager->getCertificate();
        if ($certificate === null) {
            return;
        }

        $certPem = (string) $certificate;
        $keyPem = (string) $certificate->privateKey;
        if ($certPem === '' || $keyPem === '') {
            return;
        }

        $tempCertFile = tempnam(sys_get_temp_dir(), 'nfse_cat_cert_');
        $tempKeyFile = tempnam(sys_get_temp_dir(), 'nfse_cat_key_');
        if (! is_string($tempCertFile) || ! is_string($tempKeyFile)) {
            return;
        }

        file_put_contents($tempCertFile, $certPem);
        file_put_contents($tempKeyFile, $keyPem);
        @chmod($tempCertFile, 0600);
        @chmod($tempKeyFile, 0600);

        curl_setopt($ch, CURLOPT_SSLCERT, $tempCertFile);
        curl_setopt($ch, CURLOPT_SSLKEY, $tempKeyFile);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
    }

    private function cleanupTemporaryFiles(?string ...$files): void
    {
        foreach ($files as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private function normalizeCatalogBaseUrl(string $apiBaseUrl): string
    {
        $trimmed = rtrim(trim($apiBaseUrl), '/');
        if ($trimmed === '') {
            return $trimmed;
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false) {
            return $trimmed;
        }

        $path = rtrim((string) ($parsed['path'] ?? ''), '/');
        if ($path === '/parametrizacao') {
            return $trimmed;
        }

        return $trimmed.'/parametrizacao';
    }
}
