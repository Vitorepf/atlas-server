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
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'scope_in' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['Foo works'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => [],
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
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

    public function test_gate_source_has_no_side_effects(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskGraph/AtlasSelfConstructionTaskGraphDraftQualityGate.php'));
        foreach (['file_put_contents', 'fopen', 'shell_exec', 'proc_open', 'exec(', 'system(', 'DB::', 'Http::', 'Queue::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "gate must NOT contain {$forbidden}");
        }
    }
}
