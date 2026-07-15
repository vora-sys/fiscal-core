<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use sabbajohn\FiscalCore\Adapters\NF\Helpers\StdClassBuilder;

class StdClassBuilderTest extends TestCase
{
    public function test_create_basic(): void
    {
        $obj = StdClassBuilder::create([
            'name' => 'John',
            'age' => 30,
            'city' => 'São Paulo',
        ]);

        $this->assertInstanceOf(\stdClass::class, $obj);
        $this->assertEquals('John', $obj->name);
        $this->assertEquals(30, $obj->age);
        $this->assertEquals('São Paulo', $obj->city);
    }

    public function test_create_removes_nulls_by_default(): void
    {
        $obj = StdClassBuilder::create([
            'name' => 'John',
            'email' => null,
            'phone' => null,
            'age' => 30,
        ]);

        $this->assertTrue(property_exists($obj, 'name'));
        $this->assertTrue(property_exists($obj, 'age'));
        $this->assertFalse(property_exists($obj, 'email'));
        $this->assertFalse(property_exists($obj, 'phone'));
    }

    public function test_create_with_nulls(): void
    {
        $obj = StdClassBuilder::createWithNulls([
            'name' => 'John',
            'email' => null,
            'age' => 30,
        ]);

        $this->assertTrue(property_exists($obj, 'name'));
        $this->assertTrue(property_exists($obj, 'email'));
        $this->assertTrue(property_exists($obj, 'age'));
        $this->assertNull($obj->email);
    }

    public function test_from_with_named_arguments(): void
    {
        $obj = StdClassBuilder::from(
            vBC: 100.0,
            vICMS: 18.0,
            vPIS: 1.65,
            vCOFINS: 7.6
        );

        $this->assertEquals(100.0, $obj->vBC);
        $this->assertEquals(18.0, $obj->vICMS);
        $this->assertEquals(1.65, $obj->vPIS);
        $this->assertEquals(7.6, $obj->vCOFINS);
    }

    public function test_merge_arrays(): void
    {
        $obj = StdClassBuilder::merge(
            ['name' => 'John', 'age' => 30],
            ['city' => 'São Paulo', 'country' => 'Brasil']
        );

        $this->assertEquals('John', $obj->name);
        $this->assertEquals(30, $obj->age);
        $this->assertEquals('São Paulo', $obj->city);
        $this->assertEquals('Brasil', $obj->country);
    }

    public function test_merge_objects(): void
    {
        $obj1 = (object) ['name' => 'John', 'age' => 30];
        $obj2 = (object) ['city' => 'São Paulo'];

        $merged = StdClassBuilder::merge($obj1, $obj2);

        $this->assertEquals('John', $merged->name);
        $this->assertEquals(30, $merged->age);
        $this->assertEquals('São Paulo', $merged->city);
    }

    public function test_merge_skips_nulls(): void
    {
        $obj = StdClassBuilder::merge(
            ['name' => 'John', 'email' => null],
            ['age' => 30, 'phone' => null]
        );

        $this->assertTrue(property_exists($obj, 'name'));
        $this->assertTrue(property_exists($obj, 'age'));
        $this->assertFalse(property_exists($obj, 'email'));
        $this->assertFalse(property_exists($obj, 'phone'));
    }

    public function test_transform_applies_function(): void
    {
        $obj = StdClassBuilder::transform(
            ['price' => 100, 'quantity' => 2],
            fn ($key, $value) => $key === 'price' ? $value * 1.1 : $value
        );

        $this->assertEqualsWithDelta(110, $obj->price, 0.001);
        $this->assertEquals(2, $obj->quantity);
    }

    public function test_with_formatting(): void
    {
        $obj = StdClassBuilder::withFormatting(
            [
                'vUnCom' => 123.456789,
                'qCom' => 10.5,
                'vProd' => 1234.56,
            ],
            [
                'vUnCom' => 10,  // 10 casas decimais
                'qCom' => 4,     // 4 casas decimais
                'vProd' => 2,     // 2 casas decimais
            ]
        );

        $this->assertEquals('123.4567890000', $obj->vUnCom);
        $this->assertEquals('10.5000', $obj->qCom);
        $this->assertEquals('1234.56', $obj->vProd);
    }

    public function test_real_world_nfe_totals(): void
    {
        $obj = StdClassBuilder::create([
            'vBC' => 100.00,
            'vICMS' => 18.00,
            'vICMSDeson' => 0,
            'vFCP' => null,  // Será removido
            'vBCST' => 0,
            'vST' => 0,
            'vProd' => 100.00,
            'vFrete' => 0,
            'vSeg' => 0,
            'vDesc' => 0,
            'vII' => 0,
            'vIPI' => 0,
            'vPIS' => 1.65,
            'vCOFINS' => 7.60,
            'vOutro' => 0,
            'vNF' => 100.00,
        ]);

        $this->assertEquals(100.00, $obj->vBC);
        $this->assertEquals(18.00, $obj->vICMS);
        $this->assertFalse(property_exists($obj, 'vFCP'));
        $this->assertEquals(100.00, $obj->vNF);
    }

    public function test_props_with_simple_variables(): void
    {
        $vBC = 100.0;
        $vICMS = 18.0;
        $vProd = 100.0;

        $obj = StdClassBuilder::props($vBC, $vICMS, $vProd);

        $this->assertEquals(100.0, $obj->vBC);
        $this->assertEquals(18.0, $obj->vICMS);
        $this->assertEquals(100.0, $obj->vProd);
    }

    public function test_props_with_object_properties(): void
    {
        $totais = (object) [
            'vBC' => 100.0,
            'vICMS' => 18.0,
            'vProd' => 100.0,
        ];

        $obj = StdClassBuilder::props($totais->vBC, $totais->vICMS, $totais->vProd);

        $this->assertEquals(100.0, $obj->vBC);
        $this->assertEquals(18.0, $obj->vICMS);
        $this->assertEquals(100.0, $obj->vProd);
    }

    public function test_from_vars_with_compact(): void
    {
        $vBC = 100.0;
        $vICMS = 18.0;
        $vProd = 100.0;

        $obj = StdClassBuilder::fromVars(compact('vBC', 'vICMS', 'vProd'));

        $this->assertEquals(100.0, $obj->vBC);
        $this->assertEquals(18.0, $obj->vICMS);
        $this->assertEquals(100.0, $obj->vProd);
    }
}
