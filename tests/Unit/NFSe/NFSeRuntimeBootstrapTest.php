<?php

declare(strict_types=1);

namespace Tests\Unit\NFSe;

use PHPUnit\Framework\TestCase;
use NFePHP\Common\Certificate;
use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderConfigInterface;
use sabbajohn\FiscalCore\Support\CertificateManager;
use sabbajohn\FiscalCore\Support\ConfigManager;
use sabbajohn\FiscalCore\Support\NFSeProviderResolver;
use sabbajohn\FiscalCore\Support\NFSeRuntimeBootstrap;
use sabbajohn\FiscalCore\Support\ProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class NFSeRuntimeBootstrapTest extends TestCase
{
    public function test_make_provider_preserves_runtime_environment_without_reloading_config(): void
    {
        $configManager = new FakeConfigManager([
            'ambiente' => 1,
            'timeout' => 45,
            'nfse' => ['timeout' => 32],
            'empresa' => [
                'cnpj' => '83188342000104',
                'razao_social' => 'Freeline Informatica LTDA',
                'inscricao_municipal' => '33061',
            ],
        ]);

        $bootstrap = new NFSeRuntimeBootstrap(
            new FakeProviderRegistry(),
            new NFSeProviderResolver(),
            $configManager,
            new FakeCertificateManager(),
        );

        $result = $bootstrap->makeProvider('4209102');

        $this->assertFalse($configManager->reloadCalled);
        $this->assertSame('producao', $result['config']['ambiente']);
        $this->assertSame('producao', $result['provider']->getAmbiente());
        $this->assertSame(32, $result['provider']->getTimeout());
        $this->assertSame('33061', $result['config']['prestador']['inscricao_municipal']);
    }

    #[DataProvider('wave4MunicipioProvider')]
    public function test_make_provider_loads_wave4_catalog_defaults(string $municipio, string $providerKey, string $ibge): void
    {
        ProviderRegistry::getInstance()->reload();

        $configManager = new FakeConfigManager([
            'ambiente' => 2,
            'nfse' => ['timeout' => 25],
            'empresa' => [
                'cnpj' => '83188342000104',
                'razao_social' => 'Freeline Informatica LTDA',
                'inscricao_municipal' => '33061',
            ],
        ]);

        $bootstrap = new NFSeRuntimeBootstrap(
            ProviderRegistry::getInstance(),
            new NFSeProviderResolver(),
            $configManager,
            new FakeCertificateManager(),
        );

        $result = $bootstrap->makeProvider($municipio);

        $this->assertSame($providerKey, $result['provider_key']);
        $this->assertSame($ibge, $result['config']['codigo_municipio']);
        $this->assertSame('homologacao', $result['config']['ambiente']);
        $this->assertSame('123', (string) ($result['config']['payload_defaults']['rps']['numero'] ?? ''));
    }

    public static function wave4MunicipioProvider(): array
    {
        return [
            'aracaju' => ['aracaju', 'WEBISS', '2800308'],
            'feira-de-santana' => ['feira-de-santana', 'WEBISS', '2910800'],
            'itabuna' => ['itabuna', 'WEBISS', '2914802'],
            'vitoria-da-conquista' => ['vitoria-da-conquista', 'EL', '2933307'],
        ];
    }
}

final class FakeConfigManager extends ConfigManager
{
    public bool $reloadCalled = false;

    public function __construct(private array $store)
    {
    }

    public function reload(): void
    {
        $this->reloadCalled = true;
    }

    public function isProduction(): bool
    {
        return (int) $this->get('ambiente') === 1;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->store;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    public function getEmpresaConfig(): array
    {
        return [
            'cnpj' => (string) $this->get('empresa.cnpj', ''),
            'razao_social' => (string) $this->get('empresa.razao_social', ''),
            'inscricao_estadual' => (string) $this->get('empresa.inscricao_estadual', ''),
            'inscricao_municipal' => (string) $this->get('empresa.inscricao_municipal', ''),
        ];
    }
}

final class FakeCertificateManager extends CertificateManager
{
    public function __construct()
    {
    }

    public function getCertificate(): ?Certificate
    {
        return null;
    }
}

final class FakeProviderRegistry extends ProviderRegistry
{
    public function __construct()
    {
    }

    public function getConfigForMunicipio(?string $municipio): array
    {
        return [
            'provider_class' => FakeMunicipalProvider::class,
            'codigo_municipio' => '4209102',
            'municipio_nome' => 'Joinville',
            'signature_mode' => 'optional',
            'timeout' => 30,
        ];
    }
}

final class FakeMunicipalProvider implements NFSeProviderConfigInterface
{
    public function __construct(private array $config)
    {
    }

    public function emitir(array $dados): string
    {
        return '';
    }

    public function consultar(string $chave): NFSeConsultaResultInterface
    {
        return new class implements NFSeConsultaResultInterface {
            public function getConsulta(): array
            {
                return [];
            }

            public function getDocumento(): array
            {
                return [];
            }

            public function getImpressao(): array
            {
                return [];
            }

            public function getProvider(): array
            {
                return [];
            }

            public function getRaw(): array
            {
                return [];
            }

            public function toArray(): array
            {
                return [];
            }
        };
    }

    public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
    {
        return true;
    }

    public function substituir(string $chave, array $dados): string
    {
        return '';
    }

    public function getWsdlUrl(): string
    {
        return '';
    }

    public function getVersao(): string
    {
        return '3.00';
    }

    public function getAliquotaFormat(): string
    {
        return 'percentual';
    }

    public function getCodigoMunicipio(): string
    {
        return (string) ($this->config['codigo_municipio'] ?? '');
    }

    public function getAmbiente(): string
    {
        return (string) ($this->config['ambiente'] ?? 'homologacao');
    }

    public function getTimeout(): int
    {
        return (int) ($this->config['timeout'] ?? 30);
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
        return [];
    }

    public function verificarHabilitacaoCnc(string $cnc): bool
    {
        return false;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
