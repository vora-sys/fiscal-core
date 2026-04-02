<?php

namespace freeline\FiscalCore\Support;

enum ManifestationType: string
{
    case CIENCIA = 'ciencia';
    case CONFIRMACAO = 'confirmacao';
    case DESCONHECIMENTO = 'desconhecimento';
    case OPERACAO_NAO_REALIZADA = 'operacao_nao_realizada';

    public function eventCode(): int
    {
        return match ($this) {
            self::CIENCIA => 210210,
            self::CONFIRMACAO => 210200,
            self::DESCONHECIMENTO => 210220,
            self::OPERACAO_NAO_REALIZADA => 210240,
        };
    }

    public function requiresJustification(): bool
    {
        return $this === self::OPERACAO_NAO_REALIZADA;
    }

    public static function fromValue(string $value): self
    {
        return match (strtolower(trim($value))) {
            'ciencia' => self::CIENCIA,
            'confirmacao' => self::CONFIRMACAO,
            'desconhecimento' => self::DESCONHECIMENTO,
            'operacao_nao_realizada' => self::OPERACAO_NAO_REALIZADA,
            default => throw new \InvalidArgumentException("Tipo de manifestação inválido: {$value}"),
        };
    }
}
