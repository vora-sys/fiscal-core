<?php

namespace sabbajohn\FiscalCore\Fipe;

use GuzzleHttp\ClientInterface;
use Hertzogjr\FipePhpSdk\Make\DTOs\MakesByVehicleTypeDTO;
use Hertzogjr\FipePhpSdk\Model\DTOs\ModelsByMakePayloadDTO;
use Hertzogjr\FipePhpSdk\Model\DTOs\ModelYearsPayloadDTO;
use Hertzogjr\FipePhpSdk\Vehicle\Enums\FipeVehicleTypeEnum;
use sabbajohn\FiscalCore\Contracts\EstadoFipeInterface;

final class CatalogoFipe
{
    public function __construct(private ClientInterface $cliente, private ProvedorSdkFipe $direta, private EstadoFipeInterface $estado) {}

    public function consultar(string $etapa, string $tipo = 'carros', ?int $tabela = null, ?int $marca = null, ?int $modelo = null, ?OrcamentoFipe $orcamento = null, ?string $codigoFipe = null): array
    {
        if (! in_array($etapa, ['referencias', 'marcas', 'modelos', 'anos', 'anos_codigo'], true) || ! isset(ConsultaFipe::tipos()[$tipo])
            || ($etapa !== 'referencias' && ($tabela === null || $tabela < 1))
            || (in_array($etapa, ['modelos', 'anos'], true) && ($marca === null || $marca < 1))
            || ($etapa === 'anos' && ($modelo === null || $modelo < 1))
            || ($etapa === 'anos_codigo' && ! preg_match('/^[0-9]{6}-[0-9]$/D', $codigoFipe ?? ''))) {
            throw new \InvalidArgumentException('Seleção FIPE incompleta.');
        }
        $orcamento ??= new OrcamentoFipe;
        $alertas = [];
        foreach (['parallelum', 'fipe_direta'] as $fonte) {
            if ($fonte === 'fipe_direta' && $etapa === 'anos_codigo') {
                continue;
            } // SDK não resolve códigos por varredura.
            $chave = 'fipe:catalogo:v1:'.hash('sha256', implode('|', [$fonte, $etapa, $tipo, $tabela, $marca, $modelo, $codigoFipe]));
            if (($cache = $this->estado->obter($chave)) !== null) {
                return $cache;
            }
            if (! $this->estado->reservar($fonte)) {
                continue;
            }
            try {
                if ($fonte === 'parallelum') {
                    $base = 'https://fipe.parallelum.com.br/api/v2';
                    $path = match ($etapa) {
                        'referencias' => '/references',
                        'marcas' => '/'.ConsultaFipe::tipos()[$tipo][1].'/brands',
                        'modelos' => '/'.ConsultaFipe::tipos()[$tipo][1].'/brands/'.$marca.'/models',
                        'anos' => '/'.ConsultaFipe::tipos()[$tipo][1].'/brands/'.$marca.'/models/'.$modelo.'/years',
                        'anos_codigo' => '/'.ConsultaFipe::tipos()[$tipo][1].'/'.$codigoFipe.'/years',
                    };
                    $opcoes = $orcamento->opcoes();
                    if ($etapa !== 'referencias') {
                        $opcoes['query'] = ['reference' => $tabela];
                    }
                    $resposta = $this->cliente->request('GET', $base.$path, $opcoes);
                    if ($resposta->getStatusCode() !== 200) {
                        throw FalhaFonteFipe::resposta($resposta);
                    }
                    $dados = json_decode((string) $resposta->getBody(), true, 64, JSON_THROW_ON_ERROR);
                } else {
                    $sdk = $this->direta->cliente($orcamento);
                    $tipoSdk = FipeVehicleTypeEnum::from((string) ConsultaFipe::tipos()[$tipo][0]);
                    $dados = match ($etapa) {
                        'referencias' => $sdk->referenceTable()->all()['data'],
                        'marcas' => $sdk->make()->byVehicleType(new MakesByVehicleTypeDTO($tipoSdk, (string) $tabela))['data'],
                        'modelos' => $sdk->model()->all(new ModelsByMakePayloadDTO((string) $marca, (string) $tabela, $tipoSdk))['data']['Modelos'],
                        'anos' => $sdk->model()->years(new ModelYearsPayloadDTO((string) $marca, (string) $tabela, $tipoSdk, (string) $modelo))['data'],
                    };
                }
                if (! is_array($dados) || ! array_is_list($dados) || $dados === [] || count($dados) > 2000) {
                    throw new \UnexpectedValueException('Catálogo FIPE inválido.', 502);
                }
                $itens = [];
                foreach ($dados as $dado) {
                    if ($fonte === 'parallelum') {
                        $codigo = $dado['code'] ?? null;
                        $nome = $dado[$etapa === 'referencias' ? 'month' : 'name'] ?? null;
                    } else {
                        $codigo = $etapa === 'referencias' ? $dado->code : $dado->value;
                        $nome = $etapa === 'referencias' ? $dado->month : $dado->label;
                    }
                    if (! is_scalar($codigo) || ! is_string($nome) || trim($nome) === ''
                        || ! preg_match(in_array($etapa, ['anos', 'anos_codigo'], true) ? '/^(?:[0-9]{4}|32000)-[1-6]$/D' : '/^[1-9][0-9]*$/D', (string) $codigo)) {
                        throw new \UnexpectedValueException('Item FIPE inválido.', 502);
                    }
                    $itens[] = ['codigo' => (string) $codigo, 'nome' => trim($nome)];
                }
                if ($etapa === 'referencias') {
                    foreach ($itens as $item) {
                        new ConsultaFipe('carros', '000000-0', '2020-1', (int) $item['codigo'], $item['nome']);
                    }
                }
                $resultado = ['itens' => $itens, 'fonte' => $fonte, 'tabela_referencia' => $tabela, 'alertas' => $alertas];
                $this->estado->recuperou($fonte);
                $this->estado->guardar($chave, $resultado, $etapa === 'referencias' ? 3600 : 86400);

                return $resultado;
            } catch (\Throwable $erro) {
                if ($fonte === 'fipe_direta') {
                    $erro = $this->direta->falha($erro);
                }
                $status = (int) $erro->getCode();
                if (in_array($status, [400, 404, 422], true)) {
                    throw new \RuntimeException('Seleção FIPE rejeitada ou não encontrada.', $status);
                }
                if (in_array($status, [401, 403], true)) {
                    $alertas[$fonte] = 'ACESSO_NEGADO_OU_CONFIGURACAO_INVALIDA';
                }
                $this->estado->falhou($fonte, max($erro instanceof FalhaFonteFipe ? $erro->espera : 0, in_array($status, [401, 403, 429], true) ? 300 : 60));
            }
        }
        throw new FalhaConsultaFipe('Catálogo FIPE indisponível. Tente novamente mais tarde.', 503, $alertas);
    }
}
