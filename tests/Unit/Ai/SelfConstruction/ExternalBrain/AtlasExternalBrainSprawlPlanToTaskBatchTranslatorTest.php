<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSprawlPlanToTaskBatchTranslator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSprawlPlanToTaskBatchTranslatorTest extends TestCase
{
    private function translator(): AtlasExternalBrainSprawlPlanToTaskBatchTranslator
    {
        return new AtlasExternalBrainSprawlPlanToTaskBatchTranslator();
    }

    private function validAction(array $overrides = []): array
    {
        return array_merge([
            'type'                        => 'simplify',
            'organ'                       => 'AtlasFooOrgan',
            'behavior_preservation_tests' => ['foo returns expected score', 'bar is idempotent'],
            'allowed_files'               => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooOrgan.php',
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooOrganTest.php',
            ],
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->translator()->translate(['actions' => []]);
        $this->assertSame(AtlasExternalBrainSprawlPlanToTaskBatchTranslator::SCHEMA, $result['schema']);
    }

    // ── empty plan ────────────────────────────────────────────────────────────

    public function test_empty_actions_yields_empty_specs_and_refused(): void
    {
        $result = $this->translator()->translate(['actions' => []]);

        $this->assertSame([], $result['task_specs']);
        $this->assertSame([], $result['refused']);
    }

    // ── valid actions produce task_specs ──────────────────────────────────────

    public function test_simplify_action_produces_task_spec(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $this->assertCount(1, $result['task_specs']);
        $spec = $result['task_specs'][0];
        $this->assertSame('simplify', $spec['action_type']);
        $this->assertSame('AtlasFooOrgan', $spec['organ']);
        $this->assertNotEmpty($spec['implementation_file']);
        $this->assertNotEmpty($spec['test_file']);
        $this->assertNotEmpty($spec['acceptance_criteria']);
        $this->assertNotEmpty($spec['required_evidence']);
    }

    public function test_merge_action_produces_task_spec(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction([
                'type'              => 'merge',
                'organ'             => 'AtlasOldOrgan',
                'replacement_owner' => 'AtlasNewOrgan',
            ]),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertSame('merge', $result['task_specs'][0]['action_type']);
        $this->assertSame([], $result['refused']);
    }

    public function test_retire_action_produces_task_spec(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire']),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertSame('retire', $result['task_specs'][0]['action_type']);
    }

    // ── implementation / test file split ─────────────────────────────────────

    public function test_impl_and_test_files_correctly_split(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $spec = $result['task_specs'][0];
        $this->assertStringEndsWith('.php', $spec['implementation_file']);
        $this->assertStringNotContainsString('Test.php', $spec['implementation_file']);
        $this->assertStringEndsWith('Test.php', $spec['test_file']);
    }

    // ── AC2: task_spec carries allowed_files ──────────────────────────────────

    public function test_task_spec_has_allowed_files_field(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $this->assertArrayHasKey('allowed_files', $result['task_specs'][0]);
        $this->assertNotEmpty($result['task_specs'][0]['allowed_files']);
    }

    public function test_allowed_files_contains_impl_and_test_file(): void
    {
        $files = [
            'app/Services/Ai/SelfConstruction/Foo/AtlasFooOrgan.php',
            'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooOrganTest.php',
        ];
        $result = $this->translator()->translate(['actions' => [$this->validAction(['allowed_files' => $files])]]);

        $specFiles = $result['task_specs'][0]['allowed_files'];
        $this->assertContains('app/Services/Ai/SelfConstruction/Foo/AtlasFooOrgan.php', $specFiles);
        $this->assertContains('tests/Unit/Ai/SelfConstruction/Foo/AtlasFooOrganTest.php', $specFiles);
    }

    // ── AC1: behavior_preservation_gates ─────────────────────────────────────

    public function test_task_spec_has_behavior_preservation_gates_field(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $this->assertArrayHasKey('behavior_preservation_gates', $result['task_specs'][0]);
    }

    public function test_behavior_preservation_gates_are_non_empty(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['foo returns score']]),
        ]]);

        $gates = $result['task_specs'][0]['behavior_preservation_gates'];
        $this->assertNotEmpty($gates);
    }

    public function test_behavior_preservation_gates_have_description_and_command(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['foo returns score']]),
        ]]);

        $gate = $result['task_specs'][0]['behavior_preservation_gates'][0];
        $this->assertArrayHasKey('description', $gate);
        $this->assertArrayHasKey('command', $gate);
        $this->assertSame('foo returns score', $gate['description']);
    }

    public function test_behavior_preservation_gates_command_is_runnable(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['bar is idempotent']]),
        ]]);

        $command = $result['task_specs'][0]['behavior_preservation_gates'][0]['command'];
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', $command);
    }

    // ── acceptance_criteria ───────────────────────────────────────────────────

    public function test_acceptance_criteria_contains_artisan_test_command(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $criteria = implode(' ', $result['task_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', $criteria);
    }

    public function test_acceptance_criteria_includes_behavior_preservation_tests(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['foo returns score']]),
        ]]);

        $criteria = implode(' ', $result['task_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('foo returns score', $criteria);
    }

    public function test_merge_acceptance_references_replacement_owner(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction([
                'type'              => 'merge',
                'organ'             => 'OldOrgan',
                'replacement_owner' => 'NewOwner',
            ]),
        ]]);

        $criteria = implode(' ', $result['task_specs'][0]['acceptance_criteria']);
        $this->assertStringContainsString('NewOwner', $criteria);
    }

    // ── required_evidence ─────────────────────────────────────────────────────

    public function test_required_evidence_non_empty(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);
        $this->assertNotEmpty($result['task_specs'][0]['required_evidence']);
    }

    public function test_retire_evidence_mentions_consumers(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire', 'organ' => 'DeprecatedOrgan']),
        ]]);

        $evidence = implode(' ', $result['task_specs'][0]['required_evidence']);
        $this->assertStringContainsString('DeprecatedOrgan', $evidence);
    }

    // ── refusals: existing rules ──────────────────────────────────────────────

    public function test_merge_without_replacement_owner_is_refused(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'merge', 'replacement_owner' => '']),
        ]]);

        $this->assertCount(0, $result['task_specs']);
        $this->assertCount(1, $result['refused']);
        $this->assertSame('merge_requires_replacement_owner', $result['refused'][0]['reason']);
    }

    public function test_action_without_behavior_preservation_tests_is_refused(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => []]),
        ]]);

        $this->assertCount(0, $result['task_specs']);
        $this->assertSame('behavior_preservation_tests_required', $result['refused'][0]['reason']);
    }

    public function test_action_without_allowed_files_is_refused(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['allowed_files' => []]),
        ]]);

        $this->assertCount(0, $result['task_specs']);
        $this->assertSame('allowed_files_required', $result['refused'][0]['reason']);
    }

    // ── AC1: refusals for test-only and implementation-only malformed actions ──

    public function test_test_only_allowed_files_is_refused(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooOrganTest.php',
            ]]),
        ]]);

        $this->assertCount(0, $result['task_specs']);
        $this->assertSame('test_only_allowed_files_refused', $result['refused'][0]['reason']);
    }

    public function test_implementation_only_allowed_files_is_refused(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooOrgan.php',
            ]]),
        ]]);

        $this->assertCount(0, $result['task_specs']);
        $this->assertSame('implementation_only_allowed_files_refused', $result['refused'][0]['reason']);
    }

    // ── AC1: dependency ordering ──────────────────────────────────────────────

    public function test_merge_sorts_before_simplify_before_retire(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire',   'organ' => 'OrgR']),
            $this->validAction(['type' => 'simplify', 'organ' => 'OrgS']),
            $this->validAction(['type' => 'merge',    'organ' => 'OrgM', 'replacement_owner' => 'OrgX']),
        ]]);

        $types = array_column($result['task_specs'], 'action_type');
        $this->assertSame(['merge', 'simplify', 'retire'], $types);
    }

    public function test_retire_depends_on_merge_for_same_organ(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'merge',  'organ' => 'OrgA', 'replacement_owner' => 'OrgB']),
            $this->validAction(['type' => 'retire', 'organ' => 'OrgA']),
        ]]);

        $retireSpec = array_values(array_filter(
            $result['task_specs'],
            fn(array $s): bool => $s['action_type'] === 'retire'
        ))[0];

        $this->assertNotEmpty($retireSpec['depends_on']);
        $this->assertStringContainsString('merge', $retireSpec['depends_on'][0]);
    }

    public function test_retire_without_matching_merge_has_empty_deps(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire', 'organ' => 'StandaloneOrgan']),
        ]]);

        $this->assertSame([], $result['task_specs'][0]['depends_on']);
    }

    // ── mixed valid and refused ───────────────────────────────────────────────

    public function test_valid_and_refused_actions_coexist(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'simplify', 'organ' => 'GoodOrgan']),
            $this->validAction(['type' => 'merge', 'replacement_owner' => '']),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertCount(1, $result['refused']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $plan = ['actions' => [$this->validAction(), $this->validAction(['type' => 'retire'])]];
        $this->assertSame(
            $this->translator()->translate($plan),
            $this->translator()->translate($plan),
        );
    }

    // ── AC2: sprawling plans split into minimal ordered task batches ─────────

    public function test_sprawling_plan_with_many_actions_splits_into_ordered_task_batches(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire',   'organ' => 'OrgR1']),
            $this->validAction(['type' => 'simplify', 'organ' => 'OrgS1']),
            $this->validAction(['type' => 'merge',    'organ' => 'OrgM1', 'replacement_owner' => 'OrgX1']),
            $this->validAction(['type' => 'retire',   'organ' => 'OrgR2']),
            $this->validAction(['type' => 'simplify', 'organ' => 'OrgS2']),
        ]]);

        $this->assertCount(5, $result['task_specs']);
        $types = array_column($result['task_specs'], 'action_type');
        $this->assertSame(['merge', 'simplify', 'simplify', 'retire', 'retire'], $types);
    }

    // ── AC3: prefers deletion/consolidation over simplify among declared alternatives ──

    public function test_retire_alternative_is_preferred_over_simplify_in_same_decision_group(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'simplify', 'organ' => 'AtlasSprawlOrgan', 'decision_group' => 'sprawl-fix-1']),
            $this->validAction(['type' => 'retire',   'organ' => 'AtlasSprawlOrgan', 'decision_group' => 'sprawl-fix-1']),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertSame('retire', $result['task_specs'][0]['action_type']);
        $this->assertCount(1, $result['refused']);
        $this->assertSame('superseded_by_stronger_alternative_in_decision_group', $result['refused'][0]['reason']);
    }

    public function test_merge_alternative_is_preferred_over_simplify_in_same_decision_group(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'simplify', 'organ' => 'AtlasSprawlOrgan', 'decision_group' => 'sprawl-fix-2']),
            $this->validAction(['type' => 'merge', 'organ' => 'AtlasSprawlOrgan', 'replacement_owner' => 'AtlasNewOwner', 'decision_group' => 'sprawl-fix-2']),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertSame('merge', $result['task_specs'][0]['action_type']);
    }

    public function test_shared_organ_without_decision_group_never_triggers_preference_filtering(): void
    {
        // A legitimate merge-then-retire chain for the SAME organ must survive intact —
        // bare shared organ name is not an alternative-group signal.
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'merge', 'organ' => 'OrgA', 'replacement_owner' => 'OrgB']),
            $this->validAction(['type' => 'retire', 'organ' => 'OrgA']),
        ]]);

        $this->assertCount(2, $result['task_specs']);
        $this->assertSame([], $result['refused']);
    }

    public function test_decision_group_with_only_one_action_is_never_superseded(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'simplify', 'organ' => 'OrgOnly', 'decision_group' => 'solo-group']),
        ]]);

        $this->assertCount(1, $result['task_specs']);
        $this->assertSame([], $result['refused']);
    }

    // ── AC4: every task_spec includes dependency_note and proof_floor ─────────

    public function test_task_spec_includes_dependency_note_and_proof_floor(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $spec = $result['task_specs'][0];
        $this->assertArrayHasKey('dependency_note', $spec);
        $this->assertArrayHasKey('proof_floor', $spec);
        $this->assertNotEmpty($spec['dependency_note']);
        $this->assertNotEmpty($spec['proof_floor']);
    }

    public function test_dependency_note_reflects_no_dependencies_when_standalone(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire', 'organ' => 'StandaloneOrgan']),
        ]]);

        $this->assertStringContainsString('No dependencies', $result['task_specs'][0]['dependency_note']);
    }

    public function test_dependency_note_mentions_the_dependency_when_present(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'merge', 'organ' => 'OrgA', 'replacement_owner' => 'OrgB']),
            $this->validAction(['type' => 'retire', 'organ' => 'OrgA']),
        ]]);

        $retireSpec = array_values(array_filter(
            $result['task_specs'],
            fn(array $s): bool => $s['action_type'] === 'retire'
        ))[0];

        $this->assertStringContainsString('merge:OrgA', $retireSpec['dependency_note']);
    }

    public function test_proof_floor_mentions_behavior_preservation_gate_count(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['t1', 't2', 't3']]),
        ]]);

        $this->assertStringContainsString('3/3', $result['task_specs'][0]['proof_floor']);
    }

    // ── AC4: top-level output fields ──────────────────────────────────────────

    public function test_output_includes_dependency_order_and_behavior_preservation_gates(): void
    {
        $result = $this->translator()->translate(['actions' => [$this->validAction()]]);

        $this->assertArrayHasKey('dependency_order', $result);
        $this->assertArrayHasKey('behavior_preservation_gates', $result);
    }

    public function test_dependency_order_reflects_sorted_action_order(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['type' => 'retire',   'organ' => 'OrgR']),
            $this->validAction(['type' => 'simplify', 'organ' => 'OrgS']),
            $this->validAction(['type' => 'merge',    'organ' => 'OrgM', 'replacement_owner' => 'OrgX']),
        ]]);

        $this->assertSame([
            'merge:OrgM',
            'simplify:OrgS',
            'retire:OrgR',
        ], $result['dependency_order']);
    }

    public function test_behavior_preservation_gates_collects_all_unique_gates(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['gate_a', 'gate_b']]),
            $this->validAction(['behavior_preservation_tests' => ['gate_b', 'gate_c']]),
        ]]);

        $this->assertContains('gate_a', $result['behavior_preservation_gates']);
        $this->assertContains('gate_b', $result['behavior_preservation_gates']);
        $this->assertContains('gate_c', $result['behavior_preservation_gates']);
    }

    public function test_behavior_preservation_gates_empty_when_no_task_specs(): void
    {
        $result = $this->translator()->translate(['actions' => []]);

        $this->assertSame([], $result['behavior_preservation_gates']);
    }

    public function test_behavior_preservation_gates_sorted_alphabetically(): void
    {
        $result = $this->translator()->translate(['actions' => [
            $this->validAction(['behavior_preservation_tests' => ['z_gate', 'a_gate']]),
        ]]);

        $this->assertSame(['a_gate', 'z_gate'], $result['behavior_preservation_gates']);
    }
}
