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
        $this->assertSame('continuity_index_complete', $index['status']);

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
}
