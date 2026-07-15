<?php

namespace sabbajohn\FiscalCore\Contracts;

/**
 * Interface estendida para Providers NFSe com métodos auxiliares
 *
 * Adiciona métodos de configuração e validação específicos
 * para providers municipais baseados em configuração externa.
 */
interface NFSeProviderConfigInterface extends NFSeProviderInterface
{
    /**
     * Retorna a URL do webservice (WSDL)
     *
     * @return string URL do WSDL
     */
    public function getWsdlUrl(): string;

    /**
     * Retorna a versão do layout suportado
     *
     * @return string Versão (ex: "2.02", "2.01")
     */
    public function getVersao(): string;

    /**
     * Retorna o formato de alíquota esperado pelo município
     *
     * @return string 'decimal' (0.02) ou 'percentual' (2)
     */
    public function getAliquotaFormat(): string;

    /**
     * Retorna o código IBGE do município
     *
     * @return string Código IBGE (7 dígitos)
     */
    public function getCodigoMunicipio(): string;

    /**
     * Retorna o ambiente operacional
     *
     * @return string 'producao' ou 'homologacao'
     */
    public function getAmbiente(): string;

    /**
     * Retorna timeout padrão para chamadas ao webservice
     *
     * @return int Timeout em segundos
     */
    public function getTimeout(): int;

    /**
     * Retorna configuração de autenticação do provider
     */
    public function getAuthConfig(): array;

    /**
     * Retorna URL base da API Nacional (quando aplicável)
     */
    public function getNationalApiBaseUrl(): string;

    /**
     * Valida se os dados estão no formato correto para este provider
     *
     * @throws \InvalidArgumentException Se dados inválidos
     */
    public function validarDados(array $dados): bool;

    public function consultarContribuinteCnc(string $cnc): array;

    public function verificarHabilitacaoCnc(string $cnc): bool;

    public function getConfig(): array;
}
