<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeUpdateUninfeCacheScriptTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    /** @var string[] */
    private array $localArtifacts = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->localArtifacts) as $path) {
            if (is_file($path)) {
                @unlink($path);

                continue;
            }

            if (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        $this->localArtifacts = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];
    }

    public function test_dry_run_does_not_create_cache_directory(): void
    {
        $parent = $this->makeTempDir();
        $cacheDir = $parent.'/cache/Uninfe';

        [$exitCode, $output] = $this->runScript(sprintf(
            'scripts/nfse/update-uninfe-cache.php --dry-run --no-fetch --cache-dir=%s --repo=%s --ref=main',
            escapeshellarg($cacheDir),
            escapeshellarg('https://github.com/Unimake/Uninfe.git')
        ));

        $this->assertSame(0, $exitCode, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['dry_run']);
        $this->assertSame($cacheDir, $report['cache_dir']);
        $this->assertSame('clone', $report['cache_actions'][0]['name']);
        $this->assertSame('checkout', $report['cache_actions'][1]['name']);
        $this->assertSame('skipped', $report['derived_steps'][0]['status']);
        $this->assertSame('source_missing_in_dry_run', $report['derived_steps'][0]['reason']);
        $this->assertFalse(is_dir($cacheDir));
    }

    public function test_dry_run_with_local_checkout_plans_derived_artifacts_without_writing(): void
    {
        $parent = $this->makeTempDir();
        $cacheDir = $this->makeFakeUninfeCheckout($parent);
        $catalogOutput = $parent.'/out/config/nfse/nfse-catalog-manifest.json';
        $schemasOutput = $parent.'/out/resources/nfse/schemas';
        $schemasManifest = $schemasOutput.'/manifest.json';
        $reconciliationOutput = $parent.'/out/docs/NFSE-UNINFE-RECONCILIACAO.md';
        $nfeReconciliationOutput = $parent.'/out/docs/NFE-UNINFE-RECONCILIACAO.md';
        $nfceReconciliationOutput = $parent.'/out/docs/NFCE-UNINFE-RECONCILIACAO.md';

        [$exitCode, $output] = $this->runScript(sprintf(
            'scripts/nfse/update-uninfe-cache.php --dry-run --skip-cache-update --cache-dir=%s --catalog-output=%s --schemas-output=%s --schemas-manifest=%s --reconciliation-output=%s --nfe-reconciliation-output=%s --nfce-reconciliation-output=%s',
            escapeshellarg($cacheDir),
            escapeshellarg($catalogOutput),
            escapeshellarg($schemasOutput),
            escapeshellarg($schemasManifest),
            escapeshellarg($reconciliationOutput),
            escapeshellarg($nfeReconciliationOutput),
            escapeshellarg($nfceReconciliationOutput)
        ));

        $this->assertSame(0, $exitCode, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['dry_run']);
        $this->assertSame([], $report['cache_actions']);
        $this->assertSame('planned', $report['derived_steps'][0]['status']);
        $this->assertSame('planned', $report['derived_steps'][1]['status']);
        $this->assertSame('planned', $report['derived_steps'][2]['status']);
        $this->assertSame('planned', $report['derived_steps'][3]['status']);
        $this->assertSame('planned', $report['derived_steps'][4]['status']);
        $this->assertFalse(is_file($catalogOutput));
        $this->assertFalse(is_file($schemasManifest));
        $this->assertFalse(is_file($reconciliationOutput));
        $this->assertFalse(is_file($nfeReconciliationOutput));
        $this->assertFalse(is_file($nfceReconciliationOutput));
    }

    public function test_local_checkout_generates_deterministic_derived_artifacts(): void
    {
        $parent = $this->makeTempDir();
        $cacheDir = $this->makeFakeUninfeCheckout($parent);
        $catalogOutput = $parent.'/out/config/nfse/nfse-catalog-manifest.json';
        $schemasOutput = $parent.'/out/resources/nfse/schemas';
        $schemasManifest = $schemasOutput.'/manifest.json';
        $reconciliationOutput = $parent.'/out/docs/NFSE-UNINFE-RECONCILIACAO.md';
        $nfeReconciliationOutput = $parent.'/out/docs/NFE-UNINFE-RECONCILIACAO.md';
        $nfceReconciliationOutput = $parent.'/out/docs/NFCE-UNINFE-RECONCILIACAO.md';
        $command = sprintf(
            'scripts/nfse/update-uninfe-cache.php --skip-cache-update --cache-dir=%s --catalog-output=%s --schemas-output=%s --schemas-manifest=%s --reconciliation-output=%s --nfe-reconciliation-output=%s --nfce-reconciliation-output=%s',
            escapeshellarg($cacheDir),
            escapeshellarg($catalogOutput),
            escapeshellarg($schemasOutput),
            escapeshellarg($schemasManifest),
            escapeshellarg($reconciliationOutput),
            escapeshellarg($nfeReconciliationOutput),
            escapeshellarg($nfceReconciliationOutput)
        );

        [$firstExitCode, $firstOutput] = $this->runScript($command);
        $this->assertSame(0, $firstExitCode, $firstOutput);

        $this->assertFileExists($catalogOutput);
        $this->assertFileExists($schemasOutput.'/FAKE/nfse.xsd');
        $this->assertFileExists($schemasManifest);
        $this->assertFileExists($reconciliationOutput);
        $this->assertFileExists($nfeReconciliationOutput);
        $this->assertFileExists($nfceReconciliationOutput);

        $firstCatalog = (string) file_get_contents($catalogOutput);
        $firstSchemas = (string) file_get_contents($schemasManifest);
        $firstReport = (string) file_get_contents($reconciliationOutput);
        $firstNfeReport = (string) file_get_contents($nfeReconciliationOutput);
        $firstNfceReport = (string) file_get_contents($nfceReconciliationOutput);

        [$secondExitCode, $secondOutput] = $this->runScript($command);
        $this->assertSame(0, $secondExitCode, $secondOutput);

        $this->assertSame($firstCatalog, (string) file_get_contents($catalogOutput));
        $this->assertSame($firstSchemas, (string) file_get_contents($schemasManifest));
        $this->assertSame($firstReport, (string) file_get_contents($reconciliationOutput));
        $this->assertSame($firstNfeReport, (string) file_get_contents($nfeReconciliationOutput));
        $this->assertSame($firstNfceReport, (string) file_get_contents($nfceReconciliationOutput));

        $catalog = json_decode($firstCatalog, true, 512, JSON_THROW_ON_ERROR);
        $schemas = json_decode($firstSchemas, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('1970-01-01T00:00:00+00:00', $catalog['generated_at']);
        $this->assertSame('FAKE', $catalog['municipio_overrides']['9999999']['provider_family']);
        $this->assertSame('imported', $schemas['families']['FAKE']['status']);
        $this->assertSame(hash_file('sha256', $schemasOutput.'/FAKE/nfse.xsd'), $schemas['families']['FAKE']['file_manifest'][0]['sha256']);
        $this->assertStringContainsString('Divergencias Inesperadas', $firstReport);
        $this->assertStringContainsString('# Reconciliacao UniNFe NFe', $firstNfeReport);
        $this->assertStringContainsString('# Reconciliacao UniNFe NFCe', $firstNfceReport);
    }

    public function test_raw_uninfe_and_local_secrets_remain_ignored(): void
    {
        $root = dirname(__DIR__, 2);
        $gitignore = (string) file_get_contents($root.'/.gitignore');

        $this->assertStringContainsString("\n.env\n", $gitignore);
        $this->assertStringContainsString("certs/*\n", $gitignore);
        $this->assertStringContainsString("\nUninfe\n", $gitignore);
        $this->assertStringContainsString(".uninfe-cache/\n", $gitignore);
    }

    public function test_forbidden_local_artifacts_are_ignored_by_git(): void
    {
        $coreRoot = dirname(__DIR__, 2);
        $apiRoot = dirname($coreRoot, 3);
        $paths = [
            $coreRoot.'/.env',
            $coreRoot.'/certs/teste.pfx',
            $coreRoot.'/.uninfe-cache/Uninfe/.git/config',
            $coreRoot.'/Uninfe/source/placeholder.txt',
            $coreRoot.'/.phpunit.cache/test-results',
        ];

        foreach ($paths as $path) {
            $this->writeFile($path, 'local');
            $this->localArtifacts[] = $path;
        }
        $this->localArtifacts[] = $coreRoot.'/.uninfe-cache';
        $this->localArtifacts[] = $coreRoot.'/Uninfe';
        $this->localArtifacts[] = $coreRoot.'/.phpunit.cache';

        foreach ($paths as $path) {
            $relative = substr($path, strlen($apiRoot) + 1);
            exec(sprintf('cd %s && git check-ignore -q %s', escapeshellarg($apiRoot), escapeshellarg($relative)), $lines, $exitCode);
            $this->assertSame(0, $exitCode, "{$relative} deveria estar ignorado pelo git.");
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/nfse-uninfe-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir, 0775, true));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function makeFakeUninfeCheckout(string $parent): string
    {
        $cacheDir = $parent.'/cache/Uninfe';
        $this->writeFile($cacheDir.'/.git/config', '[core]');
        $this->writeFile(
            $cacheDir.'/source/NFe.Components.Wsdl/NFse/WSDL/provedores_municipios_por_estado.csv',
            implode(PHP_EOL, [
                'provider,uf,municipio,id',
                'FAKE,SC,Cidade Teste,9999999',
            ]).PHP_EOL
        );
        $this->writeFile(
            $cacheDir.'/source/NFe.Components.Wsdl/NFse/WSDL/Webservice.xml',
            '<Webservice><Municipio provider="FAKE" uf="SC" municipio="Cidade Teste" ibge="9999999"/></Webservice>'
        );
        $this->writeFile(
            $cacheDir.'/source/NFe.Components.Wsdl/NFse/schemas/NFSe/FAKE/nfse.xsd',
            '<?xml version="1.0" encoding="UTF-8"?><schema xmlns="http://www.w3.org/2001/XMLSchema"/>'
        );
        $this->writeFile(
            $cacheDir.'/source/NFe.Components.Wsdl/NFe/PL_009/nfe.xsd',
            '<?xml version="1.0" encoding="UTF-8"?><schema xmlns="http://www.w3.org/2001/XMLSchema"/>'
        );
        $this->writeFile(
            $cacheDir.'/source/NFe.Components.Wsdl/NFCe/PL_009/nfce.xsd',
            '<?xml version="1.0" encoding="UTF-8"?><schema xmlns="http://www.w3.org/2001/XMLSchema"/>'
        );

        return $cacheDir;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0775, true));
        }

        $this->assertNotFalse(file_put_contents($path, $contents));
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runScript(string $command): array
    {
        $root = dirname(__DIR__, 2);
        $full = sprintf('cd %s && php %s 2>&1', escapeshellarg($root), $command);
        exec($full, $lines, $exitCode);

        return [$exitCode, implode(PHP_EOL, $lines)];
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
