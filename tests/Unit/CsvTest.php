<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public function test_it_prefixes_values_that_spreadsheets_can_execute(): void
    {
        $this->assertSame("'=HYPERLINK(\"https://example.test\")", Csv::cell('=HYPERLINK("https://example.test")'));
        $this->assertSame("'  +SUM(1,2)", Csv::cell('  +SUM(1,2)'));
        $this->assertSame("'-10+20", Csv::cell('-10+20'));
        $this->assertSame("'@malicious", Csv::cell('@malicious'));
    }

    public function test_it_preserves_normal_strings_and_numeric_values(): void
    {
        $this->assertSame('DNS', Csv::cell('DNS'));
        $this->assertSame('-', Csv::cell('-'));
        $this->assertSame(120, Csv::cell(120));
        $this->assertNull(Csv::cell(null));
    }
}
