<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\DpsDTO;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NacionalDpsTemporalNormalizer;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;

class DpsNacionalDtoTest extends TestCase
{
    public function test_temporal_normalizer_aceita_contexto_sem_now(): void
    {
        $payload = NacionalDpsTemporalNormalizer::normalizePayload([
            'dhEmi' => '2026-06-20T10:00:00-03:00',
        ], [
            'timezone' => 'America/Sao_Paulo',
        ]);

        $this->assertSame('2026-06-20T10:00:00-03:00', $payload['dhEmi']);
        $this->assertSame('2026-06-20', $payload['dCompet']);
    }

    public function test_dps_dto_normaliza_payload_legado_para_estrutura_canonica(): void
    {
        $dto = DpsDTO::fromArray([
            'serie_rps' => '7',
            'numero_rps' => '123',
            'prestador' => [
                'documento' => '11.222.333/0001-81',
                'inscricao_municipal' => ' 12345 ',
            ],
            'tomador' => [
                'cpf' => '123.456.789-01',
                'nome' => 'Tomador Teste',
            ],
            'servico' => [
                'codigo' => '0107',
                'discriminacao' => 'Servico de desenvolvimento',
                'aliquota' => 0.02,
                'iss_retido' => true,
                'valor_irrf' => 15,
                'valor_csll' => 9,
            ],
            'valor_servicos' => 1000,
            'cst_pis_cofins' => '1',
            'valor_pis' => 16.5,
            'valor_cofins' => 76,
            'ibscbs' => [
                'finalidade' => '0',
                'codigo_indicador_operacao' => '1',
                'indicador_destinatario' => '0',
                'gIBSCBS' => [
                    'cst' => '0',
                    'classificacao' => '1',
                    'codigo_credito_presumido' => '3',
                ],
            ],
        ], [
            'codigo_municipio' => '3550308',
            'ambiente' => 'homologacao',
            'ver_aplic' => 'invoiceflow-1.0',
        ]);

        $this->assertSame([], $dto->validate());
        $payload = $dto->toArray();

        $this->assertSame('00007', $payload['serie']);
        $this->assertSame('000000000000123', $payload['nDPS']);
        $this->assertSame('2', $payload['tpAmb']);
        $this->assertSame('3550308', $payload['cLocEmi']);
        $this->assertSame('11222333000181', $payload['prestador']['cnpj']);
        $this->assertSame('12345', trim((string) $payload['prestador']['inscricaoMunicipal']));
        $this->assertSame('12345678901', $payload['tomador']['documento']);
        $this->assertSame('Tomador Teste', $payload['tomador']['razaoSocial']);
        $this->assertSame('010701', $payload['servico']['cTribNac']);
        $this->assertSame('2', $payload['servico']['tpRetISSQN']);
        $this->assertSame(2.0, $payload['tributacao']['municipal']['pAliq']);
        $this->assertSame('2', $payload['tributacao']['municipal']['tpRetISSQN']);
        $this->assertSame('01', $payload['tributacao']['federal']['piscofins']['CST']);
        $this->assertSame(15.0, $payload['tributacao']['federal']['vRetIRRF']);
        $this->assertSame(9.0, $payload['tributacao']['federal']['vRetCSLL']);
        $this->assertSame('000001', $payload['ibscbs']['cIndOp']);
        $this->assertSame('000', $payload['ibscbs']['valores']['trib']['gIBSCBS']['CST']);
        $this->assertSame('000001', $payload['ibscbs']['valores']['trib']['gIBSCBS']['cClassTrib']);
        $this->assertSame('03', $payload['ibscbs']['valores']['trib']['gIBSCBS']['cCredPres']);
    }

