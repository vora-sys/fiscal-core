<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeReconcileUninfeProvidersScriptTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    public function testExpectedDivergencesAreClassifiedWithoutUnexpected(): void
    {
        $catalog = $this->makeCatalogFile([
            '1302603' => ['slug' => 'manaus', 'nome' => 'Manaus', 'uf' => 'AM', 'provider_family' => 'nfse_nacional', 'active' => true],
            '1501402' => ['slug' => 'belem', 'nome' => 'Belem', 'uf' => 'PA', 'provider_family' => 'BELEM_MUNICIPAL_2025', 'active' => true],
            '4209102' => ['slug' => 'joinville', 'nome' => 'Joinville', 'uf' => 'SC', 'provider_family' => 'PUBLICA', 'active' => true],
        ]);
        $csv = $this->makeCsvFile([
            ['MANAUS_AM', 'AM', 'Manaus', '1302603'],
            ['DSF', 'PA', 'Belem', '1501402'],
            ['PUBLICA', 'SC', 'Joinville', '4209102'],
        ]);

        [$exitCode, $output] = $this->runScript(sprintf(
            'scripts/nfse/reconcile-uninfe-providers.php --csv=%s --catalog=%s',
            escapeshellarg($csv),
            escapeshellarg($catalog)
        ));

        $this->assertSame(0, $exitCode, $output);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $data['summary']['missing_in_catalog']);
        $this->assertSame(2, $data['summary']['divergences_total']);
        $this->assertSame(2, $data['summary']['divergences_expected']);
        $this->assertSame(0, $data['summary']['divergences_unexpected']);
    }

    public function testFailOnUnexpectedReturnsExitCodeTwo(): void
    {
        $catalog = $this->makeCatalogFile([
            '4209102' => ['slug' => 'joinville', 'nome' => 'Joinville', 'uf' => 'SC', 'provider_family' => 'PUBLICA', 'active' => true],
        ]);
        $csv = $this->makeCsvFile([
            ['IPM', 'SC', 'Joinville', '4209102'],
        ]);

        [$exitCode, $output] = $this->runScript(sprintf(
            'scripts/nfse/reconcile-uninfe-providers.php --csv=%s --catalog=%s --fail-on-unexpected',
            escapeshellarg($csv),
            escapeshellarg($catalog)
        ));

        $this->assertSame(2, $exitCode, $output);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['summary']['divergences_unexpected']);
    }

    public function testMissingMunicipioIsReported(): void
    {
        $catalog = $this->makeCatalogFile([
            '4209102' => ['slug' => 'joinville', 'nome' => 'Joinville', 'uf' => 'SC', 'provider_family' => 'PUBLICA', 'active' => true],
        ]);
        $csv = $this->makeCsvFile([
            ['PUBLICA', 'SC', 'Joinville', '4209102'],
            ['QUALQUER', 'AM', 'Cidade Nova', '9999999'],
        ]);

        [$exitCode, $output] = $this->runScript(sprintf(
            'scripts/nfse/reconcile-uninfe-providers.php --csv=%s --catalog=%s',
            escapeshellarg($csv),
            escapeshellarg($catalog)
        ));

        $this->assertSame(0, $exitCode, $output);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['summary']['missing_in_catalog']);
        $this->assertSame('9999999', (string) ($data['missing'][0]['ibge'] ?? ''));
    }

    /**
     * @param array<string, array<string, mixed>> $municipios
     */
    private function makeCatalogFile(array $municipios): string
    {
        $file = $this->makeTempFile();
        file_put_contents(
            $file,
            json_encode(
                ['municipios' => $municipios],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );

        return $file;
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:string}> $rows
     */
    private function makeCsvFile(array $rows): string
    {
        $file = $this->makeTempFile();
        $lines = ['provider,uf,municipio,id'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);

        return $file;
    }

    private function makeTempFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nfse-reconcile-');
        $this->assertIsString($tmp);
        $this->assertNotSame('', $tmp);
        $this->tempFiles[] = $tmp;

        return $tmp;
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
}
