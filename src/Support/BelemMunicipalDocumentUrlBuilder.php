<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Support;

use InvalidArgumentException;

final class BelemMunicipalDocumentUrlBuilder
{
    private const BASE_URL = 'https://notafiscal.belem.pa.gov.br/notafiscal-ws/servico/notafiscal/autenticacao';

    public static function build(
        string $cpfOuCnpj,
        string $inscricaoMunicipal,
        string $numeroNota,
        string $codigoVerificacao
    ): string {
        $cpfOuCnpj = preg_replace('/\D+/', '', $cpfOuCnpj) ?? '';
        $inscricaoMunicipal = trim($inscricaoMunicipal);
        $numeroNota = trim($numeroNota);
        $codigoVerificacao = trim($codigoVerificacao);

        if ($cpfOuCnpj === '' || $inscricaoMunicipal === '' || $numeroNota === '' || $codigoVerificacao === '') {
            throw new InvalidArgumentException('Todos os dados obrigatorios devem estar presentes para montar a URL oficial da NFSe de Belem.');
        }

        return sprintf(
            '%s/cpfCnpj/%s/inscricaoMunicipal/%s/numeroNota/%s/codigoVerificacao/%s',
            self::BASE_URL,
            rawurlencode($cpfOuCnpj),
            rawurlencode($inscricaoMunicipal),
            rawurlencode($numeroNota),
            rawurlencode($codigoVerificacao)
        );
    }
}
