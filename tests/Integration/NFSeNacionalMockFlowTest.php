<?php

namespace Tests\Integration;

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use PHPUnit\Framework\TestCase;

class NFSeNacionalMockFlowTest extends TestCase
{
    public function test_fluxo_emissao_consulta_cancelamento_com_mock_http(): void
    {
        $chaveAcesso = '12345678901234567890123456789012345678901234567890';
        $provider = new NacionalProvider([
            'codigo_municipio' => '3550308',
            'versao' => '1.00',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'cache_dir' => sys_get_temp_dir() . '/fiscal-core-integration-' . uniqid(),
            'signature_mode' => 'none',
            'prestador' => [
                'cnpj' => '11.222.333/0001-81',
            ],
            'http_client' => function (string $method, string $path, ?string $body = null, array $headers = []) use ($chaveAcesso): string {
                if ($method === 'POST' && $path === '/nfse') {
                    return '<Resposta><Sucesso>true</Sucesso><NumeroNfse>1001</NumeroNfse></Resposta>';
                }
                if ($method === 'GET' && $path === '/nfse/' . $chaveAcesso) {
                    return '<Consulta><Sucesso>true</Sucesso><NumeroNfse>1001</NumeroNfse></Consulta>';
                }
                if ($method === 'POST' && $path === '/nfse/' . $chaveAcesso . '/eventos') {
                    return '<Cancelamento><Sucesso>true</Sucesso></Cancelamento>';
                }

                return '<Resposta><Sucesso>true</Sucesso></Resposta>';
            },
            'endpoints' => [
                'emitir' => '/nfse',
                'consultar' => '/nfse/{id}',
                'cancelar' => '/nfse/{id}/eventos',
                'substituir' => '/nfse',
            ],
            'operation_methods' => [
                'emitir' => 'POST',
                'consultar' => 'GET',
                'cancelar' => 'POST',
                'substituir' => 'POST',
            ],
        ]);

        $adapter = new NFSeAdapter('nfse_nacional', $provider);
        $facade = new NFSeFacade('nfse_nacional', $adapter);

        $emissao = $facade->emitir($this->dadosValidos());
        $this->assertTrue($emissao->isSuccess(), $emissao->getError() ?? 'Emissão NFSe nacional mock falhou.');

        $consulta = $facade->consultar($chaveAcesso);
        $this->assertTrue($consulta->isSuccess(), $consulta->getError() ?? 'Consulta NFSe nacional mock falhou.');

        $cancelamento = $facade->cancelar($chaveAcesso, 'Cancelamento de teste');
        $this->assertTrue($cancelamento->isSuccess(), $cancelamento->getError() ?? 'Cancelamento NFSe nacional mock falhou.');
        $this->assertTrue($cancelamento->getData('canceled'));
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
        ];
    }
}
