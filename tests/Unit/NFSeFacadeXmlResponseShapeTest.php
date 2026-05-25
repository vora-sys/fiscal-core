<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Facade\NFSeFacade;

class NFSeFacadeXmlResponseShapeTest extends TestCase
{
    public function test_baixar_xml_decodes_nfse_xml_gzip_without_promoting_json_payload_to_document(): void
    {
        $nfseXml = '<CompNfse><Nfse><InfNfse><Numero>456</Numero></InfNfse></Nfse></CompNfse>';

        $adapter = $this->createMock(NFSeAdapter::class);
        $adapter->expects($this->once())
            ->method('baixarXml')
            ->with('NFSE456')
            ->willReturn(json_encode([
                'status' => 'success',
                'numero' => '456',
                'nfseXmlGZipB64' => base64_encode(gzencode($nfseXml)),
            ]));

        $facade = new NFSeFacade('nacional', $adapter);
        $response = $facade->baixarXml('NFSE456');

        $this->assertTrue($response->isSuccess());
        $this->assertSame($nfseXml, $response->getData('documento')['xml']);
        $this->assertNull($response->getData('raw')['response_xml']);
        $this->assertIsString($response->getData('raw')['response_body']);
        $this->assertSame('success', $response->getData('raw')['parsed_response']['status']);
    }

    public function test_baixar_xml_returns_canonical_document_shape(): void
    {
        $adapter = $this->createMock(NFSeAdapter::class);
        $adapter->expects($this->once())
            ->method('baixarXml')
            ->with('NFSE123')
            ->willReturn(json_encode([
                'status' => 'success',
                'numero' => '123',
                'raw_xml' => '<CompNfse><Nfse><InfNfse><Numero>123</Numero></InfNfse></Nfse></CompNfse>',
            ]));

        $facade = new NFSeFacade('nacional', $adapter);
        $response = $facade->baixarXml('NFSE123');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('NFSE123', $response->getData('documento')['chave_consulta']);
        $this->assertSame('<CompNfse><Nfse><InfNfse><Numero>123</Numero></InfNfse></Nfse></CompNfse>', $response->getData('documento')['xml']);
        $this->assertSame('indisponivel', $response->getData('impressao')['modo']);
        $this->assertSame('success', $response->getData('raw')['parsed_response']['status']);
        $this->assertNull($response->getData('raw')['response_xml']);
    }

    public function test_baixar_xml_keeps_administrative_payload_out_of_document_xml(): void
    {
        $adapter = $this->createMock(NFSeAdapter::class);
        $adapter->expects($this->once())
            ->method('baixarXml')
            ->with('NFSE-ADMIN')
            ->willReturn(json_encode([
                'status' => 'error',
                'mensagens' => ['Documento indisponivel'],
                'raw_xml' => '<string>Documento indisponivel</string>',
            ]));

        $facade = new NFSeFacade('nacional', $adapter);
        $response = $facade->baixarXml('NFSE-ADMIN');

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getData('documento')['xml']);
        $this->assertSame('indisponivel', $response->getData('impressao')['modo']);
        $this->assertIsString($response->getData('raw')['response_body']);
        $this->assertNull($response->getData('raw')['response_xml']);
    }

    public function test_cancelar_returns_canonical_operation_shape(): void
    {
        $adapter = $this->createMock(NFSeAdapter::class);
        $adapter->expects($this->once())
            ->method('cancelar')
            ->with('NFSE123', 'Cancelamento por erro operacional', 'PROTO')
            ->willReturn(true);
        $adapter->expects($this->once())
            ->method('getLastOperationInfo')
            ->willReturn([
                'parsed_response' => ['status' => 'success'],
                'normalized_result' => [
                    'operacao' => [
                        'protocolo' => 'PROTO',
                    ],
                ],
            ]);

        $facade = new NFSeFacade('nacional', $adapter);
        $response = $facade->cancelar('NFSE123', 'Cancelamento por erro operacional', 'PROTO');

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->getData('operacao')['ok']);
        $this->assertSame('cancelada', $response->getData('documento')['situacao']);
        $this->assertSame('NFSE123', $response->getData('documento')['chave_consulta']);
        $this->assertSame('nfse_cancelamento', $response->getData('type'));
    }
}
