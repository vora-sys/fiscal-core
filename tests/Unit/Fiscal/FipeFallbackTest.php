<?php

namespace Tests\Unit\Fiscal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hertzogjr\FipePhpSdk\FipeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Fipe\CadeiaConsultaFipe;
use sabbajohn\FiscalCore\Fipe\CatalogoFipe;
use sabbajohn\FiscalCore\Fipe\ConsultaFipe;
use sabbajohn\FiscalCore\Fipe\EstadoFipeMemoria;
use sabbajohn\FiscalCore\Fipe\FalhaFonteFipe;
use sabbajohn\FiscalCore\Fipe\OrcamentoFipe;
use sabbajohn\FiscalCore\Fipe\ProvedorHttpFipe;
use sabbajohn\FiscalCore\Fipe\ProvedorSdkFipe;

class FipeFallbackTest extends TestCase
{
    private function consulta(?int $marca = 25, ?int $modelo = 1234): ConsultaFipe
    {
        return new ConsultaFipe('carros', '014090-2', '2020-5', 338, 'outubro de 2026', $marca, $modelo);
    }

    private function cliente(array $respostas, array &$historico): Client
    {
        $handler = HandlerStack::create(new MockHandler($respostas));
        $handler->push(Middleware::history($historico));

        return new Client(['handler' => $handler]);
    }

    private function paralelo(array $alteracoes = []): Response
    {
        return new Response(200, [], json_encode(array_replace([
            'vehicleType' => 1, 'price' => 'R$ 118.358,00', 'brand' => 'Honda', 'model' => 'Civic EXL',
            'modelYear' => 2020, 'fuel' => 'Flex', 'codeFipe' => '014090-2', 'referenceMonth' => 'outubro de 2026', 'fuelAcronym' => 'F',
        ], $alteracoes)));
    }

    private function direta(): Response
    {
        return new Response(200, [], json_encode([
            'Valor' => 'R$ 118.358,00', 'Marca' => 'Honda', 'Modelo' => 'Civic EXL', 'AnoModelo' => 2020,
            'Combustivel' => 'Flex', 'CodigoFipe' => '014090-2', 'MesReferencia' => 'outubro de 2026',
            'Autenticacao' => 'fixture', 'TipoVeiculo' => 1, 'SiglaCombustivel' => 'F', 'DataConsulta' => '2026-10-08',
        ]));
    }

    private function cadeia(Client $http, ?Client $sdk = null, ?EstadoFipeMemoria $estado = null): CadeiaConsultaFipe
    {
        return new CadeiaConsultaFipe([new ProvedorHttpFipe('brasil_api', $http), new ProvedorHttpFipe('parallelum', $http), new ProvedorSdkFipe($sdk)], $estado ?? new EstadoFipeMemoria);
    }

    public function test_parallelum_recupera_500_e_cache_preserva_fonte_mes_e_contrato(): void
    {
        $historico = [];
        $cliente = $this->cliente([new Response(500), $this->paralelo()], $historico);
        $cadeia = $this->cadeia($cliente);
        $resposta = $cadeia->consultar($this->consulta());
        self::assertTrue($resposta->isSuccess());
        self::assertSame('parallelum', $resposta->getMetadata('provider'));
        self::assertSame(338, $resposta->getData()[0]['tabela_referencia']);
        self::assertSame('2020-5', $resposta->getData()[0]['ano_combustivel']);
        self::assertSame('outubro de 2026', $resposta->getData()[0]['mes_referencia']);
        self::assertSame('reference=338', $historico[1]['request']->getUri()->getQuery());
        self::assertSame('/api/v2/cars/014090-2/years/2020-5', $historico[1]['request']->getUri()->getPath());
        self::assertSame('tabela_referencia=338', $historico[0]['request']->getUri()->getQuery());
        self::assertTrue($cadeia->consultar($this->consulta())->getMetadata('cache'));
        self::assertCount(2, $historico);
        self::assertFalse($historico[0]['options']['allow_redirects']);
        self::assertLessThanOrEqual(2.5, $historico[1]['options']['timeout']);
    }

    public static function falhasRecuperaveis(): array
    {
        return [[500], [429], [401], [403], [502], [504], [0]];
    }

