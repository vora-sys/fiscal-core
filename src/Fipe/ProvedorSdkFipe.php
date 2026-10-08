<?php

namespace sabbajohn\FiscalCore\Fipe;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Hertzogjr\FipePhpSdk\FipeClient;
use Hertzogjr\FipePhpSdk\Vehicle\DTOs\VehiclePayloadDTO;
use Hertzogjr\FipePhpSdk\Vehicle\Enums\FipeVehicleTypeEnum;
use Psr\Http\Message\ResponseInterface;
use sabbajohn\FiscalCore\Contracts\ProvedorFipeInterface;

final class ProvedorSdkFipe implements ProvedorFipeInterface
{
    private int $espera = 0;

    public function __construct(private ?Client $cliente = null) {}
    public function fonte(): string
    {
        return 'fipe_direta';
    }

    public function cliente(OrcamentoFipe $orcamento): FipeClient
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            throw new \RuntimeException('Consulta direta requer PHP 8.4 e o SDK FIPE opcional instalado.', 503);
        }
        $this->espera = 0;
        $opcoes = $orcamento->opcoes();
        $opcoes['on_headers'] = function (ResponseInterface $resposta): void {
            if ($resposta->getStatusCode() === 429) {
                $this->espera = FalhaFonteFipe::resposta($resposta)->espera;
            }
        };
        $sdk = new FipeClient;
        // O SDK 1.0.0 não oferece timeout no construtor; seu client público permite
        // injetar Guzzle com orçamento, sem alterar/vendorizar o pacote.
        $config = array_merge($this->cliente?->getConfig() ?? [], $opcoes, ['http_errors' => true]);
        $handler = $config['handler'] ?? HandlerStack::create();
        $handler = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);
        $handler->push(Middleware::mapResponse(static function (ResponseInterface $resposta): ResponseInterface {
            if ($resposta->getStatusCode() !== 200) {
                throw FalhaFonteFipe::resposta($resposta);
            }

            return $resposta;
        }));
        $config['handler'] = $handler;
        $sdk->client = new Client($config);

        return $sdk;
    }

    public static function traduzirErro(\Throwable $erro): \RuntimeException
    {
        $mensagem = $erro->getMessage();
        $status = match (true) {
            str_contains($mensagem, 'Invalid parameters provided') => 400,
            str_contains($mensagem, 'Vehicle not found'), str_contains($mensagem, 'not found') => 404,
            in_array((int) $erro->getCode(), [400, 401, 403, 404, 422, 429, 500, 502, 503, 504], true) => (int) $erro->getCode(),
            default => 503,
        };

        return new \RuntimeException('Fonte FIPE direta indisponível ou consulta rejeitada.', $status, $erro);
    }

    public function falha(\Throwable $erro): \RuntimeException
    {
        $traduzido = self::traduzirErro($erro);

        return $traduzido->getCode() === 429 ? new FalhaFonteFipe(429, $this->espera) : $traduzido;
    }

    public function consultar(ConsultaFipe $consulta, OrcamentoFipe $orcamento): array
    {
        if ($consulta->codigoMarca === null || $consulta->codigoModelo === null) {
            throw new \RuntimeException('Selecione marca e modelo para habilitar a consulta direta à FIPE.', 503);
        }
        try {
            $sdk = $this->cliente($orcamento);
            $resultado = $sdk->vehicle()->get(new VehiclePayloadDTO((string) $consulta->tabelaReferencia,
                (string) $consulta->codigoMarca, (string) $consulta->codigoModelo,
                FipeVehicleTypeEnum::from((string) ConsultaFipe::tipos()[$consulta->tipoVeiculo][0]), $consulta->anoCombustivel));
            $item = $resultado['data'];
            $linha = ['valor' => $item->value, 'marca' => $item->make, 'modelo' => $item->model, 'ano_modelo' => $item->modelYear,
                'combustivel' => $item->fuel, 'codigo_fipe' => $item->fipeCode, 'mes_referencia' => $item->referenceMonth,
                'tipo_veiculo' => $item->vehicleType, 'sigla_combustivel' => $item->fuelAbbreviation, 'data_consulta' => $item->consultationDate];
        } catch (\Throwable $erro) {
            throw $this->falha($erro);
        }

        return NormalizadorFipe::linha($linha, $consulta, $this->fonte());
    }
}
