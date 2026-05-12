<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;

final class NFSeResultNormalizer
{
    public function normalizeConsulta(
        string $operation,
        array $parsedResponse,
        array $artifacts = [],
        array $context = []
    ): NFSeConsultaResultInterface {
        $documento = $this->buildDocumento($parsedResponse, $context);
        $impressao = $this->buildImpressao($parsedResponse, $artifacts, $context, $documento);
        $mensagens = $this->extractMensagens($parsedResponse);
        $disponivel = ($documento['numero'] ?? null) !== null;

        $consulta = [
            'operation' => $operation,
            'status' => (string) ($parsedResponse['status'] ?? 'unknown'),
            'status_autorizacao' => $documento['status_autorizacao'],
            'disponivel' => $disponivel,
            'source' => (string) ($context['source'] ?? $operation),
            'mensagens' => $mensagens,
            'chave_consulta' => $documento['chave_consulta'],
        ];

        return new NFSeConsultaResult(
            $consulta,
            $documento,
            $impressao,
            $this->buildProvider($context),
            $this->buildRaw($parsedResponse, $artifacts, $context)
        );
    }

    public function normalizeOperacao(
        string $operation,
        array $parsedResponse,
        array $artifacts = [],
        array $context = []
    ): array {
        $documento = $this->buildDocumento($parsedResponse, $context);
        $mensagens = $this->extractMensagens($parsedResponse);
        $status = (string) ($parsedResponse['status'] ?? 'unknown');

        return [
            'operacao' => [
                'operation' => $operation,
                'status' => $status,
                'ok' => $this->isSuccessfulOperation($status, $documento, $parsedResponse),
                'source' => (string) ($context['source'] ?? $operation),
                'mensagens' => $mensagens,
                'protocolo' => $documento['protocolo'],
                'numero' => $documento['numero'],
                'chave_consulta' => $documento['chave_consulta'],
            ],
            'documento' => $documento,
            'cancelamento' => is_array($parsedResponse['cancelamento'] ?? null) ? $parsedResponse['cancelamento'] : null,
            'provider' => $this->buildProvider($context),
            'raw' => $this->buildRaw($parsedResponse, $artifacts, $context),
        ];
    }

    public function normalizeImpressao(
        array $impressao,
        array $raw = [],
        array $provider = []
    ): NFSeImpressaoResultInterface {
        return new NFSeImpressaoResult($impressao, $provider, $raw);
    }

    public function normalizePdfBase64(
        string $pdfBase64,
        array $context = [],
        array $raw = []
    ): NFSeImpressaoResultInterface {
        return $this->normalizeImpressao([
            'disponivel' => true,
            'modo' => 'pdf_base64',
            'url' => null,
            'pdf_base64' => $pdfBase64,
            'content_type' => 'application/pdf',
            'filename' => $context['filename'] ?? null,
            'source' => $context['source'] ?? 'pdf_base64',
        ], $raw, $this->buildProvider($context));
    }

    public function normalizeUrl(
        string $url,
        array $context = [],
        array $raw = []
    ): NFSeImpressaoResultInterface {
        return $this->normalizeImpressao([
            'disponivel' => true,
            'modo' => 'url',
            'url' => $url,
            'pdf_base64' => null,
            'content_type' => 'text/uri-list',
            'filename' => $context['filename'] ?? null,
            'source' => $context['source'] ?? 'official_url',
        ], $raw, $this->buildProvider($context));
    }

    public function normalizeIndisponivel(
        array $context = [],
        array $raw = []
    ): NFSeImpressaoResultInterface {
        return $this->normalizeImpressao([
            'disponivel' => false,
            'modo' => 'indisponivel',
            'url' => null,
            'pdf_base64' => null,
            'content_type' => null,
            'filename' => $context['filename'] ?? null,
            'source' => $context['source'] ?? 'indisponivel',
        ], $raw, $this->buildProvider($context));
    }

    private function buildDocumento(array $parsedResponse, array $context): array
    {
        $nfse = is_array($parsedResponse['nfse'] ?? null) ? $parsedResponse['nfse'] : [];
        $listaNfse = is_array($parsedResponse['lista_nfse'] ?? null) ? $parsedResponse['lista_nfse'] : [];
        $primary = is_array($listaNfse[0] ?? null) ? $listaNfse[0] : [];

        $numero = $this->pickString([
            $nfse['numero'] ?? null,
            $parsedResponse['numero'] ?? null,
            $parsedResponse['numero_nfse'] ?? null,
            $primary['numero'] ?? null,
        ]);
        $codigoVerificacao = $this->pickString([
            $nfse['codigo_verificacao'] ?? null,
            $parsedResponse['codigo_verificacao'] ?? null,
            $parsedResponse['chave_validacao'] ?? null,
            $primary['codigo_verificacao'] ?? null,
        ]);
        $protocolo = $this->pickString([
            $parsedResponse['protocolo'] ?? null,
            $parsedResponse['numero_lote'] ?? null,
            $parsedResponse['lote'] ?? null,
        ]);
        $dataEmissao = $this->pickString([
            $nfse['data_emissao'] ?? null,
            $parsedResponse['data_emissao'] ?? null,
            $primary['data_emissao'] ?? null,
        ]);
        $xml = $this->extractXml($parsedResponse, $context);

        return [
            'numero' => $numero,
            'codigo_verificacao' => $codigoVerificacao,
            'protocolo' => $protocolo,
            'status_autorizacao' => $this->resolveStatusAutorizacao($parsedResponse, $numero, $codigoVerificacao),
            'data_emissao' => $dataEmissao,
            'xml' => $xml,
            'chave_consulta' => $this->pickString([
                $context['chave_consulta'] ?? null,
                $numero,
            ]),
        ];
    }

