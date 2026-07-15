<?php

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Contracts\NFSeConsultaResultInterface;
use sabbajohn\FiscalCore\Contracts\NFSeProviderInterface;
use sabbajohn\FiscalCore\NFSe\ProviderRegistry;
use sabbajohn\FiscalCore\NFSe\ProviderResolver;
use sabbajohn\FiscalCore\Support\NFSeResultNormalizer;

class ProviderResolverTest extends TestCase
{
    public function test_resolves_registered_provider_by_key(): void
    {
        $registry = new ProviderRegistry;
        $registry->register('dummy', function (array $cfg): NFSeProviderInterface {
            return new class($cfg) implements NFSeProviderInterface
            {
                public function __construct(private array $cfg) {}

                public function emitir(array $dados): string
                {
                    return 'ok';
                }

                public function consultar(string $chave): NFSeConsultaResultInterface
                {
                    return (new NFSeResultNormalizer)->normalizeConsulta(
                        'consultar',
                        ['status' => 'success', 'numero' => '1', 'codigo_verificacao' => 'ABC', 'raw_xml' => '<ok />'],
                        [],
                        ['chave_consulta' => $chave]
                    );
                }

                public function cancelar(string $chave, string $motivo, ?string $protocolo = null): bool
                {
                    return true;
                }
            };
        });

        $resolver = new ProviderResolver(['provider' => 'dummy'], $registry);
        $provider = $resolver->resolve();

        $this->assertInstanceOf(NFSeProviderInterface::class, $provider);
        $this->assertSame('ok', $provider->emitir([]));
        $this->assertSame('x', $provider->consultar('x')->toArray()['documento']['chave_consulta']);
        $this->assertTrue($provider->cancelar('x', 'y'));
    }
}
