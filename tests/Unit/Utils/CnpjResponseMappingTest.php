<?php

namespace Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use freeline\FiscalCore\Adapters\BrasilAPIAdapter;
use freeline\FiscalCore\Facade\UtilsFacade;

class CnpjResponseMappingTest extends TestCase
{
    public function test_brasil_api_adapter_unwraps_nested_data_payload(): void
    {
        $adapter = new BrasilAPIAdapter();
        $reflection = new \ReflectionClass($adapter);
        $method = $reflection->getMethod('normalizeResponse');
        $method->setAccessible(true);

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
        $facade = new UtilsFacade();
        $reflection = new \ReflectionClass($facade);
        $method = $reflection->getMethod('mapearConsultaCNPJ');
        $method->setAccessible(true);

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
