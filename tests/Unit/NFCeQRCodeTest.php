<?php

namespace Tests\Unit;

use NFePHP\NFe\Tools;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Builder\NotaFiscalBuilder;
use sabbajohn\FiscalCore\Adapters\NF\NFCe\NFCeAdapter;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\ToolsFactory;

require_once dirname(__DIR__).'/Support/TestCertificateFile.php';

class NFCeQRCodeTest extends TestCase
{
    private ?array $certificateFile = null;

    protected function setUp(): void
    {
        CertificateManager::getInstance()->clear();
        ConfigManager::getInstance()->reload();
    }

    protected function tearDown(): void
    {
        CertificateManager::getInstance()->clear();
        ConfigManager::getInstance()->reload();
        \TestCertificateFile::cleanup($this->certificateFile['path'] ?? null);
        $this->certificateFile = null;
    }

    public function test_nfce_signature_generates_qrcode_with_csc_hash_for_version_200(): void
    {
        $this->loadTestCertificateAndConfig([
            'nfce_qrcode_version' => '200',
        ]);

        $dados = $this->nfceData();
        $xml = NotaFiscalBuilder::fromArray($dados)->build()->toXml();
        $signedXml = ToolsFactory::createNFCeTools()->signNFe($xml);

        $this->assertStringContainsString('<infNFeSupl>', $signedXml);
        $this->assertStringContainsString('<qrCode>', $signedXml);
        $this->assertStringContainsString('<urlChave>', $signedXml);
        $this->assertMatchesRegularExpression('/\\?p=\\d{44}\\|2\\|2\\|1\\|[A-F0-9]{40}/', $signedXml);
    }

    public function test_nfce_signature_generates_qrcode_automatically_without_supplemental_info(): void
    {
        $this->loadTestCertificateAndConfig();

        $dados = $this->nfceData();
        unset($dados['infoSuplementar']);

        $xml = NotaFiscalBuilder::fromArray($dados)->build()->toXml();
        $signedXml = ToolsFactory::createNFCeTools()->signNFe($xml);

        $this->assertStringContainsString('<infNFeSupl>', $signedXml);
        $this->assertStringContainsString('<qrCode>', $signedXml);
        $this->assertMatchesRegularExpression('/\\?p=\\d{44}\\|3\\|2/', $signedXml);
    }

    public function test_sc_production_signature_generates_qrcode_with_csc_hash(): void
    {
        $this->loadTestCertificateAndConfig([
            'ambiente' => ConfigManager::AMBIENTE_PRODUCAO,
            'uf' => 'SC',
            'municipio_ibge' => '4205407',
        ]);

        $dados = $this->nfceData([
            'identificacao' => [
                'cUF' => 42,
                'cMunFG' => 4205407,
                'tpAmb' => 1,
            ],
            'emitente' => [
                'codigoMunicipio' => '4205407',
                'municipio' => 'FLORIANOPOLIS',
                'uf' => 'SC',
            ],
        ]);
        unset($dados['infoSuplementar']);

        $xml = NotaFiscalBuilder::fromArray($dados)->build()->toXml();
        $signedXml = ToolsFactory::createNFCeTools()->signNFe($xml);

        $this->assertStringContainsString('<urlChave>https://sat.sef.sc.gov.br/nfce/consulta</urlChave>', $signedXml);
        $this->assertMatchesRegularExpression(
            '/https:\\/\\/sat\\.sef\\.sc\\.gov\\.br\\/nfce\\/consulta\\?p=\\d{44}\\|2\\|1\\|1\\|[A-F0-9]{40}/',
            $signedXml
        );
    }

