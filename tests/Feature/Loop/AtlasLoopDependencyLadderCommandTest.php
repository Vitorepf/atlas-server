<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the task-fabric dependency ladder is live at the operator surface and emits deterministic facts: a
 * producer/consumer pair sorts into two waves with the right depends_on edge; a consumer with no producer is
 * flagged. A missing --packets is a usage error.
 */
final class AtlasLoopDependencyLadderCommandTest extends TestCase
{
    public function test_requires_packets(): void
    {
        $exit = Artisan::call('atlas:loop:dependency-ladder', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_orders_producer_then_consumer_into_waves(): void
    {
        $decoded = $this->ladder([
            ['id' => 'b', 'consumes' => ['symX']],
            ['id' => 'a', 'produces' => ['symX']],
        ]);

        $this->assertSame('atlas.loop.dependency_ladder.v1', $decoded['schema']);
        $this->assertSame([['a'], ['b']], $decoded['waves']);
        $this->assertSame(['a'], $decoded['depends_on']['b']);
        $this->assertSame([], $decoded['blockers']);
    }

    public function test_missing_producer_is_flagged(): void
    {
        $decoded = $this->ladder([
            ['id' => 'c', 'consumes' => ['symY']],
        ]);

        $this->assertContains('missing_producer_for:symY', $decoded['blockers']);
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,mixed>
     */
    private function ladder(array $packets): array
    {
        $exit = Artisan::call('atlas:loop:dependency-ladder', [
            '--packets' => json_encode($packets),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
