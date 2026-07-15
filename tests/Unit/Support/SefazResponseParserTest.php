<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\SefazResponseParser;

class SefazResponseParserTest extends TestCase
{
    public function test_event_response_prefers_inner_event_status_over_batch_status(): void
    {
        $parser = new SefazResponseParser;

        $parsed = $parser->parseEventResponse($this->eventBatchXml('128', '135'));

        $this->assertSame('135', $parsed['cstat']);
        $this->assertSame('128', $parsed['lote']['cstat']);
        $this->assertSame('Evento registrado e vinculado a NF-e', $parsed['xmotivo']);
        $this->assertSame('123456789012345', $parsed['protocolo']);
        $this->assertTrue($parsed['ok']);
        $this->assertCount(1, $parsed['eventos']);
    }

    public function test_event_response_parses_multiple_events(): void
    {
        $parser = new SefazResponseParser;
        $xml = <<<'XML'
<retEnvEvento>
    <cStat>128</cStat>
    <xMotivo>Lote de Evento Processado</xMotivo>
    <retEvento>
        <infEvento>
            <cStat>135</cStat>
            <xMotivo>Evento registrado</xMotivo>
            <chNFe>35123456789012345678901234567890123456789012</chNFe>
            <tpEvento>110110</tpEvento>
            <nSeqEvento>1</nSeqEvento>
            <nProt>111</nProt>
        </infEvento>
    </retEvento>
    <retEvento>
        <infEvento>
            <cStat>136</cStat>
            <xMotivo>Evento registrado, mas nao vinculado</xMotivo>
            <chNFe>35123456789012345678901234567890123456789013</chNFe>
            <tpEvento>110110</tpEvento>
            <nSeqEvento>2</nSeqEvento>
            <nProt>222</nProt>
        </infEvento>
    </retEvento>
</retEnvEvento>
XML;

        $parsed = $parser->parseEventResponse($xml);

        $this->assertSame('135', $parsed['cstat']);
        $this->assertCount(2, $parsed['eventos']);
        $this->assertSame('222', $parsed['eventos'][1]['protocolo']);
    }

    public function test_event_response_parses_soap_envelope(): void
    {
        $parser = new SefazResponseParser;
        $xml = <<<XML
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
    <soap:Body>
        <nfeResultMsg>
            {$this->eventBatchXml('128', '135')}
        </nfeResultMsg>
    </soap:Body>
</soap:Envelope>
XML;

        $parsed = $parser->parseEventResponse($xml);

        $this->assertSame('135', $parsed['cstat']);
        $this->assertSame('35123456789012345678901234567890123456789012', $parsed['chave']);
        $this->assertTrue($parsed['ok']);
    }

    public function test_event_response_marks_rejection_as_not_ok(): void
    {
        $parser = new SefazResponseParser;

        $parsed = $parser->parseEventResponse($this->eventBatchXml('128', '573', 'Duplicidade de Evento'));

        $this->assertSame('573', $parsed['cstat']);
        $this->assertSame('Duplicidade de Evento', $parsed['xmotivo']);
        $this->assertFalse($parsed['ok']);
    }

    private function eventBatchXml(string $batchCstat, string $eventCstat, string $eventMotivo = 'Evento registrado e vinculado a NF-e'): string
    {
        return <<<XML
<retEnvEvento>
    <cStat>{$batchCstat}</cStat>
    <xMotivo>Lote de Evento Processado</xMotivo>
    <retEvento>
        <infEvento>
            <cStat>{$eventCstat}</cStat>
            <xMotivo>{$eventMotivo}</xMotivo>
            <chNFe>35123456789012345678901234567890123456789012</chNFe>
            <tpEvento>110110</tpEvento>
            <nSeqEvento>1</nSeqEvento>
            <nProt>123456789012345</nProt>
        </infEvento>
    </retEvento>
</retEnvEvento>
XML;
    }
}
