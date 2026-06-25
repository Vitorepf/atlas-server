<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphDraftEnqueuePlan;
use Tests\TestCase;

class AtlasSelfConstructionTaskGraphDraftEnqueuePlanTest extends TestCase
{
    private function validDraft(string $id, int $priority = 5): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'fill organ '.$id,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'acceptance_criteria' => ['noop'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => [],
            'wave' => 'self_construction_coverage',
            'tags' => ['self_construction', $id],
            'priority' => $priority,
            'rationale' => 'coverage gap',
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
        ];
    }

    public function test_valid_draft_becomes_prepare_and_enqueue_input(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$this->validDraft('a-1')]);

        self::assertSame(1, $verdict['counts']['enqueue_inputs']);
        $input = $verdict['enqueue_inputs'][0];
        self::assertArrayHasKey('task_packet', $input);
        self::assertArrayHasKey('queue', $input);
        self::assertSame('a-1', $input['task_packet']['task_packet_id']);
        self::assertSame(5, $input['queue']['priority']);
        self::assertContains('a-1', $input['queue']['tags']);
        self::assertSame('self_construction_coverage', $input['queue']['metadata']['wave']);
        // Preservation of depends_on / allowed_files / required_evidence
        self::assertSame([], $input['task_packet']['depends_on']);
        self::assertContains('tests_or_gates_result', $input['task_packet']['required_evidence']);
        self::assertCount(2, $input['task_packet']['allowed_files']);
    }

    public function test_duplicate_ids_are_withheld(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan(
            [$this->validDraft('a-1'), $this->validDraft('a-2')],
            ['existing_packet_ids' => ['a-2']],
        );

        self::assertSame(1, $verdict['counts']['enqueue_inputs']);
        self::assertSame(1, $verdict['counts']['duplicates']);
        self::assertSame('a-2', $verdict['duplicates'][0]['task_packet_id']);
        self::assertSame('already_in_queue', $verdict['duplicates'][0]['reason']);
    }

    public function test_gate_blocked_draft_is_withheld_with_blockers(): void
    {
        $bad = $this->validDraft('bad-1');
        $bad['acceptance_criteria'] = []; // breaks the gate

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$bad]);

        self::assertSame(0, $verdict['counts']['enqueue_inputs']);
        self::assertSame(1, $verdict['counts']['withheld']);
        self::assertSame('bad-1', $verdict['withheld'][0]['task_packet_id']);
        self::assertSame('quality_gate_blocked', $verdict['withheld'][0]['reason']);
        self::assertContains('acceptance_criteria_missing', $verdict['withheld'][0]['blockers']);
    }

    public function test_missing_task_packet_id_is_withheld_with_explicit_reason(): void
    {
        $bad = $this->validDraft('whatever');
        unset($bad['task_packet_id']);

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$bad]);

        self::assertSame(1, $verdict['counts']['withheld']);
        self::assertSame('task_packet_id_missing', $verdict['withheld'][0]['reason']);
    }

    public function test_plan_hash_is_stable_for_identical_input(): void
    {
        $planner = new AtlasSelfConstructionTaskGraphDraftEnqueuePlan();
        $a = $planner->plan([$this->validDraft('a-1'), $this->validDraft('b-1')]);
        $b = $planner->plan([$this->validDraft('b-1'), $this->validDraft('a-1')]); // different order, same content

        self::assertSame($a['plan_hash'], $b['plan_hash'], 'plan_hash must be stable across input ordering');
        self::assertStringStartsWith('plan_', $a['plan_hash']);
    }

    public function test_planner_performs_no_queue_writes_via_source_inspection(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskGraph/AtlasSelfConstructionTaskGraphDraftEnqueuePlan.php'));
        foreach (['QueueRepository', 'prepareAndEnqueue(', '->enqueue(', 'DB::', 'file_put_contents', 'Schema::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "plan builder must not call {$forbidden}");
        }
    }
}
