<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvidenceFreshnessBackfillPlanner;
use Tests\TestCase;

final class AtlasExternalBrainEvidenceFreshnessBackfillPlannerTest extends TestCase
{
    private AtlasExternalBrainEvidenceFreshnessBackfillPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainEvidenceFreshnessBackfillPlanner;
    }

    private function now(): int
    {
        return 1_750_000_000;
    }

    // ── AC2: has_evidence=false → high-priority missing backfill with known proof commands

    public function test_ac2_missing_stream_produces_high_priority_backfill_task(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [[
                'stream_id'    => 'queue_health',
                'has_evidence' => false,
            ]],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $this->assertCount(1, $result['backfill_tasks']);
        $task = $result['backfill_tasks'][0];
        $this->assertSame('queue_health', $task['stream_id']);
        $this->assertSame('missing', $task['reason']);
        $this->assertSame('high', $task['priority']);
    }

    public function test_ac2_known_stream_queue_health_has_catalogue_proof_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [['stream_id' => 'queue_health', 'has_evidence' => false]],
        ]);

        $this->assertStringContainsString('queue-health-snapshot', $result['backfill_tasks'][0]['proof_command']);
    }

    public function test_ac2_known_stream_muscle_outcomes_has_catalogue_proof_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [['stream_id' => 'muscle_outcomes', 'has_evidence' => false]],
        ]);

        $this->assertStringContainsString('atlas:task:report', $result['backfill_tasks'][0]['proof_command']);
    }

    public function test_ac2_known_stream_runtime_receipts_has_catalogue_proof_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [['stream_id' => 'runtime_receipts', 'has_evidence' => false]],
        ]);

        $this->assertStringContainsString('self-construction:smoke', $result['backfill_tasks'][0]['proof_command']);
    }

    public function test_ac2_known_stream_code_facts_has_catalogue_proof_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [['stream_id' => 'code_facts', 'has_evidence' => false]],
        ]);

        $this->assertStringContainsString('index-code', $result['backfill_tasks'][0]['proof_command']);
    }

    public function test_ac2_unknown_stream_gets_generic_proof_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [['stream_id' => 'my_custom_stream', 'has_evidence' => false]],
        ]);

        $task = $result['backfill_tasks'][0];
        $this->assertStringContainsString('my_custom_stream', $task['proof_command']);
        $this->assertSame('missing', $task['reason']);
    }

    // ── AC3: older than freshness_threshold → stale task; fresh → omitted

    public function test_ac3_stale_stream_produces_medium_priority_backfill_task(): void
    {
        $threshold = 3600;
        $result = $this->planner->plan([
            'now_unix' => $this->now(),
            'evidence_streams' => [[
                'stream_id'                   => 'queue_health',
                'has_evidence'                => true,
                'last_captured_at_unix'       => $this->now() - $threshold - 1,
                'freshness_threshold_seconds' => $threshold,
            ]],
        ]);

        $this->assertTrue($result['is_backfill_needed']);
        $task = $result['backfill_tasks'][0];
        $this->assertSame('stale', $task['reason']);
        $this->assertSame('medium', $task['priority']);
    }

    public function test_ac3_fresh_stream_is_omitted_from_backfill(): void
    {
        $threshold = 3600;
        $result = $this->planner->plan([
            'now_unix' => $this->now(),
            'evidence_streams' => [[
                'stream_id'                   => 'queue_health',
                'has_evidence'                => true,
                'last_captured_at_unix'       => $this->now() - 100,
                'freshness_threshold_seconds' => $threshold,
            ]],
        ]);

        $this->assertFalse($result['is_backfill_needed']);
        $this->assertSame([], $result['backfill_tasks']);
    }

    public function test_ac3_exactly_at_threshold_is_not_stale(): void
    {
        $threshold = 3600;
        $result = $this->planner->plan([
            'now_unix' => $this->now(),
            'evidence_streams' => [[
                'stream_id'                   => 'queue_health',
                'has_evidence'                => true,
                'last_captured_at_unix'       => $this->now() - $threshold,
                'freshness_threshold_seconds' => $threshold,
            ]],
        ]);

        $this->assertFalse($result['is_backfill_needed']);
    }

    // ── AC4: next_proof_command = first task's proof_command; 'none' when no backfill

    public function test_ac4_next_proof_command_equals_first_backfill_task_command(): void
    {
        $result = $this->planner->plan([
            'evidence_streams' => [
                ['stream_id' => 'queue_health',    'has_evidence' => false],
                ['stream_id' => 'muscle_outcomes', 'has_evidence' => false],
            ],
        ]);

        $this->assertSame(
            $result['backfill_tasks'][0]['proof_command'],
            $result['next_proof_command'],
        );
    }

    public function test_ac4_no_backfill_needed_returns_none_as_next_proof_command(): void
    {
        $result = $this->planner->plan([
            'now_unix' => $this->now(),
            'evidence_streams' => [[
                'stream_id'             => 'queue_health',
                'has_evidence'          => true,
                'last_captured_at_unix' => $this->now() - 60,
            ]],
        ]);

        $this->assertSame('none', $result['next_proof_command']);
        $this->assertFalse($result['is_backfill_needed']);
    }

    public function test_ac4_empty_streams_returns_none_and_no_backfill(): void
    {
        $result = $this->planner->plan(['evidence_streams' => []]);

        $this->assertSame('none', $result['next_proof_command']);
        $this->assertFalse($result['is_backfill_needed']);
        $this->assertSame([], $result['backfill_tasks']);
    }
}
