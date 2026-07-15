<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Contracts\NfseNacionalCncInterface;
use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;

final class NacionalCncService implements NfseNacionalCncInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly NacionalRestClient $client,
        private readonly FileCacheStore $cache,
    ) {}

    public function consultarCadastroCnc(string $municipio, string $inscricaoFederal, ?string $inscricaoMunicipal = null, bool $forceRefresh = false): NacionalApiResult
    {
        $municipio = preg_replace('/\D+/', '', $municipio) ?? '';
        $documento = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $inscricaoFederal) ?? '');
        $inscricaoMunicipal = trim((string) $inscricaoMunicipal);
        if (strlen($municipio) !== 7 || ! in_array(strlen($documento), [11, 14], true)) {
            throw new \InvalidArgumentException('Município e inscrição federal válidos são obrigatórios para consultar o CNC.');
        }

        $query = [
            'codMunicipio' => $municipio,
            'inscricaoFederal' => $documento,
        ];
        $key = 'nfse:cnc:'.sha1(json_encode([
            'query' => $query,
            'inscricao_municipal_referencia' => $inscricaoMunicipal,
        ]) ?: '');
        $cached = $this->cache->get($key, 21600);
        if (! $forceRefresh && $cached !== null && ($cached['stale'] ?? true) === false) {
            return $this->cached($cached, false);
        }

        try {
            $response = $this->client->get(rtrim($this->baseUrl, '/').'/cad', $query);
            $metadata = array_replace($this->metadata($response['request_id']), [
                'inscricao_municipal_referencia' => $inscricaoMunicipal !== '' ? $inscricaoMunicipal : null,
            ]);
            if ($response['status'] === 404) {
                $result = new NacionalApiResult('nao_parametrizado', $this->emptyData(), metadata: $metadata);
                $this->cache->put($key, $result->toArray());

                return $result;
            }
            if ($response['status'] >= 400) {
                throw new \RuntimeException('CNC indisponível (HTTP '.$response['status'].').');
            }
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Resposta inválida do CNC.');
            }
            $records = $this->records($decoded);
            $selected = $this->select($records, $municipio, $documento, $inscricaoMunicipal !== '' ? $inscricaoMunicipal : null);
            $result = new NacionalApiResult(
                $records === [] ? 'nao_parametrizado' : 'encontrado',
                [
                    'cadastros' => $records,
                    'correspondencia' => $selected,
                    'quantidade_correspondencias' => count($records),
                    'correspondencia_inequivoca' => $selected !== null,
                ],
                count($records) > 1 && $selected === null ? ['O CNC retornou múltiplos cadastros; confirme a inscrição municipal.'] : [],
                $metadata,
            );
            $this->cache->put($key, $result->toArray());

            return $result;
        } catch (\Throwable $e) {
            if ($cached !== null && ($cached['value']['status'] ?? null) === 'encontrado') {
                return $this->cached($cached, true, $e->getMessage());
            }

            return new NacionalApiResult('indisponivel', warnings: ['CNC indisponível; o cadastro local foi preservado.'], metadata: [
                'source' => 'remote', 'stale' => false, 'fetched_at' => gmdate(DATE_ATOM), 'error' => $e->getMessage(),
                'inscricao_municipal_referencia' => $inscricaoMunicipal !== '' ? $inscricaoMunicipal : null,
            ]);
        }
    }

    /** @return array{cadastros:list<array<string,mixed>>,correspondencia:null,quantidade_correspondencias:int,correspondencia_inequivoca:bool} */
    private function emptyData(): array
    {
        return [
            'cadastros' => [],
            'correspondencia' => null,
            'quantidade_correspondencias' => 0,
            'correspondencia_inequivoca' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function records(array $decoded): array
    {
        foreach (['ListaCadastroMunicipal', 'listaCadastroMunicipal', 'dados', 'data', 'cadastros', 'contribuintes', 'registros'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $candidate = $decoded[$key];
                if (array_is_list($candidate)) {
                    return array_values(array_filter($candidate, 'is_array'));
                }
                foreach (['dados', 'cadastros', 'contribuintes', 'registros'] as $nested) {
                    if (isset($candidate[$nested]) && is_array($candidate[$nested]) && array_is_list($candidate[$nested])) {
                        return array_values(array_filter($candidate[$nested], 'is_array'));
                    }
                }
            }
        }

        return array_is_list($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param list<array<string,mixed>> $records @return array<string,mixed>|null */
    private function select(array $records, string $municipio, string $documento, ?string $im): ?array
    {
        $matches = array_values(array_filter($records, function (array $record) use ($municipio, $documento, $im): bool {
            $info = is_array($record['InfCad'] ?? null)
                ? $record['InfCad']
                : (is_array($record['infCad'] ?? null) ? $record['infCad'] : []);
            $recordMunicipio = preg_replace('/\D+/', '', (string) ($record['CodigoMunicipio'] ?? $record['codigoMunicipio'] ?? $record['codMunicipio'] ?? $record['municipio'] ?? '')) ?? '';
            $recordDocumento = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) ($info['Inscricao'] ?? $info['inscricao'] ?? $record['inscricaoFederal'] ?? $record['cpfCnpj'] ?? $record['documento'] ?? '')) ?? '');
            $recordIm = trim((string) ($info['InscricaoMunicipal'] ?? $info['inscricaoMunicipal'] ?? $record['inscricaoMunicipal'] ?? $record['indicadorMunicipal'] ?? ''));

            return ($recordMunicipio === '' || $recordMunicipio === $municipio)
                && ($recordDocumento === '' || $recordDocumento === $documento)
                && ($im === null || $recordIm === '' || $this->sameMunicipalRegistration($recordIm, $im));
        }));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function sameMunicipalRegistration(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (! ctype_digit($left) || ! ctype_digit($right)) {
            return false;
        }

        return ltrim($left, '0') === ltrim($right, '0');
    }

    /** @param array<string,mixed> $cached */
    private function cached(array $cached, bool $stale, ?string $error = null): NacionalApiResult
    {
        $value = is_array($cached['value'] ?? null) ? $cached['value'] : [];
        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
        $metadata = array_replace($metadata, ['source' => 'cache', 'stale' => $stale, 'age_seconds' => (int) ($cached['age_seconds'] ?? 0)]);
        if ($error !== null) {
            $metadata['fallback_error'] = $error;
        }

        return new NacionalApiResult((string) ($value['status'] ?? 'indisponivel'), (array) ($value['data'] ?? []), (array) ($value['warnings'] ?? []), $metadata);
    }

    /** @return array<string,mixed> */
    private function metadata(?string $requestId): array
    {
        return ['source' => 'remote', 'stale' => false, 'fetched_at' => gmdate(DATE_ATOM), 'request_id' => $requestId];
    }
}
