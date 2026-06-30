<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphDraftQualityGate;
use Tests\TestCase;

final class AtlasSelfConstructionTaskGraphDraftQualityGateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function validDraft(array $overrides = []): array
    {
        return $overrides + [
            'objective' => 'Implement Foo',
            'expected_delta' => 'Adds Foo capability; proved by green artisan test',
            'anti_proxy' => 'Changes real runtime behavior — not a rename or whitespace fix',
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['Foo works'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => [],
            'task_graph_id' => 'tg-default',  // graph signal so batch tests pass chain_coherence
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
            'task_shape' => 'feature',
        ];
    }

    public function test_valid_draft_passes_with_no_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft());

        $this->assertSame('atlas.self_construction.task_graph_draft_quality_gate.v1', $verdict['schema_version']);
        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['blockers']);
        $this->assertSame([], $verdict['repair_hints']);
    }

    public function test_missing_objective_blocks_with_repair_hint(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft(['objective' => '']));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('objective_missing', $verdict['blockers']);
        $this->assertNotEmpty($verdict['repair_hints']);
    }

    public function test_bare_directory_in_allowed_files_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'allowed_files' => ['app/Services/Foo/'],
            'scope_in' => ['app/Services/Foo/'],
        ]));

        $this->assertFalse($verdict['passed']);
        $blockerJoined = implode("\n", $verdict['blockers']);
        $this->assertStringContainsString('bare_directories', $blockerJoined);
    }

    public function test_scope_in_must_cover_every_allowed_file(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'allowed_files' => ['app/Services/Foo.php', 'app/Services/Bar.php'],
            'scope_in' => ['app/Services/Foo.php'],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('scope_in_does_not_cover_allowed_files', $verdict['blockers']);
        $this->assertSame(['app/Services/Bar.php'], $verdict['facts']['scope_uncovered_allowed_files']);
    }

    public function test_missing_acceptance_and_evidence_block(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'acceptance_criteria' => [],
            'required_evidence' => [],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('acceptance_criteria_missing', $verdict['blockers']);
        $this->assertContains('required_evidence_missing', $verdict['blockers']);
    }

    public function test_unknown_depends_on_blocks_when_queue_facts_provided(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate(
            $this->validDraft(['depends_on' => ['pkt-1', 'pkt-missing']]),
            ['known_packet_ids' => ['pkt-1', 'pkt-2']],
        );

        $this->assertFalse($verdict['passed']);
        $this->assertContains('depends_on_unknown:pkt-missing', $verdict['blockers']);
        $this->assertSame(['pkt-missing'], $verdict['facts']['unknown_depends_on']);
    }

    public function test_unknown_depends_on_silently_passes_when_no_queue_facts_supplied(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate(
            $this->validDraft(['depends_on' => ['pkt-anything']]),
            [],
        );

        $this->assertTrue($verdict['passed']);
    }

    public function test_autonomy_regression_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'final_runtime_owner' => 'claude_code',
            'steady_state_runtime_owner' => 'codex_cli',
            'requires_operator' => true,
            'requires_human' => true,
            'requires_external_provider' => true,
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('final_runtime_owner_not_atlas_native:claude_code', $verdict['blockers']);
        $this->assertContains('steady_state_runtime_owner_not_atlas_server:codex_cli', $verdict['blockers']);
        $this->assertContains('requires_operator_must_be_false', $verdict['blockers']);
        $this->assertContains('requires_human_must_be_false', $verdict['blockers']);
        $this->assertContains('requires_external_provider_must_be_false', $verdict['blockers']);
    }

    public function test_expected_delta_missing_blocks_draft(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft(['expected_delta' => '']));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('expected_delta_missing', $verdict['blockers']);
        $this->assertNotEmpty($verdict['repair_hints']);
    }

    public function test_anti_proxy_missing_blocks_draft(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft(['anti_proxy' => '']));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('anti_proxy_missing', $verdict['blockers']);
    }

    public function test_bug_only_batch_fails_with_lane_overconcentration(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_shape' => 'bug-fix', 'objective' => 'Fix A']),
            $this->validDraft(['task_shape' => 'bug-fix', 'objective' => 'Fix B']),
            $this->validDraft(['task_shape' => 'bug-fix', 'objective' => 'Fix C']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertFalse($result['passed']);
        $this->assertNotEmpty($result['batch_blockers']);
        $batchBlockerStr = implode(',', $result['batch_blockers']);
        $this->assertStringContainsString('lane_overconcentration:bug-fix', $batchBlockerStr);
    }

    public function test_balanced_batch_with_expected_delta_and_anti_proxy_passes(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_shape' => 'feature', 'objective' => 'Add X']),
            $this->validDraft(['task_shape' => 'bug-fix', 'objective' => 'Fix Y']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['batch_blockers']);
        $this->assertCount(2, $result['per_draft_results']);
        foreach ($result['per_draft_results'] as $dr) {
            $this->assertTrue($dr['passed']);
        }
    }

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskGraph/AtlasSelfConstructionTaskGraphDraftQualityGate.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'DB::', 'Http::', 'Queue::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "gate must NOT contain {$forbidden}");
        }
    }

    // ── chain_coherence ───────────────────────────────────────────────────────

    public function test_multi_draft_batch_with_no_graph_signal_is_rejected(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_graph_id' => '', 'depends_on' => [], 'objective' => 'Add A']),
            $this->validDraft(['task_graph_id' => '', 'depends_on' => [], 'objective' => 'Add B']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertFalse($result['passed']);
        $this->assertContains('chain_coherence_missing', $result['batch_blockers']);
        $this->assertNotEmpty($result['batch_repair_hints']);
    }

    public function test_batch_with_task_graph_id_on_one_draft_passes_chain_coherence(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_shape' => 'feature', 'task_graph_id' => 'tg-1', 'objective' => 'Add X']),
            $this->validDraft(['task_shape' => 'bug-fix', 'task_graph_id' => '',     'objective' => 'Fix Y']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertNotContains('chain_coherence_missing', $result['batch_blockers']);
        $this->assertTrue($result['passed']);
    }

    public function test_batch_with_non_empty_depends_on_passes_chain_coherence(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_shape' => 'feature', 'task_graph_id' => '', 'depends_on' => ['pkt-upstream'], 'objective' => 'Add X']),
            $this->validDraft(['task_shape' => 'bug-fix',  'task_graph_id' => '', 'depends_on' => [],              'objective' => 'Fix Y']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertNotContains('chain_coherence_missing', $result['batch_blockers']);
    }

    public function test_batch_with_organ_id_passes_chain_coherence(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_graph_id' => '', 'organ_id' => 'cortex', 'objective' => 'Add X']),
            $this->validDraft(['task_graph_id' => '', 'organ_id' => '',       'objective' => 'Fix Y']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertNotContains('chain_coherence_missing', $result['batch_blockers']);
    }

    public function test_single_draft_batch_skips_chain_coherence_check(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_graph_id' => '', 'depends_on' => [], 'objective' => 'Solo task']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertSame([], $result['batch_blockers']);
    }

    // ── new checks: runnable proof, impl+test split, duplicate AC, output fields ──

    public function test_missing_runnable_proof_blocks(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'required_evidence'  => ['implementation_notes'],
            'acceptance_criteria' => ['Gate enforces business rule'],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('runnable_proof_missing', $verdict['blockers']);
    }

    public function test_artisan_test_in_acceptance_criteria_satisfies_runnable_proof(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'required_evidence'   => ['implementation_notes'],
            'acceptance_criteria' => ['Run: artisan test passes green'],
        ]));

        $this->assertNotContains('runnable_proof_missing', $verdict['blockers']);
    }

    public function test_allowed_files_without_test_file_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'allowed_files' => ['app/Services/Foo.php'],
            'scope_in'      => ['app/Services/Foo.php'],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('missing_test_file_in_allowed_files', $verdict['blockers']);
        $this->assertNotContains('missing_implementation_file_in_allowed_files', $verdict['blockers']);
    }

    public function test_allowed_files_without_implementation_file_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'allowed_files' => ['tests/Unit/FooTest.php'],
            'scope_in'      => ['tests/Unit/FooTest.php'],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('missing_implementation_file_in_allowed_files', $verdict['blockers']);
        $this->assertNotContains('missing_test_file_in_allowed_files', $verdict['blockers']);
    }

    public function test_duplicate_acceptance_criteria_wording_is_blocked(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft([
            'acceptance_criteria' => ['Gate passes when valid', 'Gate passes when valid'],
        ]));

        $this->assertFalse($verdict['passed']);
        $this->assertContains('duplicate_acceptance_criteria_wording', $verdict['blockers']);
    }

    public function test_accepted_draft_includes_capability_delta_and_proof_kind(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft());

        $this->assertTrue($verdict['passed']);
        $this->assertArrayHasKey('capability_delta', $verdict);
        $this->assertArrayHasKey('proof_kind', $verdict);
        $this->assertSame('Adds Foo capability; proved by green artisan test', $verdict['capability_delta']);
        $this->assertSame('tests_or_gates_result', $verdict['proof_kind']);
    }

    public function test_rejected_draft_has_null_capability_delta_and_proof_kind(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphDraftQualityGate)->evaluate($this->validDraft(['objective' => '']));

        $this->assertFalse($verdict['passed']);
        $this->assertNull($verdict['capability_delta']);
        $this->assertNull($verdict['proof_kind']);
    }

    public function test_chain_coherence_missing_and_lane_overconcentration_can_both_fire(): void
    {
        $gate = new AtlasSelfConstructionTaskGraphDraftQualityGate;
        $drafts = [
            $this->validDraft(['task_shape' => 'bug-fix', 'task_graph_id' => '', 'depends_on' => [], 'objective' => 'Fix A']),
            $this->validDraft(['task_shape' => 'bug-fix', 'task_graph_id' => '', 'depends_on' => [], 'objective' => 'Fix B']),
            $this->validDraft(['task_shape' => 'bug-fix', 'task_graph_id' => '', 'depends_on' => [], 'objective' => 'Fix C']),
        ];

        $result = $gate->evaluateBatch($drafts);

        $this->assertFalse($result['passed']);
        $this->assertContains('chain_coherence_missing', $result['batch_blockers']);
        $batchBlockerStr = implode(',', $result['batch_blockers']);
        $this->assertStringContainsString('lane_overconcentration:bug-fix', $batchBlockerStr);
    }
}
