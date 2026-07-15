<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeScaffoldScriptsTest extends TestCase
{
    public function test_scaffold_family_dry_run_generates_expected_artifacts(): void
    {
        $output = $this->runScript(
            'scripts/nfse/scaffold-family.php --family=PROVEDOR_TESTE --layout-family=ABRASF_203 --schema-package=PROVEDOR_TESTE --dry-run'
        );
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('family', $data['mode']);
        $this->assertTrue($data['dry_run']);
        $this->assertSame('PROVEDOR_TESTE', $data['context']['family_key']);

        $paths = array_column($data['generated_files'], 'path');
        $this->assertContains('build/nfse-scaffold/families/PROVEDOR_TESTE/src/Providers/NFSe/Municipal/ProvedorTesteProvider.php', $this->normalizePaths($paths));
        $this->assertContains('build/nfse-scaffold/families/PROVEDOR_TESTE/snippets/nfse-provider-family.json', $this->normalizePaths($paths));
    }

    public function test_scaffold_municipio_dry_run_uses_catalog_and_manifest_hints(): void
    {
        $output = $this->runScript(
            'scripts/nfse/scaffold-municipio.php --ibge=1303536 --dry-run'
        );
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('municipio', $data['mode']);
        $this->assertSame('presidente-figueiredo', $data['context']['slug']);
        $this->assertSame('ISSWEB_AM', $data['context']['family_key']);
        $this->assertSame('custom_override', $data['context']['source']);
        $this->assertArrayHasKey('official_validation_url_template', $data['context']['provider_config_overrides']);
    }

    private function runScript(string $command): string
    {
        $root = dirname(__DIR__, 2);
        $full = sprintf('cd %s && php %s 2>&1', escapeshellarg($root), $command);
        exec($full, $lines, $exitCode);

        $output = implode(PHP_EOL, $lines);
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function normalizePaths(array $paths): array
    {
        $root = dirname(__DIR__, 2).'/';

        return array_map(
            static fn (string $path): string => str_replace($root, '', $path),
            $paths
        );
    }
}
