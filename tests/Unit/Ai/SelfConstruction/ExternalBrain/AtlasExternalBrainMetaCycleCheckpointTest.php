<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMetaCycleCheckpoint;
use Tests\TestCase;

final class AtlasExternalBrainMetaCycleCheckpointTest extends TestCase
{
    private function cp(): AtlasExternalBrainMetaCycleCheckpoint
    {
        return new AtlasExternalBrainMetaCycleCheckpoint();
    }

    private function validCycle(array $overrides = []): array
    {
        return array_merge([
            'cycle_id'          => 'cycle-001',
            'recorded_at'       => 1000,
            'queue_pressure'    => 0.40,
            'targets_considered' => 10,
            'tasks_enqueued'    => 3,
            'tasks_skipped'     => 2,
            'rejection_reasons' => ['quality_gate_blocked' => 2],
            'validation_results' => ['passed' => 8, 'failed' => 2],
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_on_capture(): void
    {
        $result = $this->cp()->capture($this->validCycle());
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::SCHEMA, $result['schema']);
    }

    public function test_schema_on_latest(): void
    {
        $result = $this->cp()->latest();
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::SCHEMA, $result['schema']);
    }

    public function test_schema_on_history(): void
    {
        $result = $this->cp()->history();
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::SCHEMA, $result['schema']);
    }

    // ── capture — acceptance ──────────────────────────────────────────────────

    public function test_capture_accepts_valid_cycle(): void
    {
        $result = $this->cp()->capture($this->validCycle());
        $this->assertTrue($result['accepted']);
        $this->assertArrayHasKey('checkpoint', $result);
    }

    public function test_capture_stores_all_fields(): void
    {
        $result = $this->cp()->capture($this->validCycle());
        $cp = $result['checkpoint'];

        $this->assertSame('cycle-001', $cp['cycle_id']);
        $this->assertSame(1000, $cp['recorded_at']);
        $this->assertSame(0.40, $cp['queue_pressure']);
        $this->assertSame(10, $cp['targets_considered']);
        $this->assertSame(3, $cp['tasks_enqueued']);
        $this->assertSame(2, $cp['tasks_skipped']);
        $this->assertSame(['quality_gate_blocked' => 2], $cp['rejection_reasons']);
    }

