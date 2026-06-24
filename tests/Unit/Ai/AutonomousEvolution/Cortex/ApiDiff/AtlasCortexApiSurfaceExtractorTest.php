<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\ApiDiff;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiSurfaceExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasCortexApiSurfaceExtractorTest extends TestCase
{
    public function test_it_extracts_public_api_surface_in_declaration_order_without_qualitative_keys(): void
    {
        $records = (new AtlasCortexApiSurfaceExtractor)->extractForClass(ApiSurfaceFixture::class);

        $this->assertSame(['alpha', 'beta', 'gamma'], array_keys($records));
        $this->assertCount(3, $records);
        $this->assertFalse($records['alpha']['static']);
        $this->assertTrue($records['beta']['static']);
        $this->assertSame('?string', $records['gamma']['return_type_hint']);
        $this->assertSame("'fallback'", $records['gamma']['parameters'][1]['default_literal']);
        $this->assertTrue($records['gamma']['parameters'][2]['variadic']);
        $this->assertArrayHasKey('declaring_file_path', $records['alpha']);
        $this->assertArrayHasKey('start_line', $records['alpha']);
        $this->assertArrayHasKey('end_line', $records['alpha']);

        foreach ($records as $record) {
            $this->assertSame('public', $record['visibility']);
            $this->assertArrayNotHasKey('score', $record);
            $this->assertArrayNotHasKey('severity', $record);
            $this->assertArrayNotHasKey('risk', $record);
            $this->assertArrayNotHasKey('complexity', $record);
        }
    }

    public function test_hashes_are_byte_identical_for_same_class_and_change_when_signature_changes(): void
    {
        $extractor = new AtlasCortexApiSurfaceExtractor;

        $first = $extractor->extractForClass(ApiSurfaceFixture::class);
        $second = $extractor->extractForClass(ApiSurfaceFixture::class);
        $changed = $extractor->extractForClass(ApiSurfaceChangedFixture::class);

        $this->assertSame($first, $second);
        $this->assertSame($first['alpha']['signature_hash'], $second['alpha']['signature_hash']);
        $this->assertNotSame($first['gamma']['signature_hash'], $changed['gamma']['signature_hash']);
        $this->assertArrayNotHasKey('hidden', $first);
        $this->assertArrayNotHasKey('secret', $first);
    }
}

final class ApiSurfaceFixture
{
    public function alpha(int $value): int
    {
        return $value;
    }

    public static function beta(): void
    {
    }

    public function gamma(string $name, string $fallback = 'fallback', string ...$tail): ?string
    {
        return $name !== '' ? $name : null;
    }

    protected function hidden(): void
    {
    }

    private function secret(): void
    {
    }
}

final class ApiSurfaceChangedFixture
{
    public function alpha(int $value): int
    {
        return $value;
    }

    public static function beta(): void
    {
    }

    public function gamma(?string $name, string $fallback = 'fallback', string ...$tail): ?string
    {
        return $name !== '' ? $name : null;
    }
}
