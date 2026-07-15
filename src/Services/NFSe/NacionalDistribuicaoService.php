<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\Contracts\NfseNacionalDistribuicaoInterface;
use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

final class NacionalDistribuicaoService implements NfseNacionalDistribuicaoInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly NacionalRestClient $client,
    ) {}

    public function distribuirDfe(string $nsu, ?string $cnpjConsulta = null, bool $lote = true): NacionalApiResult
    {
        $nsu = preg_replace('/\D+/', '', $nsu) ?? '';
        $cnpj = preg_replace('/\D+/', '', (string) $cnpjConsulta) ?? '';
        if ($nsu === '') {
            throw new \InvalidArgumentException('NSU é obrigatório para distribuição.');
        }
        if ($cnpj !== '' && strlen($cnpj) !== 14) {
            throw new \InvalidArgumentException('CNPJ de consulta deve conter 14 dígitos.');
        }

        return $this->fetch('/DFe/'.rawurlencode($nsu), [
            'cnpjConsulta' => $cnpj !== '' ? $cnpj : null,
            'lote' => $lote ? 'true' : 'false',
        ]);
    }

    public function consultarEventosDistribuidos(string $chaveAcesso): NacionalApiResult
    {
        $chave = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $chaveAcesso) ?? '');
        if ($chave === '') {
            throw new \InvalidArgumentException('Chave de acesso é obrigatória.');
        }

        return $this->fetch('/NFSe/'.rawurlencode($chave).'/Eventos');
    }

    private function fetch(string $path, array $query = []): NacionalApiResult
    {
        try {
            $response = $this->client->get(rtrim($this->baseUrl, '/').$path, $query);
            if ($response['status'] === 404) {
                return new NacionalApiResult('nao_parametrizado', metadata: $this->metadata($response['request_id']));
            }
            if ($response['status'] >= 400) {
                return new NacionalApiResult('indisponivel', warnings: ['Distribuição Nacional indisponível.'], metadata: $this->metadata($response['request_id'], 'HTTP '.$response['status']));
            }
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded)) {
                return new NacionalApiResult('indisponivel', warnings: ['Resposta inválida da distribuição Nacional.'], metadata: $this->metadata($response['request_id'], 'invalid_json'));
            }

            return new NacionalApiResult('encontrado', $decoded, metadata: $this->metadata($response['request_id']));
        } catch (\Throwable $e) {
            return new NacionalApiResult('indisponivel', warnings: ['Distribuição Nacional indisponível.'], metadata: $this->metadata(null, $e->getMessage()));
        }
    }

    /** @return array<string,mixed> */
    private function metadata(?string $requestId, ?string $error = null): array
    {
        return array_filter([
            'source' => 'remote', 'stale' => false, 'fetched_at' => gmdate(DATE_ATOM), 'request_id' => $requestId, 'error' => $error,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
