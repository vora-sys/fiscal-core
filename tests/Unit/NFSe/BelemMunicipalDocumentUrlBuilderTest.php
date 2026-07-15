<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\BelemMunicipalDocumentUrlBuilder;

final class BelemMunicipalDocumentUrlBuilderTest extends TestCase
{
    public function test_build_creates_official_belem_danfse_url(): void
    {
        $url = BelemMunicipalDocumentUrlBuilder::build(
            '12.345.678/0001-95',
            '4007197',
            '1105',
            'ABC123XYZ'
        );

        $this->assertSame(
            'https://notafiscal.belem.pa.gov.br/notafiscal-ws/servico/notafiscal/autenticacao/cpfCnpj/12345678000195/inscricaoMunicipal/4007197/numeroNota/1105/codigoVerificacao/ABC123XYZ',
            $url
        );
    }
}
