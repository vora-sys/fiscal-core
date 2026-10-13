<?php

namespace Tests\Unit\Fiscal;

use sabbajohn\FiscalCore\Support\CnpjIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjIdentityTest extends TestCase
{
    #[DataProvider('validDocuments')]
    public function test_normalizes_numeric_formatted_and_alphanumeric_cnpj(string $input, string $expected): void
    {
        $this->assertSame($expected, CnpjIdentity::normalize($input));
    }

    public static function validDocuments(): array
    {
        return [
            ['12345678000199', '12345678000199'],
            ['12.345.678/0001-99', '12345678000199'],
            ['ab.c12.d34/0001-90', 'ABC12D34000190'],
        ];
    }

    public function test_extracts_first_eight_alphanumeric_positions_as_root(): void
    {
        $this->assertSame('ABC12D34', CnpjIdentity::root('ABC12D34000190'));
        $this->assertTrue(CnpjIdentity::sameRoot('ABC12D34000190', 'ABC12D34000271'));
        $this->assertFalse(CnpjIdentity::sameRoot('ABC12D34000190', 'ABC12D35000170'));
    }

    public function test_validates_numeric_and_alphanumeric_check_digits(): void
    {
        $this->assertTrue(CnpjIdentity::hasValidCheckDigits('83.188.342/0001-04'));
        $this->assertTrue(CnpjIdentity::hasValidCheckDigits('ABC12D34/0001-07'));
        $this->assertFalse(CnpjIdentity::hasValidCheckDigits('ABC12D34/0001-90'));
        $this->assertFalse(CnpjIdentity::hasValidCheckDigits('00.000.000/0000-00'));
    }

    #[DataProvider('invalidDocuments')]
    public function test_rejects_cpf_incomplete_and_invalid_characters(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        CnpjIdentity::normalize($input);
    }

    public static function invalidDocuments(): array
    {
        return [
            ['12345678901'],
            ['123456780001'],
            ['123456780001AB'],
            ['12@345678000199'],
            [''],
        ];
    }
}
