<?php

declare(strict_types=1);

use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;

final class NFSeIsswebFixtures
{
    public static function municipio(string $slug): array
    {
        return match ($slug) {
            'rio-preto-da-eva' => [
                'codigo_municipio' => '1303569',
                'municipio_nome' => 'Rio Preto da Eva',
                'municipio_slug' => 'rio-preto-da-eva',
                'validation_url_template' => '',
                'tomador_nome' => 'Cliente Rio Preto da Eva Ltda',
                'logradouro' => 'Avenida Governador Gilberto Mestrinho',
                'bairro' => 'Centro',
                'cep' => '69117-000',
                'descricao' => 'Servico de homologacao ISSWEB para Rio Preto da Eva.',
            ],
            default => [
                'codigo_municipio' => '1303536',
                'municipio_nome' => 'Presidente Figueiredo',
                'municipio_slug' => 'presidente-figueiredo',
                'validation_url_template' => 'https://servicosweb.pmpf.am.gov.br/issweb/validacao?numero={numero}&chave={chave_validacao}',
                'tomador_nome' => 'Cliente Presidente Figueiredo Ltda',
                'logradouro' => 'Avenida Amazonas',
                'bairro' => 'Centro',
                'cep' => '69735-000',
                'descricao' => 'Servico de homologacao ISSWEB para Presidente Figueiredo.',
            ],
        };
    }

    public static function config(array $overrides = []): array
    {
        $municipio = self::municipio((string) ($overrides['municipio_slug'] ?? 'presidente-figueiredo'));

        return array_replace_recursive([
            'provider_class' => 'sabbajohn\\FiscalCore\\Providers\\NFSe\\Municipal\\IsswebProvider',
            'layout_family' => 'ISSWEB',
            'schema_root' => 'resources/nfse/schemas/ISSWEB',
            'xsd_entrypoints' => [
                'emitir' => 'XSDNFEletronica.xsd',
                'consultar' => 'XSDISSEConsultaNota.xsd',
                'cancelar_nfse' => 'XSDISSECancelaNFe.xsd',
                'retorno' => 'XSDRetorno.xsd',
            ],
            'aliquota_format' => 'decimal',
            'transport' => 'soap',
            'versao' => 'ISSWEB',
            'codigo_municipio' => $municipio['codigo_municipio'],
            'municipio_nome' => $municipio['municipio_nome'],
            'municipio_uf' => 'AM',
            'ambiente' => 'homologacao',
            'wsdl_homologacao' => 'https://issweb-homologacao.example.test/servicos',
            'wsdl_producao' => 'https://issweb-producao.example.test/servicos',
            'signature_mode' => 'optional',
            'auth' => [
                'chave' => str_repeat('A', 48),
            ],
            'official_validation_url_template' => $municipio['validation_url_template'],
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricaoMunicipal' => '998877',
                'codigo_municipio' => $municipio['codigo_municipio'],
            ],
        ], $overrides);
    }

    public static function payload(array $overrides = []): array
    {
        $municipio = self::municipio((string) ($overrides['municipio_slug'] ?? 'presidente-figueiredo'));

        return array_replace_recursive([
            'id' => '1',
            'rps' => [
                'numero' => '123',
            ],
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricaoMunicipal' => '998877',
            ],
            'tomador' => [
                'documento' => '98765432000199',
                'razao_social' => $municipio['tomador_nome'],
                'email' => 'financeiro@example.com',
                'endereco' => [
                    'uf' => 'AM',
                    'codigo_municipio' => $municipio['codigo_municipio'],
                    'logradouro' => $municipio['logradouro'],
                    'numero' => '100',
                    'complemento' => 'Sala 1',
                    'bairro' => $municipio['bairro'],
                    'cep' => $municipio['cep'],
                ],
            ],
            'servico' => [
                'codigo' => '101',
                'descricao' => $municipio['descricao'],
                'discriminacao' => $municipio['descricao'],
                'tipo_documento' => '001',
                'iss_retido' => false,
                'local_prestacao' => [
                    'tipo' => '1',
                    'uf' => 'AM',
                    'codigo_municipio' => $municipio['codigo_municipio'],
                    'cep' => $municipio['cep'],
                ],
            ],
            'valor_servicos' => 150.00,
        ], $overrides);
    }

    public static function successResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Retorno>
  <NotaFiscal>
    <ID>123</ID>
    <NumeroNF>4567</NumeroNF>
    <ChaveValidacao>AB12-C3456</ChaveValidacao>
    <Lote>789</Lote>
  </NotaFiscal>
</Retorno>
XML;
    }

    public static function consultaResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Retorno>
  <NotaFiscal>
    <ID>123</ID>
    <NumeroNF>4567</NumeroNF>
    <ChaveValidacao>AB12-C3456</ChaveValidacao>
    <Lote>0</Lote>
  </NotaFiscal>
</Retorno>
XML;
    }

    public static function cancelResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Retorno>
  <NotaFiscal>
    <ID>123</ID>
    <NumeroNF>4567</NumeroNF>
    <ChaveValidacao>AB12-C3456</ChaveValidacao>
    <Lote>789</Lote>
  </NotaFiscal>
</Retorno>
XML;
    }

    public static function rejectionResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Retorno>
  <Erro>
    <ID>123</ID>
    <Erro>Item de atividade invalido para o prestador.</Erro>
  </Erro>
</Retorno>
XML;
    }

    public static function makeTransport(string $responseXml): NFSeSoapTransportInterface
    {
        return new class($responseXml) implements NFSeSoapTransportInterface
        {
            public function __construct(private readonly string $responseXml) {}

            public function send(string $endpoint, string $envelope, array $options = []): array
            {
                return [
                    'request_xml' => $envelope,
                    'response_xml' => $this->responseXml,
                    'status_code' => 200,
                    'headers' => [],
                ];
            }
        };
    }
}
