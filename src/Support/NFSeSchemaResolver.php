<?php 
namespace sabbajohn\FiscalCore\Support;

final class NFSeSchemaResolver
{
    private string $configPath;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? dirname(__DIR__, 2) . '/config/nfse/nfse-provider-families.json';
    }

    public function resolve(string $providerFamily, string $operation): string
    {
        $json = @file_get_contents($this->configPath);
        if ($json === false) {
            throw new \RuntimeException("Não foi possível ler o catálogo de schemas em '{$this->configPath}'.");
        }

        $families = json_decode(
            $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $family = $families[$providerFamily] ?? null;
        $entry = is_array($family ?? null) ? ($family['xsd_entrypoints'][$operation] ?? null) : null;
        if (!$family || !$entry) {
            foreach ($families as $candidate) {
                if (($candidate['layout_family'] ?? null) !== $providerFamily) {
                    continue;
                }
                $candidateEntry = $candidate['xsd_entrypoints'][$operation] ?? null;
                if (!$candidateEntry) {
                    continue;
                }
                $family = $candidate;
                $entry = $candidateEntry;
                break;
            }
        }

        if (!$family) {
            throw new \RuntimeException("Família '{$providerFamily}' não configurada.");
        }

        $root = dirname(__DIR__, 2) . '/' . $family['schema_root'];

        if (!$entry) {
            throw new \RuntimeException("Operação '{$operation}' sem schema mapeado.");
        }

        $schemaPath = $root . '/' . $entry;

        if (!is_file($schemaPath)) {
            throw new \RuntimeException("Schema não encontrado para '{$providerFamily}/{$operation}': {$schemaPath}");
        }

        return $schemaPath;
    }
}
