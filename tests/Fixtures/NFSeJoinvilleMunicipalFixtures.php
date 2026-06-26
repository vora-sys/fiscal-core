<?php

declare(strict_types=1);

use NFePHP\Common\Certificate;

final class NFSeJoinvilleMunicipalFixtures
{
    private static ?Certificate $certificate = null;

    public static function payload(array $overrides = []): array
    {
        $base = [
            'id' => 'JOINVILLE-RPS-2026-1',
            'rps' => [
                'numero' => '1001',
                'serie' => 'A1',
                'tipo' => '1',
                'data_emissao' => '2026-03-19 09:15:00',
                'status' => '1',
            ],
            'competencia' => '2026-03',
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricaoMunicipal' => '123456',
                'razao_social' => 'Freeline Joinville Servicos Ltda',
                'simples_nacional' => true,
                'incentivador_cultural' => false,
            ],
            'tomador' => [
                'documento' => '98765432000199',
                'razao_social' => 'Cliente Joinville Ltda',
                'email' => 'financeiro.joinville@example.com',
                'telefone' => '(47) 99999-1234',
                'endereco' => [
                    'logradouro' => 'Rua do Principe',
                    'numero' => '100',
                    'complemento' => 'Sala 401',
                    'bairro' => 'Centro',
                    'codigo_municipio' => '4209102',
                    'uf' => 'SC',
                    'cep' => '89201001',
                    'municipio' => 'Joinville',
                ],
            ],
            'servico' => [
                'codigo' => '11.01',
                'item_lista_servico' => '11.01',
                'descricao' => 'Desenvolvimento e licenciamento de software.',
                'discriminacao' => 'Desenvolvimento e licenciamento de software.',
                'codigo_municipio' => '4209102',
                'natureza_operacao' => '16',
                'aliquota' => 0.02,
                'iss_retido' => false,
            ],
            'valor_servicos' => 1500.00,
        ];

