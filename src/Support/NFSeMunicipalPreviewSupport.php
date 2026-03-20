<?php

declare(strict_types=1);

namespace freeline\FiscalCore\Support;

use NFePHP\Common\Certificate;
use RuntimeException;

final class NFSeMunicipalPreviewSupport
{
    private static ?Certificate $certificate = null;

    public static function makeCertificate(string $commonName = 'NFSe Municipal Preview'): Certificate
    {
        if (self::$certificate instanceof Certificate) {
            return self::$certificate;
        }

        $configuredPath = $_ENV['FISCAL_PREVIEW_CERT_PATH'] ?? getenv('FISCAL_PREVIEW_CERT_PATH');
        $configuredPassword = $_ENV['FISCAL_PREVIEW_CERT_PASSWORD'] ?? getenv('FISCAL_PREVIEW_CERT_PASSWORD') ?: '';
        if (is_string($configuredPath) && trim($configuredPath) !== '' && is_file($configuredPath)) {
            $content = file_get_contents($configuredPath);
            if (is_string($content) && $content !== '') {
                self::$certificate = Certificate::readPfx($content, (string) $configuredPassword);

                return self::$certificate;
            }
        }

        $privateKey = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Falha ao criar chave privada para preview NFSe.');
        }

        $csr = @openssl_csr_new([
            'commonName' => $commonName,
            'organizationName' => 'Fiscal Core Preview',
            'countryName' => 'BR',
        ], $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Falha ao criar CSR para preview NFSe.');
        }

        $x509 = @openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if ($x509 === false) {
            throw new RuntimeException('Falha ao assinar certificado de preview NFSe.');
        }

        $pkcs12 = '';
        if (!@openssl_pkcs12_export($x509, $pkcs12, $privateKey, 'preview-secret')) {
            throw new RuntimeException('Falha ao exportar certificado PKCS#12 de preview NFSe.');
        }

        self::$certificate = Certificate::readPfx($pkcs12, 'preview-secret');

        return self::$certificate;
    }

    public static function makeTransport(string $municipio): NFSeSoapTransportInterface
    {
        $responseXml = self::successSoapResponse($municipio);

        return new class($responseXml) implements NFSeSoapTransportInterface {
            public function __construct(private readonly string $responseXml)
            {
            }

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->responseXml,
                    'status_code' => 200,
                    'headers' => ['Content-Type: text/xml'],
                    'endpoint' => $endpoint,
                    'options' => $options,
                ];
            }
        };
    }

    public static function successSoapResponse(string $municipio): string
    {
        return match (self::normalizeMunicipio($municipio)) {
            'belem' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:RecepcionarLoteRpsSincronoResponse>
      <tns:EnviarLoteRpsSincronoResposta>
        <nfse:NumeroLote>1001</nfse:NumeroLote>
        <nfse:DataRecebimento>2026-03-18T10:15:00</nfse:DataRecebimento>
        <nfse:Protocolo>PROTOCOLO-BELEM-PREVIEW</nfse:Protocolo>
        <nfse:ListaNfse>
          <nfse:CompNfse>
            <nfse:Nfse>
              <nfse:InfNfse Id="406526257">
                <nfse:Numero>1105</nfse:Numero>
                <nfse:CodigoVerificacao>ABC123XYZ</nfse:CodigoVerificacao>
                <nfse:DataEmissao>2026-03-18T10:15:02</nfse:DataEmissao>
                <nfse:Servico>
                  <nfse:Valores>
                    <nfse:ValorServicos>3000.00</nfse:ValorServicos>
                    <nfse:ValorLiquidoNfse>3000.00</nfse:ValorLiquidoNfse>
                  </nfse:Valores>
                </nfse:Servico>
              </nfse:InfNfse>
            </nfse:Nfse>
          </nfse:CompNfse>
        </nfse:ListaNfse>
      </tns:EnviarLoteRpsSincronoResposta>
    </tns:RecepcionarLoteRpsSincronoResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML,
            'joinville' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:svc="http://service.nfse.integracao.ws.publica/">
  <soapenv:Body>
    <svc:GerarNfseResponse>
      <return>&lt;?xml version=&quot;1.0&quot; encoding=&quot;UTF-8&quot;?&gt;
&lt;GerarNfseResposta xmlns=&quot;http://www.publica.inf.br&quot;&gt;
  &lt;ListaNfse&gt;
    &lt;CompNfse&gt;
      &lt;Nfse&gt;
        &lt;InfNfse&gt;
          &lt;Numero&gt;202600000000123&lt;/Numero&gt;
          &lt;CodigoVerificacao&gt;AB12-CD34&lt;/CodigoVerificacao&gt;
          &lt;DataEmissao&gt;2026-03-19T09:20:00&lt;/DataEmissao&gt;
          &lt;IdentificacaoRps&gt;
            &lt;Numero&gt;1001&lt;/Numero&gt;
            &lt;Serie&gt;A1&lt;/Serie&gt;
            &lt;Tipo&gt;1&lt;/Tipo&gt;
          &lt;/IdentificacaoRps&gt;
          &lt;Servico&gt;
            &lt;Valores&gt;
              &lt;ValorServicos&gt;1500.00&lt;/ValorServicos&gt;
              &lt;ValorLiquidoNfse&gt;1500.00&lt;/ValorLiquidoNfse&gt;
            &lt;/Valores&gt;
          &lt;/Servico&gt;
          &lt;TomadorServico&gt;
            &lt;RazaoSocial&gt;Cliente Joinville Ltda&lt;/RazaoSocial&gt;
          &lt;/TomadorServico&gt;
        &lt;/InfNfse&gt;
      &lt;/Nfse&gt;
    &lt;/CompNfse&gt;
  &lt;/ListaNfse&gt;
&lt;/GerarNfseResposta&gt;</return>
    </svc:GerarNfseResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML,
            default => throw new RuntimeException("Município '{$municipio}' não possui fixture de preview."),
        };
    }

    private static function normalizeMunicipio(string $municipio): string
    {
        return strtolower(trim($municipio));
    }
}
