<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceFreshnessBackfillPlanner;
use Tests\TestCase;

final class AtlasExternalBrainEvidenceFreshnessBackfillPlannerTest extends TestCase
{
    private const NOW = 1_750_000_000;

    private function planner(): AtlasExternalBrainEvidenceFreshnessBackfillPlanner
    {
        return new AtlasExternalBrainEvidenceFreshnessBackfillPlanner();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame(AtlasExternalBrainEvidenceFreshnessBackfillPlanner::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan([]);

        foreach (['schema', 'backfill_tasks', 'is_backfill_needed', 'next_proof_command'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_each_backfill_task_has_required_fields(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        foreach (['stream_id', 'capture_task', 'freshness_threshold_seconds', 'proof_command', 'reason', 'priority'] as $f) {
            $this->assertArrayHasKey($f, $task);
        }
    }

    // ── empty plan when evidence is fresh ─────────────────────────────────────

    public function test_empty_streams_yields_no_backfill(): void
    {
        $result = $this->planner()->plan(['evidence_streams' => []]);

        $this->assertFalse($result['is_backfill_needed']);
        $this->assertSame([], $result['backfill_tasks']);
        $this->assertSame('none', $result['next_proof_command']);
    }

    public function test_stream_with_evidence_and_within_threshold_yields_no_backfill(): void
    {
        $freshAt = self::NOW - 3600;  // 1 hour ago, threshold = 24h

        $result = $this->planner()->plan([
            'now_unix'         => self::NOW,
            'evidence_streams' => [
                [
                    'stream_id'                  => 'queue_health',
                    'has_evidence'               => true,
                    'last_captured_at_unix'      => $freshAt,
                    'freshness_threshold_seconds' => 86400,
                ],
            ],
        ]);

        $this->assertFalse($result['is_backfill_needed']);
        $this->assertSame([], $result['backfill_tasks']);
    }

    // ── has_evidence = false triggers backfill ────────────────────────────────

    public function test_queue_health_missing_evidence_emits_capture_task(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $this->assertCount(1, $result['backfill_tasks']);
        $task = $result['backfill_tasks'][0];
        $this->assertSame('queue_health', $task['stream_id']);
        $this->assertSame('missing', $task['reason']);
        $this->assertStringContainsString('queue-health-snapshot', $task['capture_task']);
    }

    public function test_muscle_outcomes_missing_evidence_emits_capture_task(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'muscle_outcomes', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertSame('muscle_outcomes', $task['stream_id']);
        $this->assertSame('missing', $task['reason']);
        $this->assertStringContainsString('report', $task['capture_task']);
    }

    public function test_runtime_receipts_missing_evidence_emits_capture_task(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'runtime_receipts', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertSame('runtime_receipts', $task['stream_id']);
        $this->assertStringContainsString('smoke', $task['capture_task']);
    }

    public function test_code_facts_missing_evidence_emits_capture_task(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'code_facts', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertSame('code_facts', $task['stream_id']);
        $this->assertStringContainsString('index-code', $task['capture_task']);
    }

    public function test_all_four_missing_streams_produce_four_backfill_tasks(): void
    {
        $streams = array_map(
            fn (string $id): array => ['stream_id' => $id, 'has_evidence' => false],
            ['queue_health', 'muscle_outcomes', 'runtime_receipts', 'code_facts'],
        );

        $result = $this->planner()->plan(['evidence_streams' => $streams]);

        $this->assertCount(4, $result['backfill_tasks']);
        $ids = array_column($result['backfill_tasks'], 'stream_id');
        $this->assertContains('queue_health', $ids);
        $this->assertContains('muscle_outcomes', $ids);
        $this->assertContains('runtime_receipts', $ids);
        $this->assertContains('code_facts', $ids);
    }

    // ── stale evidence triggers backfill ──────────────────────────────────────

    public function test_stale_evidence_beyond_threshold_triggers_backfill(): void
    {
        $staleAt = self::NOW - 90000;  // 25 hours ago, threshold = 24h

        $result = $this->planner()->plan([
            'now_unix'         => self::NOW,
            'evidence_streams' => [
                [
                    'stream_id'                  => 'queue_health',
                    'has_evidence'               => true,
                    'last_captured_at_unix'      => $staleAt,
                    'freshness_threshold_seconds' => 86400,
                ],
            ],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $task = $result['backfill_tasks'][0];
        $this->assertSame('stale', $task['reason']);
    }

    public function test_no_now_unix_skips_staleness_check_for_evidence_present(): void
    {
        // Without now_unix, cannot determine staleness; has_evidence=true → no backfill.
        $result = $this->planner()->plan([
            'evidence_streams' => [
                [
                    'stream_id'             => 'queue_health',
                    'has_evidence'          => true,
                    'last_captured_at_unix' => 1_000_000,  // very old
                ],
            ],
        ]);

        $this->assertFalse($result['is_backfill_needed']);
    }

    // ── unknown stream fallback ───────────────────────────────────────────────

    public function test_unknown_stream_gets_generic_capture_task(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'my_custom_stream', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertSame('my_custom_stream', $task['stream_id']);
        $this->assertStringContainsString('my_custom_stream', $task['capture_task']);
        $this->assertStringContainsString('my_custom_stream', $task['proof_command']);
    }

    // ── next_proof_command ────────────────────────────────────────────────────

    public function test_next_proof_command_is_first_backfill_task_proof(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health',    'has_evidence' => false],
                ['stream_id' => 'muscle_outcomes', 'has_evidence' => false],
            ],
        ]);

        $expected = $result['backfill_tasks'][0]['proof_command'];
        $this->assertSame($expected, $result['next_proof_command']);
    }

    public function test_known_stream_proof_command_contains_artisan(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
        ]);

        $this->assertStringContainsString('artisan', $result['next_proof_command']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $audit = [
            'now_unix'         => self::NOW,
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
                ['stream_id' => 'code_facts',   'has_evidence' => true, 'last_captured_at_unix' => self::NOW - 90000, 'freshness_threshold_seconds' => 86400],
            ],
        ];

        $this->assertSame(
            $this->planner()->plan($audit),
            $this->planner()->plan($audit),
        );
    }

    // ── priority field ────────────────────────────────────────────────────────

    public function test_missing_evidence_produces_high_priority_backfill(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
        ]);

