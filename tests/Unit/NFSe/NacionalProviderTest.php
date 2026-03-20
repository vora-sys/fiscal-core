<?php

namespace Tests\Unit\NFSe;

use freeline\FiscalCore\Providers\NFSe\NacionalProvider;
use PHPUnit\Framework\TestCase;

class NacionalProviderTest extends TestCase
{
    public function test_emitir_monta_xml_e_envia_para_endpoint_correto(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso><NumeroNfse>123</NumeroNfse></Resposta>';
        }));

        $response = $provider->emitir($this->dadosValidos());

        $this->assertStringContainsString('<Sucesso>true</Sucesso>', $response);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('/nfse', $calls[0]['path']);
        $this->assertStringContainsString('<NFSe', (string) $calls[0]['body']);
        $this->assertStringContainsString('infDPS', (string) $calls[0]['body']);
    }

    public function test_cancelar_retorna_true_quando_resposta_indica_sucesso(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(
            function ($method = null, $path = null, $body = null, $headers = []) use (&$calls) {
                $calls[] = compact('method', 'path', 'body');
                return '<CancelarResposta><Sucesso>true</Sucesso></CancelarResposta>';
            }
        ));

        $result = $provider->cancelar('NFSE123', 'Erro operacional');
        $this->assertTrue($result);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('/nfse/NFSE123/eventos', $calls[0]['path']);
    }

    public function test_consultar_retorno_compnfse_com_xml_real(): void
    {
        $xmlReferencia = file_get_contents(__DIR__ . '/../../Fixtures/belem/retorno_lista_nfse_sanitizado.xml');
        $this->assertNotFalse($xmlReferencia);

        $provider = new NacionalProvider($this->buildConfig(
            fn ($method = null, $path = null, $body = null, $headers = []) => (string) $xmlReferencia
        ));

        $result = $provider->cancelar('NFSE123', 'Erro operacional');
        $this->assertTrue($result);
    }

    public function test_consultar_por_rps_valida_campos_obrigatorios(): void
    {
        $provider = new NacionalProvider($this->buildConfig(fn ($method = null, $path = null, $body = null, $headers = []) => '<ok/>'));
        $this->expectException(\InvalidArgumentException::class);

        $provider->consultarPorRps(['numero' => 1]);
    }

    public function test_emitir_resolve_rota_por_servico_configurado(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        });
        $config['services'] = [
            'adn' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/api/v1',
                'producao' => 'https://adn.nfse.gov.br/api/v1',
            ],
        ];
        $config['endpoints']['emitir'] = 'adn:/nfse';

        $provider = new NacionalProvider($config);
        $provider->emitir($this->dadosValidos());

        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/api/v1/nfse', $calls[0]['path']);
        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('dpsXmlGZipB64', $payload);
    }

    public function test_catalogo_resolve_rota_por_servico_configurado(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body = null, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path');
            if ($method === 'GET') {
                return json_encode(['data' => [['codigo_municipio' => '3550308']]], JSON_UNESCAPED_UNICODE);
            }

            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        });
        $config['services'] = [
            'cnc_municipio' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/cnc/municipio',
                'producao' => 'https://adn.nfse.gov.br/cnc/municipio',
            ],
            'cnc_consulta' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/cnc/consulta',
                'producao' => 'https://adn.nfse.gov.br/cnc/consulta',
            ],
        ];
        $config['catalog_endpoints'] = [
            'municipios' => 'cnc_municipio:/municipios',
            'aliquotas_municipio' => 'cnc_consulta:/municipios/{codigo_municipio}/aliquotas',
        ];

        $provider = new NacionalProvider($config);
        $provider->listarMunicipiosNacionais(true);
        $provider->consultarAliquotasMunicipio('3550308', null, null, true);

        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/cnc/municipio/municipios', $calls[0]['path']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/cnc/consulta/municipios/3550308/aliquotas', $calls[1]['path']);
    }

    public function test_consulta_cnc_resolve_rota_por_servico(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body = null, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path');
            return json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        });
        $config['services'] = [
            'adn_contribuintes' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/contribuintes',
                'producao' => 'https://adn.nfse.gov.br/contribuintes',
            ],
        ];
        $config['cnc_endpoints'] = [
            'contribuinte' => 'adn_contribuintes:/{cpfCnpj}',
            'habilitacao' => 'adn_contribuintes:/{cpfCnpj}/habilitacao',
        ];

        $provider = new NacionalProvider($config);
        $provider->consultarContribuinteCnc('11.222.333/0001-81');
        $provider->verificarHabilitacaoCnc('11.222.333/0001-81', '3550308');

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/contribuintes/11222333000181', $calls[0]['path']);
        $this->assertSame('GET', $calls[1]['method']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/contribuintes/11222333000181/habilitacao?codigoMunicipio=3550308', $calls[1]['path']);
    }

    public function test_consulta_cnc_contribuinte_e_habilitacao(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body = null, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path');
            if ($path === '/contribuintes/11222333000181') {
                return json_encode([
                    'documento' => '11222333000181',
                    'situacao' => 'HABILITADO',
                    'habilitado' => true,
                ]);
            }

            if ($path === '/contribuintes/11222333000181/habilitacao?codigoMunicipio=4106902') {
                return json_encode([
                    'documento' => '11222333000181',
                    'situacao' => 'HABILITADO',
                    'habilitado' => true,
                ]);
            }

            return json_encode(['habilitado' => false]);
        });

        $provider = new NacionalProvider($config);
        $contribuinte = $provider->consultarContribuinteCnc('11.222.333/0001-81');
        $habilitacao = $provider->verificarHabilitacaoCnc('11.222.333/0001-81', '4106902');

        $this->assertTrue($contribuinte['habilitado']);
        $this->assertTrue($habilitacao);
        $this->assertSame('/contribuintes/11222333000181', $calls[0]['path']);
        $this->assertSame('/contribuintes/11222333000181/habilitacao?codigoMunicipio=4106902', $calls[1]['path']);
    }

    private function buildConfig(callable $httpClient): array
    {
        return [
            'codigo_municipio' => '3550308',
            'versao' => '1.00',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => [
                'emitir' => '/nfse',
                'consultar' => '/nfse/{id}',
                'cancelar' => '/nfse/{id}/eventos',
                'substituir' => '/nfse',
                'consultar_rps' => '/nfse/consultar-rps',
                'consultar_lote' => '/nfse/consultar-lote',
                'baixar_xml' => '/nfse/download/xml',
                'baixar_danfse' => '/nfse/download/danfse',
            ],
            'operation_methods' => [
                'emitir' => 'POST',
                'consultar' => 'GET',
                'cancelar' => 'POST',
                'substituir' => 'POST',
            ],
            'http_client' => $httpClient,
            'cache_dir' => sys_get_temp_dir() . '/fiscal-core-provider-' . uniqid(),
        ];
    }

    private function dadosValidos(): array
    {
        return [
            'prestador' => [
                'cnpj' => '11.222.333/0001-81',
                'inscricaoMunicipal' => '12345',
            ],
            'tomador' => [
                'documento' => '12345678901',
                'razaoSocial' => 'Tomador Teste',
            ],
            'servico' => [
                'codigo' => '0107',
                'discriminacao' => 'Serviço de desenvolvimento',
                'aliquota' => 0.02,
            ],
            'valor_servicos' => 1000.00,
            'rps_numero' => '10',
            'rps_serie' => 'A1',
            'rps_tipo' => '1',
        ];
    }
}
