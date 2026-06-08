<?php

namespace Tests\Unit\NFSe;

use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
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
        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('dpsXmlGZipB64', $payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<DPS', $xml);
        $this->assertStringContainsString('infDPS', $xml);
        $this->assertStringNotContainsString('<vRetIRRF>', $xml);
    }

    public function test_emitir_inclui_irrf_retido_na_tributacao_nacional(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['valor_irrf'] = 150.00;

        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<tribFed><vRetIRRF>150.00</vRetIRRF></tribFed>', $xml);
        $this->assertLessThan(
            strpos($xml, '<pAliq>'),
            strpos($xml, '<tpRetISSQN>'),
            'tpRetISSQN must be emitted before pAliq in the national DPS schema order.'
        );
    }

    public function test_emitir_inclui_endereco_nacional_do_tomador_no_bloco_toma(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['tomador']['endereco'] = [
            'logradouro' => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cep' => '01310930',
            'codigo_municipio' => '3550308',
        ];

        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<toma>', $xml);
        $this->assertStringContainsString('<end>', $xml);
        $this->assertStringContainsString('<endNac><cMun>3550308</cMun><CEP>01310930</CEP></endNac>', $xml);
        $this->assertStringContainsString('<xLgr>Rua Teste</xLgr>', $xml);
        $this->assertStringContainsString('<nro>100</nro>', $xml);
        $this->assertStringContainsString('<xBairro>Centro</xBairro>', $xml);
    }

    public function test_dps_nacional_inclui_beneficio_municipal_quando_payload_tem_reducao(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['tpBM'] = '2';
        $dados['servico']['nBM'] = '15014020000001';
        $dados['servico']['pRedBCBM'] = 50;

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<BM><tpBM>2</tpBM><nBM>15014020000001</nBM><pRedBCBM>50.00</pRedBCBM></BM>', $xml);
        $this->assertLessThan(
            strpos($xml, '<BM>'),
            strpos($xml, '<tribISSQN>'),
            'BM must be emitted after tribISSQN in the national DPS schema order.'
        );
        $this->assertLessThan(
            strpos($xml, '<tpRetISSQN>'),
            strpos($xml, '<BM>'),
            'BM must be emitted before tpRetISSQN in the national DPS schema order.'
        );
    }

    public function test_emitir_normaliza_iss_retido_booleano_para_codigo_sefin(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['iss_retido'] = true;
        $dados['tomador']['endereco'] = $this->enderecoTomadorValido();
        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $xml);

        $calls = [];
        $dados = $this->dadosValidos();
        $dados['servico']['iss_retido'] = false;
        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $xml);
    }

    public function test_dps_nacional_inclui_endereco_nacional_do_tomador(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['tomador']['documento'] = '83.188.342/0001-04';
        $dados['tomador']['endereco'] = $this->enderecoTomadorValido();

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertStringContainsString(
            '<end><endNac><cMun>1302603</cMun><CEP>69005000</CEP></endNac><xLgr>Rua Silva Ramos</xLgr><nro>10</nro><xCpl>Sala 2</xCpl><xBairro>Centro</xBairro></end>',
            $xml
        );
    }

    public function test_emitir_preserva_dps_nos_artifacts_quando_transporte_falha(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            throw new \RuntimeException('HTTP 400 na operação /nfse | resposta: {"erros":[{"Codigo":"E36"}]}');
        }));

        try {
            $provider->emitir($this->dadosValidos());
            $this->fail('A emissão deveria propagar a falha de transporte.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
        }

        $artifacts = $provider->getLastOperationArtifacts();
        $this->assertSame('emitir', $artifacts['operation']);
        $this->assertStringContainsString('<DPS', (string) $artifacts['request_xml']);
        $this->assertStringContainsString('<infDPS', (string) $artifacts['request_xml']);
        $this->assertSame('error', $artifacts['parsed_response']['status'] ?? null);
        $this->assertSame('E36', $artifacts['parsed_response']['errors'][0]['code'] ?? null);
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
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br',
                'producao' => 'https://adn.nfse.gov.br',
            ],
        ];
        $config['endpoints']['emitir'] = 'adn:/nfse';

        $provider = new NacionalProvider($config);
        $provider->emitir($this->dadosValidos());

        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/nfse', $calls[0]['path']);
        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('dpsXmlGZipB64', $payload);
    }

    public function test_dps_nacional_omite_codigo_municipal_fora_do_padrao_do_schema(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['cTribNac'] = '010701';
        $dados['servico']['cTribMun'] = '010701';
        $dados['servico']['cNBS'] = '107011000';

        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringNotContainsString('<cTribMun>010701</cTribMun>', $xml);
        $this->assertStringContainsString('<cTribNac>010701</cTribNac>', $xml);
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
            'parametrizacao' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/parametrizacao',
                'producao' => 'https://adn.nfse.gov.br/parametrizacao',
            ],
        ];
        $config['catalog_endpoints'] = [
            'municipios' => 'parametrizacao:/catalogos/municipios',
            'aliquotas_municipio' => 'parametrizacao:/{codigo_municipio}/{codigoServico}/{competencia}/aliquota',
            'convenio_municipio' => 'parametrizacao:/{codigo_municipio}/convenio',
        ];

        $provider = new NacionalProvider($config);
        $provider->listarMunicipiosNacionais(true);
        $provider->consultarAliquotasMunicipio('3550308', '0107', '2026-04-08', true);
        $provider->consultarConvenioMunicipio('3550308', true);

        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/catalogos/municipios', $calls[0]['path']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/0107/2026-04-08/aliquota', $calls[1]['path']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio', $calls[2]['path']);
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
                'baixar_danfse' => '/danfse/{chave}',
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

    private function enderecoTomadorValido(): array
    {
        return [
            'logradouro' => 'Rua Silva Ramos',
            'numero' => '10',
            'complemento' => 'Sala 2',
            'bairro' => 'Centro',
            'codigo_municipio' => '1302603',
            'cep' => '69005000',
        ];
    }
}
