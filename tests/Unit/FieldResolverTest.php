<?php

namespace Tests\Unit;

use App\Services\Mapping\ArrayRecord;
use App\Services\Mapping\FieldResolver;
use App\Services\Mapping\MappingException;
use PHPUnit\Framework\TestCase;

class FieldResolverTest extends TestCase
{
    private FieldResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FieldResolver();
    }

    private function record(array $values): ArrayRecord
    {
        return new ArrayRecord($values, 'riadok 2');
    }

    public function test_string_spec_is_shorthand_for_from(): void
    {
        $value = $this->resolver->resolve('nazov', $this->record(['nazov' => 'Tovar']), 'name');

        $this->assertSame('Tovar', $value);
    }

    public function test_const_and_default(): void
    {
        $record = $this->record(['prazdne' => '']);

        $this->assertSame('EUR', $this->resolver->resolve(['const' => 'EUR'], $record, 'currency'));
        $this->assertSame('C62', $this->resolver->resolve(
            ['from' => 'prazdne', 'default' => 'C62'],
            $record,
            'unit'
        ));
        $this->assertNull($this->resolver->resolve(['from' => 'prazdne'], $record, 'unit'));
    }

    public function test_map_lookup_with_default_and_failure(): void
    {
        $spec = ['from' => 'mj', 'map' => ['ks' => 'C62', 'hod' => 'HUR']];

        $this->assertSame('HUR', $this->resolver->resolve($spec, $this->record(['mj' => 'hod']), 'unit'));
        $this->assertSame(
            'C62',
            $this->resolver->resolve($spec + ['map_default' => 'C62'], $this->record(['mj' => 'bal']), 'unit')
        );

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Neznáma hodnota 'bal'");
        $this->resolver->resolve($spec, $this->record(['mj' => 'bal']), 'unit');
    }

    public function test_date_transform_accepts_dates_without_leading_zeros(): void
    {
        $spec = ['from' => 'datum', 'transform' => [['type' => 'date', 'from_format' => 'd.m.Y']]];

        $this->assertSame('2026-07-01', $this->resolver->resolve($spec, $this->record(['datum' => '01.07.2026']), 'issue_date'));
        $this->assertSame('2026-07-01', $this->resolver->resolve($spec, $this->record(['datum' => '1.7.2026']), 'issue_date'));
    }

    public function test_date_transform_rejects_invalid_date(): void
    {
        $spec = ['from' => 'datum', 'transform' => [['type' => 'date', 'from_format' => 'd.m.Y']]];

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('nezodpovedá formátu dátumu');
        $this->resolver->resolve($spec, $this->record(['datum' => '32.13.2026']), 'issue_date');
    }

    public function test_decimal_transform_normalizes_separators(): void
    {
        $spec = ['from' => 'suma', 'transform' => [['type' => 'decimal']]];

        $this->assertSame('1250.50', $this->resolver->resolve($spec, $this->record(['suma' => '1 250,50']), 'unit_price'));
        $this->assertSame('-15.00', $this->resolver->resolve($spec, $this->record(['suma' => '-15,00']), 'unit_price'));
        $this->assertSame('42', $this->resolver->resolve($spec, $this->record(['suma' => '42']), 'quantity'));
    }

    public function test_decimal_transform_rejects_non_numeric_value(): void
    {
        $spec = ['from' => 'suma', 'transform' => [['type' => 'decimal']]];

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('nie je platné číslo');
        $this->resolver->resolve($spec, $this->record(['suma' => '12,50 EUR']), 'unit_price');
    }

    public function test_unknown_transform_throws(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Neznáma transformácia");
        $this->resolver->resolve(
            ['from' => 'x', 'transform' => [['type' => 'rot13']]],
            $this->record(['x' => 'y']),
            'field'
        );
    }

    public function test_spec_without_from_or_const_throws(): void
    {
        $this->expectException(MappingException::class);
        $this->resolver->resolve(['default' => 'x'], $this->record([]), 'field');
    }
}
