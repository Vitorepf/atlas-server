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
}
