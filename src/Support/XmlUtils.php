<?php

namespace sabbajohn\FiscalCore\Support;

class XmlUtils
{
    public static function buildNfeProc(?string $signedXml, ?string $responseXml): ?string
    {
        $signedXml = is_string($signedXml) ? trim($signedXml) : '';
        $responseXml = is_string($responseXml) ? trim($responseXml) : '';

        if ($signedXml === '' && $responseXml === '') {
            return null;
        }

        if ($responseXml !== '') {
            $existingProc = self::extractFirstElementXml($responseXml, 'nfeProc');
            if ($existingProc !== null) {
                return $existingProc;
            }
        }

        $nfeXml = self::extractFirstElementXml($signedXml, 'NFe');
        $protXml = self::extractFirstElementXml($responseXml, 'protNFe');

        if ($nfeXml === null || $protXml === null) {
            return null;
        }

        $proc = new \DOMDocument('1.0', 'UTF-8');
        $proc->formatOutput = false;
        $root = $proc->createElementNS('http://www.portalfiscal.inf.br/nfe', 'nfeProc');
        $root->setAttribute('versao', '4.00');
        $proc->appendChild($root);

        $nfeDom = new \DOMDocument;
        $protDom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loadedNfe = $nfeDom->loadXML($nfeXml);
        $loadedProt = $protDom->loadXML($protXml);
        libxml_clear_errors();

        if (! $loadedNfe || ! $loadedProt || ! $nfeDom->documentElement || ! $protDom->documentElement) {
            return null;
        }

        $root->appendChild($proc->importNode($nfeDom->documentElement, true));
        $root->appendChild($proc->importNode($protDom->documentElement, true));

        return $proc->saveXML() ?: null;
    }

