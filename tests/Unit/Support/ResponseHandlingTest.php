<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\FiscalResponse;
use sabbajohn\FiscalCore\Support\ResponseHandler;

/**
 * Testes unitários para sistema de respostas
 * Valida tratamento de erros e encapsulamento de dados
 */
class ResponseHandlingTest extends TestCase
{
    /** @test */
    public function deve_criar_resposta_sucesso(): void
    {
        $dados = ['resultado' => 'teste'];
        $response = FiscalResponse::success($dados);

        $this->assertTrue($response->isSuccess());
        $this->assertEquals($dados, $response->getData());
        $this->assertEmpty($response->getError());
    }

    /** @test */
    public function deve_criar_resposta_erro(): void
    {
        $mensagem = 'Erro de teste';
        $response = FiscalResponse::error($mensagem);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals($mensagem, $response->getError());
        $this->assertEquals([], $response->getData());
    }

    /** @test */
    public function deve_adicionar_metadata_resposta(): void
    {
        $dados = ['teste' => 'valor'];
        $metadata = ['timestamp' => time(), 'source' => 'api'];

        $response = FiscalResponse::success($dados, 'test_operation', $metadata);

        $this->assertEquals($metadata['timestamp'], $response->getMetadata()['timestamp']);
        $this->assertEquals($metadata['source'], $response->getMetadata()['source']);
        $this->assertEquals('1.0', $response->getMetadata()['version']);
        $this->assertArrayHasKey('timestamp', $response->getMetadata());
    }

    /** @test */
    public function deve_tratar_excecao_com_handler(): void
    {
        $handler = new ResponseHandler;

        // Testa usando o método execute que internamente chama handleException
        $response = $handler->execute(function () {
            throw new \Exception('Teste de erro');
        }, 'test_operation');

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('Teste de erro', $response->getError());
    }

    /** @test */
    public function deve_executar_callback_sucesso(): void
    {
        $handler = new ResponseHandler;
        $executado = false;

        $callback = function () use (&$executado) {
            $executado = true;

            return ['resultado' => 'ok'];
        };

        $response = $handler->execute($callback);

        $this->assertTrue($executado);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals(['resultado' => 'ok'], $response->getData());
    }

    /** @test */
    public function deve_capturar_excecao_em_callback(): void
    {
        $handler = new ResponseHandler;

        $callback = function () {
            throw new \InvalidArgumentException('Parâmetro inválido');
        };

        $response = $handler->execute($callback);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('Parâmetro inválido', $response->getError());
        $this->assertEquals('warning', $response->getMetadata()['severity']);
        $this->assertEquals('validation', $response->getMetadata()['category']);
        $this->assertArrayHasKey('trace_id', $response->getMetadata());
        $this->assertArrayHasKey('recoverable', $response->getMetadata());
        $this->assertSame('unknown', $response->getMetadata()['operation']);
    }

    /** @test */
    public function deve_validar_timeout_operacao(): void
    {
        $handler = new ResponseHandler;

        // Simula um timeout manual sem usar sleep
        $callback = function () {
            // Simula uma operação que "deveria" dar timeout
            throw new \Exception('Operation timeout exceeded');
        };

        $response = $handler->executeWithTimeout($callback, 1);

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('timeout', strtolower($response->getError()));
    }

    /** @test */
    public function deve_implementar_retry_automatico(): void
    {
        $handler = new ResponseHandler;
        $tentativas = 0;

        $callback = function () use (&$tentativas) {
            $tentativas++;
            if ($tentativas < 3) {
                throw new \Exception('Falha temporária');
            }

            return ['sucesso' => true, 'tentativas' => $tentativas];
        };

        $response = $handler->executeWithRetry($callback, 3, 0.1); // 3 tentativas, 100ms entre elas

        $this->assertTrue($response->isSuccess());
        $this->assertEquals(3, $response->getData()['tentativas']);
        $this->assertEquals(3, $response->getMetadata()['retry_attempts']);
    }

    /** @test */
    public function deve_falhar_apos_esgotar_tentativas(): void
    {
        $handler = new ResponseHandler;

        $callback = function () {
            throw new \Exception('Erro persistente');
        };

        $response = $handler->executeWithRetry($callback, 2, 0.1);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals(2, $response->getMetadata()['retry_attempts']);
        $this->assertStringContainsString('Erro persistente', $response->getError());
    }

    /** @test */
    public function deve_preservar_codigo_erro_original(): void
    {
        $exception = new \InvalidArgumentException('Dados inválidos', 400);
        $response = FiscalResponse::fromException($exception);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Dados inválidos', $response->getError());
        $this->assertEquals(400, $response->getMetadata()['error_code']);
        $this->assertEquals('InvalidArgumentException', $response->getMetadata()['exception_type']);
    }

