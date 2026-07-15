<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Contracts\NfseNacionalParametrizacaoInterface;
use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;
use sabbajohn\FiscalCore\Exceptions\NacionalApiException;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;

final class NacionalParametrizacaoService implements NfseNacionalParametrizacaoInterface
{
    private const NEGATIVE_TTL = 900;

    public function __construct(
        private readonly string $baseUrl,
        private readonly NacionalRestClient $client,
        private readonly FileCacheStore $cache,
    ) {}

    public function consultarAliquota(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
    {
        return $this->fetch('aliquota', [$this->municipio($municipio), $this->servico($servico), $this->competencia($competencia), 'aliquota'], 86400, $forceRefresh);
    }

    public function consultarHistoricoAliquotas(string $municipio, string $servico, bool $forceRefresh = false): NacionalApiResult
    {
        return $this->fetch('historico_aliquotas', [$this->municipio($municipio), $this->servico($servico), 'historicoaliquotas'], 604800, $forceRefresh);
    }

    public function consultarBeneficio(string $municipio, string $beneficio, string $competencia, bool $forceRefresh = false): NacionalApiResult
    {
        $beneficio = trim($beneficio);
        if ($beneficio === '') {
            throw new \InvalidArgumentException('Número do benefício é obrigatório.');
        }

        return $this->fetch('beneficio', [$this->municipio($municipio), $beneficio, $this->competencia($competencia), 'beneficio'], 86400, $forceRefresh);
    }

    public function consultarConvenio(string $municipio, bool $forceRefresh = false): NacionalApiResult
    {
        return $this->fetch('convenio', [$this->municipio($municipio), 'convenio'], 21600, $forceRefresh);
    }

    public function consultarRegimesEspeciais(string $municipio, string $servico, string $competencia, bool $forceRefresh = false): NacionalApiResult
    {
        return $this->fetch('regimes_especiais', [$this->municipio($municipio), $this->servico($servico), $this->competencia($competencia), 'regimes_especiais'], 86400, $forceRefresh);
    }

    public function consultarRetencoes(string $municipio, string $competencia, bool $forceRefresh = false): NacionalApiResult
    {
        return $this->fetch('retencoes', [$this->municipio($municipio), $this->competencia($competencia), 'retencoes'], 86400, $forceRefresh);
    }

    /** @param list<string> $segments */
    private function fetch(string $resource, array $segments, int $ttl, bool $forceRefresh): NacionalApiResult
    {
        $path = '/'.implode('/', array_map('rawurlencode', $segments));
        $key = 'nfse:param:'.$resource.':'.sha1($path);
        $cached = $this->cache->get($key, max($ttl, self::NEGATIVE_TTL));
        if (! $forceRefresh && $cached !== null) {
            $cachedResult = is_array($cached['value'] ?? null) ? $cached['value'] : [];
            $effectiveTtl = ($cachedResult['status'] ?? null) === 'nao_parametrizado' ? self::NEGATIVE_TTL : $ttl;
            if (($cached['age_seconds'] ?? PHP_INT_MAX) <= $effectiveTtl) {
                return $this->fromCache($cachedResult, $cached, false);
            }
        }

        try {
            $response = $this->client->get(rtrim($this->baseUrl, '/').$path);
            if ($response['status'] === 404) {
                $result = new NacionalApiResult('nao_parametrizado', metadata: $this->metadata('remote', $response['request_id'], false));
                $this->cache->put($key, $result->toArray());

                return $result;
            }
            if ($response['status'] >= 400) {
                throw new NacionalApiException('Falha ao consultar parametrização Nacional.', $response['status'], $response['request_id'], in_array($response['status'], [429, 502, 503, 504], true));
            }
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded)) {
                throw new NacionalApiException('Resposta inválida da parametrização Nacional.', $response['status'], $response['request_id']);
            }
            $data = $decoded['data'] ?? $decoded;
            $result = new NacionalApiResult('encontrado', is_array($data) ? $data : [], metadata: $this->metadata('remote', $response['request_id'], false));
            $this->cache->put($key, $result->toArray());

            return $result;
        } catch (\Throwable $e) {
            if ($cached !== null && is_array($cached['value'] ?? null) && ($cached['value']['status'] ?? null) === 'encontrado') {
                return $this->fromCache($cached['value'], $cached, true, $e->getMessage());
            }

            return new NacionalApiResult('indisponivel', warnings: ['Parametrização Nacional indisponível.'], metadata: $this->metadata('remote', $e instanceof NacionalApiException ? $e->requestId : null, false, $e->getMessage()));
        }
    }

    /** @param array<string,mixed> $value @param array<string,mixed> $cached */
    private function fromCache(array $value, array $cached, bool $stale, ?string $error = null): NacionalApiResult
    {
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $metadata = array_replace($metadata, [
            'source' => 'cache',
            'stale' => $stale,
            'age_seconds' => (int) ($cached['age_seconds'] ?? 0),
            'fetched_at' => gmdate(DATE_ATOM, (int) ($cached['created_at'] ?? time())),
        ]);
        if ($error !== null) {
            $metadata['fallback_error'] = $error;
        }

        return new NacionalApiResult(
            (string) ($value['status'] ?? 'indisponivel'),
            is_array($value['data'] ?? null) ? $value['data'] : [],
            is_array($value['warnings'] ?? null) ? $value['warnings'] : [],
            $metadata,
        );
    }

    /** @return array<string,mixed> */
    private function metadata(string $source, ?string $requestId, bool $stale, ?string $error = null): array
    {
        return array_filter([
            'source' => $source,
            'stale' => $stale,
            'fetched_at' => gmdate(DATE_ATOM),
            'request_id' => $requestId,
            'error' => $error,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function municipio(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 7) {
            throw new \InvalidArgumentException('Código do município deve conter 7 dígitos.');
        }

        return $digits;
    }

    private function servico(string $value): string
    {
        $raw = trim($value);
        if (preg_match('/^\d{2}\.\d{2}\.\d{2}\.\d{3}$/', $raw) === 1) {
            return $raw;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 6) {
            $digits .= '000';
        }
        if (strlen($digits) !== 9) {
            throw new \InvalidArgumentException('Código do serviço deve conter 6 ou 9 dígitos.');
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 2).'.'.substr($digits, 4, 2).'.'.substr($digits, 6, 3);
    }

    private function competencia(string $value): string
    {
        $raw = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw.'T00:00:00Z';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $raw) !== 1) {
            throw new \InvalidArgumentException('Competência deve ser uma data ISO 8601.');
        }

        return $raw;
    }
}
