<?php

namespace Tests\Unit\NFSe;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NacionalDpsIdentityBuilder;
use sabbajohn\FiscalCore\Adapters\NFSe\DTO\Nacional\NacionalDpsTemporalNormalizer;

class NacionalDpsIdentityTemporalTest extends TestCase
{
    public function test_identity_builder_generates_canonical_id_for_cnpj_short_series_and_number(): void
    {
        $id = NacionalDpsIdentityBuilder::build('1302603', '12.345.678/0001-95', '1', '123');

        $this->assertSame('DPS130260321234567800019500001000000000000123', $id);
        $this->assertSame([
            'codigo_municipio' => '1302603',
            'tp_inscricao' => '2',
            'documento' => '12345678000195',
            'serie' => '1',
            'numero' => '123',
        ], NacionalDpsIdentityBuilder::parse($id));
    }

    public function test_identity_builder_generates_canonical_id_for_cpf(): void
    {
        $id = NacionalDpsIdentityBuilder::build('3550308', '123.456.789-01', '12', '34');

        $this->assertSame('DPS355030810001234567890100012000000000000034', $id);
        $this->assertSame('1', NacionalDpsIdentityBuilder::parse($id)['tp_inscricao'] ?? null);
        $this->assertSame('00012345678901', NacionalDpsIdentityBuilder::parse($id)['documento'] ?? null);
    }

    public function test_temporal_normalizer_converts_utc_to_sao_paulo_and_aligns_competence(): void
    {
        $payload = NacionalDpsTemporalNormalizer::normalizePayload([
            'dhEmi' => '2026-06-28T02:30:00Z',
            'dCompet' => '2026-06-28',
        ], [
            'now' => new DateTimeImmutable('2026-06-28T12:00:00-03:00'),
        ]);

        $this->assertSame('2026-06-27T23:30:00-03:00', $payload['dhEmi']);
        $this->assertSame('2026-06-27', $payload['dCompet']);
    }

    public function test_temporal_normalizer_preserves_valid_sao_paulo_offset(): void
    {
        $payload = NacionalDpsTemporalNormalizer::normalizePayload([
            'dhEmi' => '2026-06-28T10:00:00-03:00',
        ], [
            'now' => new DateTimeImmutable('2026-06-28T12:00:00-03:00'),
        ]);

        $this->assertSame('2026-06-28T10:00:00-03:00', $payload['dhEmi']);
        $this->assertSame('2026-06-28', $payload['dCompet']);
    }

    public function test_temporal_normalizer_defaults_absent_values_to_now_minus_five_seconds(): void
    {
        $payload = NacionalDpsTemporalNormalizer::normalizePayload([], [
            'now' => new DateTimeImmutable('2026-06-28T12:00:00-03:00'),
        ]);

        $this->assertSame('2026-06-28T11:59:55-03:00', $payload['dhEmi']);
        $this->assertSame('2026-06-28', $payload['dCompet']);
    }

    public function test_temporal_normalizer_clamps_future_values(): void
    {
        $payload = NacionalDpsTemporalNormalizer::normalizePayload([
            'dhEmi' => '2026-06-28T12:30:00-03:00',
        ], [
            'timezone' => new DateTimeZone('America/Sao_Paulo'),
            'now' => new DateTimeImmutable('2026-06-28T12:00:00-03:00'),
        ]);

        $this->assertSame('2026-06-28T11:59:55-03:00', $payload['dhEmi']);
        $this->assertSame('2026-06-28', $payload['dCompet']);
    }
}
