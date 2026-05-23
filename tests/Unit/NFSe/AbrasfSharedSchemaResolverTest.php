<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Support\NFSeSchemaResolver;

final class AbrasfSharedSchemaResolverTest extends TestCase
{
    public function testAbrasfSharedEmitirSchemaUsesSharedRoot(): void
    {
        $schemaPath = (new NFSeSchemaResolver())->resolve('ABRASF_SHARED', 'emitir');
        $normalizedPath = str_replace('\\', '/', $schemaPath);

        $this->assertStringContainsString('/resources/nfse/schemas/ABRASF_SHARED/', $normalizedPath);
        $this->assertStringEndsWith('/enviar_lote_rps_sincrono_envio.xsd', $normalizedPath);
        $this->assertFileExists($schemaPath);
    }

    public function testDsfAliasEmitirSchemaUsesSharedRoot(): void
    {
        $schemaPath = (new NFSeSchemaResolver())->resolve('DSF', 'emitir');
        $normalizedPath = str_replace('\\', '/', $schemaPath);

        $this->assertStringContainsString('/resources/nfse/schemas/ABRASF_SHARED/', $normalizedPath);
        $this->assertStringEndsWith('/enviar_lote_rps_sincrono_envio.xsd', $normalizedPath);
        $this->assertFileExists($schemaPath);
    }
}

