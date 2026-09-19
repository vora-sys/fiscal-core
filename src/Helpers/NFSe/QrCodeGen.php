<?php

namespace sabbajohn\FiscalCore\Helpers\NFSe;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode as EndroidQrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class QrCodeGen
{
    private const CONSULTA_URL = 'https://www.nfse.gov.br/ConsultaPublica';

    public static function url(string $chave): string
    {
        $chave = trim($chave);

        $url = self::CONSULTA_URL.'?tpc=1&chave='.$chave;

        return $url;
    }

    public static function image(string $chave, int $size = 180): string
    {
        $url = self::url($chave);

        $qr = new EndroidQrCode(
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 4,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $writer = new PngWriter;
        $result = $writer->write($qr);

        return 'data:image/png;base64,'.base64_encode($result->getString());
    }
}
