<?php

namespace sabbajohn\FiscalCore\Adapters\NF\Helpers;

/**
 * Helper para construção de objetos stdClass
 * Facilita a criação de objetos compatíveis com NFePHP
 */
class StdClassBuilder
{
    /**
     * Cria stdClass a partir de array associativo
     * Remove automaticamente valores null (opcionais não informados)
     * 
     * @param array $data Array associativo com os dados
     * @param bool $keepNulls Se true, mantém propriedades com valor null
     * @return \stdClass
     */
    public static function create(array $data, bool $keepNulls = false): \stdClass
    {
        $obj = new \stdClass();
        
        foreach ($data as $key => $value) {
            // Pular valores null se keepNulls = false
            if ($value === null && !$keepNulls) {
                continue;
            }
            
            $obj->$key = $value;
        }
        
        return $obj;
    }
    
    /**
     * Cria stdClass mantendo todos os valores, incluindo nulls
     * 
     * @param array $data Array associativo com os dados
     * @return \stdClass
     */
    public static function createWithNulls(array $data): \stdClass
    {
        return self::create($data, true);
    }
    
    /**
     * Cria stdClass a partir de argumentos nomeados
     * Útil para manter compatibilidade com código existente
     * 
     * Exemplo: StdClassBuilder::from(vBC: 100.0, vICMS: 18.0)
     * 
     * @param mixed ...$args Argumentos nomeados
     * @return \stdClass
     */
    public static function from(...$args): \stdClass
    {
        return self::create($args);
    }
    
    /**
     * Cria stdClass usando os nomes das variáveis automaticamente
     * IMPORTANTE: Devido a limitações do PHP, as variáveis devem ser passadas como array
     * 
     * Uso: StdClassBuilder::fromVars(compact('vBC', 'vICMS', 'vProd'))
     * ou:  StdClassBuilder::fromVars(get_defined_vars())
     * 
     * Para facilitar ainda mais, use o método props():
     * StdClassBuilder::props($vBC, $vICMS, $vProd)
     * 
     * @param array $vars Array associativo de variáveis (geralmente de compact() ou get_defined_vars())
     * @return \stdClass
     */
    public static function fromVars(array $vars): \stdClass
    {
        return self::create($vars);
    }
    
    /**
     * Cria stdClass capturando nomes de variáveis dinamicamente via debug_backtrace
     * Este é o método mais conveniente - passa as variáveis diretamente!
     * 
     * Exemplo:
     * ```php
     * $vBC = 100.0;
     * $vICMS = 18.0;
     * $vProd = 100.0;
     * 
     * $obj = StdClassBuilder::props($vBC, $vICMS, $vProd);
     * // Resultado: stdClass com propriedades vBC, vICMS, vProd
     * ```
     * 
     * NOTA: Este método usa debug_backtrace() e pode ter overhead de performance.
     * Para uso em loops intensivos, prefira create() com array explícito.
     * 
     * @param mixed ...$values Valores das variáveis a serem capturadas
     * @return \stdClass
     */
    public static function props(...$values): \stdClass
    {
        // Obter código fonte da linha que chamou este método. A posição do
        // frame varia entre versões de PHP, então procuramos a chamada real.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (!$file || !$line) {
                continue;
            }

            $callingLine = self::readPropsCall((string) $file, (int) $line);
            if ($callingLine === null) {
                continue;
            }

