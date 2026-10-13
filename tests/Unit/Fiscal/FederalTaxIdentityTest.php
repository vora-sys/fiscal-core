<?php

namespace Tests\Unit\Fiscal;

use sabbajohn\FiscalCore\Support\FederalTaxIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FederalTaxIdentityTest extends TestCase
{
    #[DataProvider('validDocuments')]
    public function test_normalizes_cpf_and_numeric_or_alphanumeric_cnpj(string $document, string $expected): void
    {
        $this->assertSame($expected, FederalTaxIdentity::normalize($document));
    }

    public function test_rejects_an_alphanumeric_cpf(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FederalTaxIdentity::normalize('12345A78901');
    }

    public function test_identifies_document_and_person_types(): void
    {
        $this->assertSame('cpf', FederalTaxIdentity::documentType('123.456.789-01'));
        $this->assertSame('pf', FederalTaxIdentity::personType('123.456.789-01'));
        $this->assertTrue(FederalTaxIdentity::isCpf('12345678901'));
        $this->assertSame('cnpj', FederalTaxIdentity::documentType('ABC12D34/0001-90'));
        $this->assertSame('pj', FederalTaxIdentity::personType('ABC12D34/0001-90'));
        $this->assertTrue(FederalTaxIdentity::isCnpj('ABC12D34000190'));
    }

    /** @return array<string, array{string, string}> */
    public static function validDocuments(): array
    {
        return [
            'formatted CPF' => ['123.456.789-01', '12345678901'],
            'numeric CNPJ' => ['12.345.678/0001-90', '12345678000190'],
            'alphanumeric CNPJ' => ['ABC12D34/0001-90', 'ABC12D34000190'],
        ];
    }
}
