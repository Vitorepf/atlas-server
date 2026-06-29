<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the behavioral-equivalence gate is live at the operator surface and emits the deterministic verdict:
 * a strong suite (kill ratio above floor) passes, a weak suite fails with a reason, and a missing --sampled
 * is a usage error. Pure scoring — no mutation is ever run.
 */
final class AtlasLoopBehavioralEquivalenceCommandTest extends TestCase
{
    public function test_requires_sampled(): void
    {
        $exit = Artisan::call('atlas:loop:behavioral-equivalence', ['--killed' => 1, '--floor' => 0.9, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_strong_suite_passes(): void
    {
        $decoded = $this->invoke(100, 95, 0.9);

        $this->assertSame('atlas.loop.behavioral_equivalence.v1', $decoded['schema']);
        $this->assertTrue($decoded['passes']);
        $this->assertSame(0.95, $decoded['kill_ratio']);
        $this->assertNull($decoded['reason']);
    }

    public function test_weak_suite_fails_with_reason(): void
    {
        $decoded = $this->invoke(100, 50, 0.9);

        $this->assertFalse($decoded['passes']);
        $this->assertSame(0.5, $decoded['kill_ratio']);
        $this->assertStringContainsString('kill_ratio_below_floor', (string) $decoded['reason']);
    }

    /**
     * @return array<string,mixed>
     */
    private function invoke(int $sampled, int $killed, float $floor): array
    {
        $exit = Artisan::call('atlas:loop:behavioral-equivalence', [
            '--sampled' => $sampled,
            '--killed' => $killed,
            '--floor' => $floor,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
