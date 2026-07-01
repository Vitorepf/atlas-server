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
            'expected_delta' => 'fills organ '.$id.' with real implementation',
            'anti_proxy' => 'test suite verifies behavior change, not metric or formatting shift',
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

    public function test_plan_hash_changes_when_enqueue_content_changes(): void
    {
        $planner = new AtlasSelfConstructionTaskGraphDraftEnqueuePlan();
        $a = $planner->plan([$this->validDraft('a-1')]);
        $b = $planner->plan([$this->validDraft('a-1', priority: 9)]); // different priority → different enqueue_now content

        self::assertNotSame($a['plan_hash'], $b['plan_hash']);
    }

    public function test_plan_hash_changes_when_defer_or_reject_bucket_changes(): void
    {
        $planner = new AtlasSelfConstructionTaskGraphDraftEnqueuePlan();
        $clean = $planner->plan([$this->validDraft('a-1')]);

        $deferred = $this->validDraft('a-1');
        $deferred['depends_on'] = ['ghost-999'];
        $withDefer = $planner->plan([$deferred]);

        self::assertNotSame($clean['plan_hash'], $withDefer['plan_hash']);
    }

    public function test_planner_performs_no_queue_writes_via_source_inspection(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskGraph/AtlasSelfConstructionTaskGraphDraftEnqueuePlan.php'));
        foreach (['QueueRepository', 'prepareAndEnqueue(', '->enqueue(', 'DB::', 'file_put_contents', 'Schema::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "plan builder must not call {$forbidden}");
        }
    }

    // ── enqueue_now / defer / reject ──────────────────────────────────────────

    public function test_plan_output_has_enqueue_now_defer_and_reject_keys(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$this->validDraft('x-1')]);

        self::assertArrayHasKey('enqueue_now', $verdict);
        self::assertArrayHasKey('defer', $verdict);
        self::assertArrayHasKey('reject', $verdict);
        self::assertSame(1, $verdict['counts']['enqueue_now']);
        self::assertSame(0, $verdict['counts']['defer']);
        self::assertSame(0, $verdict['counts']['reject']);
    }

    public function test_draft_with_unresolved_depends_on_is_deferred_with_reason(): void
    {
        $draft = $this->validDraft('dep-1');
        $draft['depends_on'] = ['ghost-packet-999']; // not in batch or existing

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$draft]);

        self::assertSame(0, $verdict['counts']['enqueue_now']);
        self::assertSame(1, $verdict['counts']['defer']);
        self::assertSame(0, $verdict['counts']['reject']);
        self::assertSame('dep-1', $verdict['defer'][0]['task_packet_id']);
        self::assertSame('blocked_prerequisites', $verdict['defer'][0]['reason']);
        self::assertContains('ghost-packet-999', $verdict['defer'][0]['blockers']);
    }

    public function test_depends_on_within_same_batch_is_resolved_not_deferred(): void
    {
        $a = $this->validDraft('wave-a');
        $b = $this->validDraft('wave-b');
        $b['depends_on'] = ['wave-a']; // wave-a is in the same batch

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$a, $b]);

        self::assertSame(2, $verdict['counts']['enqueue_now']);
        self::assertSame(0, $verdict['counts']['defer']);
    }

    public function test_duplicate_allowed_files_across_batch_defers_second_draft(): void
    {
        $a = $this->validDraft('file-a');
        $b = $this->validDraft('file-b');
        // Override file-b to share a file with file-a
        $b['allowed_files'] = $a['allowed_files'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$a, $b]);

        self::assertSame(1, $verdict['counts']['enqueue_now']); // a passes
        self::assertSame(1, $verdict['counts']['defer']);       // b deferred (collision)
        self::assertSame('file-b', $verdict['defer'][0]['task_packet_id']);
        self::assertSame('duplicate_allowed_files', $verdict['defer'][0]['reason']);
    }

    public function test_queue_at_capacity_defers_eligible_drafts(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan(
            [$this->validDraft('cap-1'), $this->validDraft('cap-2')],
            ['queue_at_capacity' => true],
        );

        self::assertSame(0, $verdict['counts']['enqueue_now']);
        self::assertSame(2, $verdict['counts']['defer']);
        self::assertSame('queue_at_capacity', $verdict['defer'][0]['reason']);
    }

    public function test_gate_failure_goes_to_reject_not_defer(): void
    {
        $bad = $this->validDraft('rej-1');
        $bad['acceptance_criteria'] = [];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$bad]);

        self::assertSame(0, $verdict['counts']['enqueue_now']);
        self::assertSame(0, $verdict['counts']['defer']);
        self::assertSame(1, $verdict['counts']['reject']);
        self::assertSame('quality_gate_blocked', $verdict['reject'][0]['reason']);
    }

    // ── AC3: dependent blocked when prerequisite is rejected (not claimable), not just absent ──

    public function test_dependent_is_deferred_when_its_prerequisite_fails_the_gate(): void
    {
        $prereq = $this->validDraft('prereq-1');
        $prereq['acceptance_criteria'] = []; // fails the gate → rejected, never resolved

        $dependent = $this->validDraft('dep-2');
        $dependent['depends_on'] = ['prereq-1'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$prereq, $dependent]);

        self::assertSame(0, $verdict['counts']['enqueue_now']);
        self::assertSame(1, $verdict['counts']['reject']);
        self::assertSame('prereq-1', $verdict['reject'][0]['task_packet_id']);
        self::assertSame(1, $verdict['counts']['defer']);
        self::assertSame('dep-2', $verdict['defer'][0]['task_packet_id']);
        self::assertSame('blocked_prerequisites', $verdict['defer'][0]['reason']);
        self::assertContains('prereq-1', $verdict['defer'][0]['blockers']);
    }

    public function test_dependent_is_deferred_when_prerequisite_is_capacity_deferred(): void
    {
        $prereq = $this->validDraft('prereq-cap');
        $dependent = $this->validDraft('dep-cap');
        $dependent['depends_on'] = ['prereq-cap'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan(
            [$prereq, $dependent],
            ['queue_at_capacity' => true],
        );

        self::assertSame(0, $verdict['counts']['enqueue_now']);
        self::assertContains('prereq-cap', array_column($verdict['defer'], 'task_packet_id'));
        $depEntry = array_values(array_filter($verdict['defer'], fn ($d) => $d['task_packet_id'] === 'dep-cap'))[0];
        self::assertSame('blocked_prerequisites', $depEntry['reason']);
        self::assertContains('prereq-cap', $depEntry['blockers']);
    }

    public function test_chain_resolves_across_multiple_passes_regardless_of_array_order(): void
    {
        // dependent listed BEFORE its prerequisite in the input array.
        $prereq = $this->validDraft('order-prereq');
        $dependent = $this->validDraft('order-dep');
        $dependent['depends_on'] = ['order-prereq'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$dependent, $prereq]);

        self::assertSame(2, $verdict['counts']['enqueue_now']);
        self::assertSame(0, $verdict['counts']['defer']);
    }

    // ── AC2: chain grouping + prerequisite/unlock/terminal role labels ────────

    public function test_three_link_chain_gets_prerequisite_unlock_terminal_roles(): void
    {
        $a = $this->validDraft('chain-a');
        $b = $this->validDraft('chain-b');
        $b['depends_on'] = ['chain-a'];
        $c = $this->validDraft('chain-c');
        $c['depends_on'] = ['chain-b'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$a, $b, $c]);

        self::assertSame(3, $verdict['counts']['enqueue_now']);
        self::assertCount(1, $verdict['chains']);
        $roles = array_column($verdict['chains'][0]['members'], 'chain_role', 'task_packet_id');
        self::assertSame('prerequisite', $roles['chain-a']);
        self::assertSame('unlock', $roles['chain-b']);
        self::assertSame('terminal', $roles['chain-c']);
    }

    public function test_enqueue_now_entries_carry_chain_role(): void
    {
        $a = $this->validDraft('role-a');
        $b = $this->validDraft('role-b');
        $b['depends_on'] = ['role-a'];

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$a, $b]);

        $byId = [];
        foreach ($verdict['enqueue_now'] as $entry) {
            $byId[$entry['task_packet']['task_packet_id']] = $entry['chain_role'];
        }
        self::assertSame('prerequisite', $byId['role-a']);
        self::assertSame('terminal', $byId['role-b']);
    }

    public function test_standalone_draft_has_no_chain_role_and_is_not_in_any_chain(): void
    {
        $chained = $this->validDraft('solo-chain-a');
        $partner = $this->validDraft('solo-chain-b');
        $partner['depends_on'] = ['solo-chain-a'];
        $standalone = $this->validDraft('solo-standalone');

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$chained, $partner, $standalone]);

        $byId = [];
        foreach ($verdict['enqueue_now'] as $entry) {
            $byId[$entry['task_packet']['task_packet_id']] = $entry['chain_role'];
        }
        self::assertNull($byId['solo-standalone']);
        self::assertContains('solo-standalone', $verdict['chain_summary']['standalone_task_ids']);
        self::assertNotContains('solo-standalone', $verdict['chain_summary']['chained_task_ids']);
    }

    public function test_chain_summary_reports_chained_and_standalone_enqueued_counts(): void
    {
        $chained = $this->validDraft('sum-chain-a');
        $partner = $this->validDraft('sum-chain-b');
        $partner['depends_on'] = ['sum-chain-a'];
        $standalone1 = $this->validDraft('sum-solo-1');
        $standalone2 = $this->validDraft('sum-solo-2');

        $verdict = (new AtlasSelfConstructionTaskGraphDraftEnqueuePlan)->plan([$chained, $partner, $standalone1, $standalone2]);

        self::assertSame(1, $verdict['chain_summary']['chains']);
        self::assertSame(2, $verdict['chain_summary']['enqueued_chained_count']);
        self::assertSame(2, $verdict['chain_summary']['enqueued_standalone_count']);
    }
}
