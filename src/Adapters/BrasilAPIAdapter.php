<?php

namespace freeline\FiscalCore\Adapters;

use freeline\FiscalCore\Contracts\ConsultaPublicaInterface;
use BrasilApi\Client;

class BrasilAPIAdapter implements ConsultaPublicaInterface
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function consultarCEP(string $cep): array
    {
        try {
            $cepLimpo = preg_replace('/\D/', '', $cep);
            $response = $this->client->cep()->get($cepLimpo);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar CEP na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultarCNPJ(string $cnpj): array
    {
        try {
            $cnpjLimpo = preg_replace('/\D/', '', $cnpj);
            $response = $this->client->cnpj()->get($cnpjLimpo);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar CNPJ na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultarBanco(string $codigo): array
    {
        try {
            $response = $this->client->banks()->get((int) $codigo);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar banco na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function listarBancos(): array
    {
        try {
            $response = $this->client->banks()->getList();
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao listar bancos na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultaNcm(string $ncm): array
    {
        try {
            $ncmLimpo = preg_replace('/\D/', '', $ncm);
            $response = $this->client->ncm()->get($ncmLimpo);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar NCM na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function pesquisarNcm(string $descricao = ''): array
    {
        try {
            $response = $this->client->ncm()->search($descricao);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao pesquisar NCM na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function listarNcms(): array
    {
        try {
            $response = $this->client->ncm()->getList();
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao listar NCMs na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultarFeriados(int $ano): array
    {
        try {
            // BrasilAPI não possui endpoint específico para feriados
            // Implementando simulação ou usar outra fonte
            return $this->simularFeriados($ano);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar feriados na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultarMunicipios(string $uf): array
    {
        try {
            $response = $this->client->cities()->getByState(strtoupper($uf));
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar municípios na BrasilAPI: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consultarDDD(string $ddd): array
    {
        try {
            $response = $this->client->ddd()->get($ddd);
            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar DDD na BrasilAPI: ' . $e->getMessage(), 0, $e);
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
            '12-25' => 'Natal'
        ];

        $feriados = [];
        foreach ($feriadosFixos as $data => $nome) {
            $feriados[] = [
                'data' => "$ano-$data",
                'nome' => $nome,
                'tipo' => 'nacional'
            ];
        }

        // Adiciona Carnaval (47 dias antes da Páscoa)
        $pascoa = $this->calcularPascoa($ano);
        $carnaval = clone $pascoa;
        $carnaval->modify('-47 days');
        
        $feriados[] = [
            'data' => $carnaval->format('Y-m-d'),
            'nome' => 'Carnaval',
            'tipo' => 'nacional'
        ];

        // Adiciona Sexta-feira Santa (2 dias antes da Páscoa)
        $sextaSanta = clone $pascoa;
        $sextaSanta->modify('-2 days');
        
        $feriados[] = [
            'data' => $sextaSanta->format('Y-m-d'),
            'nome' => 'Sexta-feira Santa',
            'tipo' => 'nacional'
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
        
        return new \DateTime("$ano-$n-" . ($p + 1));
    }
}
