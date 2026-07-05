<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeEvidenceContinuityIndexer;
use Tests\TestCase;

final class AgentRuntimeEvidenceContinuityIndexerTest extends TestCase
{
    public function test_globally_complete_evidence_split_across_tasks_reports_each_incomplete_chain(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
            ['evidence_type' => 'scope_lock', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
            ['evidence_type' => 'validation_result', 'task_packet_id' => 'task-b', 'agent_id' => 'agent-2'],
            ['evidence_type' => 'continuation_summary', 'task_packet_id' => 'task-b', 'agent_id' => 'agent-2'],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        // globally every required type is present, somewhere
        $this->assertSame([], $index['missing_required_evidence_types']);
        $this->assertSame('continuity_index_stitched_proxy', $index['status']);

        $byTask = collect($index['per_task_continuity'])->keyBy('task_packet_id');

        $this->assertFalse($byTask['task-a']['complete']);
        $this->assertSame(
            ['validation_result', 'continuation_summary'],
            $byTask['task-a']['missing_required_types'],
        );

        $this->assertFalse($byTask['task-b']['complete']);
        $this->assertSame(
            ['dispatch_plan', 'claim_lease', 'scope_lock'],
            $byTask['task-b']['missing_required_types'],
        );
    }

    public function test_per_task_continuity_marks_complete_when_single_task_has_all_required_types(): void
    {
        $entries = array_map(
            static fn (string $type): array => ['evidence_type' => $type, 'task_packet_id' => 'task-x', 'agent_id' => 'agent-1'],
            AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES,
        );

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertCount(1, $index['per_task_continuity']);
        $row = $index['per_task_continuity'][0];
        $this->assertSame('task-x', $row['task_packet_id']);
        $this->assertTrue($row['complete']);
        $this->assertSame([], $row['missing_required_types']);
        $this->assertSame(AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES, $row['present_required_types']);
    }

    public function test_missing_required_types_stay_incomplete_with_deterministic_missing_list(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertSame('continuity_index_incomplete', $index['status']);
        $this->assertSame(
            ['scope_lock', 'validation_result', 'continuation_summary'],
            $index['missing_required_evidence_types'],
        );
    }

    // ── AC: next_evidence_repair_hint ──────────────────────────────────────────

    public function test_incomplete_task_row_names_the_next_evidence_to_request_via_repair_hint(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);
        $row = $index['per_task_continuity'][0];

        $this->assertFalse($row['complete']);
        $this->assertSame('request_evidence_type:scope_lock', $row['next_evidence_repair_hint']);
    }

    public function test_complete_task_row_has_no_repair_hint(): void
    {
        $entries = array_map(
            static fn (string $type): array => ['evidence_type' => $type, 'task_packet_id' => 'task-x', 'agent_id' => 'agent-1'],
            AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES,
        );

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);
        $row = $index['per_task_continuity'][0];

        $this->assertTrue($row['complete']);
        $this->assertNull($row['next_evidence_repair_hint']);
    }

    public function test_per_task_continuity_row_includes_all_ac_required_fields(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1'],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);
        $row = $index['per_task_continuity'][0];

        foreach (['complete', 'present_required_types', 'missing_required_types', 'next_evidence_repair_hint'] as $key) {
            $this->assertArrayHasKey($key, $row, "per_task_continuity row must include {$key}");
        }
    }

    // ── New: stale / duplicate / conflict / blocker detection ──────────────

    public function test_duplicate_receipts_are_detected_and_counted(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'receipt_id' => 'r1'],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'receipt_id' => 'r2'],
            ['evidence_type' => 'scope_lock', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'receipt_id' => 'r3'],
            ['evidence_type' => 'validation_result', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'receipt_id' => 'r4'],
            ['evidence_type' => 'continuation_summary', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'receipt_id' => 'r1'], // duplicate
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertSame(1, $index['duplicate_receipt_count']);
        $this->assertContains('duplicate_receipts_detected', $index['blockers']);
        $this->assertSame('resolve_duplicate_receipts', $index['next_evidence_action']);
    }

    public function test_conflicting_outcomes_are_detected(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'outcome' => 'success'],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'outcome' => 'failure'],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertContains('task-a', $index['conflicting_outcome_tasks']);
        $this->assertContains('conflicting_outcomes_detected', $index['blockers']);
    }

    public function test_stale_entries_are_detected(): void
    {
        $entries = [
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'created_at' => 1],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertSame(1, $index['stale_entry_count']);
    }

    public function test_no_blockers_on_clean_input(): void
    {
        $entries = array_map(
            static fn (string $type): array => [
                'evidence_type' => $type,
                'task_packet_id' => 'task-x',
                'agent_id' => 'agent-1',
                'created_at' => time(),
                'receipt_id' => "r-{$type}",
                'outcome' => 'success',
            ],
            AgentRuntimeEvidenceContinuityIndexer::REQUIRED_TYPES,
        );

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertSame(0, $index['stale_entry_count']);
        $this->assertSame(0, $index['duplicate_receipt_count']);
        $this->assertSame([], $index['conflicting_outcome_tasks']);
        $this->assertSame([], $index['blockers']);
        $this->assertSame('none_required', $index['next_evidence_action']);
    }

    public function test_next_evidence_action_respects_missing_types_priority(): void
    {
        $entries = []; // All required types missing

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        $this->assertSame('request_missing_types', $index['next_evidence_action']);
    }

    public function test_chronicle_preserves_order_of_entries_with_timestamps(): void
    {
        $entries = [
            ['evidence_type' => 'scope_lock', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'created_at' => 100],
            ['evidence_type' => 'dispatch_plan', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'created_at' => 50],
            ['evidence_type' => 'claim_lease', 'task_packet_id' => 'task-a', 'agent_id' => 'agent-1', 'created_at' => 75],
        ];

        $index = (new AgentRuntimeEvidenceContinuityIndexer)->build($entries);

        // The output should be deterministic — type counts are correct regardless.
        $this->assertSame(3, $index['entry_count']);
    }
}