    #[DataProvider('falhasRecuperaveis')]
    public function test_sdk_instalado_e_exercitado_com_post_real_mockado(int $status): void
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            $this->markTestSkipped('SDK opcional; executar também no consumidor PHP 8.4 com SDK instalado.');
        }
        $http = [];
        $sdk = [];
        $falha = $status === 0 ? new ConnectException('timeout', new Request('GET', 'https://brasilapi.com.br')) : new Response($status);
        $cadeia = $this->cadeia($this->cliente([$falha, new Response(500)], $http), $this->cliente([$this->direta()], $sdk));
        $resposta = $cadeia->consultar($this->consulta());
        self::assertTrue($resposta->isSuccess());
        self::assertSame('fipe_direta', $resposta->getData()[0]['fonte']);
        self::assertCount(2, $http);
        self::assertCount(1, $sdk);
        self::assertSame('POST', $sdk[0]['request']->getMethod());
        self::assertSame('veiculos.fipe.org.br', $sdk[0]['request']->getUri()->getHost());
        parse_str((string) $sdk[0]['request']->getBody(), $form);
        self::assertSame(['codigoTabelaReferencia' => '338', 'codigoMarca' => '25', 'codigoModelo' => '1234', 'codigoTipoVeiculo' => '1', 'anoModelo' => '2020', 'codigoTipoCombustivel' => '5', 'tipoVeiculo' => 'carro', 'tipoConsulta' => 'tradicional'], $form);
        self::assertLessThanOrEqual(2.5, $sdk[0]['options']['timeout']);
        self::assertFalse($sdk[0]['options']['allow_redirects']);
        if (in_array($status, [401, 403], true)) {
            self::assertSame('ACESSO_NEGADO_OU_CONFIGURACAO_INVALIDA', $resposta->getData()[0]['metadados_consulta']['alertas']['brasil_api']);
            self::assertSame($resposta->getMetadata('alertas'), $cadeia->consultar($this->consulta())->getMetadata('alertas'));
        }
    }

    public static function errosTerminais(): array
    {
        return [[400], [404], [422]];
    }

    #[DataProvider('errosTerminais')]
    public function test_erro_de_entrada_ou_404_nao_dispara_fallback(int $status): void
    {
        $historico = [];
        $resposta = $this->cadeia($this->cliente([new Response($status)], $historico))->consultar($this->consulta());
        self::assertFalse($resposta->isSuccess());
        self::assertSame($status === 404 ? 'CONSULTA_NAO_ENCONTRADA' : 'INVALID_ARGUMENT', $resposta->getErrorCode());
        self::assertCount(1, $historico);
    }

    public static function respostasIncoerentes(): array
    {
        return [[['modelYear' => 2021]], [['modelYear' => '2020abc']], [['modelYear' => 2020.5]], [['vehicleType' => 2]], [['codeFipe' => '001004-9']], [['referenceMonth' => 'setembro de 2026']], [['fuelAcronym' => 'G']], [['price' => null]]];
    }

    #[DataProvider('respostasIncoerentes')]
    public function test_retorno_incoerente_nao_e_exibido_ou_cacheado(array $alteracoes): void
    {
        $estado = new EstadoFipeMemoria;
        $historico = [];
        $cadeia = new CadeiaConsultaFipe([new ProvedorHttpFipe('parallelum', $this->cliente([$this->paralelo($alteracoes)], $historico))], $estado);
        self::assertFalse($cadeia->consultar($this->consulta())->isSuccess());
        self::assertNull($estado->obter($this->consulta()->chave('parallelum')));
    }

    public function test_sdk_requer_selecao_sem_tentar_varredura(): void
    {
        $historico = [];
        $resposta = $this->cadeia($this->cliente([new Response(500), new Response(500)], $historico))->consultar($this->consulta(null, null));
        self::assertFalse($resposta->isSuccess());
        self::assertSame('selecao_marca_modelo_necessaria', $resposta->getMetadata('tentativas')[2]['estado']);
        self::assertCount(2, $historico);
    }

    public function test_cache_discrimina_fonte_tipo_mes_ano_combustivel_e_ids(): void
    {
        $base = $this->consulta();
        $outras = [new ConsultaFipe('motos', '014090-2', '2020-5', 338, 'outubro/2026', 25, 1234),
            new ConsultaFipe('carros', '014090-2', '2020-1', 338, 'outubro/2026', 25, 1234),
            new ConsultaFipe('carros', '014090-2', '2021-5', 338, 'outubro/2026', 25, 1234),
            new ConsultaFipe('carros', '014090-2', '2020-5', 337, 'setembro/2026', 25, 1234),
            new ConsultaFipe('carros', '014090-2', '2020-5', 338, 'outubro/2026', 25, 1235)];
        foreach ($outras as $outra) {
            self::assertNotSame($base->chave('parallelum'), $outra->chave('parallelum'));
        }
        self::assertNotSame($base->chave('parallelum'), $base->chave('fipe_direta'));
    }

    public function test_orcamento_total_expira_sem_nova_chamada(): void
    {
        $tempo = 0.0;
        $orcamento = new OrcamentoFipe(relogio: static function () use (&$tempo): float {
            return $tempo;
        });
        self::assertSame(2.5, $orcamento->opcoes()['timeout']);
        $tempo = 9.5;
        self::assertSame(0.5, $orcamento->opcoes()['timeout']);
        $tempo = 10;
        $this->expectExceptionCode(503);
        $orcamento->opcoes();
    }

    public function test_orcamento_limita_quatro_chamadas_sem_retry(): void
    {
        $orcamento = new OrcamentoFipe;
        for ($i = 0; $i < 4; $i++) {
            $orcamento->opcoes();
        }
        $this->expectExceptionCode(503);
        $orcamento->opcoes();
    }

    public function test_retry_after_e_circuito_impedem_nova_tentativa(): void
    {
        self::assertSame(900, FalhaFonteFipe::resposta(new Response(429, ['Retry-After' => '900']))->espera);
        $historico = [];
        $cadeia = $this->cadeia($this->cliente([new Response(429, ['Retry-After' => '900']), new Response(500)], $historico));
        $cadeia->consultar($this->consulta(null, null));
        $cadeia->consultar($this->consulta(null, null));
        self::assertCount(2, $historico);
    }

    public function test_catalogo_codigo_preserva_referencia_e_cache(): void
    {
        $historico = [];
        $catalogo = new CatalogoFipe($this->cliente([new Response(200, [], '[{"code":"2020-5","name":"2020 Flex"}]')], $historico), new ProvedorSdkFipe, new EstadoFipeMemoria);
        $dados = $catalogo->consultar('anos_codigo', 'carros', 338, codigoFipe: '014090-2');
        self::assertSame('2020-5', $dados['itens'][0]['codigo']);
        self::assertSame('/api/v2/cars/014090-2/years', $historico[0]['request']->getUri()->getPath());
        self::assertSame('reference=338', $historico[0]['request']->getUri()->getQuery());
        self::assertSame($dados, $catalogo->consultar('anos_codigo', 'carros', 338, codigoFipe: '014090-2'));
        self::assertCount(1, $historico);
    }

    public static function catalogosSdk(): array
    {
        return [['referencias', '[{"Codigo":338,"Mes":"outubro/2026"}]', '338'],
            ['marcas', '[{"Value":"25","Label":"Honda"}]', '25'],
            ['modelos', '{"Modelos":[{"Value":"1234","Label":"Civic"}],"Anos":[]}', '1234'],
            ['anos', '[{"Value":"2020-5","Label":"2020 Flex"}]', '2020-5']];
    }

    #[DataProvider('catalogosSdk')]
    public function test_catalogos_hierarquicos_usam_sdk_efetivo(string $etapa, string $body, string $esperado): void
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            $this->markTestSkipped('SDK opcional.');
        }
        $http = [];
        $sdk = [];
        $catalogo = new CatalogoFipe($this->cliente([new Response(500)], $http), new ProvedorSdkFipe($this->cliente([new Response(200, [], $body)], $sdk)), new EstadoFipeMemoria);
        $dados = $catalogo->consultar($etapa, 'carros', 338, 25, 1234);
        self::assertSame('fipe_direta', $dados['fonte']);
        self::assertSame($esperado, $dados['itens'][0]['codigo']);
        self::assertCount(1, $sdk);
        self::assertSame('POST', $sdk[0]['request']->getMethod());
    }

    public static function codigosSdk(): array
    {
        return [['2', 400], ['0', 404]];
    }

    #[DataProvider('codigosSdk')]
    public function test_sdk_traduz_erros_de_negocio(string $codigo, int $status): void
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            $this->markTestSkipped('SDK opcional.');
        }
        $sdk = [];
        $provedor = new ProvedorSdkFipe($this->cliente([new Response(200, [], json_encode(['codigo' => $codigo]))], $sdk));
        $this->expectExceptionCode($status);
        $provedor->consultar($this->consulta(), new OrcamentoFipe);
    }

    public function test_json_invalido_recupera_por_parallelum(): void
    {
        $historico = [];
        self::assertTrue($this->cadeia($this->cliente([new Response(200, [], '{'), $this->paralelo()], $historico))->consultar($this->consulta())->isSuccess());
        self::assertCount(2, $historico);
    }
    public function test_sdk_preserva_retry_after_sem_repetir_requisicao(): void
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            $this->markTestSkipped('SDK opcional.');
        }
        $historico = [];
        $sdk = new ProvedorSdkFipe($this->cliente([new Response(429, ['Retry-After' => '1200'])], $historico));
        try {
            $sdk->consultar($this->consulta(), new OrcamentoFipe);
            self::fail('Esperava 429.');
        } catch (FalhaFonteFipe $erro) {
            self::assertSame(429, $erro->getCode());
            self::assertSame(1200, $erro->espera);
        }
        self::assertCount(1, $historico);
    }

    public static function statusSdk(): array
    {
        return [[201, 503], [301, 503], [401, 401], [422, 422], [503, 503]];
    }

    #[DataProvider('statusSdk')]
    public function test_sdk_exige_200_e_preserva_erros_http(int $status, int $esperado): void
    {
        if (PHP_VERSION_ID < 80400 || ! class_exists(FipeClient::class)) {
            $this->markTestSkipped('SDK opcional.');
        }
        $historico = [];
        $sdk = new ProvedorSdkFipe($this->cliente([new Response($status, [], (string) $this->direta()->getBody())], $historico));
        try {
            $sdk->consultar($this->consulta(), new OrcamentoFipe);
            self::fail('Esperava erro HTTP.');
        } catch (\RuntimeException $erro) {
            self::assertSame($esperado, $erro->getCode());
        }
        self::assertCount(1, $historico);
    }

}
