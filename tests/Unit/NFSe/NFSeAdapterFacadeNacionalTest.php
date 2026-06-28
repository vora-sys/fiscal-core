<?php

namespace Tests\Unit\NFSe;

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeImpressaoResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Contracts\NFSeOperationalIntrospectionInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
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
        $this->assertNotContains('servico.cTribMun', $policy['required_fields']);
        $this->assertContains('servico.cTribMun', $policy['visible_fields']);
        $this->assertContains('servico.cTribNac', $policy['required_fields']);
        $this->assertContains('servico.cNBS', $policy['required_fields']);
        $this->assertSame('text', $policy['field_schema']['servico.cTribNac']['control']);
        $this->assertSame('select', $policy['field_schema']['prestador.opSimpNac']['control']);
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

    public function test_facade_preserva_dps_em_metadata_quando_emissao_nacional_falha(): void
    {
        $provider = new NacionalProvider($this->buildNacionalConfig(function () {
            throw new \RuntimeException('HTTP 400 na operação /nfse | resposta: {"erros":[{"Codigo":"E36"}]}');
        }));
        $facade = new NFSeFacade('nfse_nacional', new NFSeAdapter('nfse_nacional', $provider));

        $response = $facade->emitir($this->dadosNacionalValidos());

        $this->assertTrue($response->isError());
        $metadata = $response->getMetadata();
        $this->assertStringContainsString('<DPS', (string) ($metadata['emissao']['artifacts']['request_xml'] ?? ''));
        $this->assertStringContainsString('<infDPS', (string) ($metadata['emissao']['artifacts']['request_xml'] ?? ''));
        $this->assertSame('error', $metadata['emissao']['parsed_response']['status'] ?? null);
        $this->assertSame('E36', $response->getError());
        $this->assertSame('E36', $response->getErrorCode());
        $this->assertSame('E36', $metadata['emissao']['parsed_response']['errors'][0]['code'] ?? null);
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

        $substituicao = $facade->substituir('NFSE-1', ['motivo_substituicao' => 'Correcao']);
        $this->assertTrue($substituicao->isSuccess());
        $this->assertSame('nfse_substituicao', $substituicao->getData('type'));
        $this->assertSame('substituir', $substituicao->getData('substituicao')['operation']);
        $this->assertSame('<substituicao />', $substituicao->getData('substituicao')['resultado']);
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

    public function test_facade_bloqueia_emissao_pre_corte_generica_para_municipio_nacional(): void
    {
        $facade = new NFSeFacade('recife', new NFSeAdapter('recife', new FakeNfseProvider()));

        $response = $facade->emitir([
            'dCompet' => '2025-10-14',
            'dhEmi' => '2025-10-14T10:00:00-03:00',
        ]);

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_NATIONAL_MIGRATION_LEGACY_PERIOD', $response->getErrorCode());
        $this->assertSame('2025-10-14', $response->getMetadata('reference_date'));
        $this->assertSame('2025-10-15', $response->getMetadata('legacy_cutoff'));
        $this->assertSame('2611606', $response->getMetadata('municipio_ibge'));
        $this->assertStringContainsString('Recife', (string) $response->getError());
    }

    public function test_facade_permite_emissao_nacional_na_data_de_vigencia_da_migracao(): void
    {
        $facade = new NFSeFacade('recife', new NFSeAdapter('recife', new FakeNfseProvider()));

        $response = $facade->emitir([
            'dCompet' => '2025-10-15',
            'dhEmi' => '2025-10-15T10:00:00-03:00',
        ]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('nfse_xml', $response->getData('type'));
    }

    public function test_facade_baixar_danfse_nacional_faz_fallback_local_via_baixar_xml(): void
    {
        $xml = $this->nfseNacionalFixture('nfse_nacional_completa.xml');

        $provider = new class ($xml) implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface {
            public function __construct(private readonly string $xml)
            {
            }

            public function emitir(array $dados): string { return '<ok />'; }
            public function consultar(string $chave): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar', [
                    'status' => 'success',
                    'numero' => '202600001234',
                    'codigo_verificacao' => 'ABC',
                    'raw_xml' => $this->xml,
                ], [], ['chave_consulta' => $chave]);
            }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool { return true; }
            public function substituir(string $chave, array $dados): string { return '<ok />'; }
            public function getWsdlUrl(): string { return 'https://example.test'; }
            public function getVersao(): string { return '1.00'; }
            public function getAliquotaFormat(): string { return 'decimal'; }
            public function getCodigoMunicipio(): string { return '1302603'; }
            public function getAmbiente(): string { return 'homologacao'; }
            public function getTimeout(): int { return 30; }
            public function getAuthConfig(): array { return []; }
            public function getNationalApiBaseUrl(): string { return 'https://api.local'; }
            public function validarDados(array $dados): bool { return true; }
            public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface { return $this->consultar((string) ($identificacaoRps['numero'] ?? '')); }
            public function consultarLote(string $protocolo): NFSeConsultaResultInterface { return $this->consultar($protocolo); }
            public function baixarXml(string $chave): string
            {
                return json_encode([
                    'status' => 'success',
                    'numero' => '202600001234',
                    'nfseXmlGZipB64' => base64_encode(gzencode($this->xml)),
                ], JSON_THROW_ON_ERROR);
            }
            public function baixarDanfse(string $chave): NFSeImpressaoResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeIndisponivel(
                    ['source' => 'download_danfse'],
                    ['parsed_response' => ['status' => 'success']]
                );
            }
            public function listarMunicipiosNacionais(bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarContribuinteCnc(string $cnc): array { return []; }
            public function verificarHabilitacaoCnc(string $cnc): bool { return true; }
            public function getConfig(): array { return []; }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array { return ['valid' => true, 'errors' => [], 'warnings' => []]; }
            public function gerarXmlDpsPreview(array $payload): string { return '<preview/>'; }
            public function validarXmlDps(array $payload): array { return ['valid' => true, 'errors' => []]; }
        };

        $facade = new NFSeFacade('nfse_nacional', new NFSeAdapter('nfse_nacional', $provider));

        $response = $facade->baixarDanfse('NFS123');

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('render_local', $response->getData('impressao')['source']);
        $this->assertSame('pdf_base64', $response->getData('impressao')['modo']);
        $this->assertStringStartsWith('%PDF', base64_decode((string) $response->getData('impressao')['pdf_base64'], true) ?: '');
        $this->assertStringContainsString('<CompNfse>', (string) $response->getData('documento')['xml']);
    }

    public function test_emitir_completo_nacional_prefere_render_local_quando_xml_final_esta_disponivel(): void
    {
        $xml = $this->nfseNacionalFixture('nfse_nacional_completa.xml');

        $provider = new class ($xml) implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface, NFSeOperationalIntrospectionInterface {
            private array $lastResponseData = [];
            private array $lastArtifacts = [];

            public function __construct(private readonly string $xml)
            {
            }

            public function emitir(array $dados): string
            {
                $this->lastResponseData = [
                    'status' => 'success',
                    'numero' => '202600001234',
                    'protocolo' => 'PROTOCOLO-123',
                    'nfseXmlGZipB64' => base64_encode(gzencode($this->xml)),
                ];
                $this->lastArtifacts = [
                    'request_xml' => '<DPS />',
                    'response_raw' => '<ok />',
                    'response_xml' => null,
                    'parsed_response' => $this->lastResponseData,
                ];

                return '<ok />';
            }
            public function consultar(string $chave): NFSeConsultaResultInterface
            {
                return (new NFSeResultNormalizer())->normalizeConsulta('consultar', [
                    'status' => 'success',
                    'numero' => '202600001234',
                    'codigo_verificacao' => 'ABC',
                    'raw_xml' => $this->xml,
                ], [], ['chave_consulta' => $chave]);
            }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool { return true; }
            public function substituir(string $chave, array $dados): string { return '<ok />'; }
            public function getWsdlUrl(): string { return 'https://example.test'; }
            public function getVersao(): string { return '1.00'; }
            public function getAliquotaFormat(): string { return 'decimal'; }
            public function getCodigoMunicipio(): string { return '1302603'; }
            public function getAmbiente(): string { return 'homologacao'; }
            public function getTimeout(): int { return 30; }
            public function getAuthConfig(): array { return []; }
            public function getNationalApiBaseUrl(): string { return 'https://api.local'; }
            public function validarDados(array $dados): bool { return true; }
            public function consultarPorRps(array $identificacaoRps): NFSeConsultaResultInterface { return $this->consultar((string) ($identificacaoRps['numero'] ?? '')); }
            public function consultarLote(string $protocolo): NFSeConsultaResultInterface { return $this->consultar($protocolo); }
            public function baixarXml(string $chave): string { return json_encode(['status' => 'success', 'nfseXmlGZipB64' => base64_encode(gzencode($this->xml))], JSON_THROW_ON_ERROR); }
            public function baixarDanfse(string $chave): NFSeImpressaoResultInterface { return (new NFSeResultNormalizer())->normalizeIndisponivel(); }
            public function listarMunicipiosNacionais(bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarContribuinteCnc(string $cnc): array { return []; }
            public function verificarHabilitacaoCnc(string $cnc): bool { return true; }
            public function getConfig(): array { return []; }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array { return ['valid' => true, 'errors' => [], 'warnings' => []]; }
            public function gerarXmlDpsPreview(array $payload): string { return '<preview/>'; }
            public function validarXmlDps(array $payload): array { return ['valid' => true, 'errors' => []]; }
            public function getLastResponseData(): array { return $this->lastResponseData; }
            public function getLastOperationArtifacts(): array { return $this->lastArtifacts; }
            public function getSupportedOperations(): array { return ['emitir', 'consultar', 'baixar_xml', 'baixar_danfse']; }
        };

        $facade = new NFSeFacade('nfse_nacional', new NFSeAdapter('nfse_nacional', $provider));

        $response = $facade->emitirCompleto($this->dadosNacionalValidos());

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('render_local', $response->getData('impressao')['source']);
        $this->assertSame('completo', $response->getData('flow_status'));
        $this->assertStringContainsString('<CompNfse>', (string) $response->getData('documento')['xml']);
    }

    public function test_facade_bloqueia_emissao_pre_corte_para_natal_migrado_nacional(): void
    {
        $facade = new NFSeFacade('natal', new NFSeAdapter('natal', new FakeNfseProvider()));

        $response = $facade->emitir([
            'dCompet' => '2025-12-31',
            'dhEmi' => '2025-12-31T10:00:00-03:00',
        ]);

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_NATIONAL_MIGRATION_LEGACY_PERIOD', $response->getErrorCode());
        $this->assertSame('2025-12-31', $response->getMetadata('reference_date'));
        $this->assertSame('2026-01-01', $response->getMetadata('legacy_cutoff'));
        $this->assertSame('2408102', $response->getMetadata('municipio_ibge'));
        $this->assertStringContainsString('Natal', (string) $response->getError());
    }

    public function test_facade_bloqueia_emissao_pre_corte_para_joinville_migrado_nacional(): void
    {
        $facade = new NFSeFacade('joinville', new NFSeAdapter('joinville', new FakeNfseProvider()));

        $response = $facade->emitir([
            'dCompet' => '2026-07-19',
            'dhEmi' => '2026-07-19T10:00:00-03:00',
        ]);

        $this->assertTrue($response->isError());
        $this->assertSame('NFSE_NATIONAL_MIGRATION_LEGACY_PERIOD', $response->getErrorCode());
        $this->assertSame('2026-07-19', $response->getMetadata('reference_date'));
        $this->assertSame('2026-07-20', $response->getMetadata('legacy_cutoff'));
        $this->assertSame('4209102', $response->getMetadata('municipio_ibge'));
        $this->assertStringContainsString('Joinville', (string) $response->getError());
    }

    public function test_facade_permite_preparacao_nacional_de_joinville_em_homologacao(): void
    {
        $facade = new NFSeFacade('joinville', new NFSeAdapter('joinville', new FakeNfseProvider()));

        $response = $facade->emitir([
            'tpAmb' => '2',
            'dCompet' => '2026-07-19',
            'dhEmi' => '2026-07-19T10:00:00-03:00',
        ]);

        $this->assertTrue($response->isSuccess(), (string) $response->getError());
        $this->assertSame('joinville', $response->getMetadata('municipio'));
        $this->assertSame('nfse_nacional', $response->getMetadata('provider_key'));
    }

    private function buildNacionalConfig(callable $httpClient): array
    {
        return [
            'codigo_municipio' => '3550308',
            'versao' => '1.01',
            'dps_versao' => '1.01',
            'ambiente' => 'homologacao',
            'api_base_url' => 'https://api.local',
            'timeout' => 10,
            'auth' => ['token' => 'abc'],
            'endpoints' => [
                'emitir' => '/nfse',
                'consultar' => '/nfse/{id}',
                'cancelar' => '/nfse/{id}/eventos',
                'substituir' => '/nfse',
                'consultar_rps' => '/nfse/consultar-rps',
                'consultar_lote' => '/nfse/consultar-lote',
                'baixar_xml' => '/nfse/download/xml',
                'baixar_danfse' => '/danfse/{chave}',
            ],
            'operation_methods' => [
                'emitir' => 'POST',
                'consultar' => 'GET',
                'cancelar' => 'POST',
                'substituir' => 'POST',
            ],
            'http_client' => $httpClient,
            'cache_dir' => sys_get_temp_dir() . '/fiscal-core-provider-' . uniqid(),
        ];
    }

    private function dadosNacionalValidos(): array
    {
        return [
            'prestador' => [
                'cnpj' => '11.222.333/0001-81',
                'inscricaoMunicipal' => '12345',
            ],
            'tomador' => [
                'documento' => '12345678901',
                'razaoSocial' => 'Tomador Teste',
            ],
            'servico' => [
                'codigo' => '0107',
                'discriminacao' => 'Servico de desenvolvimento',
                'aliquota' => 0.02,
            ],
            'valor_servicos' => 1000.00,
            'rps_numero' => '10',
            'rps_serie' => 'A1',
            'rps_tipo' => '1',
        ];
    }

    private function nfseNacionalFixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/' . $name);
        $this->assertNotFalse($contents);

        return (string) $contents;
    }
}
