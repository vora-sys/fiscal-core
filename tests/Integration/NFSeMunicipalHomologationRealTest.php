<?php

declare(strict_types=1);

use freeline\FiscalCore\Facade\FiscalFacade;
use freeline\FiscalCore\Support\NFSeMunicipalHomologationService;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class NFSeMunicipalHomologationRealTest extends TestCase
{
    private string $projectRoot;
    private const DEFAULT_TOMADOR_CPF = '00980556236';
    private const DEFAULT_BELEM_PROTOCOLO = '056412880';

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);

        if (!$this->externalTestsEnabled() || !$this->municipalRealTestsEnabled()) {
            $this->markTestSkipped(
                'Defina ENABLE_EXTERNAL_TESTS=true e ENABLE_NFSE_MUNICIPAL_REAL_TESTS=true para executar homologacao municipal real.'
            );
        }
    }

    public function testBelemRealHomologationSend(): void
    {
        $service = new NFSeMunicipalHomologationService($this->projectRoot);
        $result = $service->send('belem', $this->resolveTomadorDocumento('TEST_NFSE_BELEM_TOMADOR_DOC'), [
            'env_overrides' => [
                'FISCAL_ENVIRONMENT' => 'homologacao',
                'FISCAL_IM' => '4007197',
                'FISCAL_CERT_PATH' => $this->projectRoot . '/certs/cert_faives.p12',
                'FISCAL_CERT_PASSWORD' => '',
                'OPENSSL_CONF' => $this->projectRoot . '/openssl.cnf',
            ],
            'tomador_defaults' => [
                'razao_social' => 'JOHNNATHAN VICTOR GONCALVES SABBA',
                'cep' => '66065112',
                'endereco' => [
                    'numero' => 'S/N',
                ],
            ],
        ]);

        $this->assertSame('send', $result['mode']);
        $this->assertNotSame('', trim((string) $result['request_xml']));
        $this->assertNotSame('', trim((string) $result['soap_envelope']));
        $this->assertIsArray($result['parsed_response']);
        $this->assertSuccessfulRealResponse('belem', $result['parsed_response']);
    }

    public function testBelemRealHomologationConsultarLote(): void
    {
        $this->applyEnvOverrides([
            'FISCAL_ENVIRONMENT' => 'homologacao',
            'FISCAL_IM' => '4007197',
            'FISCAL_CERT_PATH' => $this->projectRoot . '/certs/cert_faives.p12',
            'FISCAL_CERT_PASSWORD' => '',
            'OPENSSL_CONF' => $this->projectRoot . '/openssl.cnf',
        ]);

        $fiscalFacade = new FiscalFacade();
        $nfse = $fiscalFacade->nfse('belem');
        $protocolo = $this->resolveBelemProtocolo();
        $resultado = $nfse->consultarLote($protocolo);

        $this->assertTrue($resultado->isSuccess(), $resultado->getError() ?? 'Consulta de lote falhou ao inicializar a facade.');

        $data = $resultado->getData();
        $this->assertSame('nfse_consulta_lote', $data['type'] ?? null);
        $this->assertSame($protocolo, $data['protocolo'] ?? null);
        $this->assertIsArray($data['consulta']['parsed_response'] ?? null);
        $this->assertSuccessfulRealConsultaResponse('belem', $data['consulta'] ?? []);
    }

    public function testJoinvilleRealHomologationSend(): void
    {
        $service = new NFSeMunicipalHomologationService($this->projectRoot);
        $result = $service->send('joinville', $this->resolveTomadorDocumento('TEST_NFSE_JOINVILLE_TOMADOR_DOC'), [
            'env_overrides' => [
                'FISCAL_ENVIRONMENT' => 'homologacao',
                'FISCAL_IM' => (string) (getenv('TEST_NFSE_JOINVILLE_IM') ?: '987654321'),
                'FISCAL_CERT_PATH' => $this->projectRoot . '/certs/cert2026-senha-free2026.pfx',
                'FISCAL_CERT_PASSWORD' => 'free2026',
                'FISCAL_CNPJ' => '83188342000104',
                'FISCAL_RAZAO_SOCIAL' => 'FREELINE INFORMATICA LTDA',
                'FISCAL_UF' => 'SC',
            ],
            'tomador_defaults' => [
                'razao_social' => 'JOHNNATHAN VICTOR GONCALVES SABBA',
                'cep' => '89220650',
                'endereco' => [
                    'numero' => 'S/N',
                ],
            ],
        ]);

        $this->assertSame('send', $result['mode']);
        $this->assertNotSame('', trim((string) $result['request_xml']));
        $this->assertNotSame('', trim((string) $result['soap_envelope']));
        $this->assertIsArray($result['parsed_response']);
        $this->assertSuccessfulRealResponse('joinville', $result['parsed_response']);
    }

    private function externalTestsEnabled(): bool
    {
        $value = getenv('ENABLE_EXTERNAL_TESTS');
        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function municipalRealTestsEnabled(): bool
    {
        $value = getenv('ENABLE_NFSE_MUNICIPAL_REAL_TESTS');
        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveTomadorDocumento(string $preferredEnvKey): string
    {
        $preferred = getenv($preferredEnvKey);
        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        $legacyCnpj = getenv(str_replace('_DOC', '_CNPJ', $preferredEnvKey));
        if (is_string($legacyCnpj) && trim($legacyCnpj) !== '') {
            return trim($legacyCnpj);
        }

        return self::DEFAULT_TOMADOR_CPF;
    }

    private function resolveBelemProtocolo(): string
    {
        $preferred = getenv('TEST_NFSE_BELEM_PROTOCOLO');
        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        return self::DEFAULT_BELEM_PROTOCOLO;
    }

    private function applyEnvOverrides(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function assertSuccessfulRealResponse(string $municipio, array $parsedResponse): void
    {
        $status = (string) ($parsedResponse['status'] ?? 'unknown');
        $faultMessage = trim((string) (($parsedResponse['fault']['message'] ?? '')));
        $mensagens = array_values(array_filter(
            is_array($parsedResponse['mensagens'] ?? null) ? $parsedResponse['mensagens'] : [],
            static fn (mixed $message): bool => is_string($message) && trim($message) !== ''
        ));

        if ($faultMessage !== '') {
            $this->fail("{$municipio}: SOAP Fault retornado pela prefeitura: {$faultMessage}");
        }

        if ($status !== 'success') {
            $details = $mensagens !== [] ? implode(' | ', $mensagens) : ($parsedResponse['raw_xml'] ?? 'sem detalhes');
            $this->fail("{$municipio}: retorno não autorizado. status={$status}. detalhes={$details}");
        }

        $this->assertSame('success', $status);
    }

    private function assertSuccessfulRealConsultaResponse(string $municipio, array $consulta): void
    {
        $parsedResponse = is_array($consulta['parsed_response'] ?? null) ? $consulta['parsed_response'] : [];
        $artifacts = is_array($consulta['artifacts'] ?? null) ? $consulta['artifacts'] : [];
        $transport = is_array($artifacts['transport'] ?? null) ? $artifacts['transport'] : [];

        $status = (string) ($parsedResponse['status'] ?? 'unknown');
        $faultMessage = trim((string) (($parsedResponse['fault']['message'] ?? '')));
        $signatureVariant = (string) ($transport['signature_variant'] ?? 'prestador_reference');

        if ($faultMessage !== '') {
            $details = json_encode([
                'signature_variant' => $signatureVariant,
                'retry_attempts' => $transport['retry_attempts'] ?? null,
                'request_xml' => $artifacts['request_xml'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->fail("{$municipio}: SOAP Fault na consulta de lote: {$faultMessage}. detalhes={$details}");
        }

        if ($status !== 'success') {
            $details = json_encode([
                'status' => $status,
                'mensagens' => $parsedResponse['mensagens'] ?? [],
                'signature_variant' => $signatureVariant,
                'retry_attempts' => $transport['retry_attempts'] ?? null,
                'request_xml' => $artifacts['request_xml'] ?? null,
                'response_xml' => $artifacts['response_xml'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->fail("{$municipio}: consulta de lote nao autorizada. detalhes={$details}");
        }

        $this->assertNotEmpty($artifacts['request_xml'] ?? '');
        $this->assertSame('success', $status);
    }
}
