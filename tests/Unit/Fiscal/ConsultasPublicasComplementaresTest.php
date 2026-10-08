<?php

namespace Tests\Unit\Fiscal;

use sabbajohn\FiscalCore\Adapters\BrasilAPIAdapter;
use sabbajohn\FiscalCore\Facade\UtilsFacade;
use BrasilApi\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ConsultasPublicasComplementaresTest extends TestCase
{
    private array $historico = [];

    private function adapter(array $respostas): BrasilAPIAdapter
    {
        $stack = HandlerStack::create(new MockHandler($respostas));
        $stack->push(Middleware::history($this->historico));

        return new BrasilAPIAdapter(new Client(['handler' => $stack, 'connect_timeout' => 3, 'timeout' => 8]));
    }

    private function fixture(string $nome): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/ConsultasPublicas/'.$nome.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_fipe_preserva_lista_valor_e_mes_e_envia_tabela_na_query(): void
    {
        $adapter = $this->adapter([new Response(200, [], json_encode($this->fixture('fipe-preco')))]);
        $resposta = (new UtilsFacade($adapter))->consultarPrecoFipe('001004-9', 271);
        self::assertTrue($resposta->isSuccess());
        self::assertSame('R$ 6.022,00', $resposta->getData()[0]['valor']);
        self::assertSame('junho de 2021', $resposta->getData()[0]['mes_referencia']);
        self::assertSame('brasil_api', $resposta->getData()[0]['fonte']);
        self::assertSame(271, $resposta->getData()[0]['tabela_referencia']);
        self::assertSame('/api/fipe/preco/v1/001004-9', $this->historico[0]['request']->getUri()->getPath());
        self::assertSame('tabela_referencia=271', $this->historico[0]['request']->getUri()->getQuery());
    }

    public function test_cep_v2_preserva_coordenadas_e_campos_nulos_sem_converter_array_para_string(): void
    {
        $dados = $this->fixture('cep-v2');
        $dados['street'] = null;
        $adapter = $this->adapter([new Response(200, [], json_encode($dados))]);
        $resposta = (new UtilsFacade($adapter))->consultarCEPv2('01001-000');
        self::assertTrue($resposta->isSuccess());
        self::assertSame('', $resposta->getData()['logradouro']);
        self::assertSame('Sé', $resposta->getData()['bairro']);
        self::assertSame('-23.550', $resposta->getData()['localizacao']['coordenadas']['latitude']);
        self::assertArrayNotHasKey('ibge', $resposta->getData());
        self::assertSame('/api/cep/v2/01001000', $this->historico[0]['request']->getUri()->getPath());
    }

    public function test_cep_sem_coordenadas_nao_inventa_localizacao(): void
    {
        $dados = $this->fixture('cep-v2');
        unset($dados['location']);
        $resposta = (new UtilsFacade($this->adapter([new Response(200, [], json_encode($dados))])))->consultarCEPv2('01001000');
        self::assertTrue($resposta->isSuccess());
        self::assertNull($resposta->getData()['localizacao']['coordenadas']['latitude']);
    }

    public function test_entradas_invalidas_nao_fazem_requisicao(): void
    {
        $utils = new UtilsFacade($this->adapter([]));
        foreach (['123', '0010049', '../001004-9', '001004-a'] as $codigo) {
            self::assertSame('INVALID_ARGUMENT', $utils->consultarPrecoFipe($codigo)->getErrorCode());
        }
        foreach (['123', 'abcdefgh', '01001-000x'] as $cep) {
            self::assertSame('INVALID_ARGUMENT', $utils->consultarCEPv2($cep)->getErrorCode());
        }
        self::assertSame('INVALID_ARGUMENT', $utils->consultarPrecoFipe('001004-9', 0)->getErrorCode());
        self::assertSame('INVALID_ARGUMENT', $utils->consultarMarcasPorTipoVeiculo('barcos')->getErrorCode());
        self::assertSame([], $this->historico);
    }

    public function test_tabelas_marcas_e_aliases_do_rascunho(): void
    {
        $adapter = $this->adapter([
            new Response(200, [], '[{"codigo":271,"mes":"junho/2021"}]'),
            new Response(200, [], '[{"nome":"AGRALE","valor":"102"}]'),
            new Response(200, [], json_encode($this->fixture('fipe-preco'))),
        ]);
        self::assertSame(271, $adapter->consutaTabelaReferencia()[0]['codigo']);
        self::assertSame('AGRALE', $adapter->consutaMarcasPorTipoVeiculo('caminhoes', 271)[0]['nome']);
        self::assertSame('001004-9', $adapter->consutaPrecoFipe('001004-9')[0]['codigoFipe']);
        self::assertSame('tabela_referencia=271', $this->historico[1]['request']->getUri()->getQuery());
        self::assertSame('', $this->historico[2]['request']->getUri()->getQuery());
    }

    public function test_falhas_http_tem_codigos_claros_sem_expor_corpo_do_provedor(): void
    {
        foreach ([400 => 'INVALID_ARGUMENT', 404 => 'CONSULTA_NAO_ENCONTRADA', 429 => 'CONSULTA_INDISPONIVEL', 500 => 'CONSULTA_INDISPONIVEL'] as $status => $codigo) {
            $resposta = (new UtilsFacade($this->adapter([new Response($status, [], '{"message":"segredo-do-provedor"}')])))->consultarPrecoFipe('001004-9');
            self::assertFalse($resposta->isSuccess());
            self::assertSame($codigo, $resposta->getErrorCode());
            self::assertStringNotContainsString('segredo-do-provedor', $resposta->getError());
        }
    }

    public function test_timeout_e_respostas_invalidas_nao_sao_sucesso(): void
    {
        $respostas = [new ConnectException('timeout interno', new Request('GET', 'https://brasilapi.com.br')), new Response(200, [], '{malformado'), new Response(200, [], '[]'), new Response(200, [], '{"message":"erro"}'), new Response(200, [], '[{"modelo":{}}]')];
        foreach ($respostas as $indice => $resposta) {
            $resultado = (new UtilsFacade($this->adapter([$resposta])))->consultarPrecoFipe('001004-9');
            self::assertFalse($resultado->isSuccess());
            self::assertSame($indice === 0 ? 'CONSULTA_INDISPONIVEL' : 'RESPOSTA_INVALIDA', $resultado->getErrorCode());
        }
    }

    public function test_facade_rejeita_preco_de_outro_codigo(): void
    {
        $dados = $this->fixture('fipe-preco');
        $dados[0]['codigoFipe'] = '000000-0';
        $resposta = (new UtilsFacade($this->adapter([new Response(200, [], json_encode($dados))])))->consultarPrecoFipe('001004-9');
        self::assertSame('RESPOSTA_INVALIDA', $resposta->getErrorCode());
    }

    public function test_cliente_padrao_tem_timeouts_limitados(): void
    {
        $adapter = new BrasilAPIAdapter;
        $cliente = (new \ReflectionProperty($adapter, 'client'))->getValue($adapter);
        $http = (new \ReflectionProperty($cliente, 'client'))->getValue($cliente);
        self::assertSame(3, $http->getConfig('connect_timeout'));
        self::assertSame(8, $http->getConfig('timeout'));
    }
}
