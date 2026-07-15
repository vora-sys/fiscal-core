<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Renderers\NFCe;

use Com\Tecnick\Barcode\Barcode;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use sabbajohn\FiscalCore\Support\NfceThermalLayout;

final class ThermalDanfceRenderer
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function render(string $xmlNfce, array $context = []): string
    {
        $layout = NfceThermalLayout::normalize(is_array($context['layout_cupom'] ?? null) ? $context['layout_cupom'] : null);
        $data = $this->extractDocumentData($xmlNfce, $context, $layout);
        $html = $this->buildHtmlFromData($data, $layout);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $paperWidthMm = (float) $layout['paper']['width_mm'];
        $paperHeightMm = $this->estimatePaperHeightMm($data, $layout);
        $paperSize = [0.0, 0.0, $this->mmToPoints($paperWidthMm), $this->mmToPoints($paperHeightMm)];

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paperSize);
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function buildHtml(string $xmlNfce, array $context = []): string
    {
        $layout = NfceThermalLayout::normalize(is_array($context['layout_cupom'] ?? null) ? $context['layout_cupom'] : null);
        $data = $this->extractDocumentData($xmlNfce, $context, $layout);

        return $this->buildHtmlFromData($data, $layout);
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $layout
     * @return array<string,mixed>
     */
    private function extractDocumentData(string $xmlNfce, array $context, array $layout): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xmlNfce)) {
            throw new RuntimeException('XML final da NFC-e invalido para gerar o DANFCE termico.');
        }

        $xpath = new \DOMXPath($dom);

        $emitenteNome = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='xFant']",
            "//*[local-name()='emit']/*[local-name()='xNome']",
        ]);
        $emitenteRazaoSocial = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='xNome']",
        ]);
        $cnpj = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='CNPJ']",
        ]);
        $ie = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='IE']",
        ]);
        $logradouro = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='enderEmit']/*[local-name()='xLgr']",
        ]);
        $numero = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='enderEmit']/*[local-name()='nro']",
        ]);
        $bairro = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='enderEmit']/*[local-name()='xBairro']",
        ]);
        $municipio = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='enderEmit']/*[local-name()='xMun']",
        ]);
        $uf = $this->firstNodeValue($xpath, [
            "//*[local-name()='emit']/*[local-name()='enderEmit']/*[local-name()='UF']",
        ]);

        $displayName = $this->firstNonEmpty([
            $this->sanitizeText($context['nome_fantasia'] ?? null),
            $emitenteNome,
            $this->sanitizeText($context['razao_social'] ?? null),
            $emitenteRazaoSocial,
        ]) ?? 'EMITENTE';

        $headerLines = [
            $displayName,
            trim(sprintf(
                'CNPJ: %s%s',
                $this->formatCnpj($cnpj),
                $ie !== null ? ' IE: '.$ie : ''
            )),
            $this->joinNonEmpty([$logradouro, $numero], ', '),
            $bairro,
            $this->joinNonEmpty([$municipio, $uf], '-'),
        ];

        $items = [];
        $detNodes = $xpath->query("//*[local-name()='det']");
        if ($detNodes instanceof \DOMNodeList) {
            foreach ($detNodes as $detNode) {
                $itemXPath = new \DOMXPath($detNode->ownerDocument);
                $productNode = $itemXPath->query("./*[local-name()='prod']", $detNode)->item(0);
                $itemNumber = trim((string) ($detNode->attributes?->getNamedItem('nItem')?->nodeValue ?? ''));

                $description = $this->queryString($itemXPath, "./*[local-name()='prod']/*[local-name()='xProd']", $detNode);
                $quantity = $this->queryString($itemXPath, "./*[local-name()='prod']/*[local-name()='qCom']", $detNode);
                $unit = $this->queryString($itemXPath, "./*[local-name()='prod']/*[local-name()='uCom']", $detNode);
                $unitPrice = $this->queryString($itemXPath, "./*[local-name()='prod']/*[local-name()='vUnCom']", $detNode);
                $total = $this->queryString($itemXPath, "./*[local-name()='prod']/*[local-name()='vProd']", $detNode);

                if ($description === null && $productNode === null) {
                    continue;
                }

                $items[] = [
                    'number' => $itemNumber !== '' ? $itemNumber : (string) (count($items) + 1),
                    'description' => $description ?? 'Item',
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
            }
        }

        $payments = [];
        $paymentNodes = $xpath->query("//*[local-name()='detPag']");
        if ($paymentNodes instanceof \DOMNodeList) {
            foreach ($paymentNodes as $paymentNode) {
                $code = $this->queryString($xpath, "./*[local-name()='tPag']", $paymentNode);
                $amount = $this->queryString($xpath, "./*[local-name()='vPag']", $paymentNode);
                if ($code === null && $amount === null) {
                    continue;
                }

                $payments[] = [
                    'label' => $this->paymentLabel($code),
                    'amount' => $amount,
                ];
            }
        }

        $documentNumber = $this->firstNodeValue($xpath, [
            "//*[local-name()='ide']/*[local-name()='nNF']",
        ]);
        $series = $this->firstNodeValue($xpath, [
            "//*[local-name()='ide']/*[local-name()='serie']",
        ]);
        $issuedAt = $this->firstNodeValue($xpath, [
            "//*[local-name()='ide']/*[local-name()='dhEmi']",
        ]);
        $protocol = $this->firstNodeValue($xpath, [
            "//*[local-name()='protNFe']/*[local-name()='infProt']/*[local-name()='nProt']",
        ]);
        $authorizedAt = $this->firstNodeValue($xpath, [
            "//*[local-name()='protNFe']/*[local-name()='infProt']/*[local-name()='dhRecbto']",
        ]);
        $accessKey = $this->firstNonEmpty([
            $this->stripNfePrefix($this->firstNodeValue($xpath, [
                "//*[local-name()='infNFe']/@Id",
            ])),
            $this->firstNodeValue($xpath, [
                "//*[local-name()='protNFe']/*[local-name()='infProt']/*[local-name()='chNFe']",
            ]),
        ]);
        $qrCodeValue = $this->firstNonEmpty([
            $this->firstNodeValue($xpath, [
                "//*[local-name()='infNFeSupl']/*[local-name()='qrCode']",
            ]),
            $accessKey,
        ]) ?? 'SEM_QRCODE';
        $recipientDocument = $this->firstNodeValue($xpath, [
            "//*[local-name()='dest']/*[local-name()='CPF']",
            "//*[local-name()='dest']/*[local-name()='CNPJ']",
            "//*[local-name()='dest']/*[local-name()='idEstrangeiro']",
        ]);
        $recipientName = $this->firstNodeValue($xpath, [
            "//*[local-name()='dest']/*[local-name()='xNome']",
        ]);
        $recipientMunicipio = $this->firstNodeValue($xpath, [
            "//*[local-name()='dest']/*[local-name()='enderDest']/*[local-name()='xMun']",
        ]);
        $recipientUf = $this->firstNodeValue($xpath, [
            "//*[local-name()='dest']/*[local-name()='enderDest']/*[local-name()='UF']",
        ]);

        $totals = [
            'products' => $this->firstNodeValue($xpath, [
                "//*[local-name()='ICMSTot']/*[local-name()='vProd']",
            ]),
            'discount' => $this->firstNodeValue($xpath, [
                "//*[local-name()='ICMSTot']/*[local-name()='vDesc']",
            ]),
            'total' => $this->firstNodeValue($xpath, [
                "//*[local-name()='ICMSTot']/*[local-name()='vNF']",
            ]),
            'paid' => $this->sumPayments($payments),
            'change' => $this->firstNodeValue($xpath, [
                "//*[local-name()='pag']/*[local-name()='vTroco']",
            ]),
            'taxes' => $this->firstNodeValue($xpath, [
                "//*[local-name()='ICMSTot']/*[local-name()='vTotTrib']",
            ]),
        ];

        $message = $this->firstNodeValue($xpath, [
            "//*[local-name()='infAdic']/*[local-name()='infCpl']",
        ]);

        return [
            'logo_url' => $this->resolveLogoUrl($context['logo_url'] ?? null),
            'header_lines' => array_values(array_filter($headerLines, static fn (?string $line): bool => $line !== null && trim($line) !== '')),
            'document' => [
                'number' => $documentNumber,
                'series' => $series,
                'issued_at' => $issuedAt,
                'protocol' => $protocol,
                'authorized_at' => $authorizedAt,
                'access_key' => $accessKey,
            ],
            'items' => $items,
            'recipient' => [
                'name' => $recipientName,
                'document' => $recipientDocument,
                'location' => $this->joinNonEmpty([$recipientMunicipio, $recipientUf], '/'),
            ],
            'totals' => $totals,
            'payments' => $payments,
            'consultation' => [
                'title' => 'Consulte pela chave de acesso em',
                'portal' => 'www.nfe.fazenda.gov.br/portal',
                'access_key' => $accessKey,
            ],
            'qr_code' => [
                'value' => $qrCodeValue,
                'image_data_uri' => $this->buildQrCodeDataUri($qrCodeValue),
                'size_mm' => (float) $layout['paper']['qr_size_mm'],
            ],
            'ibpt' => [
                'value' => $totals['taxes'],
            ],
            'messages' => [
                'content' => $message,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $layout
     */
    private function buildHtmlFromData(array $data, array $layout): string
    {
        $paper = $layout['paper'];
        $typography = $layout['typography'];
        $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];

        $receiptWidth = (float) $paper['width_mm']
            - (float) $paper['margin_left_mm']
            - (float) $paper['margin_right_mm'];

        $sectionHtml = [];
        foreach ($sections as $section) {
            if (! is_array($section) || ! ($section['enabled'] ?? false)) {
                continue;
            }

            $type = (string) $section['type'];
            $style = $this->sectionStyle($section);
            $content = match ($type) {
                'logo' => $this->renderLogo($data),
                'header' => $this->renderHeader($data),
                'recipient' => $this->renderRecipient($data),
                'items' => $this->renderItems($data),
                'totals' => $this->renderTotals($data, (float) $typography['total_font_pt']),
                'payments' => $this->renderPayments($data),
                'ibpt' => $this->renderIbpt($data),
                'messages' => $this->renderMessages($data),
                'consultation' => $this->renderConsultation($data),
                'qr_code' => $this->renderQrCode($data, $section),
                'protocol_footer' => $this->renderProtocolFooter($data),
                default => '',
            };

            if ($content === '') {
                continue;
            }

            $sectionHtml[] = sprintf(
                '<section class="section section-%s" data-section="%s" style="%s">%s</section>',
                $this->escape($type),
                $this->escape($type),
                $style,
                $content
            );
        }

        return sprintf(
            <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: #111827;
      font-family: DejaVu Sans, sans-serif;
      font-size: %.2fpt;
      line-height: 1.35;
    }
    .receipt {
      width: %.2fmm;
      padding: %.2fmm %.2fmm %.2fmm %.2fmm;
    }
    .section { width: 100%%; }
    .section-title {
      margin: 0 0 1mm;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: %.2fpt;
    }
    .muted { color: #4b5563; }
    .mono { font-family: DejaVu Sans Mono, monospace; font-size: %.2fpt; }
    .divider {
      border: 0;
      border-top: 1px dashed #111827;
      margin: 1mm 0;
    }
    .header-line { margin: 0 0 0.4mm; }
    .header-name { font-weight: 700; font-size: %.2fpt; }
    .meta-row, .payment-row, .total-row {
      width: 100%%;
      border-collapse: collapse;
    }
    .meta-row td, .payment-row td, .total-row td {
      padding: 0.3mm 0;
      vertical-align: top;
    }
    .item {
      padding: 0.8mm 0;
      border-bottom: 1px dotted #9ca3af;
    }
    .item:last-child { border-bottom: 0; }
    .item-desc { font-weight: 700; margin: 0 0 0.5mm; }
    .item-meta { margin: 0; }
    .total-strong { font-weight: 700; font-size: %.2fpt; }
    .qr-image { display: block; }
    .placeholder-box {
      border: 1px dashed #6b7280;
      padding: 3mm;
      text-align: center;
    }
  </style>
</head>
<body>
  <main class="receipt" style="width: %.2fmm;">
    %s
  </main>
</body>
</html>
HTML,
            (float) $typography['base_font_pt'],
            $receiptWidth,
            (float) $paper['margin_top_mm'],
            (float) $paper['margin_right_mm'],
            (float) $paper['margin_bottom_mm'],
            (float) $paper['margin_left_mm'],
            max((float) $typography['base_font_pt'] - 0.5, 7.0),
            (float) $typography['mono_font_pt'],
            max((float) $typography['total_font_pt'], (float) $typography['base_font_pt'] + 1.0),
            (float) $typography['total_font_pt'],
            $receiptWidth,
            implode('', $sectionHtml)
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderLogo(array $data): string
    {
        $logoUrl = $data['logo_url'] ?? null;
        if (! is_string($logoUrl) || $logoUrl === '') {
            return '';
        }

        return sprintf(
            '<img src="%s" alt="Logo da empresa" style="max-width: 65%%; max-height: 16mm;" />',
            $this->escape($logoUrl)
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderHeader(array $data): string
    {
        $lines = [];
        foreach ((array) ($data['header_lines'] ?? []) as $index => $line) {
            $class = $index === 0 ? 'header-line header-name' : 'header-line';
            $lines[] = sprintf('<p class="%s">%s</p>', $class, $this->escape((string) $line));
        }

        $document = (array) ($data['document'] ?? []);
        $lines[] = '<hr class="divider" />';
        $lines[] = '<p class="section-title">Documento Auxiliar da NFC-e</p>';
        $lines[] = sprintf(
            '<table class="meta-row mono"><tr><td>Numero: %s</td><td style="text-align:right;">Serie: %s</td></tr></table>',
            $this->escape((string) ($document['number'] ?? '-')),
            $this->escape((string) ($document['series'] ?? '-'))
        );
        $lines[] = sprintf(
            '<p class="mono">Emissao: %s</p>',
            $this->escape($this->formatDateTime($document['issued_at'] ?? null) ?? '-')
        );

        return implode('', $lines);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderRecipient(array $data): string
    {
        $recipient = is_array($data['recipient'] ?? null) ? $data['recipient'] : [];
        $document = $this->formatCpfCnpj($recipient['document'] ?? null);
        $name = is_string($recipient['name'] ?? null) ? trim((string) $recipient['name']) : '';
        if ($document === '-' && $name === '') {
            return '';
        }

        $rows = ['<hr class="divider" />', '<p class="section-title">Consumidor</p>'];
        $rows[] = sprintf('<p class="header-line">%s</p>', $this->escape($name !== '' ? $name : 'Consumidor identificado'));
        if ($document !== '-') {
            $rows[] = sprintf('<p class="mono">CPF/CNPJ: %s</p>', $this->escape($document));
        }
        if (is_string($recipient['location'] ?? null) && trim((string) $recipient['location']) !== '') {
            $rows[] = sprintf('<p class="muted">%s</p>', $this->escape((string) $recipient['location']));
        }

        return implode('', $rows);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderItems(array $data): string
    {
        $items = (array) ($data['items'] ?? []);
        if ($items === []) {
            return '<div class="placeholder-box muted">Itens nao informados.</div>';
        }

        $rows = ['<hr class="divider" />', '<p class="section-title">Itens</p>'];
        foreach ($items as $item) {
            $item = (array) $item;
            $meta = trim(sprintf(
                '%s %s x %s = %s',
                $item['quantity'] ?? '-',
                $item['unit'] ?? 'UN',
                $this->formatMoney($item['unit_price'] ?? null),
                $this->formatMoney($item['total'] ?? null)
            ));

            $rows[] = sprintf(
                '<div class="item"><p class="item-desc">%s. %s</p><p class="item-meta mono">%s</p></div>',
                $this->escape((string) ($item['number'] ?? '-')),
                $this->escape((string) ($item['description'] ?? 'Item')),
                $this->escape($meta)
            );
        }

        return implode('', $rows);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderTotals(array $data, float $totalFont): string
    {
        $totals = (array) ($data['totals'] ?? []);

        $rows = [
            '<hr class="divider" />',
            '<p class="section-title">Totais</p>',
            $this->totalRow('Subtotal', $this->formatMoney($totals['products'] ?? null)),
        ];

        if ($this->hasMoneyValue($totals['discount'] ?? null)) {
            $rows[] = $this->totalRow('Desconto', $this->formatMoney($totals['discount'] ?? null));
        }

        $rows[] = sprintf(
            '<table class="total-row"><tr><td class="total-strong">TOTAL</td><td class="total-strong" style="text-align:right; font-size: %.2fpt;">%s</td></tr></table>',
            $totalFont,
            $this->escape($this->formatMoney($totals['total'] ?? null))
        );

        return implode('', $rows);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderPayments(array $data): string
    {
        $payments = (array) ($data['payments'] ?? []);
        $totals = (array) ($data['totals'] ?? []);
        $rows = ['<hr class="divider" />', '<p class="section-title">Pagamentos</p>'];

        if ($payments === []) {
            $rows[] = '<p class="muted">Forma de pagamento nao informada.</p>';
        } else {
            foreach ($payments as $payment) {
                $payment = (array) $payment;
                $rows[] = $this->totalRow(
                    (string) ($payment['label'] ?? 'Pagamento'),
                    $this->formatMoney($payment['amount'] ?? null)
                );
            }
        }

        if ($this->hasMoneyValue($totals['paid'] ?? null)) {
            $rows[] = $this->totalRow('Valor pago', $this->formatMoney($totals['paid'] ?? null));
        }

        if ($this->hasMoneyValue($totals['change'] ?? null)) {
            $rows[] = $this->totalRow('Troco', $this->formatMoney($totals['change'] ?? null));
        }

        return implode('', $rows);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderIbpt(array $data): string
    {
        $ibpt = (array) ($data['ibpt'] ?? []);
        if (! $this->hasMoneyValue($ibpt['value'] ?? null)) {
            return '';
        }

        return sprintf(
            '<hr class="divider" /><p class="section-title">Tributos</p><p class="muted">Val. aprox. tributos: %s</p><p class="muted">Fonte: IBPT - Lei 12.741/2012</p>',
            $this->escape($this->formatMoney($ibpt['value'] ?? null))
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderMessages(array $data): string
    {
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $content = trim((string) ($messages['content'] ?? ''));
        if ($content === '') {
            return '';
        }

        return sprintf(
            '<hr class="divider" /><p class="section-title">Mensagens</p><p>%s</p>',
            nl2br($this->escape($content))
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderConsultation(array $data): string
    {
        $consultation = (array) ($data['consultation'] ?? []);

        return sprintf(
            '<hr class="divider" /><p class="section-title">Consulta</p><p>%s</p><p class="mono">%s</p><p class="mono">%s</p>',
            $this->escape((string) ($consultation['title'] ?? 'Consulte pela chave de acesso')),
            $this->escape((string) ($consultation['portal'] ?? 'www.nfe.fazenda.gov.br/portal')),
            $this->escape((string) ($consultation['access_key'] ?? '-'))
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $section
     */
    private function renderQrCode(array $data, array $section): string
    {
        $qrCode = is_array($data['qr_code'] ?? null) ? $data['qr_code'] : [];
        $image = $qrCode['image_data_uri'] ?? null;
        $sizeMm = (float) ($qrCode['size_mm'] ?? 28.0);
        $style = $this->mediaAlignStyle((string) ($section['align'] ?? 'center'));

        $markup = '<div class="placeholder-box muted">QR Code indisponivel.</div>';
        if (is_string($image) && $image !== '') {
            $markup = sprintf(
                '<img class="qr-image" src="%s" alt="QR Code NFC-e" style="width: %.2fmm; height: %.2fmm; %s" />',
                $this->escape($image),
                $sizeMm,
                $sizeMm,
                $style
            );
        }

        return '<hr class="divider" /><p class="section-title">QR Code</p>'.$markup;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderProtocolFooter(array $data): string
    {
        $document = (array) ($data['document'] ?? []);

        return sprintf(
            '<hr class="divider" /><p class="section-title">Protocolo</p><p class="mono">Chave: %s</p><p class="mono">Protocolo: %s</p><p class="mono">Autorizacao: %s</p>',
            $this->escape((string) ($document['access_key'] ?? '-')),
            $this->escape((string) ($document['protocol'] ?? '-')),
            $this->escape($this->formatDateTime($document['authorized_at'] ?? null) ?? '-')
        );
    }

    /**
     * @param  array<string,mixed>  $section
     */
    private function sectionStyle(array $section): string
    {
        $align = (string) ($section['align'] ?? 'left');
        $textAlign = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';

        return sprintf(
            'margin-top: %.2fmm; margin-bottom: %.2fmm; padding-left: %.2fmm; padding-right: %.2fmm; text-align: %s;',
            (float) ($section['spacing_before_mm'] ?? 0.0),
            (float) ($section['spacing_after_mm'] ?? 0.0),
            (float) ($section['padding_left_mm'] ?? 0.0),
            (float) ($section['padding_right_mm'] ?? 0.0),
            $textAlign
        );
    }

    private function mediaAlignStyle(string $align): string
    {
        return match ($align) {
            'left' => 'margin-left: 0; margin-right: auto;',
            'right' => 'margin-left: auto; margin-right: 0;',
            default => 'margin-left: auto; margin-right: auto;',
        };
    }

    private function totalRow(string $label, string $value): string
    {
        return sprintf(
            '<table class="payment-row"><tr><td>%s</td><td style="text-align:right;">%s</td></tr></table>',
            $this->escape($label),
            $this->escape($value)
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $layout
     */
    private function estimatePaperHeightMm(array $data, array $layout): float
    {
        $items = (array) ($data['items'] ?? []);
        $enabledSections = array_values(array_filter(
            (array) ($layout['sections'] ?? []),
            static fn (mixed $section): bool => is_array($section) && ($section['enabled'] ?? false)
        ));

        $baseHeight = 70.0;
        $itemsHeight = count($items) * 9.5;
        $sectionsHeight = count($enabledSections) * 5.0;
        $logoHeight = is_string($data['logo_url'] ?? null) ? 14.0 : 0.0;
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        $messagesHeight = trim((string) ($messages['content'] ?? '')) !== '' ? 10.0 : 0.0;

        return max(120.0, $baseHeight + $itemsHeight + $sectionsHeight + $logoHeight + $messagesHeight);
    }

    private function buildQrCodeDataUri(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $barcode = new Barcode;
        $image = $barcode->getBarcodeObj('QRCODE,H', $trimmed, -4, -4, 'black', [0, 0, 0, 0])->getPngData(false);

        return 'data:image/png;base64,'.base64_encode($image);
    }

    private function paymentLabel(?string $code): string
    {
        return match ($code) {
            '01' => 'Dinheiro',
            '02' => 'Cheque',
            '03' => 'Cartao de credito',
            '04' => 'Cartao de debito',
            '05' => 'Credito loja',
            '10' => 'Vale alimentacao',
            '11' => 'Vale refeicao',
            '12' => 'Vale presente',
            '13' => 'Vale combustivel',
            '15' => 'Boleto bancario',
            '17' => 'PIX',
            '18' => 'Transferencia',
            '19' => 'Programa de fidelidade',
            '90' => 'Sem pagamento',
            default => 'Outro',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $payments
     */
    private function sumPayments(array $payments): ?string
    {
        $total = 0.0;
        $hasValue = false;

        foreach ($payments as $payment) {
            $amount = $payment['amount'] ?? null;
            if (! is_string($amount) && ! is_numeric($amount)) {
                continue;
            }

            $total += (float) $amount;
            $hasValue = true;
        }

        return $hasValue ? number_format($total, 2, '.', '') : null;
    }

    private function hasMoneyValue(mixed $value): bool
    {
        return (is_string($value) && trim($value) !== '' && (float) $value > 0.0)
            || (is_numeric($value) && (float) $value > 0.0);
    }

    private function formatMoney(mixed $value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return 'R$ '.number_format($number, 2, ',', '.');
    }

    private function formatCnpj(?string $value): string
    {
        if ($value === null) {
            return '-';
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 14) {
            return trim($value) !== '' ? trim($value) : '-';
        }

        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digits));
    }

    private function formatCpfCnpj(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '-';
        }

        $raw = trim((string) $value);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11) {
            return vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digits));
        }
        if (strlen($digits) === 14) {
            return $this->formatCnpj($digits);
        }

        return $raw !== '' ? $raw : '-';
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    /**
     * @param  list<?string>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  list<?string>  $values
     */
    private function joinNonEmpty(array $values, string $separator): ?string
    {
        $parts = [];
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return $parts !== [] ? implode($separator, $parts) : null;
    }

    private function sanitizeText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function resolveLogoUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return str_starts_with($trimmed, 'data:image/') ? $trimmed : null;
    }

    private function stripNfePrefix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return str_starts_with(strtoupper($trimmed), 'NFE') ? substr($trimmed, 3) : $trimmed;
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstNodeValue(\DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (! $nodes instanceof \DOMNodeList || $nodes->length === 0) {
                continue;
            }

            $value = trim((string) $nodes->item(0)?->textContent);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function queryString(\DOMXPath $xpath, string $query, \DOMNode $contextNode): ?string
    {
        $nodes = $xpath->query($query, $contextNode);
        if (! $nodes instanceof \DOMNodeList || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value !== '' ? $value : null;
    }

    private function mmToPoints(float $mm): float
    {
        return $mm * 72 / 25.4;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