    private function buildImpressao(array $parsedResponse, array $artifacts, array $context, array $documento): array
    {
        $url = $this->pickString([
            $context['danfse_url'] ?? null,
            $parsedResponse['danfse_url'] ?? null,
            $parsedResponse['nfse_url'] ?? null,
        ]);
        $pdfBase64 = $this->pickString([
            $context['pdf_base64'] ?? null,
            $parsedResponse['pdf_base64'] ?? null,
        ]);

        if ($url !== null) {
            return [
                'disponivel' => true,
                'modo' => 'url',
                'url' => $url,
                'pdf_base64' => null,
                'content_type' => 'text/uri-list',
                'filename' => $context['filename'] ?? null,
                'source' => $context['print_source'] ?? 'official_url',
            ];
        }

        if ($pdfBase64 !== null) {
            return [
                'disponivel' => true,
                'modo' => 'pdf_base64',
                'url' => null,
                'pdf_base64' => $pdfBase64,
                'content_type' => (string) ($parsedResponse['content_type'] ?? 'application/pdf'),
                'filename' => $context['filename'] ?? null,
                'source' => $context['print_source'] ?? 'pdf_base64',
            ];
        }

        return [
            'disponivel' => false,
            'modo' => 'indisponivel',
            'url' => null,
            'pdf_base64' => null,
            'content_type' => null,
            'filename' => $context['filename'] ?? null,
            'source' => $context['print_source'] ?? 'indisponivel',
        ];
    }

    private function buildProvider(array $context): array
    {
        return array_filter([
            'provider_key' => $context['provider_key'] ?? null,
            'provider_class' => $context['provider_class'] ?? null,
            'municipio' => $context['municipio'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function buildRaw(array $parsedResponse, array $artifacts, array $context): array
    {
        return [
            'parsed_response' => $parsedResponse,
            'request_payload' => $context['request_payload'] ?? $artifacts['request_payload'] ?? null,
            'request_xml' => $artifacts['request_xml'] ?? null,
            'response_body' => $artifacts['response_raw'] ?? null,
            'response_xml' => $artifacts['response_xml'] ?? null,
        ];
    }

    private function extractMensagens(array $parsedResponse): array
    {
        $mensagens = $parsedResponse['mensagens'] ?? [];

        if (!is_array($mensagens)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $message): ?string => is_scalar($message) ? trim((string) $message) : null, $mensagens),
            static fn (?string $message): bool => $message !== null && $message !== ''
        ));
    }

    private function extractXml(array $parsedResponse, array $context): ?string
    {
        $explicitXml = $this->normalizeFiscalXml($context['xml'] ?? null);
        if ($explicitXml !== null) {
            return $explicitXml;
        }

        $rawXml = $this->extractFiscalXmlFromCandidate($parsedResponse['raw_xml'] ?? null);
        if ($rawXml !== null) {
            return $rawXml;
        }

        return $this->extractFiscalXmlFromGZipField($parsedResponse['nfseXmlGZipB64'] ?? null);
    }

    private function resolveStatusAutorizacao(array $parsedResponse, ?string $numero, ?string $codigoVerificacao): string
    {
        $status = (string) ($parsedResponse['status'] ?? 'unknown');

        if ($numero !== null && ($codigoVerificacao !== null || !empty($parsedResponse['nfse_url']) || !empty($parsedResponse['pdf_base64']))) {
            return 'autorizada';
        }

        return match ($status) {
            'error', 'invalid_xml', 'empty' => 'erro',
            'success' => 'pendente',
            default => 'nao_encontrada',
        };
    }

    private function isSuccessfulOperation(string $status, array $documento, array $parsedResponse): bool
    {
        if (in_array($status, ['success', 'authorized', 'autorizada'], true)) {
            return true;
        }

        if (($documento['status_autorizacao'] ?? null) === 'autorizada') {
            return true;
        }

        $cancelamento = $parsedResponse['cancelamento'] ?? null;

        return is_array($cancelamento) && ($cancelamento['sucesso'] ?? false) === true;
    }

    private function pickString(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeFiscalXml(mixed $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);
        if ($candidate === '' || !str_starts_with(ltrim($candidate), '<')) {
            return null;
        }

        return $candidate;
    }

    private function extractFiscalXmlFromCandidate(mixed $candidate): ?string
    {
        $candidate = $this->normalizeFiscalXml($candidate);
        if ($candidate === null) {
            return null;
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($candidate)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        foreach (['CompNfse', 'Nfse', 'InfNfse'] as $nodeName) {
            $node = $xpath->query("//*[local-name()='{$nodeName}']")->item(0);
            if ($node instanceof \DOMNode) {
                return $dom->saveXML($node) ?: null;
            }
        }

        return null;
    }

    private function extractFiscalXmlFromGZipField(mixed $candidate): ?string
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $decoded = base64_decode(trim($candidate), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $xml = @gzdecode($decoded);
        if ($xml === false) {
            $xml = @gzinflate(substr($decoded, 10));
        }

        return $this->extractFiscalXmlFromCandidate($xml);
    }
}
