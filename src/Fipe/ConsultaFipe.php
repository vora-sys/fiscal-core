<?php

namespace sabbajohn\FiscalCore\Fipe;

final class ConsultaFipe
{
    public function __construct(
        public readonly string $tipoVeiculo,
        public readonly string $codigoFipe,
        public readonly string $anoCombustivel,
        public readonly int $tabelaReferencia,
        public readonly string $mesReferencia,
        public readonly ?int $codigoMarca = null,
        public readonly ?int $codigoModelo = null,
    ) {
        if (! isset(self::tipos()[$tipoVeiculo]) || ! preg_match('/^[0-9]{6}-[0-9]$/D', $codigoFipe)
            || ! preg_match('/^(?:(?:19|20)[0-9]{2}|32000)-[1-6]$/D', $anoCombustivel)
            || $tabelaReferencia < 1 || $tabelaReferencia > 2147483647
            || ! preg_match('/^(?:janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)(?: de |\/)(?:19|20)[0-9]{2}$/uD', strtolower(trim($mesReferencia)))
            || (($codigoMarca === null) !== ($codigoModelo === null))
            || ($codigoMarca !== null && ($codigoMarca < 1 || $codigoModelo < 1 || $codigoMarca > 2147483647 || $codigoModelo > 2147483647))) {
            throw new \InvalidArgumentException('Informe tipo, código FIPE, ano/combustível e referência válidos.');
        }
    }

    public static function tipos(): array
    {
        return ['carros' => [1, 'cars'], 'motos' => [2, 'motorcycles'], 'caminhoes' => [3, 'trucks']];
    }

    public static function normalizarMes(string $mes): string
    {
        return strtolower(str_replace([' de ', ' '], ['/', ''], trim($mes)));
    }

    public function chave(string $fonte): string
    {
        return 'fipe:v3:'.hash('sha256', implode('|', [$fonte, $this->tipoVeiculo, $this->codigoFipe, $this->anoCombustivel,
            $this->tabelaReferencia, self::normalizarMes($this->mesReferencia), $this->codigoMarca, $this->codigoModelo]));
    }
}
