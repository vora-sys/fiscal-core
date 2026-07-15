<?php

namespace sabbajohn\FiscalCore\Exceptions;

/**
 * Exceção para erros de validação de dados fiscais
 */
class ValidationException extends FiscalException
{
    protected ?string $errorCode = 'VALIDATION_ERROR';

    protected array $validationErrors = [];

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
        array $validationErrors = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->validationErrors = $validationErrors;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public static function invalidChaveAcesso(string $chave): self
    {
        return new self(
            "Chave de acesso inválida: {$chave}",
            0,
            null,
            [
                'chave_fornecida' => $chave,
                'chave_length' => strlen($chave),
                'suggestions' => [
                    'Chave deve ter exatamente 44 dígitos',
                    'Remova espaços e caracteres especiais',
                    'Verifique se copiou a chave completa',
                ],
            ],
            ['chave_acesso' => 'Deve ter 44 dígitos numéricos']
        );
    }

    public static function invalidCNPJ(string $cnpj): self
    {
        return new self(
            "CNPJ inválido: {$cnpj}",
            0,
            null,
            [
                'cnpj_fornecido' => $cnpj,
                'suggestions' => [
                    'CNPJ deve ter 14 dígitos',
                    'Verifique o dígito verificador',
                    'Use apenas números',
                ],
            ],
            ['cnpj' => 'Formato inválido ou dígito verificador incorreto']
        );
    }

    public static function invalidCPF(string $cpf): self
    {
        return new self(
            "CPF inválido: {$cpf}",
            0,
            null,
            [
                'cpf_fornecido' => $cpf,
                'suggestions' => [
                    'CPF deve ter 11 dígitos',
                    'Verifique o dígito verificador',
                    'Use apenas números',
                ],
            ],
            ['cpf' => 'Formato inválido ou dígito verificador incorreto']
        );
    }

    public static function requiredField(string $field, string $context = ''): self
    {
        $message = "Campo obrigatório não informado: {$field}";
        if ($context) {
            $message .= " em {$context}";
        }

        return new self(
            $message,
            0,
            null,
            [
                'field' => $field,
                'context' => $context,
                'suggestions' => [
                    "Informe o valor para {$field}",
                    'Verifique a documentação dos campos obrigatórios',
                    'Consulte exemplos de preenchimento',
                ],
            ],
            [$field => 'Campo obrigatório']
        );
    }

    public static function invalidValue(string $field, $value, string $expected = ''): self
    {
        $message = "Valor inválido para {$field}: ".var_export($value, true);
        if ($expected) {
            $message .= ". Esperado: {$expected}";
        }

        return new self(
            $message,
            0,
            null,
            [
                'field' => $field,
                'value' => $value,
                'expected' => $expected,
                'suggestions' => [
                    "Corrija o valor do campo {$field}",
                    'Verifique o tipo de dado esperado',
                    'Consulte a documentação do campo',
                ],
            ],
            [$field => "Valor inválido. Esperado: {$expected}"]
        );
    }

    public static function multipleErrors(array $errors, string $context = ''): self
    {
        $count = count($errors);
        $message = "{$count} erro(s) de validação encontrado(s)";
        if ($context) {
            $message .= " em {$context}";
        }

        return new self(
            $message,
            0,
            null,
            [
                'context' => $context,
                'error_count' => $count,
                'suggestions' => [
                    'Corrija todos os erros listados',
                    'Verifique os dados obrigatórios',
                    'Consulte a documentação',
                ],
            ],
            $errors
        );
    }
}
