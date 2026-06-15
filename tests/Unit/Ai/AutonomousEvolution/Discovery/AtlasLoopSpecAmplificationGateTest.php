<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSpecAmplificationGate;
use PHPUnit\Framework\TestCase;

/**
 * Lever 5 — the spec-amplification gate. Static analysis over an acceptance test's richness (cases +
 * assertions); a thin spec is refused so it must be amplified before provider budget is spent. Fail-open
 * when floors are 0.
 */
final class AtlasLoopSpecAmplificationGateTest extends TestCase
{
    private function richTest(): string
    {
        return <<<'PHP'
<?php
final class ExportRateLimitTest extends TestCase
{
    public function test_allows_three_requests(): void
    {
        $this->assertSame(200, $r1->status());
        $this->assertSame(200, $r2->status());
        $this->assertSame(200, $r3->status());
    }

    public function test_rejects_the_fourth_within_window(): void
    {
        $this->assertSame(429, $r4->status());
        $this->expectException(TooManyRequests::class);
    }
}
PHP;
    }

    private function thinTest(): string
    {
        return <<<'PHP'
<?php
final class ThinTest extends TestCase
{
    public function test_it_works(): void
    {
        $this->assertTrue($result);
    }
}
PHP;
    }

    public function test_off_when_floors_zero_is_byte_identical(): void
    {
        $g = (new AtlasLoopSpecAmplificationGate)->assess($this->thinTest(), 0, 0);
        $this->assertTrue($g['rich'], 'floors 0 => never gate');
        $this->assertNull($g['reason']);
    }

    public function test_counts_assertions_and_methods(): void
    {
        $g = (new AtlasLoopSpecAmplificationGate)->assess($this->richTest(), 0, 0);
        $this->assertSame(5, $g['assertions'], '3 assertSame + 1 assertSame + 1 expectException');
        $this->assertSame(2, $g['test_methods']);
    }

    public function test_rich_spec_passes_the_floor(): void
    {
        $g = (new AtlasLoopSpecAmplificationGate)->assess($this->richTest(), 4, 2);
        $this->assertTrue($g['rich'], json_encode($g));
        $this->assertNull($g['reason']);
    }

    public function test_thin_spec_is_refused_with_a_reason(): void
    {
        $g = (new AtlasLoopSpecAmplificationGate)->assess($this->thinTest(), 3, 2);
        $this->assertFalse($g['rich']);
        $this->assertStringContainsString('spec_too_thin', (string) $g['reason']);
        $this->assertStringContainsString('assertions:1<3', (string) $g['reason']);
        $this->assertStringContainsString('test_methods:1<2', (string) $g['reason']);
    }
}
