<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeNacionalAnnexExtractorScriptTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }

        $this->tempDirs = [];
    }

    public function test_dry_run_does_not_write_manifests(): void
    {
        $source = $this->makeTempDir();
        $output = $source.'/out';
        $this->writeWorkbook($source.'/anexo_teste.xlsx');

        [$exitCode, $outputText] = $this->runScript(sprintf(
            'scripts/nfse/extract-nfse-nacional-annexes.php --source=%s --output=%s --dry-run',
            escapeshellarg($source),
            escapeshellarg($output)
        ));

        $this->assertSame(0, $exitCode, $outputText);
        $report = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($report['dry_run']);
        $this->assertCount(1, $report['workbooks']);
        $this->assertSame('anexo_teste.xlsx', $report['workbooks'][0]['source_file']);
        $this->assertFalse(is_dir($output));
    }

    public function test_extractor_writes_deterministic_workbook_manifest(): void
    {
        $source = $this->makeTempDir();
        $output = $this->makeTempDir();
        $this->writeWorkbook($source.'/anexo_teste.xlsx');

        [$firstExitCode, $firstOutput] = $this->runScript(sprintf(
            'scripts/nfse/extract-nfse-nacional-annexes.php --source=%s --output=%s',
            escapeshellarg($source),
            escapeshellarg($output)
        ));
        $this->assertSame(0, $firstExitCode, $firstOutput);

        $manifestFile = $output.'/anexo-teste.json';
        $this->assertFileExists($manifestFile);
        $firstManifest = (string) file_get_contents($manifestFile);
        $data = json_decode($firstManifest, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('nfse-nacional-annex-manifest', $data['schema']);
        $this->assertSame('Leiaute DPS', $data['workbook']['sheets'][0]['name']);
        $this->assertSame('Campo', $data['workbook']['sheets'][0]['rows'][0]['cells']['A']);
        $this->assertSame('infDPS/serv/prest', $data['workbook']['sheets'][0]['rows'][1]['cells']['A']);

        [$secondExitCode, $secondOutput] = $this->runScript(sprintf(
            'scripts/nfse/extract-nfse-nacional-annexes.php --source=%s --output=%s',
            escapeshellarg($source),
            escapeshellarg($output)
        ));
        $this->assertSame(0, $secondExitCode, $secondOutput);

        $this->assertSame($firstManifest, (string) file_get_contents($manifestFile));
    }

    private function writeWorkbook(string $file): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Leiaute DPS" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/sharedStrings.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="4" uniqueCount="4">
  <si><t>Campo</t></si>
  <si><t>Descricao</t></si>
  <si><t>infDPS/serv/prest</t></si>
  <si><t>Prestador do servico</t></si>
</sst>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="A1:B2"/>
  <sheetData>
    <row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>
    <row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>
  </sheetData>
</worksheet>
XML);

        $this->assertTrue($zip->close());
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/nfse-annex-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir, 0775, true));
        $this->tempDirs[] = $dir;

        return $dir;
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
