<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NFSeProviderSwitchScriptTest extends TestCase
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

    public function test_set_and_remove_municipio_override(): void
    {
        $overrideFile = $this->makeTempOverrideFile();

        $setOutput = $this->runScript(sprintf(
            'scripts/nfse/provider-switch.php --set --municipio=presidente-figueiredo --provider=ABRASF_SHARED --reason=%s --ticket=%s --file=%s',
            escapeshellarg('Troca emergencial para homologação'),
            escapeshellarg('NFSE-9001'),
            escapeshellarg($overrideFile)
        ));
        $setData = json_decode($setOutput, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('set', $setData['operation']);
        $this->assertSame('1303536', $setData['municipio']['ibge']);
        $this->assertSame('ABRASF_SHARED', $setData['after']['provider_family']);
        $this->assertTrue($setData['after']['active']);
        $this->assertSame('NFSE-9001', $setData['after']['ticket']);

        $saved = json_decode((string) file_get_contents($overrideFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ABRASF_SHARED', $saved['overrides']['1303536']['provider_family'] ?? null);

        $removeOutput = $this->runScript(sprintf(
            'scripts/nfse/provider-switch.php --remove --municipio=presidente-figueiredo --file=%s',
            escapeshellarg($overrideFile)
        ));
        $removeData = json_decode($removeOutput, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('remove', $removeData['operation']);
        $this->assertTrue($removeData['removed']);

        $savedAfterRemove = json_decode((string) file_get_contents($overrideFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('1303536', $savedAfterRemove['overrides'] ?? []);
    }

    public function test_set_override_dry_run_does_not_persist(): void
    {
        $overrideFile = $this->makeTempOverrideFile();

        $output = $this->runScript(sprintf(
            'scripts/nfse/provider-switch.php --set --municipio=rio-preto-da-eva --provider=ABRASF_SHARED --dry-run --file=%s',
            escapeshellarg($overrideFile)
        ));
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('set', $data['operation']);
        $this->assertTrue($data['dry_run']);

        $saved = json_decode((string) file_get_contents($overrideFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $saved['overrides'] ?? []);
    }

    private function makeTempOverrideFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nfse-switch-');
        $this->assertIsString($tmp);
        $this->assertNotSame('', $tmp);

        $this->tempFiles[] = $tmp;
        file_put_contents(
            $tmp,
            json_encode([
                'version' => 1,
                'updated_at' => null,
                'overrides' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );

        return $tmp;
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
}
