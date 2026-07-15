<?php

namespace sabbajohn\FiscalCore\Adapters;

use NFePHP\Ibpt\Ibpt;
use sabbajohn\FiscalCore\Contracts\TributacaoInterface;

class IBPTAdapter implements TributacaoInterface
{
    private Ibpt $client;

    private string $ufDefault;

    public function __construct(string $cnpj, string $token, string $ufDefault = 'SP')
    {
        $this->client = new Ibpt($cnpj, $token);
        $this->ufDefault = $ufDefault;
    }

    /**
     * Espera campos em $produto:
     * - uf (opcional, default construtor)
     * - ncm (string)
     * - extarif (int|0)
     * - descricao (string)
     * - unidade (string)
     * - valor (float|int)
     * - gtin (string|"")
     * - codigoInterno (string|"")
     */
    public function calcularImpostos(array $produto): array
    {
        $uf = $produto['uf'] ?? $this->ufDefault;
        $ncm = (string) ($produto['ncm'] ?? '');
        $ext = (int) ($produto['extarif'] ?? 0);
        $descricao = (string) ($produto['descricao'] ?? 'Produto');
        $unidade = (string) ($produto['unidade'] ?? 'UN');
        $valor = (float) ($produto['valor'] ?? 0.0);
        $gtin = (string) ($produto['gtin'] ?? '');
        $codigoInterno = (string) ($produto['codigoInterno'] ?? '');

        try {
            $resp = $this->client->productTaxes(
                $uf,
                $ncm,
                $ext,
                $descricao,
                $unidade,
                $valor,
                $gtin,
                $codigoInterno
            );

            return is_object($resp) ? get_object_vars($resp) : (array) $resp;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar IBPT (produto): '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Espera campos em $servico:
     * - uf (opcional, default construtor)
     * - codigo_servico (string, código da LC 116/NBM aceito pelo IBPT)
     * - descricao (string)
     * - unidade (string)
     * - valor (float|int)
     */
    public function calcularImpostosServico(array $servico): array
    {
        $uf = $servico['uf'] ?? $this->ufDefault;
        $codigo = (string) ($servico['codigo_servico'] ?? '');
        $descricao = (string) ($servico['descricao'] ?? 'Serviço');
        $unidade = (string) ($servico['unidade'] ?? 'UN');
        $valor = (float) ($servico['valor'] ?? 0.0);

        try {
            $resp = $this->client->serviceTaxes(
                $uf,
                $codigo,
                $descricao,
                $unidade,
                $valor
            );

            return is_object($resp) ? get_object_vars($resp) : (array) $resp;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar IBPT (serviço): '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta de alíquota por NCM.
     * Como a API requer parâmetros adicionais, usamos defaults razoáveis.
     */
    public function consultarAliquota(string $ncm): array
    {
        try {
            $resp = $this->client->productTaxes(
                $this->ufDefault,
                $ncm,
                0,
                'CONSULTA',
                'UN',
                0.01,
                ''
            );

            return is_object($resp) ? get_object_vars($resp) : (array) $resp;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Falha ao consultar IBPT por NCM: '.$e->getMessage(), 0, $e);
        }
    }
}
