<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Nodes;

use NFePHP\NFe\Make;
use sabbajohn\FiscalCore\Adapters\NF\Core\NotaNodeInterface;

class ImpostoSeletivoNode implements NotaNodeInterface
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function __construct(
        private int $item,
        private array $data,
    ) {}

    public function addToMake(Make $make): void
    {
        $payload = $this->payload();
        if ($payload === null) {
            return;
        }

        $make->tagIS((object) $payload);
    }

    public function validate(): bool
    {
        if ($this->data === []) {
            return true;
        }

        if ($this->stringValue(['CSTIS', 'cst_is', 'cst']) === null) {
            throw new \InvalidArgumentException('CST do Imposto Seletivo e obrigatorio');
        }

        if ($this->stringValue(['cClassTribIS', 'classe_is', 'classe', 'classificacao', 'codigo_classificacao']) === null) {
            throw new \InvalidArgumentException('Classe tributaria do Imposto Seletivo e obrigatoria');
        }

        if ($this->numberValue(['vIS', 'valor']) === null) {
            throw new \InvalidArgumentException('Valor do Imposto Seletivo e obrigatorio');
        }

        return true;
    }

    public function getNodeType(): string
    {
        return 'imposto_seletivo';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payload(): ?array
    {
        $cst = $this->stringValue(['CSTIS', 'cst_is', 'cst']);
        $classe = $this->stringValue(['cClassTribIS', 'classe_is', 'classe', 'classificacao', 'codigo_classificacao']);
        $valor = $this->numberValue(['vIS', 'valor']);

        if ($cst === null || $classe === null || $valor === null) {
            return null;
        }

        return $this->withoutNulls([
            'item' => $this->item,
            'CSTIS' => $this->digits($cst, 3),
            'cClassTribIS' => $this->digits($classe, 6),
            'vBCIS' => $this->numberValue(['vBCIS', 'base_calculo']),
            'pIS' => $this->numberValue(['pIS', 'aliquota']),
            'pISEspec' => $this->numberValue(['pISEspec', 'aliquota_especifica']),
            'uTrib' => $this->stringValue(['uTrib', 'unidade_tributavel']),
            'qTrib' => $this->numberValue(['qTrib', 'quantidade_tributavel']),
            'vIS' => $valor,
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function stringValue(array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($this->data, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function numberValue(array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($this->data, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutNulls(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    private function digits(string $value, int $length): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return str_pad(substr($digits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function valueAtPath(array $source, string $path): mixed
    {
        if (array_key_exists($path, $source)) {
            return $source[$path];
        }

        $value = $source;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
