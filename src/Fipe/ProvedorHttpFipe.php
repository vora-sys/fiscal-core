<?php

namespace sabbajohn\FiscalCore\Fipe;

use GuzzleHttp\ClientInterface;
use sabbajohn\FiscalCore\Contracts\ProvedorFipeInterface;

final class ProvedorHttpFipe implements ProvedorFipeInterface
{
    public function __construct(private string $nome, private ClientInterface $cliente)
    {
        if (! in_array($nome, ['brasil_api', 'parallelum'], true)) {
            throw new \InvalidArgumentException('Fonte FIPE inválida.');
        }
    }
    public function fonte(): string
    {
        return $this->nome;
    }

    public function consultar(ConsultaFipe $consulta, OrcamentoFipe $orcamento): array
    {
        $opcoes = $orcamento->opcoes();
        if ($this->nome === 'brasil_api') {
            $url = 'https://brasilapi.com.br/api/fipe/preco/v1/'.$consulta->codigoFipe;
            $opcoes['query'] = ['tabela_referencia' => $consulta->tabelaReferencia];
        } else {
            $url = 'https://fipe.parallelum.com.br/api/v2/'.ConsultaFipe::tipos()[$consulta->tipoVeiculo][1].'/'.$consulta->codigoFipe.'/years/'.$consulta->anoCombustivel;
            $opcoes['query'] = ['reference' => $consulta->tabelaReferencia];
        }
        $resposta = $this->cliente->request('GET', $url, $opcoes);
        $status = $resposta->getStatusCode();
        if ($status !== 200) {
            throw FalhaFonteFipe::resposta($resposta);
        }
        try {
            $dados = json_decode((string) $resposta->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException('Resposta FIPE inválida.', 502, $e);
        }
        if (! is_array($dados)) {
            throw new \UnexpectedValueException('Resposta FIPE inválida.', 502);
        }
        if ($this->nome === 'brasil_api') {
            if (! array_is_list($dados)) {
                throw new \UnexpectedValueException('Lista FIPE inválida.', 502);
            }
            $ano = (int) explode('-', $consulta->anoCombustivel)[0];
            $sigla = ['1' => 'G', '2' => 'A', '3' => 'D', '4' => 'E', '5' => 'F', '6' => 'H'][explode('-', $consulta->anoCombustivel)[1]];
            $linhas = array_values(array_filter($dados, static fn ($linha): bool => is_array($linha) && (int) ($linha['anoModelo'] ?? -1) === $ano && ($linha['siglaCombustivel'] ?? '') === $sigla));
            if ($linhas === []) {
                throw new \RuntimeException('Ano/combustível FIPE não encontrado.', 404);
            }
            if (count($linhas) !== 1) {
                throw new \UnexpectedValueException('Resposta FIPE ambígua.', 502);
            }
            $dados = $linhas[0];
            $mapa = ['valor' => 'valor', 'marca' => 'marca', 'modelo' => 'modelo', 'ano_modelo' => 'anoModelo', 'combustivel' => 'combustivel', 'codigo_fipe' => 'codigoFipe', 'mes_referencia' => 'mesReferencia', 'tipo_veiculo' => 'tipoVeiculo', 'sigla_combustivel' => 'siglaCombustivel'];
        } else {
            $mapa = ['valor' => 'price', 'marca' => 'brand', 'modelo' => 'model', 'ano_modelo' => 'modelYear', 'combustivel' => 'fuel', 'codigo_fipe' => 'codeFipe', 'mes_referencia' => 'referenceMonth', 'tipo_veiculo' => 'vehicleType', 'sigla_combustivel' => 'fuelAcronym'];
        }
        $linha = [];
        foreach ($mapa as $destino => $origem) {
            $linha[$destino] = $dados[$origem] ?? null;
        }

        return NormalizadorFipe::linha($linha, $consulta, $this->nome);
    }
}
