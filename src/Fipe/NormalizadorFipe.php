<?php

namespace sabbajohn\FiscalCore\Fipe;

final class NormalizadorFipe
{
    public static function linha(array $dados, ConsultaFipe $consulta, string $fonte): array
    {
        foreach (['valor', 'marca', 'modelo', 'combustivel', 'codigo_fipe', 'mes_referencia', 'sigla_combustivel'] as $campo) {
            if (! is_string($dados[$campo] ?? null) || trim($dados[$campo]) === '') {
                throw new \UnexpectedValueException('Resposta FIPE incompleta.', 502);
            }
        }
        foreach (['ano_modelo', 'tipo_veiculo'] as $campo) {
            if (! is_int($dados[$campo] ?? null) && (! is_string($dados[$campo] ?? null) || ! ctype_digit($dados[$campo]))) {
                throw new \UnexpectedValueException('Número FIPE inválido.', 502);
            }
        }
        [$ano, $combustivel] = explode('-', $consulta->anoCombustivel);
        $siglas = ['1' => 'G', '2' => 'A', '3' => 'D', '4' => 'E', '5' => 'F', '6' => 'H'];
        if ($dados['codigo_fipe'] !== $consulta->codigoFipe || (int) ($dados['ano_modelo'] ?? -1) !== (int) $ano
            || (int) ($dados['tipo_veiculo'] ?? -1) !== ConsultaFipe::tipos()[$consulta->tipoVeiculo][0]
            || str_replace('Á', 'A', strtoupper($dados['sigla_combustivel'])) !== $siglas[$combustivel]
            || ConsultaFipe::normalizarMes($dados['mes_referencia']) !== ConsultaFipe::normalizarMes($consulta->mesReferencia)) {
            throw new \UnexpectedValueException('Resposta FIPE pertence a outro veículo, combustível ou mês.', 502);
        }

        return array_merge($dados, ['tabela_referencia' => $consulta->tabelaReferencia, 'ano_combustivel' => $consulta->anoCombustivel,
            'fonte' => $fonte, 'data_consulta' => $dados['data_consulta'] ?? gmdate('c')]);
    }
}
