<?php

namespace Tests\Integration;

use freeline\FiscalCore\Adapters\NF\NFeAdapter;
use freeline\FiscalCore\Support\CertificateManager;
use freeline\FiscalCore\Support\ConfigManager;
use freeline\FiscalCore\Support\ToolsFactory;
use PHPUnit\Framework\TestCase;
use freeline\FiscalCore\Support\XmlUtils;
/**
 * @group integration
 */
class NFeConsultaNotasEmitidasParaEstabelecimentoTest extends TestCase
{
    private NFeAdapter $adapter;

    protected function setUp(): void
    {
        if (!$this->externalTestsEnabled()) {
            $this->markTestSkipped('Defina ENABLE_EXTERNAL_TESTS=true para executar integrações reais com SEFAZ.');
        }

        [$certPath, $certPassword] = $this->resolveCertificateCredentials();

        $_ENV['FISCAL_CERT_PATH'] = $certPath;
        $_ENV['FISCAL_CERT_PASSWORD'] = $certPassword;
        $_ENV['FISCAL_ENVIRONMENT'] = $_ENV['FISCAL_ENVIRONMENT'] ?? 'homologacao';
        $_ENV['FISCAL_UF'] = $_ENV['FISCAL_UF'] ?? 'SC';

        putenv('FISCAL_CERT_PATH=' . $certPath);
        putenv('FISCAL_CERT_PASSWORD=' . $certPassword);
        putenv('FISCAL_ENVIRONMENT=' . $_ENV['FISCAL_ENVIRONMENT']);
        putenv('FISCAL_UF=' . $_ENV['FISCAL_UF']);

        ConfigManager::getInstance()->reload();
        CertificateManager::reload();

        $this->adapter = new NFeAdapter(ToolsFactory::createNFeTools());
    }

    public function test_consulta_notas_emitidas_para_estabelecimento_por_nsu(): void
    {
        $xml = $this->adapter->consultaNotasEmitidasParaEstabelecimento(0, 0, null, 'AN');

        $this->assertIsString($xml);
        $this->assertNotSame('', trim($xml));
        $this->assertStringContainsString('<retDistDFeInt', $xml);

        $status = $this->extractStatus($xml);
        $this->assertContains($status, ['137', '138'], "cStat inesperado para consulta por NSU: {$status}");
    }

    public function test_consulta_notas_emitidas_para_estabelecimento_por_chave(): void
    {
        $chave = getenv('TEST_NFE_CHAVE') ?: $this->extractChaveFromNsuQuery();
        if (!$chave) {
            $this->markTestSkipped(
                'Nenhuma chave disponível. Defina TEST_NFE_CHAVE=44_digitos ou execute com um certificado que retorne documentos na consulta por NSU.'
            );
        }

        $xml = $this->adapter->consultaNotasEmitidasParaEstabelecimento(0, 0, $chave, 'AN');

        $this->assertIsString($xml);
        $this->assertNotSame('', trim($xml));
        $this->assertStringContainsString('<retDistDFeInt', $xml);

        $status = $this->extractStatus($xml);
        $this->assertContains($status, ['137', '138'], "cStat inesperado para consulta por chave: {$status}");
    }

    private function extractChaveFromNsuQuery(): ?string
    {
        $xml = $this->adapter->consultaNotasEmitidasParaEstabelecimento(0, 0, null, 'AN');
        $response = @simplexml_load_string($xml);
        if ($response === false || !isset($response->loteDistDFeInt->docZip)) {
            return null;
        }

        foreach ($response->loteDistDFeInt->docZip as $docZip) {
            $encoded = (string) $docZip;
            $binary = base64_decode($encoded, true);
            if ($binary === false) {
                continue;
            }

            $decoded = @gzdecode($binary);
            if ($decoded === false) {
                continue;
            }

            if (preg_match('/<chNFe>(\d{44})<\/chNFe>/', $decoded, $matches) === 1) {
                return $matches[1];
            }

            if (preg_match('/Id="NFe(\d{44})"/', $decoded, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function extractStatus(string $xml): string
    {
        $response = XmlUtils::parseSefazRetorno($xml);
        if ($response === []) {
            $this->fail('Resposta inválida (não é XML): ' . mb_substr($xml, 0, 300));
        }

        return (string) ($response['lote']['cStat'] ?? '');

    }

    private function externalTestsEnabled(): bool
    {
        $value = getenv('ENABLE_EXTERNAL_TESTS');
        if ($value === false) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveCertificateCredentials(): array
    {
        $envPath = getenv('TEST_CERT_PATH') ?: getenv('FISCAL_CERT_PATH');
        $envPassword = getenv('TEST_CERT_PASSWORD') ?: getenv('FISCAL_CERT_PASSWORD');

        if (is_string($envPath) && $envPath !== '' && is_string($envPassword)) {
            $candidate = $this->resolvePath($envPath);
            if (is_file($candidate)) {
                return [$candidate, $envPassword];
            }
        }

        $this->markTestSkipped(
            'Certificado não encontrado. Defina TEST_CERT_PATH/TEST_CERT_PASSWORD ou FISCAL_CERT_PATH/FISCAL_CERT_PASSWORD.'
        );
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return dirname(__DIR__, 2) . '/' . ltrim($path, './');
    }
}
