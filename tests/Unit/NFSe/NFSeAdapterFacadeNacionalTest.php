<?php

namespace Tests\Unit\NFSe;

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;
use Tests\Fakes\FakeNfseProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Fakes/FakeNfseProvider.php';

class NFSeAdapterFacadeNacionalTest extends TestCase
{
    public function test_policy_nacional_nao_exige_codigo_municipal(): void
    {
        $adapter = new NFSeAdapter('nfse_nacional', new FakeNfseProvider());

        $policy = $adapter->getProviderInfo()['form_policy'];

        $this->assertSame('nfse_nacional_policy', $policy['policy_source']);
        $this->assertNotContains('service.municipal_code', $policy['required_fields']);
        $this->assertContains('service.municipal_code', $policy['visible_fields']);
        $this->assertContains('service.national_tax_code', $policy['required_fields']);
        $this->assertContains('service.nbs', $policy['required_fields']);
    }

    public function test_adapter_lanca_erro_quando_provider_nao_suporta_capability_nacional(): void
    {
        $provider = new class () implements NFSeProviderConfigInterface {
            public function emitir(array $dados): string
            {
                return '<ok />';
            }
            public function consultar(string $chave): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<ok />'], [], ['chave_consulta' => $chave]);
            }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
            {
                return true;
            }
            public function substituir(string $chave, array $dados): string
            {
                return '<ok />';
            }
            public function getWsdlUrl(): string
            {
                return 'https://example.test';
            }
            public function getVersao(): string
            {
                return '2.02';
            }
            public function getAliquotaFormat(): string
            {
                return 'decimal';
            }
            public function getCodigoMunicipio(): string
            {
                return '4106902';
            }
            public function getAmbiente(): string
            {
                return 'homologacao';
            }
            public function getTimeout(): int
            {
                return 30;
            }
            public function getAuthConfig(): array
            {
                return [];
            }
            public function getNationalApiBaseUrl(): string
            {
                return '';
            }
            public function validarDados(array $dados): bool
            {
                return true;
            }
            public function consultarContribuinteCnc(string $cnc): array
            {
                return ['status' => 'ok'];
            }

            public function verificarHabilitacaoCnc(string $cnpj): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [
                    'provider' => 'nfse_nacional',
                    'timeout' => 30,
                    'endpoints' => []
                ];
            }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
            {
                return ['data' => [], 'metadata' => ['source' => 'test']];
            }

            public function validarLayoutDps(array $payload, bool $checkCatalog = true): bool
            {
                return true;
            }

            public function gerarXmlDpsPreview(array $payload): string
            {
                return '<xml-preview />';
            }

            public function validarXmlDps(array $payload): bool
            {
                return true;
            }

            public function validarPrestador(array $prestador): bool
            {
                return true;
            }

