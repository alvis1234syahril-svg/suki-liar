<?php

namespace Tests\Unit;

use App\Services\FortiGateLogParser;
use Tests\TestCase;

class FortiGateLogParserTest extends TestCase
{
    public function test_it_reads_quoted_and_unquoted_fortigate_fields(): void
    {
        $parser = new FortiGateLogParser();

        $data = $parser->parseLine(
            'date=2026-07-23 time=13:45:10 srcip=10.0.0.5 policyname="Office Internet" app=HTTPS'
        );

        $this->assertSame('10.0.0.5', $data['srcip']);
        $this->assertSame('Office Internet', $data['policyname']);
        $this->assertSame('HTTPS', $data['app']);
    }

    public function test_it_accepts_empty_quoted_fields(): void
    {
        $parser = new FortiGateLogParser();
        $data = $parser->parseLine('srcip=10.0.0.5 srcname="" policyname="" action=accept');

        $this->assertSame('', $data['srcname']);
        $this->assertSame('', $data['policyname']);
        $this->assertSame('accept', $data['action']);
    }

    public function test_it_preserves_unknown_fields_as_json(): void
    {
        $parser = new FortiGateLogParser();
        $data = $parser->parseLine('srcip=10.0.0.5 custom_field="custom value"');
        $row = $parser->toDatabaseRow(12, $data, 'raw log');

        $this->assertSame(['custom_field' => 'custom value'], json_decode($row['extra_fields'], true));
    }

    public function test_invalid_timestamp_does_not_invalidate_the_log(): void
    {
        $parser = new FortiGateLogParser();
        $data = $parser->parseLine('date=invalid time=25:61:00 srcip=10.0.0.5');
        $row = $parser->toDatabaseRow(12, $data, 'raw log');

        $this->assertNull($row['logged_at']);
        $this->assertSame('10.0.0.5', $row['srcip']);
    }

    public function test_invalid_numeric_and_oversized_values_are_safely_normalized(): void
    {
        $parser = new FortiGateLogParser();
        $data = $parser->parseLine(
            'srcip=10.0.0.5 srcport=invalid proto=999 policyid=-1 sentbyte=NaN rcvdbyte=42 srcname="'.str_repeat('A', 300).'"'
        );
        $row = $parser->toDatabaseRow(12, $data, 'raw log');

        $this->assertNull($row['srcport']);
        $this->assertNull($row['proto']);
        $this->assertNull($row['policyid']);
        $this->assertSame(0, $row['sentbyte']);
        $this->assertSame(42, $row['rcvdbyte']);
        $this->assertSame(255, mb_strlen($row['srcname']));
    }

    public function test_normalized_identity_prefers_user_and_keeps_ip_as_association(): void
    {
        $parser = new FortiGateLogParser();
        $row = $parser->toDatabaseRow(12, $parser->parseLine(
            'user="rara.dwi" srcname=LAPTOP-RARA srcserver=AD01 srcip=10.10.1.7 sentbyte=25 rcvdbyte=75'
        ), 'raw log');

        $this->assertSame('rara.dwi', $row['identity_value']);
        $this->assertSame('user', $row['identity_type']);
        $this->assertSame(hash('sha256', 'user|rara.dwi'), $row['identity_key']);
        $this->assertSame('10.10.1.7', $row['srcip']);
        $this->assertSame(100, $row['total_bytes']);
    }
}