        return self::arrayMergeRecursiveDistinct($base, $overrides);
    }

    public static function incompatibleItemsPayload(): array
    {
        return self::payload([
            'itens' => [
                [
                    'codigo' => '11.01',
                    'descricao' => 'Servico A',
                    'valor' => 700,
                    'aliquota' => 0.02,
                    'codigo_municipio' => '4209102',
                ],
                [
                    'codigo' => '14.01',
                    'descricao' => 'Servico B',
                    'valor' => 800,
                    'aliquota' => 0.03,
                    'codigo_municipio' => '4209102',
                ],
            ],
        ]);
    }

    public static function consultaRps(array $overrides = []): array
    {
        $base = [
            'numero' => '1001',
            'serie' => 'A1',
            'tipo' => '1',
        ];

        return self::arrayMergeRecursiveDistinct($base, $overrides);
    }

    public static function loteProtocolo(): string
    {
        return 'PROTOCOLO-JOINVILLE-2026';
    }

    public static function cancelamentoNumeroNfse(): string
    {
        return '202600000000123';
    }

    public static function joinvilleConfig(array $overrides = []): array
    {
        $config = \sabbajohn\FiscalCore\Support\ProviderRegistry::getInstance()->getConfig('PUBLICA');
        $config['emission_mode'] = 'async_lote';
        $config['prestador'] = [
            'cnpj' => self::payload()['prestador']['cnpj'],
            'inscricaoMunicipal' => self::payload()['prestador']['inscricaoMunicipal'],
            'codigo_municipio' => '4209102',
        ];
        $config['certificate'] = self::makeCertificate();

        return self::arrayMergeRecursiveDistinct($config, $overrides);
    }

    public static function successSoapResponse(): string
    {
        return self::wrapServicesResponse('GerarNfseResponse', self::successPayloadXml());
    }

    public static function rejectionSoapResponse(): string
    {
        return self::wrapServicesResponse('GerarNfseResponse', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GerarNfseResposta xmlns="http://www.publica.inf.br">
  <ListaMensagemRetorno>
    <MensagemRetorno>
      <Codigo>E201</Codigo>
      <Mensagem>Item de serviço inválido para o prestador.</Mensagem>
    </MensagemRetorno>
  </ListaMensagemRetorno>
</GerarNfseResposta>
XML);
    }

    public static function deprecatedGerarNfseSoapResponse(): string
    {
        return self::wrapServicesResponse('GerarNfseResponse', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GerarNfseResposta xmlns="http://www.publica.inf.br">
  <ListaMensagemRetorno>
    <MensagemRetorno>
      <Codigo>E000</Codigo>
      <Mensagem>Serviço descontinuado. Utilize o serviço 'RecepcionarLoteRps' para enviar lotes de RPS e posteriormente o serviço de 'ConsultarSituacaoLoteRps' para consultar a situação.</Mensagem>
    </MensagemRetorno>
  </ListaMensagemRetorno>
</GerarNfseResposta>
XML);
    }

    public static function asyncEnviarLoteSoapResponse(): string
    {
        return self::wrapServicesResponse('RecepcionarLoteRpsResponse', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<EnviarLoteRpsResposta xmlns="http://www.publica.inf.br">
  <NumeroLote>1001</NumeroLote>
  <DataRecebimento>2026-03-19T09:20:10</DataRecebimento>
  <Protocolo>PROTOCOLO-JOINVILLE-2026</Protocolo>
</EnviarLoteRpsResposta>
XML);
    }

    public static function consultarLoteSoapResponse(): string
    {
        return self::wrapConsultasResponse('ConsultarLoteRpsResponse', self::consultarLotePayloadXml());
    }

    public static function consultarSituacaoLoteSoapResponse(string $situacao = '4'): string
    {
        return self::wrapConsultasResponse('ConsultarSituacaoLoteRpsResponse', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarSituacaoLoteRpsResposta xmlns="http://www.publica.inf.br">
  <NumeroLote>1001</NumeroLote>
  <Situacao>{$situacao}</Situacao>
</ConsultarSituacaoLoteRpsResposta>
XML);
    }

    public static function consultarNfseRpsSoapResponse(): string
    {
        return self::wrapConsultasResponse('ConsultarNfsePorRpsResponse', self::consultarNfseRpsPayloadXml());
    }

    public static function cancelarSoapSuccessResponse(): string
    {
        return self::wrapServicesResponse('CancelarNfseResponse', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CancelarNfseResposta xmlns="http://www.publica.inf.br">
  <Cancelamento>
    <Confirmacao>
      <Pedido>
        <InfPedidoCancelamento id="assinar">
          <IdentificacaoNfse>
            <Numero>202600000000123</Numero>
            <Cnpj>12345678000195</Cnpj>
            <InscricaoMunicipal>123456</InscricaoMunicipal>
            <CodigoMunicipio>4209102</CodigoMunicipio>
          </IdentificacaoNfse>
          <CodigoCancelamento>C001</CodigoCancelamento>
          <MotivoCancelamento>Cancelamento de homologacao</MotivoCancelamento>
        </InfPedidoCancelamento>
      </Pedido>
      <DataHoraCancelamento>2026-03-19T10:15:00</DataHoraCancelamento>
    </Confirmacao>
  </Cancelamento>
</CancelarNfseResposta>
XML);
    }

    public static function cancelarSoapRejectionResponse(): string
    {
        return self::wrapServicesResponse('CancelarNfseResponse', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<CancelarNfseResposta xmlns="http://www.publica.inf.br">
  <ListaMensagemRetorno>
    <MensagemRetorno>
      <Codigo>E301</Codigo>
      <Mensagem>NFSe ja se encontra cancelada.</Mensagem>
    </MensagemRetorno>
  </ListaMensagemRetorno>
</CancelarNfseResposta>
XML);
    }

    public static function invalidSoapResponse(): string
    {
        return '<soapenv:Envelope><soapenv:Body><invalid></soapenv:Body>';
    }

    public static function sanitizedExampleResponseXml(): string
    {
        return (string) file_get_contents(self::sanitizedExampleResponsePath());
    }

    public static function sanitizedExampleResponsePath(): string
    {
        return __DIR__ . '/joinville/gerar_nfse_resposta_sanitizada.xml';
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
            'commonName' => 'Freeline Joinville Teste',
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

    private static function successPayloadXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GerarNfseResposta xmlns="http://www.publica.inf.br">
  <ListaNfse>
    <CompNfse>
      <Nfse>
        <InfNfse>
          <Numero>202600000000123</Numero>
          <CodigoVerificacao>AB12-CD34</CodigoVerificacao>
          <DataEmissao>2026-03-19T09:20:00</DataEmissao>
          <IdentificacaoRps>
            <Numero>1001</Numero>
            <Serie>A1</Serie>
            <Tipo>1</Tipo>
          </IdentificacaoRps>
          <Servico>
            <Valores>
              <ValorServicos>1500.00</ValorServicos>
              <ValorLiquidoNfse>1500.00</ValorLiquidoNfse>
            </Valores>
          </Servico>
          <TomadorServico>
            <RazaoSocial>Cliente Joinville Ltda</RazaoSocial>
          </TomadorServico>
        </InfNfse>
      </Nfse>
    </CompNfse>
  </ListaNfse>
</GerarNfseResposta>
XML;
    }

    private static function consultarLotePayloadXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarLoteRpsResposta xmlns="http://www.publica.inf.br">
  <ListaNfse>
    <CompNfse>
      <Nfse>
        <InfNfse>
          <Numero>202600000000123</Numero>
          <CodigoVerificacao>AB12-CD34</CodigoVerificacao>
          <DataEmissao>2026-03-19T09:20:00</DataEmissao>
          <IdentificacaoRps>
            <Numero>1001</Numero>
            <Serie>A1</Serie>
            <Tipo>1</Tipo>
          </IdentificacaoRps>
          <Servico>
            <Valores>
              <ValorServicos>1500.00</ValorServicos>
              <ValorLiquidoNfse>1500.00</ValorLiquidoNfse>
            </Valores>
          </Servico>
          <TomadorServico>
            <RazaoSocial>Cliente Joinville Ltda</RazaoSocial>
          </TomadorServico>
        </InfNfse>
      </Nfse>
    </CompNfse>
  </ListaNfse>
