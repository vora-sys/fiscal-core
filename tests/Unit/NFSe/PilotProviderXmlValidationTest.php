<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/Fixtures/NFSePilotPayloads.php';
require_once dirname(__DIR__, 2).'/Fixtures/NFSeBelemMunicipalFixtures.php';
require_once dirname(__DIR__, 2).'/Fixtures/NFSeJoinvilleMunicipalFixtures.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use sabbajohn\FiscalCore\Providers\NFSe\Municipal\PublicaProvider;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;
use sabbajohn\FiscalCore\Support\NFSeSchemaValidator;
use sabbajohn\FiscalCore\Support\NFSeSoapTransportInterface;
use sabbajohn\FiscalCore\Support\ProviderRegistry;

final class PilotProviderXmlValidationTest extends TestCase
{
    #[DataProvider('pilotCases')]
    public function test_pilot_provider_generates_schema_valid_xml(
        string $municipio,
        string $expectedClass,
        string $expectedFamily,
        array $payload
    ): void {
        if ($municipio === 'belem') {
            $config = ProviderRegistry::getInstance()->getConfig('BELEM_MUNICIPAL_2025');
            $config['soap_transport'] = new class implements NFSeSoapTransportInterface
            {
                public function send(string $endpoint, string $envelope, array $options = []): array
                {
                    return [
                        'request_xml' => $envelope,
                        'response_xml' => NFSeBelemMunicipalFixtures::successSoapResponse(),
                        'status_code' => 200,
                        'headers' => [],
                    ];
                }
            };
            $config['certificate'] = NFSeBelemMunicipalFixtures::makeCertificate();
            $provider = new BelemMunicipalProvider($config);
        } elseif ($municipio === 'joinville') {
            $config = ProviderRegistry::getInstance()->getConfig('PUBLICA');
            $config['soap_transport'] = new class implements NFSeSoapTransportInterface
            {
                public function send(string $endpoint, string $envelope, array $options = []): array
                {
                    return [
                        'request_xml' => $envelope,
                        'response_xml' => NFSeJoinvilleMunicipalFixtures::successSoapResponse(),
                        'status_code' => 200,
                        'headers' => [],
                    ];
                }
            };
            $config['certificate'] = NFSeJoinvilleMunicipalFixtures::makeCertificate();
            $provider = new PublicaProvider($config);
        } elseif ($municipio === 'manaus') {
            $config = ProviderRegistry::getInstance()->getConfig('nfse_nacional');
            $config['http_client'] = static function (): array {
                return ['status' => 200, 'body' => '<ok />', 'headers' => []];
            };
            $provider = new NacionalProvider($config);
        } else {
            $registry = ProviderRegistry::getInstance();
            $provider = $registry->getByMunicipio($municipio);
        }

        $this->assertInstanceOf($expectedClass, $provider);

        if ($municipio === 'manaus') {
            $xml = $provider->gerarXmlDpsPreview($payload);
        } else {
            $xml = $provider->emitir($payload);
        }
        if (in_array($municipio, ['belem', 'joinville'], true)) {
            $xml = $provider->getLastRequestXml();
        }

        if ($municipio === 'belem') {
            $xml = self::normalizeBelemXmlForSchemaValidation($xml);
        }

        if ($municipio === 'manaus') {
            $validation = $provider->validarDpsXml((string) $xml);
            $this->assertTrue(
                $validation['ok'],
                implode(PHP_EOL, array_column($validation['errors'], 'message'))
            );
        } else {
            $schemaPath = (new NFSeSchemaResolver)->resolve($expectedFamily, 'emitir');
            $validation = (new NFSeSchemaValidator)->validate($xml, $schemaPath);

            $this->assertTrue(
                $validation['valid'],
                implode(PHP_EOL, $validation['errors'])
            );
        }
    }

    #[DataProvider('invalidPilotCases')]
    public function test_pilot_provider_rejects_missing_required_field(
        string $municipio,
        array $payload,
        string $expectedMessage
    ): void {
        if ($municipio === 'manaus') {
            $config = ProviderRegistry::getInstance()->getConfig('nfse_nacional');
            $config['api_base_url'] = 'https://api.example.test';
            $config['http_client'] = static fn (): string => '<ok />';
            $provider = new NacionalProvider($config);
        } else {
            $registry = ProviderRegistry::getInstance();
            $provider = $registry->getByMunicipio($municipio);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $provider->emitir($payload);
    }

    public static function pilotCases(): array
    {
        return [
            'belem' => ['belem', BelemMunicipalProvider::class, 'BELEM_MUNICIPAL_2025', NFSePilotPayloads::belem()],
            'manaus' => ['manaus', NacionalProvider::class, 'NACIONAL', NFSePilotPayloads::manaus()],
            'joinville' => ['joinville', PublicaProvider::class, 'PUBLICA', NFSePilotPayloads::joinville()],
        ];
    }

    public static function invalidPilotCases(): array
    {
        $belem = NFSePilotPayloads::belem();
        unset($belem['tomador']['endereco']['logradouro']);

        $manaus = NFSePilotPayloads::manaus();
        unset($manaus['prestador']['cnpj']);

        $joinville = NFSePilotPayloads::joinville();
        unset($joinville['servico']['codigo']);

        return [
            'belem' => ['belem', $belem, 'tomador.endereco.logradouro'],
            'manaus' => ['manaus', $manaus, 'CNPJ do prestador inválido'],
            'joinville' => ['joinville', $joinville, 'Código de serviço é obrigatório'],
        ];
    }

    private static function normalizeBelemXmlForSchemaValidation(string $xml): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        foreach ($xpath->query('//ds:Signature') ?: [] as $signatureNode) {
            $signatureNode->parentNode?->removeChild($signatureNode);
        }

        foreach ($xpath->query('//*[local-name()="Prestador"]/@Id') ?: [] as $attributeNode) {
            $attributeNode->ownerElement?->removeAttributeNode($attributeNode);
        }

        $root = $dom->documentElement;
        if ($root instanceof DOMElement && ! $root->hasAttribute('xmlns')) {
            $normalized = preg_replace(
                '/^<([A-Za-z0-9:_-]+)/',
                '<$1 xmlns="http://www.abrasf.org.br/nfse.xsd"',
                $dom->saveXML($root) ?: ''
            );

            return $normalized ?: ($dom->saveXML() ?: '');
        }

        return $dom->saveXML() ?: '';
    }
}
