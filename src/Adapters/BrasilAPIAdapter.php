<?php

namespace sabbajohn\FiscalCore\Adapters;

use BrasilApi\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use sabbajohn\FiscalCore\Contracts\ConsultaPublicaInterface;

class BrasilAPIAdapter implements ConsultaPublicaInterface
{
    private Client $client;

    private ClientInterface $cnpjFallbackClient;

    public function __construct(?Client $client = null, ?ClientInterface $cnpjFallbackClient = null)
    {
        $this->client = $client ?? new Client([
            'connect_timeout' => 3,
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'NotaAgil-FiscalPlatform/1.0',
            ],
        ]);
        $this->cnpjFallbackClient = $cnpjFallbackClient ?? new HttpClient([
            'base_uri' => 'https://publica.cnpj.ws/',
            'connect_timeout' => 3,
            'timeout' => 8,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'NotaAgil-FiscalPlatform/1.0',
            ],
        ]);
    }

    public function consultarCEP(string $cep): array
    {
        try {
            $cepLimpo = preg_replace('/\D/', '', $cep);
            $response = $this->client->cep()->get($cepLimpo);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar CEP na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function consultarCNPJ(string $cnpj): array
    {
        $cnpjLimpo = preg_replace('/\D/', '', $cnpj) ?? '';
        if (strlen($cnpjLimpo) !== 14) {
            throw new \InvalidArgumentException('CNPJ deve conter 14 dígitos.');
        }

        $primaryError = null;
        try {
            $response = $this->client->cnpj()->get($cnpjLimpo);
            $normalized = $this->normalizeResponse($response);
            if ($normalized !== []) {
                $normalized['_source'] = 'brasil_api';

                return $normalized;
            }
        } catch (\Throwable $e) {
            $primaryError = $e;
        }

        try {
            return $this->consultarCnpjWs($cnpjLimpo);
        } catch (\Throwable $fallbackError) {
            $primaryFailure = $primaryError !== null ? $this->failureLabel($primaryError) : 'resposta vazia';
            throw new \RuntimeException(
                sprintf(
                    'Falha ao consultar CNPJ nas fontes públicas: BrasilAPI (%s); CNPJ.ws (%s).',
                    $primaryFailure,
                    $this->failureLabel($fallbackError),
                ),
                0,
                $fallbackError,
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function consultarCnpjWs(string $cnpj): array
    {
        $response = $this->cnpjFallbackClient->request('GET', 'cnpj/'.rawurlencode($cnpj));
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException('CNPJ.ws respondeu HTTP '.$status.'.', $status);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! is_array($decoded['estabelecimento'] ?? null)) {
            throw new \RuntimeException('CNPJ.ws retornou uma resposta inválida.');
        }

        $estabelecimento = $decoded['estabelecimento'];
        $atividadePrincipal = is_array($estabelecimento['atividade_principal'] ?? null)
            ? $estabelecimento['atividade_principal']
            : [];
        $naturezaJuridica = is_array($decoded['natureza_juridica'] ?? null) ? $decoded['natureza_juridica'] : [];
        $porte = is_array($decoded['porte'] ?? null) ? $decoded['porte'] : [];
        $simples = is_array($decoded['simples'] ?? null) ? $decoded['simples'] : [];
        $cidade = is_array($estabelecimento['cidade'] ?? null) ? $estabelecimento['cidade'] : [];
        $estado = is_array($estabelecimento['estado'] ?? null) ? $estabelecimento['estado'] : [];

        return [
            '_source' => 'cnpj_ws',
            'cnpj' => preg_replace('/\D/', '', (string) ($estabelecimento['cnpj'] ?? $cnpj)) ?? $cnpj,
            'razao_social' => trim((string) ($decoded['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($estabelecimento['nome_fantasia'] ?? '')),
            'email' => trim((string) ($estabelecimento['email'] ?? '')),
            'telefone' => $this->joinPhone($estabelecimento['ddd1'] ?? null, $estabelecimento['telefone1'] ?? null),
            'ddd_telefone_1' => $this->joinPhone($estabelecimento['ddd1'] ?? null, $estabelecimento['telefone1'] ?? null),
            'cnae_fiscal' => preg_replace('/\D/', '', (string) ($atividadePrincipal['id'] ?? '')) ?? '',
            'cnae_fiscal_descricao' => trim((string) ($atividadePrincipal['descricao'] ?? '')),
            'natureza_juridica' => preg_replace('/\D/', '', (string) ($naturezaJuridica['id'] ?? '')) ?? '',
            'natureza_juridica_descricao' => trim((string) ($naturezaJuridica['descricao'] ?? '')),
            'codigo_porte' => trim((string) ($porte['id'] ?? '')),
            'porte' => trim((string) ($porte['descricao'] ?? '')),
            'opcao_pelo_simples' => $this->yesNoBoolean($simples['simples'] ?? null),
            'opcao_pelo_mei' => $this->yesNoBoolean($simples['mei'] ?? null),
            'logradouro' => trim((string) ($estabelecimento['logradouro'] ?? '')),
            'numero' => trim((string) ($estabelecimento['numero'] ?? '')),
            'complemento' => trim((string) ($estabelecimento['complemento'] ?? '')),
            'bairro' => trim((string) ($estabelecimento['bairro'] ?? '')),
            'cep' => preg_replace('/\D/', '', (string) ($estabelecimento['cep'] ?? '')) ?? '',
            'municipio' => trim((string) ($cidade['nome'] ?? '')),
            'uf' => strtoupper(trim((string) ($estado['sigla'] ?? ''))),
            'codigo_municipio_ibge' => preg_replace('/\D/', '', (string) ($cidade['ibge_id'] ?? '')) ?? '',
            'situacao_cadastral' => trim((string) ($estabelecimento['situacao_cadastral'] ?? '')),
            'atividades_secundarias' => is_array($estabelecimento['atividades_secundarias'] ?? null)
                ? $estabelecimento['atividades_secundarias']
                : [],
        ];
    }

    private function joinPhone(mixed $ddd, mixed $phone): string
    {
        return (preg_replace('/\D/', '', (string) $ddd) ?? '')
            .(preg_replace('/\D/', '', (string) $phone) ?? '');
    }

    private function yesNoBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value)) {
            return null;
        }

        return match (mb_strtolower(trim((string) $value))) {
            'sim', 's', 'true', '1', 'yes' => true,
            'não', 'nao', 'n', 'false', '0', 'no' => false,
            default => null,
        };
    }

    private function failureLabel(\Throwable $error): string
    {
        return $error->getCode() > 0 ? 'HTTP '.$error->getCode() : $error::class;
    }

    public function consultarBanco(string $codigo): array
    {
        try {
            $response = $this->client->banks()->get((int) $codigo);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar banco na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function listarBancos(): array
    {
        try {
            $response = $this->client->banks()->getList();

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao listar bancos na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function consultaNcm(string $ncm): array
    {
        try {
            $ncmLimpo = preg_replace('/\D/', '', $ncm);
            $response = $this->client->ncm()->get($ncmLimpo);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar NCM na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function pesquisarNcm(string $descricao = ''): array
    {
        try {
            $response = $this->client->ncm()->search($descricao);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao pesquisar NCM na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function listarNcms(): array
    {
        try {
            $response = $this->client->ncm()->getList();

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao listar NCMs na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function consultarFeriados(int $ano): array
    {
        try {
            // BrasilAPI não possui endpoint específico para feriados
            // Implementando simulação ou usar outra fonte
            return $this->simularFeriados($ano);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar feriados na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function consultarMunicipios(string $uf): array
    {
        try {
            $response = $this->client->cities()->getByState(strtoupper($uf));

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar municípios na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    public function consultarDDD(string $ddd): array
    {
        try {
            $response = $this->client->ddd()->get($ddd);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar DDD na BrasilAPI: '.$e->getMessage(), 0, $e);
        }
    }

    private function normalizeResponse($response): array
    {
        if (is_array($response)) {
            if (isset($response['data']) && is_array($response['data'])) {
                return $response['data'];
            }

            return $response;
        }
        if (is_object($response)) {
            $normalized = json_decode(json_encode($response), true) ?? [];
            if (isset($normalized['data']) && is_array($normalized['data'])) {
                return $normalized['data'];
            }

            return $normalized;
        }

        return [];
    }

    /**
     * Simula consulta de feriados (BrasilAPI não possui este endpoint)
     */
    private function simularFeriados(int $ano): array
    {
        $feriadosFixos = [
            '01-01' => 'Confraternização Universal',
            '04-21' => 'Tiradentes',
            '05-01' => 'Dia do Trabalhador',
            '09-07' => 'Independência do Brasil',
            '10-12' => 'Nossa Senhora Aparecida',
            '11-02' => 'Finados',
            '11-15' => 'Proclamação da República',
            '12-25' => 'Natal',
        ];

        $feriados = [];
        foreach ($feriadosFixos as $data => $nome) {
            $feriados[] = [
                'data' => "$ano-$data",
                'nome' => $nome,
                'tipo' => 'nacional',
            ];
        }

        // Adiciona Carnaval (47 dias antes da Páscoa)
        $pascoa = $this->calcularPascoa($ano);
        $carnaval = clone $pascoa;
        $carnaval->modify('-47 days');

        $feriados[] = [
            'data' => $carnaval->format('Y-m-d'),
            'nome' => 'Carnaval',
            'tipo' => 'nacional',
        ];

        // Adiciona Sexta-feira Santa (2 dias antes da Páscoa)
        $sextaSanta = clone $pascoa;
        $sextaSanta->modify('-2 days');

        $feriados[] = [
            'data' => $sextaSanta->format('Y-m-d'),
            'nome' => 'Sexta-feira Santa',
            'tipo' => 'nacional',
        ];

        return $feriados;
    }

    /**
     * Calcula data da Páscoa
     */
    private function calcularPascoa(int $ano): \DateTime
    {
        $a = $ano % 19;
        $b = intval($ano / 100);
        $c = $ano % 100;
        $d = intval($b / 4);
        $e = $b % 4;
        $f = intval(($b + 8) / 25);
        $g = intval(($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intval($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intval(($a + 11 * $h + 22 * $l) / 451);
        $n = intval(($h + $l - 7 * $m + 114) / 31);
        $p = ($h + $l - 7 * $m + 114) % 31;

        return new \DateTime("$ano-$n-".($p + 1));
    }
}