    public function test_adapter_drops_incomplete_supplemental_info_before_signing(): void
    {
        $tools = $this->getMockBuilder(Tools::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['model', 'signNFe', 'sefazEnviaLote'])
            ->getMock();

        $tools->expects($this->once())
            ->method('model')
            ->with(65);

        $tools->expects($this->once())
            ->method('signNFe')
            ->with($this->callback(function (string $xml): bool {
                $this->assertStringNotContainsString('<infNFeSupl>', $xml);
                $this->assertStringNotContainsString('https://example.test/qrcode?p=351234|2|2', $xml);

                return true;
            }))
            ->willReturn('<signed />');

        $tools->expects($this->once())
            ->method('sefazEnviaLote')
            ->with(['<signed />'], '1', 1)
            ->willReturn('<retEnviNFe />');

        $adapter = new NFCeAdapter($tools);
        $response = $adapter->emitir($this->nfceData([
            'infoSuplementar' => [
                'qrCode' => 'https://example.test/qrcode?p=351234|2|2',
                'urlChave' => 'https://example.test/chave',
            ],
        ]));

        $this->assertSame('<retEnviNFe />', $response);
        $this->assertSame('<signed />', $adapter->getLastSignedXml());
    }

    private function loadTestCertificateAndConfig(array $overrides = []): void
    {
        $this->certificateFile = \TestCertificateFile::create(
            'NFCe QRCode Teste',
            'secret',
            '11222333000181'
        );

        CertificateManager::getInstance()->loadFromFile($this->certificateFile['path'], $this->certificateFile['password']);
        ConfigManager::getInstance()->load(array_merge([
            'ambiente' => ConfigManager::AMBIENTE_HOMOLOGACAO,
            'uf' => 'SP',
            'municipio_ibge' => '3550308',
            'csc' => 'TEST_CSC_TOKEN',
            'csc_id' => '000001',
            'nfce_qrcode_version' => null,
        ], $overrides));
    }

    private function nfceData(array $overrides = []): array
    {
        return array_replace_recursive([
            'identificacao' => [
                'cUF' => 35,
                'cNF' => 12345678,
                'natOp' => 'VENDA',
                'mod' => 65,
                'serie' => 1,
                'nNF' => 1,
                'dhEmi' => '2026-05-25T10:00:00-03:00',
                'cMunFG' => 3550308,
                'tpAmb' => 2,
                'tpImp' => 4,
            ],
            'emitente' => [
                'cnpj' => '11222333000181',
                'razaoSocial' => 'EMPRESA TESTE LTDA',
                'nomeFantasia' => 'TESTE',
                'inscricaoEstadual' => '110042490114',
                'logradouro' => 'RUA TESTE',
                'numero' => '100',
                'bairro' => 'CENTRO',
                'codigoMunicipio' => '3550308',
                'municipio' => 'SAO PAULO',
                'uf' => 'SP',
                'cep' => '01001000',
                'crt' => 1,
            ],
            'destinatario' => [
                'cpfCnpj' => '12345678909',
                'nome' => 'CONSUMIDOR FINAL',
                'indIEDest' => 9,
            ],
            'itens' => [
                [
                    'produto' => [
                        'codigo' => '001',
                        'descricao' => 'PRODUTO TESTE',
                        'ncm' => '61091000',
                        'cfop' => '5102',
                        'unidade' => 'UN',
                        'quantidade' => 1,
                        'valorUnitario' => 10,
                        'valorTotal' => 10,
                    ],
                    'impostos' => [
                        'icms' => [
                            'cst' => '102',
                            'orig' => 0,
                        ],
                        'pis' => [
                            'cst' => '49',
                            'vBC' => 0,
                            'pPIS' => 0,
                            'vPIS' => 0,
                        ],
                        'cofins' => [
                            'cst' => '49',
                            'vBC' => 0,
                            'pCOFINS' => 0,
                            'vCOFINS' => 0,
                        ],
                    ],
                ],
            ],
            'totais' => [
                'vProd' => 10,
                'vNF' => 10,
            ],
            'transporte' => [
                'modFrete' => 9,
            ],
            'pagamentos' => [
                [
                    'tPag' => '01',
                    'vPag' => 10,
                ],
            ],
        ], $overrides);
    }
}
