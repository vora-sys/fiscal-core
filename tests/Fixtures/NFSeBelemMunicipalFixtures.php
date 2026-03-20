<?php

declare(strict_types=1);

use NFePHP\Common\Certificate;

final class NFSeBelemMunicipalFixtures
{
    private static ?Certificate $certificate = null;

    public static function payload(array $overrides = []): array
    {
        $base = [
            'id' => 'RPS-BELEM-2026-1',
            'lote' => [
                'id' => 'LOTE-BELEM-2026-1',
                'numero' => '1001',
            ],
            'rps' => [
                'id' => 'RPS-BELEM-2026-1-RAW',
                'numero' => '1001',
                'serie' => 'RPS',
                'tipo' => '1',
                'data_emissao' => '2026-03-18',
                'status' => '1',
            ],
            'competencia' => '2026-03-18',
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricaoMunicipal' => '4007197',
                'razao_social' => 'Freeline Tecnologia Ltda',
                'simples_nacional' => true,
                'regime_tributario' => 'simples nacional',
                'mei' => false,
                'incentivo_fiscal' => false,
            ],
            'tomador' => [
                'documento' => '98765432000199',
                'razao_social' => 'Cliente de Belem Ltda',
                'email' => 'financeiro@example.com',
                'telefone' => '(91) 99999-0000',
                'endereco' => [
                    'logradouro' => 'Rua das Mangueiras',
                    'numero' => '100',
                    'complemento' => 'Sala 2',
                    'bairro' => 'Nazare',
                    'codigo_municipio' => '1501402',
                    'uf' => 'PA',
                    'cep' => '66000000',
                ],
            ],
            'servico' => [
                'codigo' => '0107',
                'item_lista_servico' => '0107',
                'codigo_cnae' => '620910000',
                'descricao' => 'Servicos de tecnologia da informacao prestados em Belém.',
                'discriminacao' => 'Servicos de tecnologia da informacao prestados em Belém.',
                'codigo_municipio' => '1501402',
                'aliquota' => 0.02,
                'iss_retido' => false,
                'exigibilidade_iss' => '1',
            ],
            'valor_servicos' => 10.00,
        ];