    /**
     * Normaliza retorno XML da SEFAZ para estrutura amigável.
     *
     * @return array{
     *   lote: ?array{cStat:?string,xMotivo:?string,cUF:?string,dhRecbto:?string},
     *   protocolo: ?array{cStat:?string,xMotivo:?string,chNFe:?string,nProt:?string,dhRecbto:?string},
     *   autorizado: bool,
     *   status: string
     * }
     */
    public static function parseSefazRetorno(string $xml): array
    {
        $fallback = [
            'lote' => null,
            'protocolo' => null,
            'autorizado' => false,
            'status' => 'desconhecido',
        ];

        if ($xml === '') {
            return $fallback;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        if (! $dom->loadXML($xml)) {
            libxml_clear_errors();

            return $fallback;
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $retEnviNodes = $xpath->query("//*[local-name()='retEnviNFe']");
        $retEnvi = ($retEnviNodes && $retEnviNodes->length > 0) ? $retEnviNodes->item(0) : null;
        $retDistNodes = $xpath->query("//*[local-name()='retDistDFeInt']");
        $retDist = ($retDistNodes && $retDistNodes->length > 0) ? $retDistNodes->item(0) : null;

        $lote = null;
        if ($retEnvi instanceof \DOMNode) {
            $lote = [
                'cStat' => self::firstChildTextByLocalName($xpath, $retEnvi, 'cStat'),
                'xMotivo' => self::firstChildTextByLocalName($xpath, $retEnvi, 'xMotivo'),
                'cUF' => self::firstChildTextByLocalName($xpath, $retEnvi, 'cUF'),
                'dhRecbto' => self::firstChildTextByLocalName($xpath, $retEnvi, 'dhRecbto'),
            ];
        } elseif ($retDist instanceof \DOMNode) {
            $lote = [
                'cStat' => self::firstChildTextByLocalName($xpath, $retDist, 'cStat'),
                'xMotivo' => self::firstChildTextByLocalName($xpath, $retDist, 'xMotivo'),
                'cUF' => self::firstChildTextByLocalName($xpath, $retDist, 'tpAmb'),
                'dhRecbto' => self::firstChildTextByLocalName($xpath, $retDist, 'dhResp'),
            ];
        }

        $infProtNodes = $xpath->query("//*[local-name()='infProt']");
        $infProt = ($infProtNodes && $infProtNodes->length > 0) ? $infProtNodes->item(0) : null;

        $protocolo = null;
        if ($infProt instanceof \DOMNode) {
            $protocolo = [
                'cStat' => self::firstChildTextByLocalName($xpath, $infProt, 'cStat'),
                'xMotivo' => self::firstChildTextByLocalName($xpath, $infProt, 'xMotivo'),
                'chNFe' => self::firstChildTextByLocalName($xpath, $infProt, 'chNFe'),
                'nProt' => self::firstChildTextByLocalName($xpath, $infProt, 'nProt'),
                'dhRecbto' => self::firstChildTextByLocalName($xpath, $infProt, 'dhRecbto'),
            ];
        }

        $protStat = (string) ($protocolo['cStat'] ?? '');
        $loteStat = (string) ($lote['cStat'] ?? '');
        $autorizado = in_array($protStat, ['100', '150'], true);

        if ($autorizado) {
            $status = 'autorizada';
        } elseif ($protStat !== '') {
            $status = 'rejeitada';
        } elseif ($loteStat !== '') {
            // Para DistDFe, 137/138 representam processamento normal (sem/com documentos)
            $status = in_array($loteStat, ['137', '138'], true) ? 'processada' : 'rejeitada';
        } else {
            $status = 'processada';
        }

        return [
            'lote' => $lote,
            'protocolo' => $protocolo,
            'autorizado' => $autorizado,
            'status' => $status,
        ];
    }

    public static function parseSefazRetornoAsJson(string $xml): string
    {
        return json_encode(self::parseSefazRetorno($xml), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Converte XML em array associativo no formato chave => valor.
     *
     * Exemplo de chave: "retDistDFeInt.cStat"
     * Nós repetidos viram array no mesmo índice.
     *
     * @return array<string, mixed>
     */
    public static function xmlToKeyValueArray(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        if (! $dom->loadXML($xml)) {
            libxml_clear_errors();

            return [];
        }
        libxml_clear_errors();

        $root = $dom->documentElement;
        if (! $root instanceof \DOMElement) {
            return [];
        }

        $result = [];
        $childElements = self::getChildElements($root);

        if ($childElements === []) {
            $text = trim($root->textContent);
            if ($text !== '') {
                $result[$root->localName ?: $root->nodeName] = $text;
            }

            return $result;
        }

        foreach ($childElements as $child) {
            $path = $child->localName ?: $child->nodeName;
            self::flattenXmlElement($child, $path, $result);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function flattenXmlElement(\DOMElement $element, string $path, array &$result): void
    {
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attribute) {
                self::appendFlattenedValue($result, $path.'.@'.$attribute->nodeName, trim($attribute->nodeValue ?? ''));
            }
        }

        $childElements = self::getChildElements($element);
        if ($childElements === []) {
            $text = trim($element->textContent);
            if ($text !== '') {
                self::appendFlattenedValue($result, $path, $text);
            }

            return;
        }

        foreach ($childElements as $child) {
            $childName = $child->localName ?: $child->nodeName;
            self::flattenXmlElement($child, $path.'.'.$childName, $result);
        }
    }

    /**
     * @return list<\DOMElement>
     */
    private static function getChildElements(\DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement) {
                $children[] = $childNode;
            }
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function appendFlattenedValue(array &$result, string $key, string $value): void
    {
        if ($value === '') {
            return;
        }

        if (! array_key_exists($key, $result)) {
            $result[$key] = $value;

            return;
        }

        if (! is_array($result[$key])) {
            $result[$key] = [$result[$key]];
        }

        $result[$key][] = $value;
    }

    private static function firstChildTextByLocalName(\DOMXPath $xpath, \DOMNode $contextNode, string $localName): ?string
    {
        $nodes = $xpath->query("./*[local-name()='{$localName}']", $contextNode);
        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    private static function extractFirstElementXml(string $xml, string $localName): ?string
    {
        if (trim($xml) === '') {
            return null;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        if (! $dom->loadXML($xml)) {
            libxml_clear_errors();

            return null;
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//*[local-name()='{$localName}']");
        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof \DOMElement ? $dom->saveXML($node) ?: null : null;
    }
}
