<?php

namespace Tests\Unit\Utils;

use BrasilApi\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\BrasilAPIAdapter;
use sabbajohn\FiscalCore\Facade\UtilsFacade;

class CnpjResponseMappingTest extends TestCase
{
    public function test_cnpj_ws_is_used_when_brasil_api_is_unavailable(): void
    {
        $primary = new class extends Client
        {
            public function __construct() {}

            public function cnpj(): object
            {
                return new class
                {
                    public function get(string $cnpj): array
                    {
                        throw new \RuntimeException('BrasilAPI indisponível.', 503);
                    }
                };
            }
        };
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'razao_social' => 'FREELINE INFORMATICA LTDA',
                'porte' => ['id' => '01', 'descricao' => 'Micro Empresa'],
                'natureza_juridica' => ['id' => '2062', 'descricao' => 'Sociedade Empresária Limitada'],
                'simples' => ['simples' => 'Sim', 'mei' => 'Não'],
                'estabelecimento' => [
                    'cnpj' => '83188342000104',
                    'nome_fantasia' => 'FREELINE',
                    'ddd1' => '47',
                    'telefone1' => '4230813',
                    'email' => 'contato@example.com',
                    'logradouro' => 'BENJAMIN CONSTANT',
                    'numero' => '4135',
                    'bairro' => 'GLORIA',
                    'cep' => '89217002',
                    'atividade_principal' => [
                        'id' => '6203100',
                        'descricao' => 'Desenvolvimento de programas de computador',
                    ],
                    'cidade' => ['nome' => 'Joinville', 'ibge_id' => 4209102],
                    'estado' => ['sigla' => 'SC'],
                ],
            ], JSON_UNESCAPED_UNICODE) ?: '{}'),
        ]);
        $fallback = new HttpClient([
            'base_uri' => 'https://publica.cnpj.ws/',
            'handler' => HandlerStack::create($handler),
        ]);

        $result = (new BrasilAPIAdapter($primary, $fallback))->consultarCNPJ('83.188.342/0001-04');

        $this->assertSame('cnpj_ws', $result['_source']);
        $this->assertSame('FREELINE INFORMATICA LTDA', $result['razao_social']);
        $this->assertSame('6203100', $result['cnae_fiscal']);
        $this->assertSame('2062', $result['natureza_juridica']);
        $this->assertSame('01', $result['codigo_porte']);
        $this->assertTrue($result['opcao_pelo_simples']);
        $this->assertFalse($result['opcao_pelo_mei']);
        $this->assertSame('474230813', $result['telefone']);
        $this->assertSame('4209102', $result['codigo_municipio_ibge']);
        $this->assertSame('/cnpj/83188342000104', $handler->getLastRequest()?->getUri()->getPath());
    }

    public function test_brasil_api_adapter_unwraps_nested_data_payload(): void
    {
        $adapter = new BrasilAPIAdapter;
        $reflection = new \ReflectionClass($adapter);
        $method = $reflection->getMethod('normalizeResponse');

        $normalized = $method->invoke($adapter, [
            'data' => [
                'cnpj' => '50350496000100',
                'razaoSocial' => 'SABBA SISTEMAS E INTEGRACOES LTDA',
            ],
        ]);

        $this->assertSame('50350496000100', $normalized['cnpj']);
        $this->assertSame('SABBA SISTEMAS E INTEGRACOES LTDA', $normalized['razaoSocial']);
    }

    public function test_utils_facade_maps_new_cnpj_payload_format(): void
    {
        $facade = new UtilsFacade;
        $reflection = new \ReflectionClass($facade);
        $method = $reflection->getMethod('mapearConsultaCNPJ');

        $mapped = $method->invoke($facade, '50350496000100', [
            'cnpj' => '50350496000100',
            'razaoSocial' => 'SABBA SISTEMAS E INTEGRACOES LTDA',
            'nomeFantasia' => 'CROW IT CONSULTING',
            'email' => '',
            'telefone' => '4730282699',
            'cnaePrincipal' => 'Desenvolvimento de programas de computador sob encomenda',
            'logradouro' => 'JOAO EBERHARDT',
            'numero' => '212',
            'complemento' => '',
            'bairro' => 'PIRABEIRABA',
            'cep' => '89239110',
            'municipio' => 'JOINVILLE',
            'uf' => 'SC',
        ]);

        $this->assertSame('50.350.496/0001-00', $mapped['cnpj']);
        $this->assertSame('50350496000100', $mapped['cnpj_limpo']);
        $this->assertSame('SABBA SISTEMAS E INTEGRACOES LTDA', $mapped['razao_social']);
        $this->assertSame('CROW IT CONSULTING', $mapped['nome_fantasia']);
        $this->assertSame('4730282699', $mapped['telefone']);
        $this->assertSame('Desenvolvimento de programas de computador sob encomenda', $mapped['atividade_principal']);
        $this->assertSame('JOAO EBERHARDT', $mapped['logradouro']);
        $this->assertSame('212', $mapped['numero']);
        $this->assertSame('PIRABEIRABA', $mapped['bairro']);
        $this->assertSame('89239110', $mapped['cep']);
        $this->assertSame('JOINVILLE', $mapped['municipio']);
        $this->assertSame('SC', $mapped['uf']);
    }
}
