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
    private const DEFAULT_TOMADOR_CPF = '12345678909';

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
            'env_overrides' => $this->resolveBelemEnvOverrides('homologacao'),
            'tomador_defaults' => [
                'razao_social' => 'TOMADOR DE HOMOLOGACAO',
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
        $this->applyEnvOverrides($this->resolveBelemEnvOverrides('homologacao'));

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
            'env_overrides' => $this->resolveJoinvilleEnvOverrides(),
            'tomador_defaults' => [
                'razao_social' => 'TOMADOR DE HOMOLOGACAO',
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
        $preferred = $this->envValue($preferredEnvKey);
        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        $legacyCnpj = $this->envValue(str_replace('_DOC', '_CNPJ', $preferredEnvKey));
        if (is_string($legacyCnpj) && trim($legacyCnpj) !== '') {
            return trim($legacyCnpj);
        }

        return self::DEFAULT_TOMADOR_CPF;
    }

    private function resolveBelemProtocolo(): string
    {
        $preferred = $this->envValue('TEST_NFSE_BELEM_PROTOCOLO');
        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        $this->markTestSkipped('Defina TEST_NFSE_BELEM_PROTOCOLO para consultar lote real de Belém.');
    }

    private function applyEnvOverrides(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function resolveBelemEnvOverrides(string $ambiente): array
    {
        $overrides = [
            'FISCAL_ENVIRONMENT' => $ambiente,
            'FISCAL_IM' => $this->requiredEnvValue('TEST_NFSE_BELEM_IM', 'FISCAL_IM'),
            'FISCAL_CERT_PATH' => $this->resolvePath(
                $this->requiredEnvValue('TEST_NFSE_BELEM_CERT_PATH', 'FISCAL_CERT_PATH')
            ),
            'FISCAL_CERT_PASSWORD' => (string) ($this->envValue('TEST_NFSE_BELEM_CERT_PASSWORD') ?? $this->envValue('FISCAL_CERT_PASSWORD') ?? ''),
        ];

        $opensslConf = $this->envValue('TEST_NFSE_BELEM_OPENSSL_CONF') ?? $this->envValue('OPENSSL_CONF');
        if (is_string($opensslConf) && trim($opensslConf) !== '') {
            $overrides['OPENSSL_CONF'] = $this->resolvePath($opensslConf);
        }

        return $overrides;
    }

    private function resolveJoinvilleEnvOverrides(): array
    {
        return [
            'FISCAL_ENVIRONMENT' => 'homologacao',
            'FISCAL_IM' => $this->requiredEnvValue('TEST_NFSE_JOINVILLE_IM', 'FISCAL_IM'),
            'FISCAL_CERT_PATH' => $this->resolvePath(
                $this->requiredEnvValue('TEST_NFSE_JOINVILLE_CERT_PATH', 'FISCAL_CERT_PATH')
            ),
            'FISCAL_CERT_PASSWORD' => $this->requiredEnvValue('TEST_NFSE_JOINVILLE_CERT_PASSWORD', 'FISCAL_CERT_PASSWORD'),
            'FISCAL_CNPJ' => $this->requiredEnvValue('TEST_NFSE_JOINVILLE_CNPJ', 'FISCAL_CNPJ'),
            'FISCAL_RAZAO_SOCIAL' => $this->requiredEnvValue('TEST_NFSE_JOINVILLE_RAZAO_SOCIAL', 'FISCAL_RAZAO_SOCIAL'),
            'FISCAL_UF' => $this->requiredEnvValue('TEST_NFSE_JOINVILLE_UF', 'FISCAL_UF'),
        ];
    }

    private function envValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function requiredEnvValue(string $preferredKey, ?string $fallbackKey = null): string
    {
        $keys = array_filter([$preferredKey, $fallbackKey], static fn (?string $key): bool => is_string($key) && $key !== '');

        foreach ($keys as $key) {
            $value = $this->envValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        $details = implode(' ou ', $keys);
        $this->markTestSkipped("Defina {$details} para executar esta integração real.");
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim($path, './');
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
