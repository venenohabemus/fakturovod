<?php

namespace Tests\Unit;

use App\Services\Mapping\MappingException;
use App\Services\Mapping\Readers\CsvReader;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{
    public function test_reads_semicolon_csv_with_header(): void
    {
        $records = (new CsvReader())->read(
            "cislo;nazov;cena\nFA-1;Tovar A;10,50\nFA-2;Tovar B;20,00\n",
            ['delimiter' => ';']
        );

        $this->assertCount(2, $records);
        $this->assertSame('FA-1', $records[0]->get('cislo'));
        $this->assertSame('Tovar A', $records[0]->get('nazov'));
        $this->assertSame('20,00', $records[1]->get('cena'));
        $this->assertSame('riadok 2', $records[0]->describe());
        $this->assertSame('riadok 3', $records[1]->describe());
    }

    public function test_skips_blank_lines_and_strips_bom(): void
    {
        $records = (new CsvReader())->read(
            "\xEF\xBB\xBFcislo;nazov\n\nFA-1;Tovar\n\n",
            ['delimiter' => ';']
        );

        $this->assertCount(1, $records);
        $this->assertSame('FA-1', $records[0]->get('cislo'));
    }

    public function test_quoted_fields_may_contain_delimiter(): void
    {
        $records = (new CsvReader())->read(
            "cislo;nazov\nFA-1;\"Tovar; špeciálny\"\n",
            ['delimiter' => ';']
        );

        $this->assertSame('Tovar; špeciálny', $records[0]->get('nazov'));
    }

    public function test_converts_windows_1250_encoding(): void
    {
        $content = iconv('UTF-8', 'Windows-1250', "cislo;nazov\nFA-1;Zväčšovací ľadový čaj\n");

        $records = (new CsvReader())->read($content, ['delimiter' => ';', 'encoding' => 'Windows-1250']);

        $this->assertSame('Zväčšovací ľadový čaj', $records[0]->get('nazov'));
    }

    public function test_missing_column_returns_null(): void
    {
        $records = (new CsvReader())->read("a;b\n1\n", ['delimiter' => ';']);

        $this->assertSame('1', $records[0]->get('a'));
        $this->assertNull($records[0]->get('b'));
        $this->assertNull($records[0]->get('neexistuje'));
    }

    public function test_empty_file_throws(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('prázdny');
        (new CsvReader())->read('');
    }
}
