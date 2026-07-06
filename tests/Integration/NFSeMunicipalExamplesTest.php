<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeMunicipalExamplesTest extends TestCase
{
    public function testMultiplosMunicipiosExampleRunsWithConsistentPayloads(): void
    {
        $output = $this->runScript('examples/avancado/01-multiplos-municipios.php');

        $this->assertStringContainsString('Status preview: success', $output);
        $this->assertStringContainsString('Numero preview: 1105', $output);
        $this->assertStringContainsString('Preview municipal ignorado', $output);
        $this->assertStringContainsString('fluxo NFSe nacional', $output);
        $this->assertStringNotContainsString('Dados inválidos', $output);
    }

    public function testFunctionalMunicipalEmissionExampleRunsWithoutCallingPrefeitura(): void
    {
        $output = $this->runScript('examples/avancado/03-emissao-municipal-funcional.php');

        $this->assertStringContainsString('Status parseado: success', $output);
        $this->assertStringContainsString('Nenhuma prefeitura foi acionada neste exemplo.', $output);
        $this->assertStringContainsString('Preview municipal ignorado', $output);
        $this->assertStringContainsString('fluxo NFSe nacional', $output);
    }

    private function runScript(string $relativePath): string
    {
        $root = dirname(__DIR__, 2);
        $script = $root . '/' . $relativePath;
        $command = sprintf('env -u OPENSSL_CONF php %s 2>&1', escapeshellarg($script));
        exec($command, $lines, $exitCode);

        $output = implode(PHP_EOL, $lines);
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }
}