            public function validarMunicipio(?string $municipio = null): bool
            {
                return true;
            }
        };

        $adapter = new NFSeAdapter('curitiba', $provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('não suporta capacidades avançadas');

        $adapter->consultarPorRps([
            'numero' => '1',
            'serie' => 'A',
            'tipo' => '1',
        ]);
    }

    public function test_facade_retorna_fiscal_response_para_operacoes_nacionais(): void
    {
        $provider = new class () implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface {
            public function emitir(array $dados): string
            {
                return '<ok />';
            }
            public function consultar(string $chave): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta />'], [], ['chave_consulta' => $chave]);
            }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
            {
                return true;
            }
            public function substituir(string $chave, array $dados): string
            {
                return '<substituicao />';
            }
            public function getWsdlUrl(): string
            {
                return 'https://example.test';
            }
            public function getVersao(): string
            {
                return '1.00';
            }
            public function getAliquotaFormat(): string
            {
                return 'decimal';
            }
            public function getCodigoMunicipio(): string
            {
                return '0000000';
            }
            public function getAmbiente(): string
            {
                return 'homologacao';
            }
            public function getTimeout(): int
            {
                return 30;
            }
            public function getAuthConfig(): array
            {
                return [];
            }
            public function getNationalApiBaseUrl(): string
            {
                return 'https://api.local';
            }
            public function validarDados(array $dados): bool
            {
                return true;
            }
            public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar_rps', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta-rps />'], [], ['chave_consulta' => (string) ($identificacaoRps['numero'] ?? '')]);
            }
            public function consultarLote(string $protocolo): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar_lote', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta-lote />'], [], ['chave_consulta' => $protocolo]);
            }
            public function baixarXml(string $chave): string
            {
                return '<xml-download />';
            }
            public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
            {
                return (new NFSeResultNormalizer())->normalizePdfBase64(base64_encode('pdf'));
            }
            public function listarMunicipiosNacionais(bool $forceRefresh = false): array
            {
                return [
                    'data' => [['codigo_municipio' => '4106902']],
                    'metadata' => ['source' => 'cache', 'stale' => false],
                ];
            }
            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array
            {
                return [
                    'data' => [['item_lista_servico' => '0107', 'aliquota' => 2.0]],
                    'metadata' => ['source' => 'remote', 'stale' => false],
                ];
            }

            public function consultarContribuinteCnc(string $cnc): array
            {
                return [
                    'suportado' => false,
                    'cnc' => $cnc,
                ];
            }

            public function verificarHabilitacaoCnc(string $cnc): bool
            {
                return false;
            }

            public function getConfig(): array
            {
                return [];
            }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
            {
                return ['data' => [], 'metadata' => ['source' => 'test']];
            }

            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function gerarXmlDpsPreview(array $payload): string
            {
                return '<preview/>';
            }

            public function validarXmlDps(array $payload): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function validarPrestador(array $prestador): array
            {
                return ['valid' => true];
            }

            public function validarMunicipio(?string $municipio = null): array
            {
                return ['valid' => true];
            }
        };

        $adapter = new NFSeAdapter('nfse_nacional', $provider);
        $facade = new NFSeFacade('nfse_nacional', $adapter);

        $consultaRps = $facade->consultarPorRps(['numero' => '1', 'serie' => 'A', 'tipo' => '1']);
        $this->assertTrue($consultaRps->isSuccess());
        $this->assertSame('nfse_consulta_rps', $consultaRps->getData('type'));

        $municipios = $facade->listarMunicipiosNacionais();
        $this->assertTrue($municipios->isSuccess());
        $this->assertSame('4106902', $municipios->getData()[0]['codigo_municipio']);

        $aliquotas = $facade->consultarAliquotasMunicipio('4106902');
        $this->assertTrue($aliquotas->isSuccess());
        $this->assertSame(2.0, $aliquotas->getData()[0]['aliquota']);

        $contribuinte = $adapter->consultarContribuinteCnc('11222333000181');
        $this->assertFalse($contribuinte['suportado']);
        $this->assertSame('11222333000181', $contribuinte['cnc']);

        $habilitacao = $adapter->verificarHabilitacaoCnc('11222333000181');
        $this->assertFalse($habilitacao);
    }

    public function test_facade_bloqueia_emissao_legada_de_manaus_no_fluxo_nacional(): void
    {
        $provider = new class () implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface {
            public function emitir(array $dados): string
            {
                return '<ok />';
            }

            public function consultar(string $chave): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta />'], [], ['chave_consulta' => $chave]);
            }

            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
            {
                return true;
            }

            public function substituir(string $chave, array $dados): string
            {
                return '<substituicao />';
            }

            public function getWsdlUrl(): string
            {
                return 'https://example.test';
            }

            public function getVersao(): string
            {
                return '1.00';
            }

            public function getAliquotaFormat(): string
            {
                return 'decimal';
            }

            public function getCodigoMunicipio(): string
            {
                return '1302603';
            }

            public function getAmbiente(): string
            {
                return 'homologacao';
            }

            public function getTimeout(): int
            {
                return 30;
            }

            public function getAuthConfig(): array
            {
                return [];
            }

            public function getNationalApiBaseUrl(): string
            {
                return 'https://api.local';
            }

            public function validarDados(array $dados): bool
            {
                return true;
            }

            public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar_rps', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta-rps />'], [], ['chave_consulta' => (string) ($identificacaoRps['numero'] ?? '')]);
            }

            public function consultarLote(string $protocolo): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar_lote', ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<consulta-lote />'], [], ['chave_consulta' => $protocolo]);
            }

            public function baixarXml(string $chave): string
            {
                return '<xml-download />';
            }

            public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
            {
                return (new NFSeResultNormalizer())->normalizePdfBase64(base64_encode('pdf'));
            }

            public function listarMunicipiosNacionais(bool $forceRefresh = false): array
            {
                return ['data' => [], 'metadata' => []];
            }

            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array
            {
                return ['data' => [], 'metadata' => []];
            }

            public function consultarContribuinteCnc(string $cnc): array
            {
                return [];
            }

            public function verificarHabilitacaoCnc(string $cnc): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }

            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array
            {
                return ['data' => [], 'metadata' => []];
            }

            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array
            {
                return ['valid' => true, 'errors' => [], 'warnings' => []];
            }

            public function gerarXmlDpsPreview(array $payload): string
            {
                return '<preview/>';
            }

            public function validarXmlDps(array $payload): array
            {
                return ['valid' => true, 'errors' => []];
            }

            public function validarPrestador(array $prestador): array
            {
                return ['valid' => true];
            }

            public function validarMunicipio(?string $municipio = null): array
            {
                return ['valid' => true];
            }
        };

        $facade = new NFSeFacade('manaus', new NFSeAdapter('manaus', $provider));

        $response = $facade->emitir([
            'dCompet' => '2025-12-31',
            'dhEmi' => '2025-12-31T10:00:00-04:00',
        ]);

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_MANAUS_LEGACY_PERIOD', $response->getErrorCode());
        $this->assertSame('2025-12-31', $response->getMetadata('reference_date'));
        $this->assertStringContainsString('2026-01-01', (string) $response->getError());
    }
}
