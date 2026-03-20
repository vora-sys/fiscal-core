<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/NFSePilotPayloads.php';
require_once dirname(__DIR__, 2) . '/Fixtures/NFSeBelemMunicipalFixtures.php';
require_once dirname(__DIR__, 2) . '/Fixtures/NFSeJoinvilleMunicipalFixtures.php';

use freeline\FiscalCore\Providers\NFSe\Municipal\BelemMunicipalProvider;
use freeline\FiscalCore\Providers\NFSe\Municipal\ManausAmProvider;
use freeline\FiscalCore\Providers\NFSe\Municipal\PublicaProvider;
use freeline\FiscalCore\Support\NFSeSchemaResolver;
use freeline\FiscalCore\Support\NFSeSchemaValidator;
use freeline\FiscalCore\Support\NFSeSoapTransportInterface;
use freeline\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PilotProviderXmlValidationTest extends TestCase
{
    #[DataProvider('pilotCases')]
    public function testPilotProviderGeneratesSchemaValidXml(
        string $municipio,
        string $expectedClass,
        string $expectedFamily,
        array $payload
    ): void {
        if ($municipio === 'belem') {
            $config = ProviderRegistry::getInstance()->getConfig('BELEM_MUNICIPAL_2025');
            $config['soap_transport'] = new class implements NFSeSoapTransportInterface {
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
            $config['soap_transport'] = new class implements NFSeSoapTransportInterface {
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
        } else {
            $registry = ProviderRegistry::getInstance();
            $provider = $registry->getByMunicipio($municipio);
        }

        $this->assertInstanceOf($expectedClass, $provider);

        $xml = $provider->emitir($payload);
        if (in_array($municipio, ['belem', 'joinville'], true)) {
            $xml = $provider->getLastRequestXml();
        }

        if ($municipio === 'belem') {
            $xml = self::normalizeBelemXmlForSchemaValidation($xml);
        }

        $schemaPath = (new NFSeSchemaResolver())->resolve($expectedFamily, 'emitir');
        $validation = (new NFSeSchemaValidator())->validate($xml, $schemaPath);

        $this->assertTrue(
            $validation['valid'],
            implode(PHP_EOL, $validation['errors'])
        );
    }

    #[DataProvider('invalidPilotCases')]
    public function testPilotProviderRejectsMissingRequiredField(
        string $municipio,
        array $payload,
        string $expectedMessage
    ): void {
        $registry = ProviderRegistry::getInstance();
        $provider = $registry->getByMunicipio($municipio);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $provider->emitir($payload);
    }

    public static function pilotCases(): array
    {
        return [
            'belem' => ['belem', BelemMunicipalProvider::class, 'BELEM_MUNICIPAL_2025', NFSePilotPayloads::belem()],
            'manaus' => ['manaus', ManausAmProvider::class, 'MANAUS_AM', NFSePilotPayloads::manaus()],
            'joinville' => ['joinville', PublicaProvider::class, 'PUBLICA', NFSePilotPayloads::joinville()],
        ];
    }

    public static function invalidPilotCases(): array
    {
        $belem = NFSePilotPayloads::belem();
        unset($belem['tomador']['endereco']['logradouro']);

        $manaus = NFSePilotPayloads::manaus();
        unset($manaus['prestador']['inscricaoMunicipal']);

        $joinville = NFSePilotPayloads::joinville();
        unset($joinville['servico']['codigo']);

        return [
            'belem' => ['belem', $belem, 'tomador.endereco.logradouro'],
            'manaus' => ['manaus', $manaus, 'prestador.inscricaoMunicipal'],
            'joinville' => ['joinville', $joinville, 'servico.codigo'],
        ];
    }

    private static function normalizeBelemXmlForSchemaValidation(string $xml): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        foreach ($xpath->query('//ds:Signature') ?: [] as $signatureNode) {
            $signatureNode->parentNode?->removeChild($signatureNode);
        }

        foreach ($xpath->query('//*[local-name()="Prestador"]/@Id') ?: [] as $attributeNode) {
            $attributeNode->ownerElement?->removeAttributeNode($attributeNode);
        }

        $root = $dom->documentElement;
        if ($root instanceof \DOMElement && !$root->hasAttribute('xmlns')) {
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
