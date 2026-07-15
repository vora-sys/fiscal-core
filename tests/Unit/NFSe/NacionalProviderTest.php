<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPreviewSupport;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;

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
        $this->assertStringContainsString('versao="1.01"', $xml);
        $this->assertStringContainsString('infDPS', $xml);
        $this->assertStringContainsString('<serie>1</serie>', $xml);
        $this->assertStringNotContainsString('<vRetIRRF>', $xml);

        $schemaValidation = $provider->validarDpsXml($xml);
        $this->assertTrue(
            $schemaValidation['ok'],
            implode(PHP_EOL, array_column($schemaValidation['errors'], 'message'))
        );
    }

    public function test_substituir_emite_nova_dps_com_grupo_subst_valido_no_xsd(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');

            return '<Resposta><Sucesso>true</Sucesso><ChaveAcesso>123</ChaveAcesso></Resposta>';
        }));
        $dados = $this->dadosValidos();
        $dados['motivo_substituicao'] = 'rejeicao_pelo_tomador_intermediario';
        $dados['justificativa'] = 'Serviço rejeitado pelo tomador responsável.';

        $provider->substituir(str_repeat('7', 50), $dados);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('/nfse', $calls[0]['path']);
        $payload = json_decode((string) $calls[0]['body'], true);
        $xml = gzdecode((string) base64_decode((string) ($payload['dpsXmlGZipB64'] ?? '')));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<subst><chSubstda>'.str_repeat('7', 50).'</chSubstda><cMotivo>05</cMotivo>', $xml);
        $this->assertStringContainsString('<xMotivo>Serviço rejeitado pelo tomador responsável.</xMotivo>', $xml);
        $this->assertLessThan(strpos($xml, '<prest>'), strpos($xml, '<subst>'));

        $schemaValidation = $provider->validarDpsXml($xml);
        $this->assertTrue(
            $schemaValidation['ok'],
            implode(PHP_EOL, array_column($schemaValidation['errors'], 'message'))
        );
        $this->assertContains('substituir', $provider->getSupportedOperations());
    }

    public function test_facade_expoe_a_chave_da_substituta_sem_sobrescrever_com_a_chave_substituida(): void
    {
        $originalKey = str_repeat('7', 50);
        $replacementKey = str_repeat('8', 50);
        $nfseXml = '<NFSe><infNFSe Id="NFS'.$replacementKey.'"><DPS><infDPS><nDPS>11</nDPS></infDPS></DPS></infNFSe></NFSe>';
        $provider = new NacionalProvider($this->buildConfig(static fn (): string => json_encode([
            'chaveAcesso' => $replacementKey,
            'idDps' => 'DPS42091021234567800019000003000000000000011',
            'nfseXmlGZipB64' => base64_encode(gzencode($nfseXml)),
        ], JSON_THROW_ON_ERROR)));
        $facade = new NFSeFacade('nfse_nacional', new NFSeAdapter('nfse_nacional', $provider));
        $dados = $this->dadosValidos();
        $dados['nDPS'] = '11';
        $dados['motivo_substituicao'] = 'outros';
        $dados['justificativa'] = 'Correção dos dados do serviço prestado.';

        $response = $facade->substituir($originalKey, $dados);

        $this->assertTrue($response->isSuccess());
        $this->assertSame($replacementKey, $response->getData('chave'));
        $this->assertSame($replacementKey, $response->getData('chave_acesso'));
        $this->assertSame($replacementKey, $response->getData('documento')['chave_acesso']);
        $this->assertSame($originalKey, $response->getData('chave_substituida'));
        $this->assertStringContainsString('<nDPS>11</nDPS>', (string) $response->getData('xml'));
    }

    public function test_substituir_preserva_xml_da_tentativa_quando_transporte_expira(): void
    {
        $provider = new NacionalProvider($this->buildConfig(static function (): never {
            throw new \RuntimeException('Timeout was reached');
        }));
        $dados = $this->dadosValidos();
        $dados['motivo_substituicao'] = 'rejeicao_pelo_tomador_intermediario';
        $dados['justificativa'] = 'Serviço rejeitado pelo tomador responsável.';

        $facade = new NFSeFacade('nfse_nacional', new NFSeAdapter('nfse_nacional', $provider));
        $response = $facade->substituir(str_repeat('7', 50), $dados);

        $this->assertTrue($response->isError());
        $this->assertSame('Timeout was reached', $response->getError());
        $artifacts = $provider->getLastOperationArtifacts();
        $this->assertSame('substituir', $artifacts['operation']);
        $this->assertStringContainsString('<chSubstda>'.str_repeat('7', 50).'</chSubstda>', (string) $artifacts['request_xml']);
        $this->assertStringContainsString('<xMotivo>Serviço rejeitado pelo tomador responsável.</xMotivo>', (string) $artifacts['request_xml']);
        $metadata = $response->getMetadata();
        $this->assertSame($artifacts['request_xml'], $metadata['artifacts']['request_xml'] ?? null);
    }

    public function test_substituir_aplica_im_exata_confirmada_pelo_cnc_antes_do_envio(): void
    {
        $calls = [];
        $config = $this->buildConfig(static function (string $method, string $path, mixed $body = null) use (&$calls): string {
            $calls[] = compact('method', 'path', 'body');
            if ($method === 'GET' && str_contains($path, 'cnc.local')) {
                return json_encode([
                    'ListaCadastroMunicipal' => [[
                        'CodigoMunicipio' => 3550308,
                        'InfCad' => [
                            'Inscricao' => '11222333000181',
                            'TpInscricao' => 'CNPJ',
                            'InscricaoMunicipal' => '      12345',
                            'SituacaoEmissaoNFSe' => 'HABILITADO',
                        ],
                    ]],
                    'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
                ], JSON_THROW_ON_ERROR);
            }
            if ($method === 'GET') {
                return '{"data":{}}';
            }

            return '<Resposta><Sucesso>true</Sucesso><ChaveAcesso>123</ChaveAcesso></Resposta>';
        });
        $config['remote_preflight_enabled'] = true;
        $config['services'] = [
            'parametrizacao' => ['homologacao' => 'https://param.local'],
            'cnc_consulta' => ['homologacao' => 'https://cnc.local'],
        ];
        $provider = new NacionalProvider($config);
        $dados = $this->dadosValidos();
        $dados['motivo_substituicao'] = 'rejeicao_pelo_tomador_intermediario';
        $dados['justificativa'] = 'Serviço rejeitado pelo tomador responsável.';

        $provider->substituir(str_repeat('7', 50), $dados);

        $emission = null;
        foreach ($calls as $call) {
            if ($call['method'] === 'POST' && $call['path'] === '/nfse') {
                $emission = $call;
                break;
            }
        }
        $this->assertIsArray($emission);
        $payload = json_decode((string) $emission['body'], true, flags: JSON_THROW_ON_ERROR);
        $xml = gzdecode((string) base64_decode((string) ($payload['dpsXmlGZipB64'] ?? '')));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<IM>      12345</IM>', $xml);
        $this->assertStringContainsString('<subst><chSubstda>'.str_repeat('7', 50).'</chSubstda>', $xml);
        $artifacts = $provider->getLastOperationArtifacts();
        $this->assertSame('      12345', $artifacts['emission_context']['decisions'][0]['value'] ?? null);
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

    public function test_dps_100_emite_paliq_antes_de_tpretissqn(): void
    {
        $config = $this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        });
        $config['versao'] = '1.00';
        $config['dps_versao'] = '1.00';
        $config['dps_xsd_path'] = 'src/Providers/NFSe/Xsd/1.00/DPS_v1.00.xsd';
        $provider = new NacionalProvider($config);

        $dados = $this->dadosValidos();
        $dados['rps_serie'] = '1';
        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertLessThan(
            strpos($xml, '<tpRetISSQN>'),
            strpos($xml, '<pAliq>'),
            'pAliq must be emitted before tpRetISSQN in DPS 1.00.'
        );

        $schemaValidation = $provider->validarDpsXml($xml);
        $this->assertTrue(
            $schemaValidation['ok'],
            implode(PHP_EOL, array_column($schemaValidation['errors'], 'message'))
        );
    }

    public function test_dps_100_assinado_usa_algoritmo_compativel_com_schema(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');

            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        });
        $config['versao'] = '1.00';
        $config['dps_versao'] = '1.00';
        $config['dps_xsd_path'] = 'src/Providers/NFSe/Xsd/1.00/DPS_v1.00.xsd';
        $config['signature_mode'] = 'required';
        $config['certificate'] = NFSeMunicipalPreviewSupport::makeCertificate('DPS 100 Signed');
        $provider = new NacionalProvider($config);

        $dados = $this->dadosValidos();
        $dados['rps_serie'] = '1';
        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('http://www.w3.org/2000/09/xmldsig#rsa-sha1', $xml);
        $this->assertStringContainsString('http://www.w3.org/2000/09/xmldsig#sha1', $xml);
    }

    public function test_emitir_omite_irrf_quando_valor_zerado(): void
    {
        $calls = [];
        $provider = new NacionalProvider($this->buildConfig(function ($method, $path, $body, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path', 'body');

            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['valor_irrf'] = 0;
        $dados['servico']['valor_ir'] = 0;

        $provider->emitir($dados);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $xml = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringNotContainsString('<vRetIRRF>', $xml);
        $this->assertStringNotContainsString('<tribFed><vRetIRRF>0.00</vRetIRRF></tribFed>', $xml);
    }

    public function test_emitir_rejeita_irrf_maior_ou_igual_ao_valor_do_servico(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['valor_irrf'] = 1000.00;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vRetIRRF deve ser maior que zero e menor que vServ.');

        $provider->emitir($dados);
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
        $this->assertStringContainsString('<BM><nBM>15014020000001</nBM><pRedBCBM>50.00</pRedBCBM></BM>', $xml);
        $this->assertStringNotContainsString('<tpBM>', $xml);
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

    public function test_dps_nacional_inclui_tributacao_federal_completa_na_ordem_do_schema(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['tributacao']['federal'] = [
            'piscofins' => [
                'CST' => '01',
                'vBCPisCofins' => 1000,
                'pAliqPis' => 1.65,
                'pAliqCofins' => 7.6,
                'vPis' => 16.50,
                'vCofins' => 76.00,
                'tpRetPisCofins' => '3',
            ],
            'vRetCP' => 11.00,
            'vRetIRRF' => 15.00,
            'vRetCSLL' => 9.00,
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString(
            '<tribFed><piscofins><CST>01</CST><vBCPisCofins>1000.00</vBCPisCofins><pAliqPis>1.65</pAliqPis><pAliqCofins>7.60</pAliqCofins><vPis>16.50</vPis><vCofins>76.00</vCofins><tpRetPisCofins>3</tpRetPisCofins></piscofins><vRetCP>11.00</vRetCP><vRetIRRF>15.00</vRetIRRF><vRetCSLL>9.00</vRetCSLL></tribFed>',
            $xml
        );
        $this->assertStringNotContainsString('<vRetPIS>', $xml);
        $this->assertStringNotContainsString('<vRetCOFINS>', $xml);
        $this->assertLessThan(strpos($xml, '<tribFed>'), strpos($xml, '<tribMun>'));
        $this->assertLessThan(strpos($xml, '<totTrib>'), strpos($xml, '<tribFed>'));
    }

    public function test_dps_nacional_omite_pis_cofins_de_apuracao_propria_para_simples_nacional(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '3';
        $dados['prestador']['regApTribSN'] = '1';
        $dados['tributacao']['federal'] = [
            'piscofins' => [
                'CST' => '01',
                'vBCPisCofins' => 1000,
                'pAliqPis' => 1.65,
                'pAliqCofins' => 7.6,
                'vPis' => 16.50,
                'vCofins' => 76.00,
                'tpRetPisCofins' => '2',
            ],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringNotContainsString('<tribFed>', $xml);
        $this->assertStringNotContainsString('<piscofins>', $xml);
        $this->assertStringContainsString('<totTrib><vTotTrib><vTotTribFed>135.00</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>47.00</vTotTribMun></vTotTrib></totTrib>', $xml);
    }

    public function test_dps_nacional_inclui_descontos_deducao_e_total_de_tributos_por_escolha(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '3';
        $dados['prestador']['regApTribSN'] = '1';
        $dados['valores'] = [
            'vReceb' => 950.00,
            'vDescIncond' => 25.00,
            'vDescCond' => 10.00,
            'deducao_reducao' => [
                'vDR' => 100.00,
            ],
        ];
        $dados['tributacao']['total'] = [
            'pTotTribSN' => 4.25,
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<vServPrest><vServ>1000.00</vServ></vServPrest>', $xml);
        $this->assertStringNotContainsString('<vReceb>', $xml);
        $this->assertStringContainsString('<vDescCondIncond><vDescIncond>25.00</vDescIncond><vDescCond>10.00</vDescCond></vDescCondIncond>', $xml);
        $this->assertStringContainsString('<vDedRed><vDR>100.00</vDR></vDedRed>', $xml);
        $this->assertStringContainsString('<totTrib><pTotTribSN>4.25</pTotTribSN></totTrib>', $xml);
        $this->assertStringNotContainsString('<vTotTribFed>0.00</vTotTribFed>', $xml);
    }

    public function test_dps_nacional_nao_envia_indicador_ou_percentual_simples_para_nao_optante(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '1';
        $dados['tributacao']['total'] = [
            'indTotTrib' => '0',
            'pTotTribSN' => 4.25,
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringNotContainsString('<indTotTrib>', $xml);
        $this->assertStringNotContainsString('<pTotTribSN>', $xml);
        $this->assertStringContainsString('<vTotTribFed>135.00</vTotTribFed>', $xml);
        $this->assertStringContainsString('<vTotTribMun>47.00</vTotTribMun>', $xml);
    }

    public function test_dps_nacional_nao_envia_indicador_de_total_para_me_epp(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '3';
        $dados['prestador']['regApTribSN'] = '1';
        $dados['tributacao']['total'] = ['indTotTrib' => '0'];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertStringNotContainsString('<indTotTrib>', $xml);
        $this->assertStringContainsString('<totTrib><vTotTrib>', $xml);
        $this->assertStringContainsString('<vTotTribFed>135.00</vTotTribFed>', $xml);
        $this->assertStringContainsString('<vTotTribMun>47.00</vTotTribMun>', $xml);
    }

    public function test_dps_nacional_nao_envia_percentual_simples_para_mei(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '2';
        unset($dados['prestador']['regApTribSN']);
        $dados['tributacao']['total'] = ['pTotTribSN' => 4.25];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertStringNotContainsString('<pTotTribSN>', $xml);
        $this->assertStringContainsString('<totTrib><vTotTrib>', $xml);
    }

    public function test_dps_nacional_inclui_valor_recebido_quando_intermediario_emitente(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['tpEmit'] = '3';
        $dados['valores'] = [
            'vReceb' => 950.00,
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<vServPrest><vReceb>950.00</vReceb><vServ>1000.00</vServ></vServPrest>', $xml);
    }

    public function test_dps_nacional_omite_descontos_quando_valores_sao_zero(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['valores'] = [
            'vDescIncond' => 0,
            'vDescCond' => 0,
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);
        $validation = $provider->validarDpsXml($xml);

        $this->assertIsString($xml);
        $this->assertStringNotContainsString('<vDescCondIncond>', $xml);
        $this->assertStringNotContainsString('<vDescIncond>0.00</vDescIncond>', $xml);
        $this->assertStringNotContainsString('<vDescCond>0.00</vDescCond>', $xml);
        $this->assertTrue(
            $validation['ok'],
            implode(PHP_EOL, array_column($validation['errors'], 'message'))
        );
    }

    public function test_dps_nacional_usa_total_aproximado_por_padrao_no_modelo_uninfe(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['prestador']['opSimpNac'] = '3';
        $dados['prestador']['regApTribSN'] = '1';
        $dados['valor_servicos'] = 10.00;

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<totTrib><vTotTrib><vTotTribFed>1.35</vTotTribFed><vTotTribEst>0.00</vTotTribEst><vTotTribMun>0.47</vTotTribMun></vTotTrib></totTrib>', $xml);
        $this->assertStringNotContainsString('<pTotTribSN>0.00</pTotTribSN>', $xml);
    }

    public function test_dps_nacional_omite_grupos_ibscbs_nao_permitidos_para_classe_000001(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['ibscbs'] = [
            'finNFSe' => '0',
            'indFinal' => '0',
            'cIndOp' => '000001',
            'indDest' => '0',
            'valores' => [
                'trib' => [
                    'gIBSCBS' => [
                        'CST' => '000',
                        'cClassTrib' => '000001',
                        'gTribRegular' => [
                            'CSTReg' => '000',
                            'cClassTribReg' => '000001',
                        ],
                        'gDif' => [
                            'pDifUF' => 1,
                            'pDifMun' => 2,
                            'pDifCBS' => 3,
                        ],
                    ],
                ],
            ],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString(
            '<IBSCBS><finNFSe>0</finNFSe><indFinal>0</indFinal><cIndOp>000001</cIndOp><indDest>0</indDest><valores><trib><gIBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib></gIBSCBS></trib></valores></IBSCBS>',
            $xml
        );
        $this->assertStringNotContainsString('<gTribRegular>', $xml);
        $this->assertStringNotContainsString('<gDif>', $xml);
        $this->assertLessThan(strpos($xml, '<IBSCBS>'), strpos($xml, '</valores>'));
    }

    public function test_dps_nacional_preserva_grupos_ibscbs_quando_cst_permite_no_modelo_uninfe(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['ibscbs'] = [
            'finNFSe' => '0',
            'indFinal' => '0',
            'cIndOp' => '000001',
            'indDest' => '0',
            'valores' => [
                'trib' => [
                    'gIBSCBS' => [
                        'CST' => '101',
                        'cClassTrib' => '000001',
                        'gTribRegular' => [
                            'CSTReg' => '000',
                            'cClassTribReg' => '000001',
                        ],
                        'gDif' => [
                            'pDifUF' => 1,
                            'pDifMun' => 2,
                            'pDifCBS' => 3,
                        ],
                    ],
                ],
            ],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);
        $validation = $provider->validarDpsXml($xml);

        $this->assertIsString($xml);
        $this->assertStringContainsString(
            '<gIBSCBS><CST>101</CST><cClassTrib>000001</cClassTrib><gTribRegular><CSTReg>000</CSTReg><cClassTribReg>000001</cClassTribReg></gTribRegular><gDif><pDifUF>1.00</pDifUF><pDifMun>2.00</pDifMun><pDifCBS>3.00</pDifCBS></gDif></gIBSCBS>',
            $xml
        );
        $this->assertTrue(
            $validation['ok'],
            implode(PHP_EOL, array_column($validation['errors'], 'message'))
        );
    }

    public function test_dps_nacional_serializa_ibscbs_declarativo_com_destino_imovel_e_reembolso(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['ibscbs'] = [
            'finNFSe' => '0',
            'indFinal' => '1',
            'cIndOp' => '1',
            'tpOper' => '5',
            'refNFSe' => ['NFSE-REFERENCIADA-1'],
            'tpEnteGov' => '4',
            'indDest' => '1',
            'dest' => [
                'NIF' => 'EXT123456',
                'xNome' => 'Destinatario Exterior',
                'endereco' => [
                    'cPais' => 'US',
                    'cEndPost' => '10001',
                    'xCidade' => 'New York',
                    'xEstProvReg' => 'NY',
                    'xLgr' => '5th Avenue',
                    'nro' => '1',
                    'xBairro' => 'Manhattan',
                ],
                'fone' => '+1 212 555 0100',
                'email' => 'dest@example.com',
            ],
            'imovel' => [
                'inscImobFisc' => 'IMOVEL123',
                'endereco' => [
                    'CEP' => '01310930',
                    'xLgr' => 'Rua Imovel',
                    'nro' => '200',
                    'xBairro' => 'Centro',
                ],
            ],
            'valores' => [
                'gReeRepRes' => [
                    'documentos' => [
                        [
                            'dFeNacional' => [
                                'tipoChaveDFe' => '2',
                                'xTipoChaveDFe' => 'NF-e',
                                'chaveDFe' => 'NFE123456789',
                            ],
                            'fornecedor' => [
                                'cnpj' => '11.222.333/0001-81',
                                'xNome' => 'Fornecedor Teste',
                            ],
                            'dtEmiDoc' => '2026-01-10',
                            'dtCompDoc' => '2026-01-11',
                            'tpReeRepRes' => '99',
                            'xTpReeRepRes' => 'Outros',
                            'vlrReeRepRes' => 150.25,
                        ],
                    ],
                ],
                'trib' => [
                    'gIBSCBS' => [
                        'cst' => '0',
                        'classificacao' => '1',
                        'codigo_credito_presumido' => '3',
                        'gTribRegular' => [
                            'CST' => '0',
                            'classificacao' => '1',
                        ],
                        'gDif' => [
                            'pDifUF' => 1.5,
                            'pDifMun' => 2.5,
                            'pDifCBS' => 3.5,
                        ],
                    ],
                ],
            ],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<finNFSe>0</finNFSe><indFinal>1</indFinal><cIndOp>000001</cIndOp><tpOper>5</tpOper>', $xml);
        $this->assertStringContainsString('<gRefNFSe><refNFSe>NFSE-REFERENCIADA-1</refNFSe></gRefNFSe><tpEnteGov>4</tpEnteGov><indDest>1</indDest>', $xml);
        $this->assertStringContainsString(
            '<dest><NIF>EXT123456</NIF><xNome>Destinatario Exterior</xNome><end><endExt><cPais>US</cPais><cEndPost>10001</cEndPost><xCidade>New York</xCidade><xEstProvReg>NY</xEstProvReg></endExt><xLgr>5th Avenue</xLgr><nro>1</nro><xBairro>Manhattan</xBairro></end><fone>12125550100</fone><email>dest@example.com</email></dest>',
            $xml
        );
        $this->assertStringContainsString(
            '<imovel><inscImobFisc>IMOVEL123</inscImobFisc><end><CEP>01310930</CEP><xLgr>Rua Imovel</xLgr><nro>200</nro><xBairro>Centro</xBairro></end></imovel>',
            $xml
        );
        $this->assertStringContainsString(
            '<gReeRepRes><documentos><dFeNacional><tipoChaveDFe>2</tipoChaveDFe><xTipoChaveDFe>NF-e</xTipoChaveDFe><chaveDFe>NFE123456789</chaveDFe></dFeNacional><fornec><CNPJ>11222333000181</CNPJ><xNome>Fornecedor Teste</xNome></fornec><dtEmiDoc>2026-01-10</dtEmiDoc><dtCompDoc>2026-01-11</dtCompDoc><tpReeRepRes>99</tpReeRepRes><xTpReeRepRes>Outros</xTpReeRepRes><vlrReeRepRes>150.25</vlrReeRepRes></documentos></gReeRepRes>',
            $xml
        );
        $this->assertStringContainsString(
            '<trib><gIBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib><cCredPres>03</cCredPres></gIBSCBS></trib>',
            $xml
        );
        $this->assertStringNotContainsString('<gTribRegular>', $xml);
        $this->assertStringNotContainsString('<gDif>', $xml);
        $this->assertLessThan(strpos($xml, '<trib><gIBSCBS>'), strpos($xml, '<gReeRepRes>'));
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
                $calls[] = compact('method', 'path', 'body', 'headers');

                return json_encode([
                    'tipoAmbiente' => 2,
                    'versaoAplicativo' => 'test',
                    'dataHoraProcessamento' => '2026-06-28T12:00:00-03:00',
                ], JSON_UNESCAPED_UNICODE);
            }
        ));

        $chave = str_repeat('1', 50);
        $result = $provider->cancelar($chave, 'Cancelamento por erro operacional');
        $this->assertTrue($result);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('/nfse/'.$chave.'/eventos', $calls[0]['path']);
        $this->assertContains('Content-Type: application/json', $calls[0]['headers']);

        $payload = json_decode((string) $calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('pedidoRegistroEventoXmlGZipB64', $payload);
        $xml = gzdecode((string) base64_decode((string) $payload['pedidoRegistroEventoXmlGZipB64']));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<pedRegEvento', $xml);
        $this->assertStringContainsString('<infPedReg Id="PRE'.$chave.'101101">', $xml);
        $this->assertStringContainsString('<e101101>', $xml);
        $this->assertStringContainsString('<xMotivo>Cancelamento por erro operacional</xMotivo>', $xml);
    }

    public function test_consultar_retorno_compnfse_com_xml_real(): void
    {
        $xmlReferencia = file_get_contents(__DIR__.'/../../Fixtures/belem/retorno_lista_nfse_sanitizado.xml');
        $this->assertNotFalse($xmlReferencia);

        $provider = new NacionalProvider($this->buildConfig(
            fn ($method = null, $path = null, $body = null, $headers = []) => (string) $xmlReferencia
        ));

        $result = $provider->cancelar(str_repeat('2', 50), 'Cancelamento por erro operacional');
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

    public function test_validacao_envia_im_joinville_quando_cnc_exige_identificacao_municipal(): void
    {
        $config = $this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        });
        $config['codigo_municipio'] = '4209102';
        $config['ambiente'] = 'producao';
        $provider = new NacionalProvider($config);

        $payload = [
            'tpAmb' => '2',
            'dhEmi' => '2026-07-09T10:00:00-03:00',
            'verAplic' => 'fiscal-platform-api',
            'serie' => '1',
            'nDPS' => '9028',
            'dCompet' => '2026-07-09',
            'tpEmit' => '1',
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83188342000104',
                'inscricaoMunicipal' => '33061',
                'opSimpNac' => '3',
                'regApTribSN' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '00980556236',
                'razaoSocial' => 'Cliente Teste',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de suporte tecnico em informatica',
                'tribISSQN' => '1',
                'tpRetISSQN' => '1',
                'aliquota' => 2,
            ],
            'valor_servicos' => 200,
        ];

        $validation = $provider->validarLayoutDps($payload, false);
        $xml = $provider->gerarXmlDpsPreview($payload);

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertStringContainsString('<CNPJ>83188342000104</CNPJ>', (string) $xml);
        $this->assertStringContainsString('<IM>33061</IM>', (string) $xml);
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

    public function test_dps_nacional_rejeita_nbs_fora_do_padrao_do_schema(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['servico']['cNBS'] = '0107010000';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('servico.cNBS deve conter exatamente 9 dígitos.');

        $provider->emitir($dados);
    }

    public function test_dps_nacional_rejeita_serie_com_mais_de_cinco_digitos(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $dados = $this->dadosValidos();
        $dados['serie'] = '123456';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('serie deve conter de 1 a 5 dígitos numéricos.');

        $provider->emitir($dados);
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
            'aliquotas_municipio' => 'parametrizacao:/{codigo_municipio}/{codigoServico}/{competencia}/aliquota',
            'convenio_municipio' => 'parametrizacao:/{codigo_municipio}/convenio',
        ];

        $provider = new NacionalProvider($config);
        $provider->listarMunicipiosNacionais(true);
        $provider->consultarAliquotasMunicipio('3550308', '010701', '2026-04-08', true);
        $provider->consultarConvenioMunicipio('3550308', true);

        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/01.07.01.000/2026-04-08T00%3A00%3A00Z/aliquota', $calls[0]['path']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio', $calls[1]['path']);
    }

    public function test_consulta_cnc_resolve_rota_por_servico(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body = null, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path');

            return json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        });
        $config['services'] = [
            'cnc_consulta' => [
                'homologacao' => 'https://adn.producaorestrita.nfse.gov.br/cnc/consulta',
                'producao' => 'https://adn.nfse.gov.br/cnc/consulta',
            ],
        ];
        $config['cnc_endpoints'] = [
            'cadastro' => 'cnc_consulta:/cad',
        ];

        $provider = new NacionalProvider($config);
        $provider->consultarContribuinteCnc('11.222.333/0001-81');
        $provider->verificarHabilitacaoCnc('11.222.333/0001-81', '3550308');

        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/cnc/consulta/cad?codMunicipio=3550308&inscricaoFederal=11222333000181', $calls[0]['path']);
        $this->assertCount(1, $calls, 'A segunda consulta idêntica deve reutilizar o cache CNC de 6 horas.');
    }

    public function test_consulta_cnc_contribuinte_e_habilitacao(): void
    {
        $calls = [];
        $config = $this->buildConfig(function ($method, $path, $body = null, $headers = []) use (&$calls) {
            $calls[] = compact('method', 'path');
            if (str_contains($path, '/cnc/consulta/cad?codMunicipio=3550308')) {
                return json_encode([
                    'dados' => [[
                        'codMunicipio' => '3550308',
                        'inscricaoFederal' => '11222333000181',
                        'situacao' => 'HABILITADO',
                        'habilitado' => true,
                    ]],
                ]);
            }

            if (str_contains($path, '/cnc/consulta/cad?codMunicipio=4106902')) {
                return json_encode([
                    'dados' => [[
                        'codMunicipio' => '4106902',
                        'inscricaoFederal' => '11222333000181',
                        'situacao' => 'HABILITADO',
                        'habilitado' => true,
                    ]],
                ]);
            }

            return json_encode(['habilitado' => false]);
        });

        $provider = new NacionalProvider($config);
        $contribuinte = $provider->consultarContribuinteCnc('11.222.333/0001-81');
        $habilitacao = $provider->verificarHabilitacaoCnc('11.222.333/0001-81', '4106902');

        $this->assertSame('encontrado', $contribuinte['status']);
        $this->assertTrue($contribuinte['data']['correspondencia']['habilitado']);
        $this->assertTrue($habilitacao);
        $this->assertStringContainsString('/cnc/consulta/cad?codMunicipio=3550308&inscricaoFederal=11222333000181', $calls[0]['path']);
        $this->assertStringContainsString('/cnc/consulta/cad?codMunicipio=4106902&inscricaoFederal=11222333000181', $calls[1]['path']);
    }

    public function test_schema_resolver_resolve_layout_nacional_para_xsd_101(): void
    {
        $schemaPath = (new NFSeSchemaResolver)->resolve('NACIONAL', 'emitir');

        $this->assertStringEndsWith('src/Providers/NFSe/Xsd/1.01/DPS_v1.01.xsd', $schemaPath);
        $this->assertFileExists($schemaPath);
    }

    public function test_validacao_xsd_dps_retorna_diagnostico_rastreavel(): void
    {
        $provider = new NacionalProvider($this->buildConfig(function () {
            return '<Resposta><Sucesso>true</Sucesso></Resposta>';
        }));

        $xml = $provider->gerarXmlDpsPreview($this->dadosValidos());
        $this->assertIsString($xml);
        $invalidXml = preg_replace('/<cTribNac>[^<]+<\/cTribNac>/', '<cTribNac>ABC</cTribNac>', $xml, 1);
        $this->assertIsString($invalidXml);
        $this->assertNotSame($xml, $invalidXml);

        $validation = $provider->validarDpsXml($invalidXml);

        $this->assertFalse($validation['ok']);
        $this->assertStringEndsWith('src/Providers/NFSe/Xsd/1.01/DPS_v1.01.xsd', $validation['schema']);
        $this->assertSame('cTribNac', $validation['errors'][0]['campo_provavel']);
        $this->assertSame('servico.cTribNac', $validation['errors'][0]['payload_path']);
        $this->assertIsInt($validation['errors'][0]['line']);
        $this->assertArrayHasKey('column', $validation['errors'][0]);
        $this->assertSame($validation['schema'], $validation['errors'][0]['schema']);
    }

    public function test_preview_104_serializa_grupos_rtc_regulares_na_ordem_do_layout(): void
    {
        $config = $this->buildConfig(fn () => '<ok/>');
        $config['versao'] = '1.04';
        $config['dps_versao'] = '1.04';
        $config['dps_xsd_path'] = null;
        $provider = new NacionalProvider($config);

        $dados = $this->dadosValidos();
        $dados['prestador']['cnpj'] = 'AB222333000181';
        $dados['prestador']['opSimpNac'] = '4';
        $dados['prestador']['regApIBSCBSSN'] = '1';
        $dados['servico']['cAtvSN'] = '11';
        $dados['valores']['vAjusteBC'] = [
            'pAjusteBCISSQN' => 10,
            'vAjusteBCISSQN' => 100,
            'documentos' => ['docAjusteBC' => [[
                'tpAjusteBC' => '01',
                'vTotDoc' => 100,
                'vAjuteAplic' => 100,
                'dFeNacional' => [
                    'tipoChaveDFe' => '1',
                    'chaveDFe' => str_repeat('1', 44),
                ],
            ]]],
        ];
        $dados['ibscbs'] = [
            'finNFSe' => '0',
            'indFinal' => '1',
            'cIndOp' => '050101',
            'indDest' => '0',
            'imovel' => [
                'cMun' => '3550308',
                'gLocacao' => ['pCopropriedade' => 100, 'vTotOper' => 1000],
                'gUnidImob' => [[
                    'inscImobFisc' => '123',
                    'cCIB' => 'CIB123',
                    'gAjusteBCLocImoveis' => [[
                        'tpAjusteBCLocImoveis' => '01',
                        'vAjusteBCLocImoveis' => 50,
                    ]],
                ]],
            ],
            'gPgtoVinc' => ['pgto' => [[
                'nPag' => '1',
                'idTransacao' => 'PIX-123',
                'tpMeioPgto' => '01',
                'CNPJReceb' => 'CD222333000181',
                'CNPJBasePSP' => 'EF222333',
            ]]],
            'valores' => ['trib' => [
                'gIBSCBS' => ['CST' => '000', 'cClassTrib' => '000001'],
            ]],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertStringContainsString('versao="1.04"', $xml);
        $this->assertStringContainsString('<cLocEmi>3550308</cLocEmi><finNFSe>0</finNFSe><prest>', $xml);
        $this->assertStringContainsString('<CNPJ>AB222333000181</CNPJ>', $xml);
        $this->assertStringContainsString('<opSimpNac>4</opSimpNac><regApIBSCBSSN>1</regApIBSCBSSN>', $xml);
        $this->assertStringContainsString('<cAtvSN>11</cAtvSN>', $xml);
        $this->assertStringContainsString('<vAjusteBC><pAjusteBCISSQN>10.00</pAjusteBCISSQN><vAjusteBCISSQN>100.00</vAjusteBCISSQN><documentos><docAjusteBC>', $xml);
        $this->assertStringContainsString('<IBSCBS><indFinal>1</indFinal><cIndOp>050101</cIndOp>', $xml);
        $this->assertStringContainsString('<imovel><cMun>3550308</cMun><gLocacao><pCopropriedade>100.00</pCopropriedade><vTotOper>1000.00</vTotOper></gLocacao><gUnidImob><inscImobFisc>123</inscImobFisc><cCIB>CIB123</cCIB><gAjusteBCLocImoveis><tpAjusteBCLocImoveis>01</tpAjusteBCLocImoveis><vAjusteBCLocImoveis>50.00</vAjusteBCLocImoveis></gAjusteBCLocImoveis></gUnidImob></imovel>', $xml);
        $this->assertStringContainsString('<gPgtoVinc><pgto><nPag>001</nPag><idTransacao>PIX-123</idTransacao><tpMeioPgto>01</tpMeioPgto><CNPJReceb>CD222333000181</CNPJReceb><CNPJBasePSP>EF222333</CNPJBasePSP></pgto></gPgtoVinc>', $xml);
        $this->assertStringContainsString('<valores><trib><gIBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib></gIBSCBS></trib></valores>', $xml);
        $this->assertStringNotContainsString('<IBSCBS><finNFSe>', $xml);
    }

    public function test_preview_104_serializa_nota_de_debito_com_ajuste(): void
    {
        $config = $this->buildConfig(fn () => '<ok/>');
        $config['versao'] = '1.04';
        $config['dps_versao'] = '1.04';
        $config['dps_xsd_path'] = null;
        $provider = new NacionalProvider($config);

        $dados = $this->dadosValidos();
        $dados['ibscbs'] = [
            'finNFSe' => '2',
            'tpNFSeDebito' => '06',
            'valores' => ['trib' => [
                'gIBSCBSAjuste' => ['vIBS' => 12.34, 'vCBS' => 5.67],
            ]],
        ];

        $xml = $provider->gerarXmlDpsPreview($dados);

        $this->assertStringContainsString('<finNFSe>2</finNFSe><tpNFSeDebito>06</tpNFSeDebito><prest>', $xml);
        $this->assertStringContainsString('<IBSCBS><valores><trib><gIBSCBSAjuste><vIBS>12.34</vIBS><vCBS>5.67</vCBS></gIBSCBSAjuste></trib></valores></IBSCBS>', $xml);
        $this->assertStringNotContainsString('<gIBSCBS><CST>', $xml);
    }

    private function buildConfig(callable $httpClient): array
    {
        return [
            'codigo_municipio' => '3550308',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'dps_xsd_path' => 'src/Providers/NFSe/Xsd/1.01/DPS_v1.01.xsd',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'prestador' => [
                'cnpj' => '11.222.333/0001-81',
            ],
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
            'cache_dir' => sys_get_temp_dir().'/fiscal-core-provider-'.uniqid(),
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
