<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the decomposition shape-prior is live at the operator surface and emits the deterministic Wilson
 * verdict: a thin corpus is `unknown` (never blocks a novel shape), a historically-thrashing shape is
 * `suspect`, and a reliably-certified shape is `ok`. A missing --total is a usage error.
 */
final class AtlasLoopDecompositionShapePriorCommandTest extends TestCase
{
    public function test_requires_total(): void
    {
        $exit = Artisan::call('atlas:loop:decomposition-shape-prior', ['--certified' => 3, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_thin_corpus_is_unknown(): void
    {
        $decoded = $this->assess(3, 5);

        $this->assertSame('atlas.loop.decomposition_shape_prior.v1', $decoded['schema']);
        $this->assertSame('unknown', $decoded['verdict']);
        $this->assertSame(5, $decoded['n']);
    }

    public function test_thrashing_shape_is_suspect(): void
    {
        $decoded = $this->assess(2, 20);

        $this->assertSame('suspect', $decoded['verdict']);
        $this->assertLessThan(0.5, $decoded['lower_bound']);
    }

    public function test_reliable_shape_is_ok(): void
    {
        $decoded = $this->assess(10, 10);

        $this->assertSame('ok', $decoded['verdict']);
        $this->assertGreaterThanOrEqual(0.5, $decoded['lower_bound']);
    }

    /**
     * @return array<string,mixed>
     */
    private function assess(int $certified, int $total): array
    {
        $exit = Artisan::call('atlas:loop:decomposition-shape-prior', [
            '--certified' => $certified,
            '--total' => $total,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