</ConsultarLoteRpsResposta>
XML;
    }

    private static function consultarNfseRpsPayloadXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ConsultarNfseResposta xmlns="http://www.publica.inf.br">
  <ListaNfse>
    <CompNfse>
      <Nfse>
        <InfNfse>
          <Numero>202600000000123</Numero>
          <CodigoVerificacao>AB12-CD34</CodigoVerificacao>
          <DataEmissao>2026-03-19T09:20:00</DataEmissao>
          <IdentificacaoRps>
            <Numero>1001</Numero>
            <Serie>A1</Serie>
            <Tipo>1</Tipo>
          </IdentificacaoRps>
          <Servico>
            <Valores>
              <ValorServicos>1500.00</ValorServicos>
              <ValorLiquidoNfse>1500.00</ValorLiquidoNfse>
            </Valores>
          </Servico>
          <TomadorServico>
            <RazaoSocial>Cliente Joinville Ltda</RazaoSocial>
          </TomadorServico>
        </InfNfse>
      </Nfse>
    </CompNfse>
  </ListaNfse>
</ConsultarNfseResposta>
XML;
    }

    private static function wrapServicesResponse(string $operationResponse, string $payloadXml): string
    {
        return self::wrapSoapResponse($operationResponse, $payloadXml);
    }

    private static function wrapConsultasResponse(string $operationResponse, string $payloadXml): string
    {
        return self::wrapSoapResponse($operationResponse, $payloadXml);
    }

    private static function wrapSoapResponse(string $operationResponse, string $payloadXml): string
    {
        $escaped = htmlspecialchars($payloadXml, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:svc="http://service.nfse.integracao.ws.publica/">
  <soapenv:Body>
    <svc:{$operationResponse}>
      <return>{$escaped}</return>
    </svc:{$operationResponse}>
  </soapenv:Body>
</soapenv:Envelope>
XML;
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
