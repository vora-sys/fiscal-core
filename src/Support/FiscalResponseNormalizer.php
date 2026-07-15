<?php

namespace sabbajohn\FiscalCore\Support;

final class FiscalResponseNormalizer
{
    public function normalizeFiscalOperation(
        string $modelo,
        string $operation,
        array $operacao = [],
        array $documento = [],
        array $provider = [],
        array $raw = [],
        array $extra = []
    ): array {
        $normalizedDocumento = array_merge([
            'modelo' => $modelo,
            'xml' => null,
            'chave_acesso' => null,
            'chave_consulta' => null,
            'situacao' => null,
            'protocolo' => null,
        ], $documento);

        $normalizedOperacao = array_merge([
            'tipo' => $operation,
            'status' => null,
            'ok' => null,
            'cstat' => null,
            'xmotivo' => null,
            'mensagens' => [],
            'protocolo' => $normalizedDocumento['protocolo'],
        ], $operacao);

        $normalizedRaw = array_merge([
            'request_payload' => null,
            'request_xml' => null,
            'response_body' => null,
            'response_xml' => null,
            'parsed_response' => null,
        ], $raw);

        return array_merge([
            'operacao' => $normalizedOperacao,
            'documento' => $normalizedDocumento,
            'impressao' => (new FiscalDocumentResultNormalizer)->emptyImpressao(),
            'provider' => array_merge([
                'type' => $modelo === 'nfse' ? 'nfse' : 'sefaz',
                'modelo' => $modelo,
                'operation' => $operation,
            ], $provider),
            'raw' => $normalizedRaw,
            'xml' => $normalizedDocumento['xml'],
            'xml_retorno' => $normalizedRaw['response_xml'],
            'chave_acesso' => $normalizedDocumento['chave_acesso'],
            'situacao' => $normalizedDocumento['situacao'],
            'protocolo' => $normalizedDocumento['protocolo'],
        ], $extra);
    }

    public function normalizeImpressaoPdf(
        string $modelo,
        string $operation,
        ?string $xml,
        string $pdf,
        string $filename,
        array $extra = []
    ): array {
        $pdfBase64 = base64_encode($pdf);

        return array_merge((new FiscalDocumentResultNormalizer)->normalizePdfBase64(
            $modelo,
            $operation,
            $xml,
            $pdfBase64,
            $filename,
            $extra
        ), [
            'pdf' => $pdf,
            'size' => strlen($pdf),
        ]);
    }

    public function normalizeTributacao(
        string $operation,
        array $resultado,
        array $context = [],
        array $extra = []
    ): array {
        return array_merge($resultado, [
            'tributacao' => array_merge([
                'operation' => $operation,
                'resultado' => $resultado,
                'ok' => true,
            ], $context),
            'provider' => [
                'type' => 'tributacao',
                'operation' => $operation,
            ],
            'raw' => [
                'request_payload' => $context['request_payload'] ?? null,
                'request_xml' => null,
                'response_body' => null,
                'response_xml' => null,
                'parsed_response' => $resultado,
            ],
        ], $extra);
    }
}