            // Extrair nomes das variáveis da chamada (suporta multi-linha)
            // Padrão: StdClassBuilder::props($var1, $var2, $obj->prop, etc)
            if (preg_match('/::props\s*\((.*?)\)\s*[;,\)]/', $callingLine, $matches)) {
                $argsString = $matches[1];

                // Dividir por vírgula, mas respeitar parênteses e colchetes
                $args = self::splitArguments($argsString);

                // Extrair nomes de variáveis
                $names = array_map(function($arg) {
                    $arg = trim($arg);

                    // Variável simples: $vBC
                    if (preg_match('/^\$(\w+)$/', $arg, $m)) {
                        return $m[1];
                    }

                    // Propriedade de objeto: $this->totais->vBC
                    if (preg_match('/->(\w+)$/', $arg, $m)) {
                        return $m[1];
                    }

                    // Array access: $array['key']
                    if (preg_match('/\[[\'"](.*?)[\'"]\]$/', $arg, $m)) {
                        return $m[1];
                    }

                    // Fallback: usar o próprio argumento sem $
                    return str_replace('$', '', $arg);
                }, $args);

                // Combinar nomes com valores
                $data = [];
                foreach ($names as $i => $name) {
                    if (isset($values[$i])) {
                        $data[$name] = $values[$i];
                    }
                }

                return self::create($data);
            }
        }
        
        throw new \RuntimeException('Não foi possível extrair nomes das variáveis da chamada');
    }

    private static function readPropsCall(string $file, int $line): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $startLine = max(0, $line - 1);
        $callingLine = '';
        $foundStart = false;
        $openParens = 0;

        for ($i = $startLine; $i < min($startLine + 10, count($lines)); $i++) {
            $currentLine = $lines[$i];
            $callingLine .= ' ' . trim($currentLine);

            if (!$foundStart && strpos($currentLine, '::props(') !== false) {
                $foundStart = true;
            }

            if ($foundStart) {
                $openParens += substr_count($currentLine, '(') - substr_count($currentLine, ')');
                if ($openParens <= 0) {
                    return $callingLine;
                }
            }
        }

        return null;
    }
    
    /**
     * Divide string de argumentos respeitando parênteses e colchetes
     * 
     * @param string $str String com argumentos separados por vírgula
     * @return array Array de argumentos
     */
    private static function splitArguments(string $str): array
    {
        $args = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            
            // Controlar strings
            if (($char === '"' || $char === "'") && ($i === 0 || $str[$i - 1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }
            
            if (!$inString) {
                // Controlar profundidade de parênteses/colchetes
                if ($char === '(' || $char === '[') {
                    $depth++;
                } elseif ($char === ')' || $char === ']') {
                    $depth--;
                }
                
                // Vírgula no nível raiz = separador
                if ($char === ',' && $depth === 0) {
                    $args[] = trim($current);
                    $current = '';
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $args[] = trim($current);
        }
        
        return $args;
    }
    
    /**
     * Mescla múltiplos arrays/objetos em um único stdClass
     * 
     * @param array|object ...$sources Arrays ou objetos para mesclar
     * @return \stdClass
     */
    public static function merge(...$sources): \stdClass
    {
        $result = new \stdClass();
        
        foreach ($sources as $source) {
            if (is_array($source)) {
                foreach ($source as $key => $value) {
                    if ($value !== null) {
                        $result->$key = $value;
                    }
                }
            } elseif (is_object($source)) {
                foreach (get_object_vars($source) as $key => $value) {
                    if ($value !== null) {
                        $result->$key = $value;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Cria stdClass e aplica transformações aos valores
     * 
     * @param array $data Array associativo com os dados
     * @param callable $transformer Função de transformação (key, value) => newValue
     * @return \stdClass
     */
    public static function transform(array $data, callable $transformer): \stdClass
    {
        $obj = new \stdClass();
        
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            
            $transformed = $transformer($key, $value);
            if ($transformed !== null) {
                $obj->$key = $transformed;
            }
        }
        
        return $obj;
    }
    
    /**
     * Helper para formatar valores numéricos conforme NFePHP
     * 
     * @param array $data Array com os dados
     * @param array $decimals Mapa de campo => casas decimais
     * @return \stdClass
     */
    public static function withFormatting(array $data, array $decimals = []): \stdClass
    {
        return self::transform($data, function($key, $value) use ($decimals) {
            if (isset($decimals[$key]) && is_numeric($value)) {
                return number_format($value, $decimals[$key], '.', '');
            }
            return $value;
        });
    }
}
