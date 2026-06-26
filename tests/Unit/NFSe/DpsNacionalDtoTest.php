<?php

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\DpsDTO;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;

class DpsNacionalDtoTest extends TestCase
{
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
        $this->assertStringContainsString('<IM>000000000012345</IM>', $xml);
        $this->assertStringContainsString('<CPF>12345678901</CPF>', $xml);
        $this->assertStringContainsString('<xNome>Tomador Teste</xNome>', $xml);
        $this->assertStringContainsString('<cTribNac>010701</cTribNac>', $xml);
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $xml);
        $this->assertStringContainsString('<pAliq>2.00</pAliq>', $xml);
    }
}
