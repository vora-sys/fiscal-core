<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Renderers\NFSe;

use Com\Tecnick\Barcode\Barcode;
use DOMAttr;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use Dompdf\Dompdf;
use Dompdf\Options;
use DOMXPath;
use RuntimeException;
use sabbajohn\FiscalCore\Contracts\MunicipalDanfseRendererInterface;

final class NacionalDanfseRenderer implements MunicipalDanfseRendererInterface
{
    private const NATIONAL_PUBLIC_QUERY_URL = 'https://www.nfse.gov.br/ConsultaPublica/';

    public function render(string $xmlNfse): string
    {
        $data = $this->extractDocumentData($xmlNfse);
        $html = $this->buildHtml($data);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function extractDocumentData(string $xmlNfse): array
    {
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xmlNfse)) {
            throw new RuntimeException('XML final da NFSe invalido para gerar o DANFSe nacional.');
        }

        $xpath = new DOMXPath($dom);

        $identificacao = [
            'municipio_ambiente' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='infNFSe']/*[local-name()='xLocEmi']"),
                $this->nodeValue($xpath, "//*[local-name()='infNFSe']/*[local-name()='UF']"),
            ]),
            'ambiente_gerador' => $this->nodeValue($xpath, "//*[local-name()='infNFSe']/*[local-name()='ambGer']"),
            'tipo_ambiente' => $this->mapAmbiente($this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='tpAmb']")),
            'chave_acesso' => $this->normalizeChaveAcesso($this->attributeValue($xpath, "//*[local-name()='infNFSe']/@Id")),
            'numero_nfse' => $this->nodeValue($xpath, "//*[local-name()='infNFSe']/*[local-name()='nNFSe']"),
            'competencia' => $this->formatDate($this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='dCompet']")),
            'data_hora_emissao_nfse' => $this->formatDateTime($this->nodeValue($xpath, "//*[local-name()='infNFSe']/*[local-name()='dhProc']")),
            'numero_dps' => $this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='nDPS']"),
            'serie_dps' => $this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='serie']"),
            'data_hora_emissao_dps' => $this->formatDateTime($this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='dhEmi']")),
            'emitente_nfse' => $this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='emit']/*[local-name()='xNome']"),
            'situacao' => $this->resolveSituacao($xpath),
            'finalidade' => $this->mapFinalidade($this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='finNFSe']")),
        ];

        $prestador = $this->extractPartyData($xpath, 'emit', true);
        $prestador['simples_nacional'] = $this->mapSimplesNacional($this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='emit']/*[local-name()='CRT']"));
        $prestador['regime_apuracao_sn'] = $this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='emit']/*[local-name()='regApTribSN']");

        $serviceDescription = $this->joinParagraphs([
            $this->nodeValue($xpath, "//*[local-name()='xServ']"),
            $this->nodeValue($xpath, "//*[local-name()='xInfComp']"),
        ]);
        [$federalRetentions, $federalRetentionTotal] = $this->collectFederalRetentions($xpath);

        $service = [
            'codigo_tributacao' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='cTribNac']"),
                $this->nodeValue($xpath, "//*[local-name()='cTribMun']"),
            ]),
            'codigo_nbs' => $this->nodeValue($xpath, "//*[local-name()='cNBS']"),
            'local_prestacao' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='xLocPrestacao']"),
                $this->nodeValue($xpath, "//*[local-name()='UFPrest']"),
                $this->nodeValue($xpath, "//*[local-name()='xPaisPrestacao']"),
            ]),
            'descricao_tributacao' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='xTribNac']"),
                $this->nodeValue($xpath, "//*[local-name()='xTribMun']"),
            ]),
            'descricao_servico' => $serviceDescription,
        ];

        $issqn = [
            'tipo_tributacao_issqn' => $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='tpTrib']"),
            'municipio_incidencia' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='xMunInc']"),
                $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='UF']"),
                $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='xPais']"),
            ]),
            'regime_especial' => $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='regEspTrib']"),
            'tipo_imunidade' => $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='tpImunidade']"),
            'suspensao_exigibilidade' => $this->mapBooleanCode($this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='suspExig']")),
            'processo_suspensao' => $this->nodeValue($xpath, "//*[local-name()='tribISSQN']/*[local-name()='nProcSusp']"),
            'beneficio_municipal' => $this->nodeValue($xpath, "//*[local-name()='BM']/*[local-name()='nBM']"),
            'calculo_bm' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='BM']/*[local-name()='pRedBCBM']")),
            'deducoes_reducoes' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vDeducao']")),
            'desconto_incondicionado' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vDescIncond']")),
            'base_calculo' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vBC']")),
            'aliquota_aplicada' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='pAliq']")),
            'retencao_issqn' => $this->mapIssRetido($this->nodeValue($xpath, "//*[local-name()='tpRetISSQN']")),
            'issqn_apurado' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vISSQN']")),
        ];

        $federal = [
            'base_calculo' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='tribFed']/*[local-name()='vBC']")),
            'aliquota' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='tribFed']/*[local-name()='pTotTrib']")),
            'valor_total' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='tribFed']/*[local-name()='vTotTrib']")),
            'retencoes' => $federalRetentions,
            'descricao_retencoes' => $this->joinParagraphs($federalRetentions),
        ];

        $ibsCbs = [
            'cst_classificacao' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='CST']"),
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='cClassTrib']"),
            ]),
            'indicador_operacao_incidencia' => $this->joinNonEmpty(' / ', [
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='indOper']"),
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='cMunInc']"),
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='xMunInc']"),
                $this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='UF']"),
            ]),
            'exclusoes_reducoes_bc' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='vRedBC']")),
            'base_calculo_apos_reducoes' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='vBC']")),
            'reducoes_aliquota' => $this->joinNonEmpty(' / ', [
                $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='pRedAliqIBSMun']")),
                $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='pRedAliqCBS']")),
            ]),
            'aliquota_ibs_estadual_municipal' => $this->joinNonEmpty(' / ', [
                $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='uf']/*[local-name()='pIBS']")),
                $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='mun']/*[local-name()='pIBS']")),
            ]),
            'aliquota_efetiva_ibs_municipal' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='mun']/*[local-name()='pAliqEfet']")),
            'valor_apurado_ibs_municipal' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='gIBSMunTot']/*[local-name()='vIBSMun']")),
            'aliquota_efetiva_ibs_estadual' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='uf']/*[local-name()='pAliqEfet']")),
            'valor_apurado_ibs_estadual' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='gIBSUFTot']/*[local-name()='vIBSUF']")),
            'valor_total_ibs' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='gIBS']/*[local-name()='vIBSTot']")),
            'aliquota_cbs' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='fed']/*[local-name()='pCBS']")),
            'aliquota_efetiva_cbs' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='IBSCBS']/*[local-name()='fed']/*[local-name()='pAliqEfet']")),
            'valor_total_cbs' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='gCBS']/*[local-name()='vCBS']")),
        ];

        $valorLiquido = $this->parseDecimal($this->nodeValue($xpath, "//*[local-name()='vLiq']"));
        $valorTotalIbsCbs = $this->sumDecimals([
            $this->nodeValue($xpath, "//*[local-name()='gIBS']/*[local-name()='vIBSTot']"),
            $this->nodeValue($xpath, "//*[local-name()='gCBS']/*[local-name()='vCBS']"),
        ]);

        $totals = [
            'valor_operacao_servico' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vServPrest']/*[local-name()='vServ']")),
            'desconto_incondicionado' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vDescIncond']")),
            'desconto_condicionado' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vDescCond']")),
            'total_retencoes' => $this->formatDecimal(($this->parseDecimal($this->nodeValue($xpath, "//*[local-name()='vRetISSQN']")) + $federalRetentionTotal) ?: null),
            'valor_liquido_nfse' => $this->formatDecimal($this->nodeValue($xpath, "//*[local-name()='vLiq']")),
            'total_ibs_cbs' => $this->formatDecimal($valorTotalIbsCbs ?: null),
            'valor_liquido_mais_ibs_cbs' => $this->formatDecimal(($valorLiquido + $valorTotalIbsCbs) ?: null),
        ];

        $complementares = [
            'imovel' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='imovel']/*[local-name()='inscricaoImob']"),
                $this->nodeValue($xpath, "//*[local-name()='imovel']/*[local-name()='end']/*[local-name()='xLgr']"),
            ]),
            'obra' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='obra']/*[local-name()='cObra']"),
                $this->nodeValue($xpath, "//*[local-name()='obra']/*[local-name()='ART']"),
            ]),
            'evento' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='evento']/*[local-name()='descEvento']"),
                $this->nodeValue($xpath, "//*[local-name()='evento']/*[local-name()='xEvento']"),
            ]),
            'informacoes_complementares' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='infAdFisco']"),
                $this->nodeValue($xpath, "//*[local-name()='infCpl']"),
                $this->nodeValue($xpath, "//*[local-name()='xInfComp']"),
            ]),
            'informacoes_administracao' => $this->joinParagraphs([
                $this->nodeValue($xpath, "//*[local-name()='infMun']"),
                $this->nodeValue($xpath, "//*[local-name()='xInfMun']"),
            ]),
            'totais_aproximados_tributos' => $this->nodeValue($xpath, "//*[local-name()='xTotTrib']"),
        ];

        $statusBadges = array_values(array_filter([
            $this->isHomologacao($xpath) ? 'NFS-e SEM VALIDADE JURIDICA' : null,
            $this->isCancelled($xpath) ? 'NFS-E CANCELADA' : null,
            $this->isSubstituted($xpath) ? 'NFS-E SUBSTITUIDA' : null,
        ]));

        $qrCodeUrl = $this->buildQrCodeQueryUrl(
            $identificacao['chave_acesso'],
            $prestador['documento'],
            $identificacao['numero_dps'],
            $identificacao['serie_dps'],
            $identificacao['municipio_ambiente']
        );

        return [
            'identificacao' => $identificacao,
            'prestador' => $prestador,
            'tomador' => $this->extractPartyData($xpath, 'toma', true),
            'destinatario' => $this->extractPartyData($xpath, 'dest', false),
            'intermediario' => $this->extractPartyData($xpath, 'interm', true),
            'servico' => $service,
            'issqn' => $issqn,
            'federal' => $federal,
            'ibs_cbs' => $ibsCbs,
            'totais' => $totals,
            'complementares' => $complementares,
            'status_badges' => $statusBadges,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_svg' => $this->buildQrCodeSvg($qrCodeUrl),
            'qr_code_hint' => 'A autenticidade desta NFS-e pode ser verificada pela leitura deste codigo QR ou pela consulta da chave de acesso no portal nacional da NFS-e.',
        ];
    }

    private function buildHtml(array $data): string
    {
        $identificacaoRows = $this->renderRows($data['identificacao'], 3);
        $prestadorRows = $this->renderRows($data['prestador'], 3);
        $tomadorRows = $this->renderRows($data['tomador'], 3);
        $destinatarioRows = $this->renderRows($data['destinatario'], 3);
        $intermediarioRows = $this->renderRows($data['intermediario'], 3);
        $servicoRows = $this->renderRows($data['servico'], 2);
        $issqnRows = $this->renderRows($data['issqn'], 3);
        $federalRows = $this->renderRows($data['federal'], 2);
        $ibsCbsRows = $this->renderRows($data['ibs_cbs'], 2);
        $totaisRows = $this->renderRows($data['totais'], 3);
        $complementaresRows = $this->renderRows($data['complementares'], 1);
        $statusBadges = $this->renderStatusBadges($data['status_badges']);
        $destinatarioSection = $this->renderSection('Destinatario da Operacao', $destinatarioRows);
        $intermediarioSection = $this->renderSection('Intermediario da Operacao', $intermediarioRows);
        $qrCodeSvg = $data['qr_code_svg'] !== '' ? $data['qr_code_svg'] : '<div class="qr-placeholder">QR indisponivel</div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>DANFSe Nacional</title>
  <style>
    @page { margin: 0.15cm; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: DejaVu Sans, sans-serif;
      color: #111827;
      font-size: 7pt;
      line-height: 1.22;
    }
    .page {
      width: 100%;
      min-height: 100%;
      border: 1pt solid #111827;
      padding: 0.18cm;
    }
    .header {
      width: 100%;
      border: 0.5pt solid #111827;
      background: #f1f1f1;
      padding: 0.18cm;
      margin-bottom: 0.12cm;
    }
    .header-table {
      width: 100%;
      border-collapse: collapse;
    }
    .header-table td {
      vertical-align: top;
    }
    .title {
      font-size: 10pt;
      font-weight: bold;
      margin: 0;
      text-transform: uppercase;
    }
    .subtitle {
      font-size: 7pt;
      margin-top: 0.05cm;
    }
    .status-badges {
      margin-top: 0.08cm;
    }
    .status-badge {
      display: inline-block;
      margin-right: 0.08cm;
      margin-bottom: 0.04cm;
      padding: 0.02cm 0.08cm;
      border: 0.5pt solid #7f1d1d;
      color: #7f1d1d;
      font-size: 6.5pt;
      font-weight: bold;
      text-transform: uppercase;
    }
    .status-badge.homologacao {
      color: #b91c1c;
      border-color: #b91c1c;
    }
    .qr-cell {
      width: 3.6cm;
      text-align: center;
    }
    .qr-wrap svg {
      width: 2.65cm;
      height: 2.65cm;
    }
    .qr-note {
      margin-top: 0.06cm;
      font-size: 6pt;
      line-height: 1.15;
    }
    .section {
      margin-bottom: 0.10cm;
      border: 0.5pt solid #111827;
    }
    .section-title {
      background: #f1f1f1;
      border-bottom: 0.5pt solid #111827;
      font-size: 7pt;
      font-weight: bold;
      padding: 0.05cm 0.08cm;
      text-transform: uppercase;
    }
    table.grid {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .grid td {
      width: 33.33%;
      border-right: 0.5pt solid #111827;
      border-bottom: 0.5pt solid #111827;
      vertical-align: top;
      padding: 0.06cm 0.08cm;
    }
    .grid.two td { width: 50%; }
    .grid.one td { width: 100%; }
    .grid td:last-child { border-right: none; }
    .grid tr:last-child td { border-bottom: none; }
    .field-label {
      display: block;
      font-size: 6pt;
      font-weight: bold;
      text-transform: uppercase;
      margin-bottom: 0.02cm;
    }
    .field-value {
      min-height: 0.34cm;
      white-space: pre-wrap;
      word-break: break-word;
    }
    .mono {
      font-family: DejaVu Sans Mono, monospace;
      letter-spacing: 0.01cm;
    }
    .highlight {
      background: #f1f1f1;
      font-weight: bold;
    }
    .qr-placeholder {
      width: 2.65cm;
      height: 2.65cm;
      border: 0.5pt dashed #6b7280;
      font-size: 6pt;
      padding-top: 1.1cm;
    }
  </style>
</head>
<body>
  <div class="page">
    <div class="header">
      <table class="header-table">
        <tr>
          <td>
            <div class="title">Documento Auxiliar da NFS-e</div>
            <div class="subtitle">DANFSe padrao nacional</div>
            {$statusBadges}
          </td>
          <td class="qr-cell">
            <div class="qr-wrap">{$qrCodeSvg}</div>
            <div class="qr-note">{$this->escape($data['qr_code_hint'])}</div>
          </td>
        </tr>
      </table>
    </div>

    {$this->renderSection('Identificacao da NFS-e', $identificacaoRows)}
    {$this->renderSection('Prestador / Fornecedor', $prestadorRows)}
    {$this->renderSection('Tomador / Adquirente da Operacao', $tomadorRows)}
    {$destinatarioSection}
    {$intermediarioSection}
    {$this->renderSection('Servico Prestado', $servicoRows, 'two')}
    {$this->renderSection('Tributacao Municipal (ISSQN)', $issqnRows)}
    {$this->renderSection('Tributacao Federal (Exceto CBS)', $federalRows, 'two')}
    {$this->renderSection('Tributacao IBS / CBS', $ibsCbsRows, 'two')}
    {$this->renderSection('Valor Total da NFS-e', $totaisRows, 'three', true)}
    {$this->renderSection('Informacoes Complementares', $complementaresRows, 'one')}
  </div>
</body>
</html>
HTML;
    }

    private function extractPartyData(DOMXPath $xpath, string $nodeName, bool $includeMunicipalIndicator): array
    {
        $base = "//*[local-name()='{$nodeName}']";

        $municipio = $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='xMun']")
            ?? $this->nodeValue($xpath, $base."/*[local-name()='endNac']/*[local-name()='xMun']")
            ?? $this->nodeValue($xpath, $base."/*[local-name()='xMun']");
        $uf = $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='UF']")
            ?? $this->nodeValue($xpath, $base."/*[local-name()='endNac']/*[local-name()='UF']")
            ?? $this->nodeValue($xpath, $base."/*[local-name()='UF']");
        $codigoMunicipio = $this->nodeValue($xpath, $base."/*[local-name()='endNac']/*[local-name()='cMun']")
            ?? $this->nodeValue($xpath, $base."/*[local-name()='cMun']");
        $cep = $this->formatCep($this->nodeValue($xpath, $base."/*[local-name()='endNac']/*[local-name()='CEP']"))
            ?? $this->formatCep($this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='CEP']"));

        $data = [
            'documento' => $this->firstNodeValue($xpath, [
                $base."/*[local-name()='CNPJ']",
                $base."/*[local-name()='CPF']",
                $base."/*[local-name()='NIF']",
            ]),
            'indicador_municipal' => $includeMunicipalIndicator
                ? $this->firstNodeValue($xpath, [
                    $base."/*[local-name()='IM']",
                    $base."/*[local-name()='IMTomador']",
                    $base."/*[local-name()='IMIntermed']",
                ])
                : null,
            'telefone' => $this->firstNodeValue($xpath, [
                $base."/*[local-name()='fone']",
                $base."/*[local-name()='telefone']",
            ]),
            'nome' => $this->firstNodeValue($xpath, [
                $base."/*[local-name()='xNome']",
                $base."/*[local-name()='xRazao']",
            ]),
            'municipio_uf' => $this->joinNonEmpty(' / ', [$municipio, $uf]),
            'codigo_ibge_cep' => $this->joinNonEmpty(' / ', [$codigoMunicipio, $cep]),
            'endereco' => $this->joinNonEmpty(', ', array_filter([
                $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='xLgr']"),
                $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='nro']"),
                $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='xCpl']"),
                $this->nodeValue($xpath, $base."/*[local-name()='end']/*[local-name()='xBairro']"),
            ], static fn (?string $value): bool => $value !== null)),
            'email' => $this->firstNodeValue($xpath, [
                $base."/*[local-name()='email']",
                $base."/*[local-name()='xEmail']",
            ]),
        ];

        if (! $includeMunicipalIndicator) {
            unset($data['indicador_municipal']);
        }

        return $data;
    }

    private function collectFederalRetentions(DOMXPath $xpath): array
    {
        $fields = [
            'PIS' => "//*[local-name()='tribFed']/*[local-name()='vRetPIS']",
            'COFINS' => "//*[local-name()='tribFed']/*[local-name()='vRetCOFINS']",
            'CSLL' => "//*[local-name()='tribFed']/*[local-name()='vRetCSLL']",
            'IRRF' => "//*[local-name()='tribFed']/*[local-name()='vRetIRRF']",
            'INSS' => "//*[local-name()='tribFed']/*[local-name()='vRetINSS']",
        ];

        $items = [];
        $total = 0.0;
        foreach ($fields as $label => $query) {
            $rawValue = $this->nodeValue($xpath, $query);
            $value = $this->formatDecimal($rawValue);
            if ($value !== null) {
                $items[] = $label.': '.$value;
                $total += $this->parseDecimal($rawValue);
            }
        }

        return [$items, $total];
    }

    private function renderRows(array $fields, int $columns): array
    {
        $items = [];
        foreach ($fields as $label => $value) {
            if (is_array($value)) {
                $value = $this->joinParagraphs($value);
            }

            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $items[] = [
                'label' => $this->humanizeLabel($label),
                'value' => (string) $value,
                'mono' => $this->isMonospaceField($label),
                'highlight' => in_array($label, ['valor_liquido_mais_ibs_cbs', 'situacao'], true),
            ];
        }

        if ($items === []) {
            return [];
        }

        return array_chunk($items, $columns);
    }

    private function renderSection(string $title, array $rows, string $gridClass = 'three', bool $highlightLastField = false): string
    {
        if ($rows === []) {
            return '';
        }

        $classMap = [
            'one' => 'one',
            'two' => 'two',
            'three' => 'three',
        ];
        $tableClass = $classMap[$gridClass] ?? 'three';
        $html = '<div class="section"><div class="section-title">'.$this->escape($title).'</div><table class="grid '.$tableClass.'">';

        foreach ($rows as $rowIndex => $row) {
            $html .= '<tr>';
            foreach ($row as $fieldIndex => $field) {
                $classes = [];
                if ($field['mono']) {
                    $classes[] = 'mono';
                }
                if ($field['highlight'] || ($highlightLastField && $rowIndex === array_key_last($rows) && $fieldIndex === array_key_last($row))) {
                    $classes[] = 'highlight';
                }
                $classAttr = $classes !== [] ? ' class="'.implode(' ', $classes).'"' : '';

                $html .= '<td'.$classAttr.'>';
                $html .= '<span class="field-label">'.$this->escape($field['label']).'</span>';
                $html .= '<div class="field-value">'.$this->escape($field['value']).'</div>';
                $html .= '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table></div>';

        return $html;
    }

    private function renderStatusBadges(array $badges): string
    {
        if ($badges === []) {
            return '';
        }

        $html = '<div class="status-badges">';
        foreach ($badges as $badge) {
            $class = str_contains($badge, 'SEM VALIDADE') ? 'status-badge homologacao' : 'status-badge';
            $html .= '<span class="'.$class.'">'.$this->escape($badge).'</span>';
        }
        $html .= '</div>';

        return $html;
    }

    private function buildQrCodeSvg(string $contents): string
    {
        if ($contents === '') {
            return '';
        }

        try {
            $barcode = new Barcode;
            $qr = $barcode->getBarcodeObj('QRCODE,H', $contents, -4, -4, 'black');

            return $qr->getSvgCode();
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildQrCodeQueryUrl(
        ?string $chaveAcesso,
        ?string $documentoPrestador,
        ?string $numeroDps,
        ?string $serieDps,
        ?string $municipioAmbiente
    ): string {
        $params = array_filter([
            'chaveAcesso' => $chaveAcesso,
            'cpfCnpjPrestador' => $this->onlyDigits($documentoPrestador),
            'numeroDps' => $numeroDps,
            'serieDps' => $serieDps,
            'municipio' => $municipioAmbiente,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        if ($params === []) {
            return self::NATIONAL_PUBLIC_QUERY_URL;
        }

        return self::NATIONAL_PUBLIC_QUERY_URL.'?'.http_build_query($params);
    }

    private function firstNodeValue(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $value = $this->nodeValue($xpath, $query);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nodeValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if (! $nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (! $node instanceof DOMNode) {
            return null;
        }

        $value = trim((string) $node->textContent);

        return $value !== '' ? $value : null;
    }

    private function attributeValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if (! $nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (! $node instanceof DOMAttr) {
            return null;
        }

        $value = trim($node->value);

        return $value !== '' ? $value : null;
    }

    private function resolveSituacao(DOMXPath $xpath): string
    {
        if ($this->isCancelled($xpath)) {
            return 'Cancelada';
        }

        if ($this->isSubstituted($xpath)) {
            return 'Substituida';
        }

        return $this->firstNodeValue($xpath, [
            "//*[local-name()='xSitNFS']",
            "//*[local-name()='xSitNFSe']",
            "//*[local-name()='sitNFSe']",
        ]) ?? 'Autorizada';
    }

    private function isCancelled(DOMXPath $xpath): bool
    {
        return $this->hasAnyNode($xpath, [
            "//*[contains(local-name(), 'Canc')]",
            "//*[contains(translate(text(), 'cancelada', 'CANCELADA'), 'CANCELADA')]",
        ]);
    }

    private function isSubstituted(DOMXPath $xpath): bool
    {
        return $this->hasAnyNode($xpath, [
            "//*[contains(local-name(), 'Subst')]",
            "//*[contains(translate(text(), 'substituida', 'SUBSTITUIDA'), 'SUBSTITUIDA')]",
        ]);
    }

    private function isHomologacao(DOMXPath $xpath): bool
    {
        return $this->nodeValue($xpath, "//*[local-name()='infDPS']/*[local-name()='tpAmb']") === '2';
    }

    private function hasAnyNode(DOMXPath $xpath, array $queries): bool
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
                return true;
            }
        }

        return false;
    }

    private function humanizeLabel(string $label): string
    {
        $label = str_replace('_', ' ', $label);

        return mb_strtoupper($label, 'UTF-8');
    }

    private function isMonospaceField(string $label): bool
    {
        return in_array($label, [
            'chave_acesso',
            'numero_nfse',
            'numero_dps',
            'serie_dps',
            'documento',
            'indicador_municipal',
            'codigo_ibge_cep',
            'codigo_tributacao',
            'codigo_nbs',
            'beneficio_municipal',
        ], true);
    }

    private function joinNonEmpty(string $separator, array $values): ?string
    {
        $values = array_values(array_filter(array_map(static function (mixed $value): ?string {
            if (! is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }, $values)));

        if ($values === []) {
            return null;
        }

        return implode($separator, $values);
    }

    private function joinParagraphs(array $values): ?string
    {
        return $this->joinNonEmpty("\n", $values);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function mapAmbiente(?string $code): ?string
    {
        return match ($code) {
            '1' => 'Producao',
            '2' => 'Homologacao',
            default => $code,
        };
    }

    private function mapFinalidade(?string $code): ?string
    {
        return match ($code) {
            '1' => 'Normal',
            '2' => 'Substituicao',
            '3' => 'Ajuste',
            default => $code,
        };
    }

    private function mapSimplesNacional(?string $code): ?string
    {
        return match ($code) {
            '1' => 'Simples Nacional',
            '2' => 'Excesso sublimite',
            '3' => 'Regime normal',
            default => $code,
        };
    }

    private function mapIssRetido(?string $code): ?string
    {
        return match ($code) {
            '1' => 'Nao',
            '2' => 'Sim',
            default => $code,
        };
    }

    private function mapBooleanCode(?string $code): ?string
    {
        return match ($code) {
            '0', '1' => $code === '1' ? 'Sim' : 'Nao',
            default => $code,
        };
    }

    private function normalizeChaveAcesso(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/^NFS/i', '', trim($value));
    }

    private function formatDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('d/m/Y', $timestamp) : $value;
    }

    private function formatDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('d/m/Y H:i:s', $timestamp) : $value;
    }

    private function formatCep(?string $value): ?string
    {
        $digits = $this->onlyDigits($value);
        if ($digits === null) {
            return null;
        }

        if (strlen($digits) === 8) {
            return substr($digits, 0, 5).'-'.substr($digits, 5);
        }

        return $digits;
    }

    private function onlyDigits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits !== '' ? $digits : null;
    }

    private function parseDecimal(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function sumDecimals(array $values): float
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += $this->parseDecimal($value);
        }

        return $sum;
    }

    private function formatDecimal(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $number = $this->parseDecimal($value);

        return number_format($number, 2, ',', '.');
    }
}
