<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Renderers\NFSe;

use Dompdf\Dompdf;
use Dompdf\Options;
use freeline\FiscalCore\Contracts\MunicipalDanfseRendererInterface;
use RuntimeException;

final class BelemMunicipalDanfseRenderer implements MunicipalDanfseRendererInterface
{
    public function render(string $xmlNfse): string
    {
        $data = $this->extractDocumentData($xmlNfse);
        $html = $this->buildHtml($data);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function extractDocumentData(string $xmlNfse): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xmlNfse)) {
            throw new RuntimeException('XML final da NFSe invalido para gerar o DANFSe.');
        }

        $xpath = new \DOMXPath($dom);

        return [
            'numero' => $this->firstNodeValue($xpath, [
                "//*[local-name()='InfNfse']/*[local-name()='Numero']",
                "//*[local-name()='Numero']",
            ]),
            'codigo_verificacao' => $this->firstNodeValue($xpath, [
                "//*[local-name()='InfNfse']/*[local-name()='CodigoVerificacao']",
                "//*[local-name()='CodigoVerificacao']",
            ]),
            'data_emissao' => $this->firstNodeValue($xpath, [
                "//*[local-name()='InfNfse']/*[local-name()='DataEmissao']",
                "//*[local-name()='DataEmissao']",
            ]),
            'prestador_razao_social' => $this->firstNodeValue($xpath, [
                "//*[local-name()='PrestadorServico']/*[local-name()='RazaoSocial']",
                "//*[local-name()='Prestador']/*[local-name()='RazaoSocial']",
            ]),
            'prestador_cnpj' => $this->firstNodeValue($xpath, [
                "//*[local-name()='PrestadorServico']//*[local-name()='Cnpj']",
                "//*[local-name()='Prestador']//*[local-name()='Cnpj']",
            ]),
            'prestador_im' => $this->firstNodeValue($xpath, [
                "//*[local-name()='PrestadorServico']/*[local-name()='IdentificacaoPrestador']/*[local-name()='InscricaoMunicipal']",
                "//*[local-name()='Prestador']/*[local-name()='InscricaoMunicipal']",
            ]),
            'tomador_razao_social' => $this->firstNodeValue($xpath, [
                "//*[local-name()='TomadorServico']/*[local-name()='RazaoSocial']",
                "//*[local-name()='Tomador']/*[local-name()='RazaoSocial']",
            ]),
            'tomador_documento' => $this->firstNodeValue($xpath, [
                "//*[local-name()='TomadorServico']//*[local-name()='Cnpj']",
                "//*[local-name()='TomadorServico']//*[local-name()='Cpf']",
                "//*[local-name()='Tomador']//*[local-name()='Cnpj']",
                "//*[local-name()='Tomador']//*[local-name()='Cpf']",
            ]),
            'discriminacao' => $this->firstNodeValue($xpath, [
                "//*[local-name()='Servico']/*[local-name()='Discriminacao']",
                "//*[local-name()='Discriminacao']",
            ]),
            'valor_servicos' => $this->firstNodeValue($xpath, [
                "//*[local-name()='Servico']//*[local-name()='ValorServicos']",
                "//*[local-name()='ValorServicos']",
            ]),
            'valor_liquido' => $this->firstNodeValue($xpath, [
                "//*[local-name()='Servico']//*[local-name()='ValorLiquidoNfse']",
                "//*[local-name()='ValorLiquidoNfse']",
            ]),
        ];
    }

    private function buildHtml(array $data): string
    {
        $fields = array_map(
            static fn (?string $value): string => htmlspecialchars(trim((string) $value) !== '' ? (string) $value : '-', ENT_QUOTES, 'UTF-8'),
            $data
        );

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
    .wrap { border: 1px solid #111827; padding: 24px; }
    h1 { margin: 0 0 12px; font-size: 20px; }
    h2 { margin: 20px 0 8px; font-size: 14px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
    .grid { width: 100%; border-collapse: collapse; }
    .grid td { padding: 6px 8px; vertical-align: top; border: 1px solid #e5e7eb; }
    .label { font-size: 10px; text-transform: uppercase; color: #6b7280; display: block; margin-bottom: 4px; }
    .box { min-height: 52px; }
    .mono { font-family: DejaVu Sans Mono, monospace; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>DANFSe - Belém</h1>
    <table class="grid">
      <tr>
        <td><span class="label">Numero</span><div class="box mono">{$fields['numero']}</div></td>
        <td><span class="label">Codigo de verificacao</span><div class="box mono">{$fields['codigo_verificacao']}</div></td>
        <td><span class="label">Data de emissao</span><div class="box">{$fields['data_emissao']}</div></td>
      </tr>
    </table>
    <h2>Prestador</h2>
    <table class="grid">
      <tr>
        <td><span class="label">Razao social</span><div class="box">{$fields['prestador_razao_social']}</div></td>
        <td><span class="label">CNPJ</span><div class="box mono">{$fields['prestador_cnpj']}</div></td>
        <td><span class="label">IM</span><div class="box mono">{$fields['prestador_im']}</div></td>
      </tr>
    </table>
    <h2>Tomador</h2>
    <table class="grid">
      <tr>
        <td><span class="label">Razao social</span><div class="box">{$fields['tomador_razao_social']}</div></td>
        <td><span class="label">Documento</span><div class="box mono">{$fields['tomador_documento']}</div></td>
      </tr>
    </table>
    <h2>Servico</h2>
    <table class="grid">
      <tr>
        <td colspan="2"><span class="label">Discriminacao</span><div class="box">{$fields['discriminacao']}</div></td>
      </tr>
      <tr>
        <td><span class="label">Valor servicos</span><div class="box mono">{$fields['valor_servicos']}</div></td>
        <td><span class="label">Valor liquido</span><div class="box mono">{$fields['valor_liquido']}</div></td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;
    }

    private function firstNodeValue(\DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)?->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
