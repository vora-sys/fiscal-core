<?php

namespace Tests\Unit\NFSe;

use sabbajohn\FiscalCore\Adapters\NF\NFSeAdapter;
use sabbajohn\FiscalCore\Contracts\NFSeNacionalCapabilitiesInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Facade\NFSeFacade;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use sabbajohn\FiscalCore\Providers\NFSe\NacionalProvider;
use PHPUnit\Framework\TestCase;

class NFSeNationalOnlyModeTest extends TestCase
{
    public function test_resolver_retorna_nacional_para_blank_ou_fallback_e_preserva_rotas_ativas(): void
    {
        $resolver = new NFSeProviderResolver();

        $this->assertSame('nfse_nacional', $resolver->resolveKey(null));
        $this->assertSame('nfse_nacional', $resolver->resolveKey('qualquer_valor'));
        $this->assertSame('BELEM_MUNICIPAL_2025', $resolver->resolveKey('belem'));
    }

    public function test_registry_faz_fallback_para_provider_nacional(): void
    {
        $registry = ProviderRegistry::getInstance();
        $provider = $registry->getByMunicipio('qualquer_valor');

        $this->assertInstanceOf(NacionalProvider::class, $provider);
    }

    public function test_facade_sinaliza_deprecacao_de_municipio_no_metadata(): void
    {
        $provider = new class implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface {
            public function emitir(array $dados): string { return '<ok />'; }
            public function consultar(string $chave): string { return '<ok />'; }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool { return true; }
            public function substituir(string $chave, array $dados): string { return '<ok />'; }
            public function getWsdlUrl(): string { return 'https://example.test'; }
            public function getVersao(): string { return '1.00'; }
            public function getAliquotaFormat(): string { return 'decimal'; }
            public function getCodigoMunicipio(): string { return '0000000'; }
            public function getAmbiente(): string { return 'homologacao'; }
            public function getTimeout(): int { return 30; }
            public function getAuthConfig(): array { return []; }
            public function getNationalApiBaseUrl(): string { return 'https://api.local'; }
            public function validarDados(array $dados): bool { return true; }
            public function consultarPorRps(array $identificacaoRps): string { return '<ok />'; }
            public function consultarLote(string $protocolo): string { return '<ok />'; }
            public function baixarXml(string $chave): string { return '<ok />'; }
            public function baixarDanfse(string $chave): string { return '<ok />'; }
            public function listarMunicipiosNacionais(bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarContribuinteCnc(string $cpfCnpj): array { return ['documento' => $cpfCnpj, 'habilitado' => true]; }
            public function verificarHabilitacaoCnc(string $cpfCnpj): bool { return true; }
            public function getConfig(): array { return []; }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array { return ['valid' => true, 'errors' => []]; }
            public function gerarXmlDpsPreview(array $payload): string { return '<preview />'; }
            public function validarXmlDps(array $payload): array { return ['valid' => true, 'errors' => []]; }
        };

        $adapter = new NFSeAdapter('municipio-inexistente', $provider);
        $facade = new NFSeFacade('municipio-inexistente', $adapter);
        $response = $facade->emitir([]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('nfse_nacional', $response->getMetadata('provider_key'));
        $this->assertTrue($response->getMetadata('municipio_ignored'));
        $this->assertNotEmpty($response->getMetadata('warnings'));
    }

    public function test_facade_default_sem_municipio_expresso_fica_no_fluxo_nacional(): void
    {
        $provider = new class implements NFSeProviderConfigInterface, NFSeNacionalCapabilitiesInterface {
            public function emitir(array $dados): string { return '<ok />'; }
            public function consultar(string $chave): string { return '<ok />'; }
            public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool { return true; }
            public function substituir(string $chave, array $dados): string { return '<ok />'; }
            public function getWsdlUrl(): string { return 'https://example.test'; }
            public function getVersao(): string { return '1.00'; }
            public function getAliquotaFormat(): string { return 'decimal'; }
            public function getCodigoMunicipio(): string { return '0000000'; }
            public function getAmbiente(): string { return 'homologacao'; }
            public function getTimeout(): int { return 30; }
            public function getAuthConfig(): array { return []; }
            public function getNationalApiBaseUrl(): string { return 'https://api.local'; }
            public function validarDados(array $dados): bool { return true; }
            public function consultarPorRps(array $identificacaoRps): string { return '<ok />'; }
            public function consultarLote(string $protocolo): string { return '<ok />'; }
            public function baixarXml(string $chave): string { return '<ok />'; }
            public function baixarDanfse(string $chave): string { return '<ok />'; }
            public function listarMunicipiosNacionais(bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarAliquotasMunicipio(string $codigoMunicipio, ?string $codigoServico = null, ?string $competencia = null, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function consultarContribuinteCnc(string $cpfCnpj): array { return ['documento' => $cpfCnpj, 'habilitado' => true]; }
            public function verificarHabilitacaoCnc(string $cpfCnpj): bool { return true; }
            public function getConfig(): array { return []; }
            public function consultarConvenioMunicipio(string $codigoMunicipio, bool $forceRefresh = false): array { return ['data' => [], 'metadata' => []]; }
            public function validarLayoutDps(array $payload, bool $checkCatalog = true): array { return ['valid' => true, 'errors' => []]; }
            public function gerarXmlDpsPreview(array $payload): string { return '<preview />'; }
            public function validarXmlDps(array $payload): array { return ['valid' => true, 'errors' => []]; }
        };

        $adapter = new NFSeAdapter('nacional', $provider);
        $facade = new NFSeFacade(nfse: $adapter);
        $response = $facade->getProviderInfo();

        $this->assertTrue($response->isSuccess());
        $this->assertSame('nfse_nacional', $response->getData('provider_key'));
        $this->assertSame('nacional', $response->getData('municipio'));
    }
}
