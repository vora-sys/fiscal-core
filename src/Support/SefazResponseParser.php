<?php

namespace sabbajohn\FiscalCore\Support;

final class SefazResponseParser
{
    /** @var list<string> */
    private const SUCCESS_CSTATS = ['100', '101', '102', '135', '136', '155'];

    public function parseEventResponse(string $xml): array
    {
        $common = $this->parseCommonResponse($xml);
        $dom = $this->loadXml($xml);
        if (! $dom instanceof \DOMDocument) {
            return $common + ['eventos' => []];
        }

        $xpath = new \DOMXPath($dom);
        $eventNodes = $xpath->query("//*[local-name()='retEvento']/*[local-name()='infEvento']");
        $events = [];

        if ($eventNodes !== false) {
            foreach ($eventNodes as $eventNode) {
                if (! $eventNode instanceof \DOMElement) {
                    continue;
                }

                $events[] = [
                    'id' => $eventNode->getAttribute('Id') ?: null,
                    'cstat' => $this->firstNodeValue($xpath, ['cStat'], $eventNode),
                    'xmotivo' => $this->firstNodeValue($xpath, ['xMotivo'], $eventNode),
                    'protocolo' => $this->firstNodeValue($xpath, ['nProt'], $eventNode),
                    'chave' => $this->firstNodeValue($xpath, ['chNFe'], $eventNode),
                    'tipo_evento' => $this->firstNodeValue($xpath, ['tpEvento'], $eventNode),
                    'sequencia' => $this->firstNodeValue($xpath, ['nSeqEvento'], $eventNode),
                ];
            }
        }

        $main = $events[0] ?? [];
        $cstat = $main['cstat'] ?? $common['cstat'];
        $xmotivo = $main['xmotivo'] ?? $common['xmotivo'];

        return array_merge($common, [
            'cstat' => $cstat,
            'xmotivo' => $xmotivo,
            'protocolo' => $main['protocolo'] ?? $common['protocolo'],
            'chave' => $main['chave'] ?? $common['chave'],
            'tipo_evento' => $main['tipo_evento'] ?? $common['tipo_evento'],
            'sequencia' => $main['sequencia'] ?? $common['sequencia'],
            'ok' => $this->isSuccessfulCStat($cstat),
            'eventos' => $events,
            'lote' => [
                'cstat' => $common['cstat'],
                'xmotivo' => $common['xmotivo'],
                'id_lote' => $this->extractTagValue($xml, ['idLote']),
            ],
        ]);
    }

    public function parseCommonResponse(string $xml): array
    {
        return [
            'cstat' => $this->extractTagValue($xml, ['cStat']),
            'xmotivo' => $this->extractTagValue($xml, ['xMotivo']),
            'protocolo' => $this->extractTagValue($xml, ['nProt', 'nRec']),
            'chave' => $this->extractTagValue($xml, ['chNFe']),
            'recibo' => $this->extractTagValue($xml, ['nRec']),
            'tipo_evento' => $this->extractTagValue($xml, ['tpEvento']),
            'sequencia' => $this->extractTagValue($xml, ['nSeqEvento']),
            'ok' => $this->isSuccessfulCStat($this->extractTagValue($xml, ['cStat'])),
        ];
    }

    public function isSuccessfulEventResponse(string $xml): bool
    {
        return (bool) ($this->parseEventResponse($xml)['ok'] ?? false);
    }

    public function validateXmlFragment(string $fragment): void
    {
        if (trim($fragment) === '') {
            return;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML('<root>'.$fragment.'</root>');
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            $message = $errors[0]->message ?? 'fragmento XML inválido';
            throw new \InvalidArgumentException('tagAdicional XML inválida: '.trim($message));
        }
    }

    public function extractTagValue(?string $xml, array $tagNames): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return null;
        }

        $dom = $this->loadXml($xml);
        if (! $dom instanceof \DOMDocument) {
            return null;
        }

        $xpath = new \DOMXPath($dom);

        return $this->firstNodeValue($xpath, $tagNames);
    }

    private function loadXml(string $xml): ?\DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    /**
     * @param  list<string>  $tagNames
     */
    private function firstNodeValue(\DOMXPath $xpath, array $tagNames, ?\DOMNode $context = null): ?string
    {
        foreach ($tagNames as $tagName) {
            $query = ".//*[local-name()='{$tagName}']";
            $nodes = $context instanceof \DOMNode
                ? $xpath->query($query, $context)
                : $xpath->query("//*[local-name()='{$tagName}']");

            $node = $nodes !== false ? $nodes->item(0) : null;
            if ($node instanceof \DOMNode) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function isSuccessfulCStat(?string $cstat): bool
    {
        return $cstat !== null && in_array($cstat, self::SUCCESS_CSTATS, true);
    }
}