    /** @test */
    public function deve_serializar_resposta_json(): void
    {
        $dados = ['teste' => 'valor', 'numero' => 123];
        $response = FiscalResponse::success($dados);

        $json = $response->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals(true, $decoded['success']);
        $this->assertEquals($dados, $decoded['data']);
        $this->assertArrayHasKey('timestamp', $decoded);
    }

    /** @test */
    public function deve_implementar_cache_resposta(): void
    {
        $handler = new ResponseHandler;
        $executado = 0;

        $callback = function () use (&$executado) {
            $executado++;

            return ['execucao' => $executado];
        };

        $chave_cache = 'teste_cache';

        // Primeira execução - deve executar callback
        $response1 = $handler->executeWithCache($chave_cache, $callback, 1);
        $this->assertEquals(1, $response1->getData()['execucao']);

        // Segunda execução - deve usar cache
        $response2 = $handler->executeWithCache($chave_cache, $callback, 1);
        $this->assertEquals(1, $response2->getData()['execucao']); // Mesmo valor (cache)
        $this->assertTrue($response2->getMetadata()['from_cache']);
    }

    /** @test */
    public function deve_normalizar_retorno_sefaz_xml_invalido_com_fallback(): void
    {
        $handler = new ResponseHandler;
        $parsed = ResponseHandler::parseSefazRetorno('<xml-invalido');

        $this->assertSame([
            'lote' => null,
            'protocolo' => null,
            'autorizado' => false,
            'status' => 'desconhecido',
        ], $parsed);
    }

    /** @test */
    public function deve_normalizar_retorno_sefaz_com_namespace(): void
    {
        $handler = new ResponseHandler;
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe">
  <retEnviNFe>
    <cUF>41</cUF>
    <cStat>103</cStat>
    <xMotivo>Lote recebido com sucesso</xMotivo>
    <dhRecbto>2026-02-11T10:00:00-03:00</dhRecbto>
  </retEnviNFe>
  <protNFe>
    <infProt>
      <chNFe>41260200000000000000550010000000011000000010</chNFe>
      <nProt>141260000000001</nProt>
      <cStat>100</cStat>
      <xMotivo>Autorizado o uso da NF-e</xMotivo>
      <dhRecbto>2026-02-11T10:00:05-03:00</dhRecbto>
    </infProt>
  </protNFe>
</nfeProc>
XML;

        $parsed = ResponseHandler::parseSefazRetorno($xml);

        $this->assertSame('103', $parsed['lote']['cStat']);
        $this->assertSame('100', $parsed['protocolo']['cStat']);
        $this->assertTrue($parsed['autorizado']);
        $this->assertSame('autorizada', $parsed['status']);
    }

    /** @test */
    public function deve_converter_xml_para_array_chave_valor(): void
    {
        $handler = new ResponseHandler;
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<retDistDFeInt versao="1.01">
    <cStat>138</cStat>
    <xMotivo>Documento localizado</xMotivo>
    <loteDistDFeInt>
        <docZip NSU="000000000000001">ABC</docZip>
    </loteDistDFeInt>
</retDistDFeInt>
XML;

        $result = ResponseHandler::xmlToKeyValueArray($xml);

        $this->assertSame('138', $result['cStat']);
        $this->assertSame('Documento localizado', $result['xMotivo']);
        $this->assertSame('000000000000001', $result['loteDistDFeInt.docZip.@NSU']);
        $this->assertSame('ABC', $result['loteDistDFeInt.docZip']);
    }

    /** @test */
    public function deve_agrupar_nos_repetidos_na_conversao_xml_para_array(): void
    {
        $handler = new ResponseHandler;
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<root>
    <item>
        <chNFe>111</chNFe>
    </item>
    <item>
        <chNFe>222</chNFe>
    </item>
</root>
XML;

        $result = ResponseHandler::xmlToKeyValueArray($xml);

        $this->assertSame(['111', '222'], $result['item.chNFe']);
    }

    /** @test */
    public function deve_normalizar_retorno_distdfe_soap_com_consumo_indevido(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
    <soap:Body>
        <nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">
            <nfeDistDFeInteresseResult>
                <retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
                    <tpAmb>2</tpAmb>
                    <verAplic>1.7.6</verAplic>
                    <cStat>656</cStat>
                    <xMotivo>Rejeicao: Consumo Indevido</xMotivo>
                    <dhResp>2026-02-13T14:05:17-03:00</dhResp>
                    <ultNSU>000000000000000</ultNSU>
                    <maxNSU>000000000000000</maxNSU>
                </retDistDFeInt>
            </nfeDistDFeInteresseResult>
        </nfeDistDFeInteresseResponse>
    </soap:Body>
</soap:Envelope>
XML;

        $parsed = ResponseHandler::parseSefazRetorno($xml);

        $this->assertSame('656', $parsed['lote']['cStat']);
        $this->assertSame('Rejeicao: Consumo Indevido', $parsed['lote']['xMotivo']);
        $this->assertFalse($parsed['autorizado']);
        $this->assertSame('rejeitada', $parsed['status']);
    }
}
