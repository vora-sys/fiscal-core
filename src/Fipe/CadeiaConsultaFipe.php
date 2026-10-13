<?php

namespace sabbajohn\FiscalCore\Fipe;

use sabbajohn\FiscalCore\Contracts\EstadoFipeInterface;
use sabbajohn\FiscalCore\Contracts\ProvedorFipeInterface;
use sabbajohn\FiscalCore\Support\FiscalResponse;

final class CadeiaConsultaFipe
{
    /** @param ProvedorFipeInterface[] $provedores */
    public function __construct(private array $provedores, private EstadoFipeInterface $estado) {}

    public function consultar(ConsultaFipe $consulta, ?OrcamentoFipe $orcamento = null): FiscalResponse
    {
        $orcamento ??= new OrcamentoFipe;
        $tentativas = [];
        $alertas = [];
        foreach ($this->provedores as $provedor) {
            $fonte = $provedor->fonte();
            $cache = $this->estado->obter($consulta->chave($fonte));
            if ($cache !== null) {
                return FiscalResponse::success([$cache], 'consultar_preco_fipe', ['provider' => $fonte, 'fallback' => $fonte !== 'brasil_api', 'cache' => true, 'tentativas' => $cache['metadados_consulta']['tentativas'] ?? [], 'alertas' => $cache['metadados_consulta']['alertas'] ?? []]);
            }
        }
        foreach ($this->provedores as $provedor) {
            $fonte = $provedor->fonte();
            if ($fonte === 'fipe_direta' && $consulta->codigoMarca === null) {
                $tentativas[] = ['fonte' => $fonte, 'estado' => 'selecao_marca_modelo_necessaria'];

                continue;
            }
            if (! $this->estado->reservar($fonte)) {
                $tentativas[] = ['fonte' => $fonte, 'estado' => 'quota_ou_circuito'];

                continue;
            }
            try {
                $linha = $provedor->consultar($consulta, $orcamento);
                // Validar também adapters personalizados e antes de gravar qualquer cache.
                $linha = NormalizadorFipe::linha($linha, $consulta, $fonte);
                $linha['metadados_consulta'] = ['tentativas' => $tentativas, 'alertas' => $alertas];
                $this->estado->recuperou($fonte);
                $this->estado->guardar($consulta->chave($fonte), $linha, 3600);

                return FiscalResponse::success([$linha], 'consultar_preco_fipe', ['provider' => $fonte, 'fallback' => $fonte !== 'brasil_api', 'cache' => false, 'tentativas' => $tentativas, 'alertas' => $alertas]);
            } catch (\Throwable $erro) {
                $status = (int) $erro->getCode();
                $tentativas[] = ['fonte' => $fonte, 'estado' => 'erro', 'status' => $status ?: 503];
                if ($erro instanceof \InvalidArgumentException || in_array($status, [400, 404, 422], true)) {
                    return FiscalResponse::error($status === 404 ? 'Consulta FIPE não encontrada.' : 'Parâmetros FIPE rejeitados.',
                        $status === 404 ? 'CONSULTA_NAO_ENCONTRADA' : 'INVALID_ARGUMENT', 'consultar_preco_fipe', ['provider' => $fonte, 'tentativas' => $tentativas, 'alertas' => $alertas]);
                }
                if (in_array($status, [401, 403], true)) {
                    $alertas[$fonte] = 'ACESSO_NEGADO_OU_CONFIGURACAO_INVALIDA';
                }
                $this->estado->falhou($fonte, max($erro instanceof FalhaFonteFipe ? $erro->espera : 0, in_array($status, [401, 403, 429], true) ? 300 : 60));
            }
        }

        return FiscalResponse::error('Consulta FIPE indisponível. Se necessário, selecione marca e modelo para a fonte direta.',
            'CONSULTA_INDISPONIVEL', 'consultar_preco_fipe', ['tentativas' => $tentativas, 'alertas' => $alertas]);
    }
}