    public function test_dps_dto_ignora_retencoes_federais_zeradas(): void
    {
        $dto = DpsDTO::fromArray([
            'prestador' => [
                'documento' => '11.222.333/0001-81',
            ],
            'tomador' => [
                'cpf' => '123.456.789-01',
                'nome' => 'Tomador Teste',
            ],
            'servico' => [
                'codigo' => '0107',
                'discriminacao' => 'Servico de desenvolvimento',
                'aliquota' => 0.02,
                'valor_irrf' => 0,
                'valor_ir' => 0,
            ],
            'tributacao' => [
                'federal' => [
                    'vRetCP' => 0,
                    'vRetIRRF' => 0,
                    'vRetCSLL' => 0,
                ],
            ],
            'valor_servicos' => 1000,
        ], [
            'codigo_municipio' => '3550308',
            'ambiente' => 'homologacao',
            'ver_aplic' => 'invoiceflow-1.0',
        ]);

        $payload = $dto->toArray();

        $this->assertArrayNotHasKey('federal', $payload['tributacao']);
    }

    public function test_provider_preview_aceita_payload_com_aliases_normalizados_pelo_dto(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '3550308',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
            'http_client' => fn () => '<Resposta><Sucesso>true</Sucesso></Resposta>',
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'prestador' => [
                'documento' => '11.222.333/0001-81',
                'inscricao_municipal' => '12345',
            ],
            'tomador' => [
                'cpf' => '123.456.789-01',
                'nome' => 'Tomador Teste',
            ],
            'servico' => [
                'codigo' => '0107',
                'discriminacao' => 'Servico de desenvolvimento',
                'aliquota' => 0.02,
                'iss_retido' => true,
            ],
            'valor_servicos' => 1000,
        ]);

        $this->assertIsString($xml);
        $this->assertStringContainsString('<CNPJ>11222333000181</CNPJ>', $xml);
        $this->assertStringContainsString('<IM>12345</IM>', $xml);
        $this->assertStringContainsString('<CPF>12345678901</CPF>', $xml);
        $this->assertStringContainsString('<xNome>Tomador Teste</xNome>', $xml);
        $this->assertStringContainsString('<cTribNac>010701</cTribNac>', $xml);
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $xml);
        $this->assertStringContainsString('<pAliq>2.00</pAliq>', $xml);
    }

    public function test_provider_preserva_representacao_exata_da_im_oficial_do_cnc(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '1302603',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '1302603',
            'prestador' => [
                'cnpj' => '01824852000832',
                'inscricaoMunicipal' => '      665940001',
                'opSimpNac' => '3',
                'regApTribSN' => '1',
                'regEspTrib' => '0',
            ],
            'tomador' => [
                'cpf' => '78648998204',
                'razaoSocial' => 'TIAGO QUEIROZ DE OLIVEIRA',
            ],
            'servico' => [
                'cLocPrestacao' => '1302603',
                'cTribNac' => '140101',
                'descricao' => 'Revisão geral',
            ],
            'valor_servicos' => 180,
        ]);

        $this->assertStringContainsString('<IM>      665940001</IM>', $xml);
    }

    public function test_provider_omite_im_do_prestador_para_municipio_configurado(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
            'dps' => [
                'omit_prestador_im_municipios' => ['4209102'],
            ],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'inscricaoMunicipal' => '1618414',
                'opSimpNac' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '123.456.789-01',
                'razaoSocial' => 'Tomador Teste',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 100,
        ]);

        $this->assertStringContainsString('<CNPJ>83188342000104</CNPJ>', $xml);
        $this->assertStringNotContainsString('<IM>', $xml);
        $this->assertStringNotContainsString('1618414', $xml);
    }

    public function test_provider_envia_im_do_prestador_para_joinville_homologacao_sem_configuracao_de_omissao(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'inscricaoMunicipal' => '33061',
                'opSimpNac' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ]);

        $this->assertStringContainsString('<CNPJ>83188342000104</CNPJ>', $xml);
        $this->assertStringContainsString('<IM>33061</IM>', $xml);
    }

    public function test_provider_preserva_im_do_prestador_em_ambos_os_ambientes(): void
    {
        $baseConfig = [
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ];
        $payload = [
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'inscricaoMunicipal' => '33061',
                'opSimpNac' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ];

        $homologacaoXml = (new NacionalProvider($baseConfig + [
            'ambiente' => 'homologacao',
        ]))->gerarXmlDpsPreview($payload);
        $producaoXml = (new NacionalProvider($baseConfig + [
            'ambiente' => 'producao',
        ]))->gerarXmlDpsPreview($payload);

        $this->assertStringContainsString('<IM>33061</IM>', $homologacaoXml);
        $this->assertStringContainsString('<IM>33061</IM>', $producaoXml);
    }

    public function test_dps_dto_informa_regime_apuracao_sn_para_simples_me_epp(): void
    {
        $dto = DpsDTO::fromArray([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'opSimpNac' => '3',
                'regEspTrib' => '0',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ], [
            'codigo_municipio' => '4209102',
            'ambiente' => 'homologacao',
            'ver_aplic' => 'invoiceflow-1.0',
        ]);

        $payload = $dto->toArray();

        $this->assertSame('3', $payload['prestador']['opSimpNac']);
        $this->assertSame('1', $payload['prestador']['regApTribSN']);
    }

    public function test_dps_dto_resolve_simples_nacional_a_partir_do_crt(): void
    {
        $dto = DpsDTO::fromArray([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'crt' => '1',
                'regEspTrib' => '0',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ], [
            'codigo_municipio' => '4209102',
            'ambiente' => 'homologacao',
            'ver_aplic' => 'invoiceflow-1.0',
        ]);

        $payload = $dto->toArray();

        $this->assertSame('3', $payload['prestador']['opSimpNac']);
        $this->assertSame('1', $payload['prestador']['regApTribSN']);
    }

    public function test_provider_emite_regime_apuracao_sn_para_simples_me_epp(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'opSimpNac' => '3',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ]);

        $this->assertStringContainsString('<opSimpNac>3</opSimpNac>', $xml);
        $this->assertStringContainsString('<regApTribSN>1</regApTribSN>', $xml);
        $this->assertStringContainsString('<regEspTrib>0</regEspTrib>', $xml);
    }

    public function test_provider_nao_emite_aliquota_para_simples_me_epp_sem_retencao_iss(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'opSimpNac' => '3',
                'regApTribSN' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
                'tpRetISSQN' => '1',
                'enviarPAliq' => true,
            ],
            'valor_servicos' => 254,
        ]);

        $this->assertStringContainsString('<opSimpNac>3</opSimpNac>', $xml);
        $this->assertStringContainsString('<regApTribSN>1</regApTribSN>', $xml);
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml);
    }

    public function test_provider_permite_forcar_envio_de_im_no_payload(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
            'dps' => [
                'omit_prestador_im_municipios' => ['4209102'],
            ],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'inscricaoMunicipal' => '1618414',
                'enviarIM' => true,
                'opSimpNac' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '123.456.789-01',
                'razaoSocial' => 'Tomador Teste',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 100,
        ]);

        $this->assertStringContainsString('<IM>1618414</IM>', $xml);
    }

    public function test_provider_omite_im_quando_preflight_cnc_desabilita_envio(): void
    {
        $provider = new NacionalProvider([
            'codigo_municipio' => '4209102',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => ['emitir' => '/nfse'],
        ]);

        $xml = $provider->gerarXmlDpsPreview([
            'cLocEmi' => '4209102',
            'prestador' => [
                'cnpj' => '83.188.342/0001-04',
                'inscricaoMunicipal' => '33061',
                'enviarIM' => false,
                'opSimpNac' => '1',
                'regEspTrib' => '0',
                'codigoMunicipio' => '4209102',
            ],
            'tomador' => [
                'documento' => '03.364.685/0001-43',
                'razaoSocial' => 'PISCINAS H2O LTDA',
            ],
            'servico' => [
                'cLocPrestacao' => '4209102',
                'cTribNac' => '010701',
                'descricao' => 'Servico de desenvolvimento',
                'aliquota' => 2,
            ],
            'valor_servicos' => 254,
        ]);

        $this->assertStringContainsString('<CNPJ>83188342000104</CNPJ>', $xml);
        $this->assertStringNotContainsString('<IM>', $xml);
    }
}
