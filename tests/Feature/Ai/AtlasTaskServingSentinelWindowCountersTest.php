<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use Tests\TestCase;

final class AtlasTaskServingSentinelWindowCountersTest extends TestCase
{
    public function test_queue_history_with_recent_transitions_emits_window_deltas(): void
    {
        $sentinel = new AtlasTaskServingSentinel();

        $result = $sentinel->windowCounters([
            [
                'task_packet_id' => 'p1',
                'history' => [
                    ['status' => 'claimed', 'at' => '2026-06-30T12:00:00Z'],
                    ['status' => 'completed_dry_run', 'at' => '2026-06-30T12:05:00Z'],
                ],
            ],
            [
                'task_packet_id' => 'p2',
                'history' => [
                    ['status' => 'claimed', 'at' => '2026-06-30T12:01:00Z'],
                    ['status' => 'released', 'at' => '2026-06-30T12:02:00Z'],
                ],
            ],
        ]);

        $this->assertSame(2, $result['claim_delta']);
        $this->assertSame(1, $result['completion_delta']);
        $this->assertSame(1, $result['release_delta']);
    }

    public function test_no_transition_history_keeps_counters_at_zero_and_does_not_fabricate_serve_total(): void
    {
        $sentinel = new AtlasTaskServingSentinel();

        $result = $sentinel->windowCounters([]);

        $this->assertSame(0, $result['claim_delta']);
        $this->assertSame(0, $result['completion_delta']);
        $this->assertSame(0, $result['release_delta']);
        $this->assertArrayNotHasKey('serve_total', $result);
    }

    public function test_records_with_empty_history_contribute_zero(): void
    {
        $sentinel = new AtlasTaskServingSentinel();

        $result = $sentinel->windowCounters([
            ['task_packet_id' => 'p1', 'history' => []],
        ]);

        $this->assertSame(0, $result['claim_delta']);
        $this->assertSame(0, $result['completion_delta']);
        $this->assertSame(0, $result['release_delta']);
    }

    public function test_window_start_filters_out_transitions_before_it(): void
    {
        $sentinel = new AtlasTaskServingSentinel();

        $result = $sentinel->windowCounters([
            [
                'task_packet_id' => 'p1',
                'history' => [
                    ['status' => 'claimed', 'at' => '2026-06-29T00:00:00Z'],
                    ['status' => 'claimed', 'at' => '2026-06-30T13:00:00Z'],
                ],
            ],
        ], '2026-06-30T00:00:00Z');

        $this->assertSame(1, $result['claim_delta']);
        $this->assertSame('2026-06-30T00:00:00Z', $result['window_start_iso8601']);
    }

    public function test_unrelated_statuses_are_not_counted(): void
    {
        $sentinel = new AtlasTaskServingSentinel();

        $result = $sentinel->windowCounters([
            [
                'task_packet_id' => 'p1',
                'history' => [
                    ['status' => 'queued', 'at' => '2026-06-30T12:00:00Z'],
                    ['status' => 'blocked', 'at' => '2026-06-30T12:01:00Z'],
                ],
            ],
        ]);

        $this->assertSame(0, $result['claim_delta']);
        $this->assertSame(0, $result['completion_delta']);
        $this->assertSame(0, $result['release_delta']);
    }
}