        return self::arrayMergeRecursiveDistinct($base, $overrides);
    }

    public static function meiPayload(): array
    {
        return self::payload([
            'prestador' => [
                'mei' => true,
                'regime_tributario' => 'mei',
            ],
        ]);
    }

    public static function payloadWithoutClassification(): array
    {
        $payload = self::payload();
        unset($payload['prestador']['mei'], $payload['prestador']['regime_tributario']);

        return $payload;
    }

    public static function incompatibleItemsPayload(): array
    {
        return self::payload([
            'itens' => [
                [
                    'descricao' => 'Servico A',
                    'valor' => 1000,
                    'codigo_cnae' => '620910000',
                    'aliquota' => 0.02,
                    'exigibilidade_iss' => '1',
                    'iss_retido' => false,
                ],
                [
                    'descricao' => 'Servico B',
                    'valor' => 2000,
                    'codigo_cnae' => '620150000',
                    'aliquota' => 0.05,
                    'exigibilidade_iss' => '1',
                    'iss_retido' => false,
                ],
            ],
        ]);
    }

    public static function consultaRps(array $overrides = []): array
    {
        $base = [
            'numero' => '1001',
            'serie' => 'RPS',
            'tipo' => '1',
        ];

        return self::arrayMergeRecursiveDistinct($base, $overrides);
    }

    public static function consultaRpsWithPrestador(array $overrides = []): array
    {
        return self::consultaRps(self::arrayMergeRecursiveDistinct([
            'prestador' => [
                'cnpj' => self::payload()['prestador']['cnpj'],
                'inscricaoMunicipal' => self::payload()['prestador']['inscricaoMunicipal'],
                'codigo_municipio' => '1501402',
            ],
        ], $overrides));
    }

    public static function loteProtocolo(): string
    {
        return 'PROTOCOLO-BELEM-2026';
    }

    public static function cancelamentoNumeroNfse(): string
    {
        return '1105';
    }

    public static function belemConfig(array $overrides = []): array
    {
        $config = \freeline\FiscalCore\Support\ProviderRegistry::getInstance()->getConfig('BELEM_MUNICIPAL_2025');
        $config['prestador'] = [
            'cnpj' => self::payload()['prestador']['cnpj'],
            'inscricaoMunicipal' => self::payload()['prestador']['inscricaoMunicipal'],
            'codigo_municipio' => '1501402',
        ];
        $config['certificate'] = self::makeCertificate();
        $config['sign_operations'] = [
            'emitir',
            'consultar_lote',
            'consultar_nfse_rps',
            'cancelar_nfse',
        ];

        return self::arrayMergeRecursiveDistinct($config, $overrides);
    }

    public static function successSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:RecepcionarLoteRpsSincronoResponse>
      <tns:EnviarLoteRpsSincronoResposta>
        <nfse:NumeroLote>1001</nfse:NumeroLote>
        <nfse:DataRecebimento>2026-03-18T10:15:00</nfse:DataRecebimento>
        <nfse:Protocolo>PROTOCOLO-BELEM-2026</nfse:Protocolo>
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
XML;
    }

    public static function rejectionSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:RecepcionarLoteRpsSincronoResponse>
      <tns:EnviarLoteRpsSincronoResposta>
        <nfse:ListaMensagemRetorno>
          <nfse:MensagemRetorno>
            <nfse:Codigo>E160</nfse:Codigo>
            <nfse:Mensagem>CNAE informado nao habilitado para o prestador.</nfse:Mensagem>
          </nfse:MensagemRetorno>
        </nfse:ListaMensagemRetorno>
      </tns:EnviarLoteRpsSincronoResposta>
    </tns:RecepcionarLoteRpsSincronoResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function consultarLoteSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:ConsultarLoteRpsResponse>
      <tns:ConsultarLoteRpsResposta>
        <nfse:NumeroLote>1001</nfse:NumeroLote>
        <nfse:Protocolo>PROTOCOLO-BELEM-2026</nfse:Protocolo>
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
      </tns:ConsultarLoteRpsResposta>
    </tns:ConsultarLoteRpsResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function consultarNfseRpsSoapResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:ConsultarNfsePorRpsResponse>
      <tns:ConsultarNfsePorRpsResposta>
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
      </tns:ConsultarNfsePorRpsResposta>
    </tns:ConsultarNfsePorRpsResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function cancelarSoapSuccessResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:CancelarNfseResponse>
      <tns:CancelarNfseResposta>
        <nfse:Pedido>
          <nfse:InfPedidoCancelamento Id="Cancelamento_12345678000195_1105">
            <nfse:IdentificacaoNfse>
              <nfse:Numero>1105</nfse:Numero>
              <nfse:CpfCnpj>
                <nfse:Cnpj>12345678000195</nfse:Cnpj>
              </nfse:CpfCnpj>
              <nfse:InscricaoMunicipal>4007197</nfse:InscricaoMunicipal>
              <nfse:CodigoMunicipio>1501402</nfse:CodigoMunicipio>
            </nfse:IdentificacaoNfse>
            <nfse:CodigoCancelamento>1</nfse:CodigoCancelamento>
          </nfse:InfPedidoCancelamento>
        </nfse:Pedido>
      </tns:CancelarNfseResposta>
    </tns:CancelarNfseResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function cancelarSoapRejectionResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="http://nfse.abrasf.org.br" xmlns:nfse="http://www.abrasf.org.br/nfse.xsd">
  <soapenv:Body>
    <tns:CancelarNfseResponse>
      <tns:CancelarNfseResposta>
        <nfse:ListaMensagemRetorno>
          <nfse:MensagemRetorno>
            <nfse:Codigo>E301</nfse:Codigo>
            <nfse:Mensagem>NFSe ja se encontra cancelada.</nfse:Mensagem>
          </nfse:MensagemRetorno>
        </nfse:ListaMensagemRetorno>
      </tns:CancelarNfseResposta>
    </tns:CancelarNfseResponse>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    public static function invalidSoapResponse(): string
    {
        return '<soapenv:Envelope><soapenv:Body><invalid></soapenv:Body>';
    }

    public static function sanitizedExportXml(): string
    {
        return (string) file_get_contents(self::sanitizedExportXmlPath());
    }

    public static function sanitizedExportXmlPath(): string
    {
        return __DIR__ . '/belem/retorno_lista_nfse_sanitizado.xml';
    }

    public static function makeCertificate(): Certificate
    {
        if (self::$certificate instanceof Certificate) {
            return self::$certificate;
        }

        $privateKey = @openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new RuntimeException('Falha ao criar chave privada de teste.');
        }

        $csr = @openssl_csr_new([
            'commonName' => 'Freeline Belém Teste',
            'organizationName' => 'Freeline',
            'countryName' => 'BR',
        ], $privateKey, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Falha ao criar CSR de teste.');
        }

        $x509 = @openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if ($x509 === false) {
            throw new RuntimeException('Falha ao assinar certificado de teste.');
        }

        $pkcs12 = '';
        if (!@openssl_pkcs12_export($x509, $pkcs12, $privateKey, 'secret')) {
            throw new RuntimeException('Falha ao exportar certificado PKCS#12 de teste.');
        }

        self::$certificate = Certificate::readPfx($pkcs12, 'secret');

        return self::$certificate;
    }

    private static function arrayMergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::arrayMergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
