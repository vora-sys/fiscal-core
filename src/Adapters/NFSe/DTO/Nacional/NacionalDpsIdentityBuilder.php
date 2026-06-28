<?php

namespace sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional;

final class NacionalDpsIdentityBuilder
{
    public const ID_PATTERN = '/^DPS\d{42}$/';

    public static function build(string $codigoMunicipio, string $prestadorDocumento, mixed $serie, mixed $numero): string
    {
        $prestadorDigits = DpsPayloadHelper::onlyDigits($prestadorDocumento);
        $prestadorDoc = strlen($prestadorDigits) === 14
            ? $prestadorDigits
            : str_pad(substr($prestadorDigits, 0, 11), 14, '0', STR_PAD_LEFT);
        $tpInsc = strlen($prestadorDigits) === 14 ? '2' : '1';

        return 'DPS'
            . str_pad(substr(DpsPayloadHelper::onlyDigits($codigoMunicipio), 0, 7), 7, '0', STR_PAD_LEFT)
            . $tpInsc
            . $prestadorDoc
            . self::normalizeSerie($serie)
            . self::normalizeNumero($numero);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     */
    public static function fromPayload(array $payload, array $context = []): ?string
    {
        $codigoMunicipio = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['cLocEmi'] ?? null,
            $payload['prestador']['codigoMunicipio'] ?? null,
            $payload['prestador']['codigo_municipio'] ?? null,
            $context['codigo_municipio'] ?? null,
        ]) ?? '');
        $prestadorDocumento = DpsPayloadHelper::onlyDigits(DpsPayloadHelper::firstString([
            $payload['prestador']['cnpj'] ?? null,
            $payload['prestador']['cpf'] ?? null,
            $payload['prestador']['documento'] ?? null,
        ]) ?? '');
        $numero = DpsPayloadHelper::firstString([
            $payload['nDPS'] ?? null,
            $payload['numero_rps'] ?? null,
            $payload['numero_dps'] ?? null,
        ]);
        $serie = DpsPayloadHelper::firstString([
            $payload['serie'] ?? null,
            $payload['serie_rps'] ?? null,
            '1',
        ]);

        if ($codigoMunicipio === '' || $prestadorDocumento === '' || $numero === null) {
            return null;
        }

        return self::build($codigoMunicipio, $prestadorDocumento, $serie ?? '1', $numero);
    }

    /**
     * @return array{codigo_municipio:string,tp_inscricao:string,documento:string,serie:string,numero:string}|null
     */
    public static function parse(string $id): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $serie = ltrim(substr($id, 25, 5), '0');
        $numero = ltrim(substr($id, 30, 15), '0');

        return [
            'codigo_municipio' => substr($id, 3, 7),
            'tp_inscricao' => substr($id, 10, 1),
            'documento' => substr($id, 11, 14),
            'serie' => $serie !== '' ? $serie : '0',
            'numero' => $numero !== '' ? $numero : '0',
        ];
    }

    public static function normalizeSerie(mixed $serie): string
    {
        return DpsPayloadHelper::normalizeNumeric($serie, 5, '1');
    }

    public static function normalizeNumero(mixed $numero): string
    {
        return DpsPayloadHelper::normalizeNumeric($numero, 15, '1');
    }
}
