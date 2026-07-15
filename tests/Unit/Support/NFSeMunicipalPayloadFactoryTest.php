<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPayloadFactory;
use sabbajohn\FiscalCore\Support\NFSeMunicipalPreviewSupport;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

final class NFSeMunicipalPayloadFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderRegistry::getInstance()->reload();
    }

    #[DataProvider('municipioProvider')]
    public function test_demo_payloads_generate_schema_valid_xml(string $municipio): void
    {
        $factory = new NFSeMunicipalPayloadFactory;
        $meta = $factory->providerMeta($municipio);
        $payload = $factory->demo($municipio);

        $config = ProviderRegistry::getInstance()->getConfig($meta['provider_key']);
        $config['certificate'] = NFSeMunicipalPreviewSupport::makeCertificate('Factory '.ucfirst($municipio));
        $config['prestador'] = $payload['prestador'];
        $config['soap_transport'] = NFSeMunicipalPreviewSupport::makeTransport($municipio);

        $providerClass = $config['provider_class'];
        $provider = new $providerClass($config);
        $provider->emitir($payload);

        $this->assertInstanceOf(NFSeOperationalIntrospectionInterface::class, $provider);

        $requestXml = (string) $provider->getLastRequestXml();
        if ($meta['provider_key'] === 'BELEM_MUNICIPAL_2025') {
            $requestXml = $this->schemaCompatibleXml($requestXml);
        }

        $validation = (new NFSeSchemaValidator)->validate(
            $requestXml,
            (new NFSeSchemaResolver)->resolve($meta['provider_key'], 'emitir')
        );

        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));
        $this->assertSame('success', $provider->getLastResponseData()['status'] ?? null);
    }

    public function test_build_prestador_requires_inscricao_municipal(): void
    {
        $factory = new NFSeMunicipalPayloadFactory;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FISCAL_IM');

        $factory->buildPrestador(
            'belem',
            NFSeMunicipalPreviewSupport::makeCertificate(),
            [
                'cnpj' => '83188342000104',
                'razao_social' => 'Freeline Informatica Ltda',
                'inscricao_municipal' => '',
            ]
        );
    }

    public function test_rejects_joinville_after_national_migration(): void
    {
        $factory = new NFSeMunicipalPayloadFactory;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fluxo NFSe nacional');

        $factory->demo('joinville');
    }

    public function test_demo_payload_can_be_resolved_from_catalog_payload_defaults_for_issweb_municipio(): void
    {
        $factory = new NFSeMunicipalPayloadFactory;
        $payload = $factory->demo('presidente-figueiredo');

        $this->assertSame('1303536', $payload['servico']['local_prestacao']['codigo_municipio']);
        $this->assertSame('001', $payload['servico']['tipo_documento']);
        $this->assertSame('Cliente Presidente Figueiredo Ltda', $payload['tomador']['razao_social']);
    }

    #[DataProvider('priorityMunicipioPayloadDefaultsProvider')]
    public function test_demo_payload_uses_canonized_defaults_for_priority_municipios(
        string $municipio,
        string $ibge,
        string $expectedDescricao
    ): void {
        $factory = new NFSeMunicipalPayloadFactory;
        $payload = $factory->demo($municipio);

        $this->assertSame('123', (string) ($payload['rps']['numero'] ?? ''));
        $this->assertSame($ibge, (string) ($payload['tomador']['endereco']['codigo_municipio'] ?? ''));
        $this->assertSame($ibge, (string) ($payload['servico']['local_prestacao']['codigo_municipio'] ?? ''));
        $this->assertSame($expectedDescricao, (string) ($payload['servico']['descricao'] ?? ''));
    }

    public static function municipioProvider(): array
    {
        return [
            'belem' => ['belem'],
        ];
    }

    public static function priorityMunicipioPayloadDefaultsProvider(): array
    {
        return [
            'campo-grande' => ['campo-grande', '5002704', 'Servico de homologacao NFSe para Campo Grande.'],
            'castanhal' => ['castanhal', '1502400', 'Servico de homologacao NFSe para Castanhal.'],
            'joao-pessoa' => ['joao-pessoa', '2507507', 'Servico de homologacao NFSe para Joao Pessoa.'],
            'teresina' => ['teresina', '2211001', 'Servico de homologacao NFSe para Teresina.'],
            'brasilia' => ['brasilia', '5300108', 'Servico de homologacao NFSe para Brasilia.'],
            'goiania' => ['goiania', '5208707', 'Servico de homologacao NFSe para Goiania.'],
            'cuiaba' => ['cuiaba', '5103403', 'Servico de homologacao NFSe para Cuiaba.'],
            'fortaleza' => ['fortaleza', '2304400', 'Servico de homologacao NFSe para Fortaleza.'],
            'maceio' => ['maceio', '2704302', 'Servico de homologacao NFSe para Maceio.'],
            'sao-paulo' => ['sao-paulo', '3550308', 'Servico de homologacao NFSe para Sao Paulo.'],
            'salvador' => ['salvador', '2927408', 'Servico de homologacao NFSe para Salvador.'],
            'porto-velho' => ['porto-velho', '1100205', 'Servico de homologacao NFSe para Porto Velho.'],
            'aracaju' => ['aracaju', '2800308', 'Servico de homologacao NFSe para Aracaju.'],
            'feira-de-santana' => ['feira-de-santana', '2910800', 'Servico de homologacao NFSe para Feira de Santana.'],
            'itabuna' => ['itabuna', '2914802', 'Servico de homologacao NFSe para Itabuna.'],
            'vitoria-da-conquista' => ['vitoria-da-conquista', '2933307', 'Servico de homologacao NFSe para Vitoria da Conquista.'],
            'palmas' => ['palmas', '1721000', 'Servico de homologacao NFSe para Palmas.'],
        ];
    }

    private function schemaCompatibleXml(string $xml): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        if (@$dom->loadXML($xml)) {
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query("//*[local-name()='Signature' and namespace-uri()='http://www.w3.org/2000/09/xmldsig#']") as $signatureNode) {
                if ($signatureNode->parentNode instanceof DOMNode) {
                    $signatureNode->parentNode->removeChild($signatureNode);
                }
            }

            foreach ($xpath->query("//*[local-name()='Prestador']/@Id") as $attributeNode) {
                if ($attributeNode instanceof DOMAttr) {
                    $attributeNode->ownerElement?->removeAttributeNode($attributeNode);
                }
            }

            $root = $dom->documentElement;
            if ($root instanceof DOMElement) {
                $normalized = $dom->saveXML($root) ?: $xml;
                if (str_contains($normalized, 'xmlns="http://www.abrasf.org.br/nfse.xsd"')) {
                    return $normalized;
                }

                return preg_replace(
                    '/^<([A-Za-z0-9_:-]+)/',
                    '<$1 xmlns="http://www.abrasf.org.br/nfse.xsd"',
                    $normalized,
                    1
                ) ?: $normalized;
            }
        }

        return preg_replace(
            '/^<([A-Za-z0-9_:-]+)/',
            '<$1 xmlns="http://www.abrasf.org.br/nfse.xsd"',
            $xml,
            1
        ) ?: $xml;
    }
}
