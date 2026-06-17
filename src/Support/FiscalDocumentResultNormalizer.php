<?php

namespace sabbajohn\FiscalCore\Support;

class FiscalDocumentResultNormalizer
{
    public function normalizeDocumentoFiscal(
        string $modelo,
        string $operation,
        array $documento = [],
        array $impressao = [],
        array $provider = [],
        array $raw = [],
        array $extra = []
    ): array {
        $normalizedDocumento = array_merge([
                'modelo' => $modelo,
                'xml' => null,
                'chave_acesso' => null,
                'situacao' => null,
                'protocolo' => null,
            ], $documento);
        $normalizedRaw = array_merge([
                'request_payload' => null,
                'request_xml' => null,
                'response_body' => null,
                'response_xml' => null,
                'parsed_response' => null,
            ], $raw);

        return array_merge([
            'operacao' => [
                'tipo' => $operation,
                'status' => $normalizedDocumento['situacao'],
                'ok' => null,
                'cstat' => null,
                'xmotivo' => $normalizedDocumento['situacao'],
                'mensagens' => [],
                'protocolo' => $normalizedDocumento['protocolo'],
            ],
            'documento' => $normalizedDocumento,
            'impressao' => array_merge($this->emptyImpressao(), $impressao),
            'provider' => array_merge([
                'type' => 'sefaz',
                'modelo' => $modelo,
                'operation' => $operation,
            ], $provider),
            'raw' => $normalizedRaw,
            // Aliases legados seguros: apontam para o documento fiscal canônico.
            'xml' => $normalizedDocumento['xml'],
            'chave_acesso' => $normalizedDocumento['chave_acesso'],
            'situacao' => $normalizedDocumento['situacao'],
            'protocolo' => $normalizedDocumento['protocolo'],
            // Alias explícito do retorno bruto para facilitar migração do consumidor.
            'xml_retorno' => $normalizedRaw['response_xml'],
        ], $extra);
    }

    public function normalizeConsulta(
        string $modelo,
        string $operation,
        string $xml,
        ?string $chaveAcesso,
        ?string $situacao,
        array $extra = []
    ): array {
        return $this->normalizeDocumentoFiscal(
            $modelo,
            $operation,
            [
                'xml' => $xml,
                'chave_acesso' => $chaveAcesso,
                'situacao' => $situacao,
            ],
            $this->emptyImpressao(),
            [],
            [
                'response_xml' => $xml,
            ],
            $extra
        );
    }

    public function normalizeEmissao(
        string $modelo,
        string $operation,
        string $xmlRetorno,
        ?string $xmlAssinado,
        ?string $chaveAcesso,
        ?string $situacao,
        array $extra = [],
        ?string $xmlDocumento = null,
        ?string $protocolo = null
    ): array {
        return $this->normalizeDocumentoFiscal(
            $modelo,
            $operation,
            [
                'xml' => $xmlDocumento,
                'chave_acesso' => $chaveAcesso,
                'situacao' => $situacao,
                'protocolo' => $protocolo,
            ],
            $this->emptyImpressao(),
            [],
            [
                'response_xml' => $xmlRetorno,
                'parsed_response' => [
                    'xml_assinado' => $xmlAssinado,
                ],
            ],
            $extra
        );
    }

    public function normalizePdfBase64(
        string $modelo,
        string $operation,
        ?string $xml,
        string $pdfBase64,
        string $filename,
        array $extra = []
    ): array {
        $printSource = is_string($extra['print_source'] ?? null) ? $extra['print_source'] : 'local_render';
        unset($extra['print_source']);

        return $this->normalizeDocumentoFiscal(
            $modelo,
            $operation,
            [
                'xml' => $xml,
            ],
            [
                'disponivel' => true,
                'modo' => 'pdf_base64',
                'url' => null,
                'pdf_base64' => $pdfBase64,
                'content_type' => 'application/pdf',
                'filename' => $filename,
                'source' => $printSource,
            ],
            [],
            [
                'response_xml' => $xml,
            ],
            $extra
        );
    }

    public function normalizeXmlRetrieval(
        string $modelo,
        string $operation,
        ?string $xml,
        ?string $chaveAcesso,
        ?string $situacao = null,
        array $raw = [],
        array $extra = []
    ): array {
        return $this->normalizeDocumentoFiscal(
            $modelo,
            $operation,
            [
                'xml' => $xml,
                'chave_acesso' => $chaveAcesso,
                'situacao' => $situacao,
            ],
            $this->emptyImpressao(),
            [],
            array_merge([
                'response_xml' => $raw['response_xml'] ?? $xml,
            ], $raw),
            $extra
        );
    }

    public function emptyImpressao(): array
    {
        return [
            'disponivel' => false,
            'modo' => 'indisponivel',
            'url' => null,
            'pdf_base64' => null,
            'content_type' => null,
            'filename' => null,
            'source' => null,
        ];
    }
}
