<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\SchemaVersionedJsonBlockParser;
use PHPUnit\Framework\TestCase;

final class SchemaVersionedJsonBlockParserTest extends TestCase
{
    public function test_prefers_matching_schema_version_in_fenced_block(): void
    {
        $output = "noise\n```json\n{\"schema_version\":\"v1\",\"ok\":true}\n```\n";
        $decoded = SchemaVersionedJsonBlockParser::parse($output, 'v1');
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
    }

    public function test_falls_back_when_schema_missing(): void
    {
        $output = '{"hello":"world"}';
        $decoded = SchemaVersionedJsonBlockParser::parse($output, 'v99');
        $this->assertIsArray($decoded);
        $this->assertSame('world', $decoded['hello']);
    }
}
