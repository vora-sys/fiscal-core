<?php

namespace sabbajohn\FiscalCore\Adapters;

use sabbajohn\FiscalCore\Contracts\ConsultaPublicaInterface;
use BrasilApi\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;

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
        $cnpjLimpo = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($cnpj))) ?? '';
        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $cnpjLimpo) !== 1) {
            throw new \InvalidArgumentException('CNPJ deve conter 14 posições, com dígitos verificadores numéricos.');
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
            $notFound = (int) $fallbackError->getCode() === 404
                && ($primaryError === null || (int) $primaryError->getCode() === 404);
            throw new \RuntimeException(
                sprintf(
                    'Falha ao consultar CNPJ nas fontes públicas: BrasilAPI (%s); CNPJ.ws (%s).',
                    $primaryFailure,
                    $this->failureLabel($fallbackError),
                ),
                $notFound ? 404 : 0,
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
        $pais = is_array($estabelecimento['pais'] ?? null) ? $estabelecimento['pais'] : [];
        $responsibleQualification = is_array($decoded['qualificacao_do_responsavel'] ?? null)
            ? $decoded['qualificacao_do_responsavel']
            : [];

        return [
            '_source' => 'cnpj_ws',
            'cnpj' => preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($estabelecimento['cnpj'] ?? $cnpj))) ?? $cnpj,
            'razao_social' => trim((string) ($decoded['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($estabelecimento['nome_fantasia'] ?? '')),
            'data_inicio_atividade' => trim((string) ($estabelecimento['data_inicio_atividade'] ?? '')),
            'atualizado_em' => trim((string) ($estabelecimento['atualizado_em'] ?? $decoded['atualizado_em'] ?? '')),
            'capital_social' => is_numeric($decoded['capital_social'] ?? null) ? (float) $decoded['capital_social'] : null,
            'ente_federativo_responsavel' => trim((string) ($decoded['responsavel_federativo'] ?? '')),
            'identificador_matriz_filial' => match (mb_strtolower(trim((string) ($estabelecimento['tipo'] ?? '')))) {
                'matriz' => 1,
                'filial' => 2,
                default => null,
            },
            'descricao_identificador_matriz_filial' => trim((string) ($estabelecimento['tipo'] ?? '')),
            'qualificacao_do_responsavel' => [
                'id' => $responsibleQualification['id'] ?? null,
                'descricao' => trim((string) ($responsibleQualification['descricao'] ?? '')),
            ],
            'email' => trim((string) ($estabelecimento['email'] ?? '')),
            'telefone' => $this->joinPhone($estabelecimento['ddd1'] ?? null, $estabelecimento['telefone1'] ?? null),
            'ddd_telefone_1' => $this->joinPhone($estabelecimento['ddd1'] ?? null, $estabelecimento['telefone1'] ?? null),
            'ddd_telefone_2' => $this->joinPhone($estabelecimento['ddd2'] ?? null, $estabelecimento['telefone2'] ?? null),
            'ddd_fax' => $this->joinPhone($estabelecimento['ddd_fax'] ?? null, $estabelecimento['fax'] ?? null),
            'cnae_fiscal' => preg_replace('/\D/', '', (string) ($atividadePrincipal['id'] ?? '')) ?? '',
            'cnae_fiscal_descricao' => trim((string) ($atividadePrincipal['descricao'] ?? '')),
            'cnaes_secundarios' => $this->cnpjWsActivities($estabelecimento['atividades_secundarias'] ?? []),
            'natureza_juridica' => preg_replace('/\D/', '', (string) ($naturezaJuridica['id'] ?? '')) ?? '',
            'codigo_natureza_juridica' => preg_replace('/\D/', '', (string) ($naturezaJuridica['id'] ?? '')) ?? '',
            'natureza_juridica_descricao' => trim((string) ($naturezaJuridica['descricao'] ?? '')),
            'codigo_porte' => trim((string) ($porte['id'] ?? '')),
            'porte' => trim((string) ($porte['descricao'] ?? '')),
            'opcao_pelo_simples' => $this->yesNoBoolean($simples['simples'] ?? null),
            'data_opcao_pelo_simples' => trim((string) ($simples['data_opcao_simples'] ?? '')),
            'data_exclusao_do_simples' => trim((string) ($simples['data_exclusao_simples'] ?? '')),
            'opcao_pelo_mei' => $this->yesNoBoolean($simples['mei'] ?? null),
            'data_opcao_pelo_mei' => trim((string) ($simples['data_opcao_mei'] ?? '')),
            'data_exclusao_do_mei' => trim((string) ($simples['data_exclusao_mei'] ?? '')),
            'descricao_tipo_de_logradouro' => trim((string) ($estabelecimento['tipo_logradouro'] ?? '')),
            'logradouro' => trim((string) ($estabelecimento['logradouro'] ?? '')),
            'numero' => trim((string) ($estabelecimento['numero'] ?? '')),
            'complemento' => trim((string) ($estabelecimento['complemento'] ?? '')),
            'bairro' => trim((string) ($estabelecimento['bairro'] ?? '')),
            'cep' => preg_replace('/\D/', '', (string) ($estabelecimento['cep'] ?? '')) ?? '',
            'municipio' => trim((string) ($cidade['nome'] ?? '')),
            'uf' => strtoupper(trim((string) ($estado['sigla'] ?? ''))),
            'codigo_municipio_ibge' => preg_replace('/\D/', '', (string) ($cidade['ibge_id'] ?? '')) ?? '',
            'codigo_pais' => trim((string) ($pais['id'] ?? '')),
            'pais' => trim((string) ($pais['nome'] ?? '')),
            'nome_cidade_no_exterior' => trim((string) ($estabelecimento['nome_cidade_exterior'] ?? '')),
            'situacao_cadastral' => trim((string) ($estabelecimento['situacao_cadastral'] ?? '')),
            'descricao_situacao_cadastral' => trim((string) ($estabelecimento['situacao_cadastral'] ?? '')),
            'data_situacao_cadastral' => trim((string) ($estabelecimento['data_situacao_cadastral'] ?? '')),
            'descricao_motivo_situacao_cadastral' => trim((string) ($estabelecimento['motivo_situacao_cadastral'] ?? '')),
            'situacao_especial' => trim((string) ($estabelecimento['situacao_especial'] ?? '')),
            'data_situacao_especial' => trim((string) ($estabelecimento['data_situacao_especial'] ?? '')),
            'qsa' => $this->cnpjWsPartners($decoded['socios'] ?? []),
            'inscricoes_estaduais' => is_array($estabelecimento['inscricoes_estaduais'] ?? null)
                ? $estabelecimento['inscricoes_estaduais']
                : [],
        ];
    }

    /**
     * @return list<array{codigo:string,descricao:string}>
     */
    private function cnpjWsActivities(mixed $activities): array
    {
        if (! is_array($activities)) {
            return [];
        }

        return array_values(array_map(static fn (array $activity): array => [
            'codigo' => preg_replace('/\D/', '', (string) ($activity['id'] ?? '')) ?? '',
            'descricao' => trim((string) ($activity['descricao'] ?? '')),
        ], array_filter($activities, 'is_array')));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cnpjWsPartners(mixed $partners): array
    {
        if (! is_array($partners)) {
            return [];
        }

        $normalized = [];
        foreach ($partners as $partner) {
            if (! is_array($partner)) {
                continue;
            }

            $qualification = is_array($partner['qualificacao_socio'] ?? null)
                ? $partner['qualificacao_socio']
                : [];
            $country = is_array($partner['pais'] ?? null) ? $partner['pais'] : [];
            $normalized[] = [
                'nome_socio' => trim((string) ($partner['nome'] ?? '')),
                'cnpj_cpf_do_socio' => trim((string) ($partner['cpf_cnpj_socio'] ?? '')),
                'identificador_de_socio' => trim((string) ($partner['tipo'] ?? '')),
                'codigo_qualificacao_socio' => $qualification['id'] ?? null,
                'qualificacao_socio' => trim((string) ($qualification['descricao'] ?? '')),
                'data_entrada_sociedade' => trim((string) ($partner['data_entrada'] ?? '')),
                'faixa_etaria' => trim((string) ($partner['faixa_etaria'] ?? '')),
                'codigo_pais' => $country['id'] ?? $partner['pais_id'] ?? null,
                'pais' => trim((string) ($country['nome'] ?? '')),
                'cpf_representante_legal' => trim((string) ($partner['cpf_representante_legal'] ?? '')),
                'nome_representante_legal' => trim((string) ($partner['nome_representante'] ?? '')),
                'qualificacao_representante_legal' => trim((string) ($partner['qualificacao_representante'] ?? '')),
            ];
        }

        return $normalized;
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
