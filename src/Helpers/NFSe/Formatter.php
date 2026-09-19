<?php

namespace sabbajohn\FiscalCore\Helpers\NFSe;

class Formatter
{
    public static function data(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        $timestamp = strtotime($valor);

        if ($timestamp === false) {
            return $valor;
        }

        return date('d/m/Y', $timestamp);
    }

    public static function dataHora(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        $timestamp = strtotime($valor);

        if ($timestamp === false) {
            return $valor;
        }

        return date('d/m/Y H:i:s', $timestamp);
    }

    public static function moeda(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    public static function percentual(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        return number_format((float) $valor, 2, ',', '.').'%';
    }

    public static function documento(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        $valor = preg_replace('/\D/', '', $valor);

        if (strlen($valor) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $valor);
        }

        if (strlen($valor) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $valor);
        }

        return $valor;
    }

    public static function telefone(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        $valor = preg_replace('/\D/', '', $valor);

        if (strlen($valor) === 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $valor);
        }

        if (strlen($valor) === 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $valor);
        }

        return $valor;
    }

    public static function cep(?string $valor): string
    {
        if (empty($valor) || $valor === '-') {
            return '-';
        }

        $valor = preg_replace('/\D/', '', $valor);

        if (strlen($valor) !== 8) {
            return $valor;
        }

        return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $valor);
    }

    public static function endereco(?string $logradouro, ?string $numero, ?string $complemento): string
    {
        $partes = array_filter([$logradouro, $numero, $complemento], fn ($v) => ! empty($v) && $v !== '-');

        return $partes ? implode(', ', $partes) : '-';
    }

    public static function municipioUf(?string $municipio, ?string $uf): string
    {
        $partes = array_filter([$municipio, $uf], fn ($v) => ! empty($v) && $v !== '-');

        return $partes ? implode(' / ', $partes) : '-';
    }

    public static function ibgeCep(?string $ibge, ?string $cep): string
    {
        $partes = [];

        if (! empty($ibge) && $ibge !== '-') {
            $partes[] = $ibge;
        }

        $cep = self::cep($cep);

        if ($cep !== '-') {
            $partes[] = $cep;
        }

        return $partes ? implode('/', $partes) : '-';
    }

    public static function vazio(?string $valor): string
    {
        return empty($valor) ? '-' : $valor;
    }
}