        $this->assertSame('high', $result['backfill_tasks'][0]['priority']);
    }

    public function test_stale_evidence_produces_medium_priority_backfill(): void
    {
        $result = $this->planner()->plan([
            'now_unix'         => self::NOW,
            'evidence_streams' => [
                [
                    'stream_id'                  => 'code_facts',
                    'has_evidence'               => true,
                    'last_captured_at_unix'      => self::NOW - 90000,
                    'freshness_threshold_seconds' => 86400,
                ],
            ],
        ]);

        $this->assertSame('medium', $result['backfill_tasks'][0]['priority']);
    }

    public function test_unknown_stream_proof_command_is_runnable_artisan(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'my_custom_stream', 'has_evidence' => false],
            ],
        ]);

        $proof = $result['backfill_tasks'][0]['proof_command'];
        $this->assertStringContainsString('artisan', $proof);
        $this->assertStringContainsString('my_custom_stream', $proof);
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_new_ac1_keys(): void
    {
        $result = $this->planner()->plan(['evidence_streams' => []]);

        foreach (['grouped_by_reason', 'priority_order', 'freshness_summary'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame('none', $result['next_proof_command']);
    }

    // ── AC2: missing outranks stale; contradictory + never_captured are unsafe ──

    public function test_missing_evidence_outranks_stale_in_priority_order(): void
    {
        $result = $this->planner()->plan([
            'now_unix' => 1000000,
            'evidence_streams' => [
                ['stream_id' => 'stale-one', 'has_evidence' => true, 'last_captured_at_unix' => 1, 'freshness_threshold_seconds' => 10],
                ['stream_id' => 'missing-one', 'has_evidence' => false],
            ],
        ]);

        $this->assertSame(['missing-one', 'stale-one'], $result['priority_order']);
        $this->assertSame('missing', $result['backfill_tasks'][0]['reason']);
    }

    public function test_contradictory_stream_requires_backfill(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'bad-claim', 'has_evidence' => true, 'last_captured_at_unix' => 500, 'contradictory' => true],
            ],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $this->assertSame('contradictory', $result['backfill_tasks'][0]['reason']);
        $this->assertSame('high', $result['backfill_tasks'][0]['priority']);
    }

    public function test_has_evidence_true_with_zero_last_captured_is_unsafe_even_without_now_unix(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'phantom', 'has_evidence' => true, 'last_captured_at_unix' => 0],
            ],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $this->assertSame('never_captured', $result['backfill_tasks'][0]['reason']);
    }

    // ── grouped_by_reason / freshness_summary ─────────────────────────────────

    public function test_grouped_by_reason_buckets_streams_correctly(): void
    {
        $result = $this->planner()->plan([
            'now_unix' => 1000000,
            'evidence_streams' => [
                ['stream_id' => 'm1', 'has_evidence' => false],
                ['stream_id' => 's1', 'has_evidence' => true, 'last_captured_at_unix' => 1, 'freshness_threshold_seconds' => 10],
                ['stream_id' => 'c1', 'has_evidence' => true, 'last_captured_at_unix' => 999999, 'contradictory' => true],
            ],
        ]);

        $this->assertSame(['m1'], $result['grouped_by_reason']['missing']);
        $this->assertSame(['s1'], $result['grouped_by_reason']['stale']);
        $this->assertSame(['c1'], $result['grouped_by_reason']['contradictory']);
        $this->assertSame(3, $result['freshness_summary']['needs_backfill_count']);
        $this->assertSame(1, $result['freshness_summary']['missing_count']);
    }

    public function test_freshness_summary_is_backfill_needed_false_when_clean(): void
    {
        $result = $this->planner()->plan([
            'now_unix' => 1000000,
            'evidence_streams' => [
                ['stream_id' => 'fresh', 'has_evidence' => true, 'last_captured_at_unix' => 999999, 'freshness_threshold_seconds' => 86400],
            ],
        ]);

        $this->assertFalse($result['freshness_summary']['is_backfill_needed']);
        $this->assertSame('none', $result['next_proof_command']);
    }

    // ── AC: missing high-leverage streams outrank low-leverage stale streams ──

    public function test_missing_high_leverage_outranks_low_leverage_stale(): void
    {
        $result = $this->planner()->plan([
            'now_unix' => 1000000,
            'evidence_streams' => [
                // Stale but low leverage
                ['stream_id' => 'low-lev-stale', 'has_evidence' => true, 'last_captured_at_unix' => 1, 'freshness_threshold_seconds' => 10, 'leverage_impact' => 0.1],
                // Missing and high leverage
                ['stream_id' => 'high-lev-missing', 'has_evidence' => false, 'leverage_impact' => 0.9],
            ],
        ]);

        // Missing (reason rank 0) should outrank stale (reason rank 2) regardless of leverage
        $this->assertSame('high-lev-missing', $result['priority_order'][0]);
        $this->assertSame('low-lev-stale', $result['priority_order'][1]);
    }

    public function test_high_leverage_missing_outranks_low_leverage_missing(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'low-lev', 'has_evidence' => false, 'leverage_impact' => 0.1],
                ['stream_id' => 'high-lev', 'has_evidence' => false, 'leverage_impact' => 0.9],
            ],
        ]);

        // Both missing (same reason rank), but high leverage should come first
        $this->assertSame('high-lev', $result['priority_order'][0]);
        $this->assertSame('low-lev', $result['priority_order'][1]);
    }

    // ── AC: capture_cost affects ordering only after leverage impact and freshness risk ──

    public function test_capture_cost_affects_ordering_after_leverage_and_risk(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                // Same reason (missing), same leverage, same freshness risk, different cost
                ['stream_id' => 'expensive', 'has_evidence' => false, 'leverage_impact' => 0.5, 'capture_cost' => 0.9],
                ['stream_id' => 'cheap', 'has_evidence' => false, 'leverage_impact' => 0.5, 'capture_cost' => 0.1],
            ],
        ]);

        // Both missing, same leverage, same risk → cheaper one first
        $this->assertSame('cheap', $result['priority_order'][0]);
        $this->assertSame('expensive', $result['priority_order'][1]);
    }

    public function test_capture_cost_does_not_override_leverage_impact(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                // High leverage but expensive
                ['stream_id' => 'high-lev-expensive', 'has_evidence' => false, 'leverage_impact' => 0.9, 'capture_cost' => 0.9],
                // Low leverage but cheap
                ['stream_id' => 'low-lev-cheap', 'has_evidence' => false, 'leverage_impact' => 0.1, 'capture_cost' => 0.1],
            ],
        ]);

        // Leverage impact should override capture cost
        $this->assertSame('high-lev-expensive', $result['priority_order'][0]);
    }

    // ── AC: every backfill item includes capture_task and proof_command ──

    public function test_every_backfill_item_includes_capture_task_and_proof_command(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
                ['stream_id' => 'muscle_outcomes', 'has_evidence' => false],
                ['stream_id' => 'custom_stream', 'has_evidence' => false],
            ],
        ]);

        foreach ($result['backfill_tasks'] as $task) {
            $this->assertArrayHasKey('capture_task', $task);
            $this->assertArrayHasKey('proof_command', $task);
            $this->assertNotEmpty($task['capture_task']);
            $this->assertNotEmpty($task['proof_command']);
        }
    }

    public function test_backfill_items_include_leverage_freshness_and_cost_fields(): void
    {
        $result = $this->planner()->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health', 'has_evidence' => false],
            ],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertArrayHasKey('leverage_impact', $task);
        $this->assertArrayHasKey('freshness_risk', $task);
        $this->assertArrayHasKey('capture_cost', $task);
    }
}