    public function test_capture_computes_validation_rate(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'validation_results' => ['passed' => 8, 'failed' => 2],
        ]));

        $this->assertSame(0.8, $result['checkpoint']['validation_results']['rate']);
    }

    // ── continuity sections (AC2/AC3) ────────────────────────────────────────

    private function fullContinuityCycle(array $overrides = []): array
    {
        return $this->validCycle(array_merge([
            'domain_map_summary'      => ['areas_covered' => 5, 'top_area' => 'external_brain'],
            'queued_target_digest'    => ['count' => 7, 'families' => ['poison_repair']],
            'rejected_candidates'     => [
                ['candidate_id' => 'c1', 'reason' => 'duplicate'],
                ['candidate_id' => 'c2', 'reason' => 'duplicate'],
                ['candidate_id' => 'c3', 'reason' => 'low_leverage'],
            ],
            'outcome_learning_digest' => ['success_rate' => 0.72, 'families_learned' => 4],
            'next_frontier'           => 'harden_quarantine_repair_loop',
        ], $overrides));
    }

    public function test_continuity_sections_captured_and_continuation_safe(): void
    {
        $result = $this->cp()->capture($this->fullContinuityCycle());
        $cp = $result['checkpoint'];

        $this->assertSame(['areas_covered' => 5, 'top_area' => 'external_brain'], $cp['domain_map_summary']);
        $this->assertSame(['count' => 7, 'families' => ['poison_repair']], $cp['queued_target_digest']);
        $this->assertSame(['success_rate' => 0.72, 'families_learned' => 4], $cp['outcome_learning_digest']);
        $this->assertSame('harden_quarantine_repair_loop', $cp['next_frontier']);
        $this->assertTrue($cp['continuation_safe']);
        $this->assertSame([], $cp['continuation_unsafe_reasons']);
    }

    public function test_rejected_candidate_digest_groups_by_reason(): void
    {
        $result = $this->cp()->capture($this->fullContinuityCycle());
        $digest = $result['checkpoint']['rejected_candidate_digest'];

        $this->assertSame(3, $digest['total']);
        $this->assertSame(['duplicate' => 2, 'low_leverage' => 1], $digest['by_reason']);
    }

    public function test_missing_required_section_marks_continuation_unsafe(): void
    {
        $cycle = $this->fullContinuityCycle();
        unset($cycle['next_frontier']);

        $result = $this->cp()->capture($cycle);
        $cp = $result['checkpoint'];

        $this->assertFalse($cp['continuation_safe']);
        $this->assertContains('missing:next_frontier', $cp['continuation_unsafe_reasons']);
    }

    public function test_stale_section_marks_continuation_unsafe(): void
    {
        $cycle = $this->fullContinuityCycle([
            'domain_map_summary' => ['areas_covered' => 5, 'stale' => true],
        ]);

        $result = $this->cp()->capture($cycle);
        $cp = $result['checkpoint'];

        $this->assertFalse($cp['continuation_safe']);
        $this->assertContains('stale:domain_map_summary', $cp['continuation_unsafe_reasons']);
    }

    public function test_no_continuity_sections_supplied_marks_unsafe_with_all_missing(): void
    {
        $result = $this->cp()->capture($this->validCycle());
        $cp = $result['checkpoint'];

        $this->assertFalse($cp['continuation_safe']);
        $this->assertCount(5, $cp['continuation_unsafe_reasons']);
    }

    public function test_capture_validation_rate_zero_when_no_results(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'validation_results' => ['passed' => 0, 'failed' => 0],
        ]));

        $this->assertSame(0.0, $result['checkpoint']['validation_results']['rate']);
    }

    // ── capture — provider-safe rejection ─────────────────────────────────────

    public function test_rejects_raw_prompt_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['raw_prompt' => 'some text']));
        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('raw_prompt', $result['rejection_reason']);
    }

    public function test_rejects_prompt_text_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['prompt_text' => 'some text']));
        $this->assertFalse($result['accepted']);
    }

    public function test_rejects_prompt_template_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['prompt_template' => 'some text']));
        $this->assertFalse($result['accepted']);
    }

    public function test_rejects_raw_log_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['raw_log' => 'stdout...']));
        $this->assertFalse($result['accepted']);
    }

    public function test_rejects_unbounded_task_payload(): void
    {
        $bigTask = array_fill_keys(array_map(fn ($i) => "key_$i", range(0, 25)), 'val');
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['tasks' => [$bigTask]]));
        $this->assertFalse($result['accepted']);
        $this->assertSame('unbounded_task_payload', $result['rejection_reason']);
    }

    public function test_accepts_task_within_key_limit(): void
    {
        $smallTask = array_fill_keys(array_map(fn ($i) => "key_$i", range(0, 5)), 'val');
        $result = $this->cp()->capture(array_merge($this->validCycle(), ['tasks' => [$smallTask]]));
        $this->assertTrue($result['accepted']);
    }

    // ── next_cycle_recommendation ─────────────────────────────────────────────

    public function test_recommendation_continue_by_default(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure' => 0.40, 'tasks_enqueued' => 3,
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_CONTINUE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_escalate_when_queue_pressure_high(): void
    {
        $result = $this->cp()->capture($this->validCycle(['queue_pressure' => 0.95]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_ESCALATE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_drain_when_pressure_high_and_no_enqueue(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure' => 0.75, 'tasks_enqueued' => 0,
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_DRAIN, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_pause_when_no_enqueue_skipped_and_low_validation(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure'     => 0.30,
            'tasks_enqueued'     => 0,
            'tasks_skipped'      => 5,
            'validation_results' => ['passed' => 2, 'failed' => 8],
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_PAUSE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_escalate_takes_priority_over_drain(): void
    {
        // Both escalate (>=0.90) and drain (>=0.70 + no enqueue) would match; escalate wins
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure' => 0.92, 'tasks_enqueued' => 0,
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_ESCALATE, $result['checkpoint']['next_cycle_recommendation']);
    }

    // ── latest ────────────────────────────────────────────────────────────────

    public function test_latest_null_when_no_checkpoints(): void
    {
        $result = $this->cp()->latest();
        $this->assertNull($result['checkpoint']);
    }

    public function test_latest_returns_most_recent(): void
    {
        $cp = $this->cp();
        $cp->capture($this->validCycle(['cycle_id' => 'c1']));
        $cp->capture($this->validCycle(['cycle_id' => 'c2']));

        $this->assertSame('c2', $cp->latest()['checkpoint']['cycle_id']);
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function test_history_empty_initially(): void
    {
        $result = $this->cp()->history();
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['checkpoints']);
    }

    public function test_history_accumulates_all_captures(): void
    {
        $cp = $this->cp();
        $cp->capture($this->validCycle(['cycle_id' => 'c1']));
        $cp->capture($this->validCycle(['cycle_id' => 'c2']));
        $cp->capture($this->validCycle(['cycle_id' => 'c3']));

        $result = $cp->history();
        $this->assertSame(3, $result['count']);
        $this->assertSame('c1', $result['checkpoints'][0]['cycle_id']);
        $this->assertSame('c3', $result['checkpoints'][2]['cycle_id']);
    }

    public function test_rejected_cycle_not_stored_in_history(): void
    {
        $cp = $this->cp();
        $cp->capture(array_merge($this->validCycle(), ['raw_prompt' => 'oops']));
        $this->assertSame(0, $cp->history()['count']);
    }

    // ── nested provider-safe rejection ────────────────────────────────────────

    public function test_rejects_nested_provider_unsafe_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), [
            'metadata' => ['nested_context' => ['raw_prompt' => 'deep leak']],
        ]));
        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('raw_prompt', $result['rejection_reason']);
    }

    public function test_rejects_nested_log_data_key(): void
    {
        $result = $this->cp()->capture(array_merge($this->validCycle(), [
            'debug' => ['log_data' => 'some log'],
        ]));
        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('log_data', $result['rejection_reason']);
    }

    // ── carryover_notes ───────────────────────────────────────────────────────

    public function test_carryover_notes_stored_in_checkpoint(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'carryover_notes' => ['retry surface arxiv next cycle', 'quality gate threshold under review'],
        ]));
        $this->assertSame(
            ['retry surface arxiv next cycle', 'quality gate threshold under review'],
            $result['checkpoint']['carryover_notes'],
        );
    }

    public function test_carryover_notes_defaults_to_empty_list(): void
    {
        $result = $this->cp()->capture($this->validCycle());
        $this->assertSame([], $result['checkpoint']['carryover_notes']);
    }

    // ── consolidate recommendation ────────────────────────────────────────────

    public function test_recommendation_consolidate_when_enqueued_but_low_validation(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure'     => 0.40,
            'tasks_enqueued'     => 3,
            'validation_results' => ['passed' => 1, 'failed' => 9],  // rate=0.10 < 0.50
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_CONSOLIDATE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_consolidate_not_fired_when_validation_good(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'tasks_enqueued'     => 5,
            'validation_results' => ['passed' => 9, 'failed' => 1],  // rate=0.90 >= 0.50
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_CONTINUE, $result['checkpoint']['next_cycle_recommendation']);
    }

    // ── repair recommendation ─────────────────────────────────────────────────

    public function test_recommendation_repair_when_nothing_enqueued_or_skipped_but_validation_failed(): void
    {
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure'     => 0.20,
            'tasks_enqueued'     => 0,
            'tasks_skipped'      => 0,
            'validation_results' => ['passed' => 2, 'failed' => 5],
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_REPAIR, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_recommendation_repair_not_fired_when_tasks_were_skipped(): void
    {
        // skipped > 0 → repair gate does not fire (pause fires instead if valRate < 0.50)
        $result = $this->cp()->capture($this->validCycle([
            'queue_pressure'     => 0.20,
            'tasks_enqueued'     => 0,
            'tasks_skipped'      => 3,
            'validation_results' => ['passed' => 1, 'failed' => 5],
        ]));
        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_PAUSE, $result['checkpoint']['next_cycle_recommendation']);
    }
}
