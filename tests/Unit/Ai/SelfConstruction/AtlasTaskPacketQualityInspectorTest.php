<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use PHPUnit\Framework\TestCase;

/**
 * FROZEN proof of the task-packet self-sufficiency inspector — the crystallized FACTS that decide whether a
 * COLD client could implement and prove a served packet. Facts, never a score.
 */
final class AtlasTaskPacketQualityInspectorTest extends TestCase
{
    private function packet(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'wire AtlasFooService into the php artisan boot kernel so the test passes',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    public function test_a_complete_packet_is_self_sufficient(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet());

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame([], $r['blocking_deficiencies']);
        $this->assertTrue($r['facts']['has_objective']);
        $this->assertSame(1, $r['facts']['acceptance_criteria_count']);
    }

    public function test_missing_acceptance_criteria_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['acceptance_criteria' => []]));

        $this->assertFalse($r['self_sufficient'], 'no acceptance ⇒ the client cannot know when it is done');
        $this->assertContains('missing_acceptance_criteria', $r['blocking_deficiencies']);
    }

    public function test_missing_required_evidence_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['required_evidence' => []]));

        $this->assertFalse($r['self_sufficient'], 'no required evidence ⇒ the report gate cannot validate the work');
        $this->assertContains('missing_required_evidence', $r['blocking_deficiencies']);
    }

    public function test_bare_directory_in_allowed_files_is_blocking(): void
    {
        // A directory write-scope guarantees a files_changed_outside_allowed_scope failure at completion (MF-12).
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'],
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('bare_directory_in_allowed_files', $r['blocking_deficiencies']);
        $this->assertSame(['app/Services/Ai/SelfConstruction/'], $r['facts']['bare_directories']);
    }

    public function test_missing_objective_and_empty_allowed_files_are_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['objective' => '   ', 'allowed_files' => []]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('missing_objective', $r['blocking_deficiencies']);
        $this->assertContains('empty_allowed_files', $r['blocking_deficiencies']);
    }

    public function test_scope_incoherence_is_advisory_not_blocking(): void
    {
        // allowed_files not covered by scope_in is surfaced, but does NOT disqualify a packet on its own.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['app/Other/Unrelated.php'],
        ]));

        $this->assertTrue($r['self_sufficient'], 'scope incoherence alone is advisory');
        $this->assertContains('scope_incoherent', $r['deficiencies']);
        $this->assertNotContains('scope_incoherent', $r['blocking_deficiencies']);
    }

    public function test_test_authoring_acceptance_without_test_path_in_allowed_files_is_blocking(): void
    {
        // A packet that asks the worker to AUTHOR proof but writes no `tests/...` file is unprovable — the cold
        // worker cannot create or amend the test that would satisfy the acceptance criteria.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['add a PHPUnit test that exercises Foo through the new call path'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertFalse($r['self_sufficient'], 'asks for test authoring with no test path → unprovable');
        $this->assertContains('test_evidence_without_test_in_allowed_files', $r['blocking_deficiencies']);
        $this->assertTrue($r['facts']['requires_test_authoring']);
    }

    public function test_existing_gate_evidence_without_test_path_is_self_sufficient_for_code_only_tasks(): void
    {
        // `tests_or_gates_result` can also mean "run the existing suite after editing code". That is legitimate
        // for code-only shared-main tasks and must not be blocked as if the worker needed to author a test.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['the change is in place and php artisan stays green'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('test_evidence_without_test_in_allowed_files', $r['blocking_deficiencies']);
        $this->assertFalse($r['facts']['requires_test_authoring']);
    }

    public function test_test_evidence_with_a_tests_path_in_allowed_files_is_self_sufficient(): void
    {
        // Including a `tests/...` path in allowed_files dissolves the deficiency: the worker has the scope
        // it needs to write the proof.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooWiringTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php', 'tests/Unit/Ai/SelfConstruction/FooWiringTest.php'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('test_evidence_without_test_in_allowed_files', $r['blocking_deficiencies']);
    }

    public function test_other_evidence_ids_do_not_require_a_test_path(): void
    {
        // The invariant ONLY fires for the `tests_or_gates_result` evidence id (the report gate's test
        // assertion). Other evidence ids (e.g. `grep_proves_wiring`) do not strand a packet on this rule.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'required_evidence' => ['grep_proves_wiring'],
        ]));

        $this->assertTrue($r['self_sufficient'], 'non-test evidence ids ⇒ no test scope required');
        $this->assertNotContains('test_evidence_without_test_in_allowed_files', $r['blocking_deficiencies']);
    }

    public function test_forbidden_self_target_in_allowed_files_is_blocking(): void
    {
        // A packet scoped to a pétreo file (config/atlas.php) is UNCOMMITTABLE — AtlasTaskScopedCommitter refuses
        // it with forbidden_self_target. The inspector rejects it BEFORE serving, so a cold worker never wastes
        // an implementation it can't commit (the loop-cortex-memory-cli-1020 / loop-recovery-backup-composer
        // give_back waste). "servable" now implies "committable".
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Console/Commands/AtlasFoo.php', 'config/atlas.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php'],
            'scope_in' => ['app/Console/Commands/AtlasFoo.php', 'config/atlas.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php'],
        ]));

        $this->assertFalse($r['self_sufficient'], 'a packet scoped to a forbidden pétreo target is uncommittable');
        $this->assertContains('forbidden_self_target_in_allowed_files', $r['blocking_deficiencies']);
        $this->assertContains('config/atlas.php', $r['facts']['forbidden_self_targets']);
    }

    public function test_a_command_packet_touching_appserviceprovider_is_not_a_false_positive(): void
    {
        // No false-positive: AppServiceProvider.php is a COMMITABLE shared hotspot (not on the pétreo list), so a
        // CLI-registration task that edits it stays servable — only genuinely forbidden targets are rejected.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Console/Commands/AtlasFoo.php', 'app/Providers/AppServiceProvider.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php'],
            'scope_in' => ['app/Console/Commands/AtlasFoo.php', 'app/Providers/AppServiceProvider.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooTest.php'],
        ]));

        $this->assertTrue($r['self_sufficient'], 'AppServiceProvider is a commitable hotspot, not a forbidden target');
        $this->assertSame([], $r['facts']['forbidden_self_targets']);
    }

    public function test_scope_repair_that_leaves_only_tests_for_a_removed_required_target_is_blocking(): void
    {
        $target = 'app/Services/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRoles.php';
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Implement AtlasLoopGroundedProjectionRoles memory-grounded role seeds. Scope repair: '.$target.' was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).',
            'allowed_files' => ['tests/Unit/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRolesTest.php'],
            'scope_in' => ['tests/Unit/Ai/AutonomousEvolution/Memory/AtlasLoopGroundedProjectionRolesTest.php'],
            'forbidden_files' => [$target],
            'acceptance_criteria' => ['AtlasLoopGroundedProjectionRoles exposes deterministic role seeds'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertFalse($r['self_sufficient'], 'test-only repair cannot implement the removed required target');
        $this->assertContains('scope_repair_removed_required_target_from_allowed_files', $r['blocking_deficiencies']);
        $this->assertSame([$target], $r['facts']['scope_repair_removed_required_targets']);
        $this->assertSame([$target], $r['facts']['scope_repair_removed_targets_mentioned_in_acceptance']);
    }

    public function test_scope_repair_note_alone_does_not_block_legitimate_test_only_work(): void
    {
        $target = 'config/atlas.php';
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Add Atlas regression coverage for the existing operator-facing behavior. Scope repair: '.$target.' was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).',
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/ExistingBehaviorTest.php'],
            'scope_in' => ['tests/Unit/Ai/SelfConstruction/ExistingBehaviorTest.php'],
            'forbidden_files' => [$target],
            'acceptance_criteria' => ['add a PHPUnit test for the already implemented behavior'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertTrue($r['self_sufficient'], 'the repair note itself is not enough to quarantine a packet');
        $this->assertNotContains('scope_repair_removed_required_target_from_allowed_files', $r['blocking_deficiencies']);
        $this->assertSame([], $r['facts']['scope_repair_removed_required_targets']);
        $this->assertSame([], $r['facts']['scope_repair_removed_targets_mentioned_in_acceptance']);
    }

    public function test_scope_repair_acceptance_that_mentions_removed_target_is_blocking_even_with_code_allowed(): void
    {
        $judge = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        $config = 'config/atlas.php';
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Build AtlasLoopAntiGoodhartUnifiedRefusal and wire AtlasAutonomousEvolutionCertificationService plus AtlasEvolutionFrozenJudge through the single refusal authority. Scope repair: '.$judge.', '.$config.' was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).',
            'allowed_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationService.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopAntiGoodhartUnifiedRefusal.php',
                'tests/Unit/Ai/AutonomousEvolution/AtlasLoopAntiGoodhartUnifiedRefusalTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionCertificationService.php',
                'app/Services/Ai/AutonomousEvolution/AtlasLoopAntiGoodhartUnifiedRefusal.php',
                'tests/Unit/Ai/AutonomousEvolution/AtlasLoopAntiGoodhartUnifiedRefusalTest.php',
            ],
            'forbidden_files' => [$judge, $config],
            'acceptance_criteria' => ['Both AtlasAutonomousEvolutionCertificationService and AtlasEvolutionFrozenJudge consult this service as the sole refusal authority'],
            'required_evidence' => ['tests_or_gates_result'],
        ]));

        $this->assertFalse($r['self_sufficient'], 'acceptance still requires behavior in the removed forbidden target');
        $this->assertContains('scope_repair_removed_required_target_from_allowed_files', $r['blocking_deficiencies']);
        $this->assertSame([$judge], $r['facts']['scope_repair_removed_required_targets']);
        $this->assertSame([$judge], $r['facts']['scope_repair_removed_targets_mentioned_in_acceptance']);
    }

    public function test_normalized_scope_shape_is_read(): void
    {
        // The served projection nests scope under normalized_scope — the inspector must read both shapes.
        $r = (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => 'verify normalized_scope is read by the AtlasTaskPacketQualityInspector under php artisan',
            'normalized_scope' => ['allowed_files' => ['app/A/B.php'], 'scope_in' => ['app/A/B.php']],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['ev'],
        ]);

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame(1, $r['facts']['allowed_files_count']);
    }

    public function test_permanent_human_or_external_provider_dependency_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Make Atlas Self-Construction depend on Claude Code and operator approval for every 24/7 evolution decision.',
            'acceptance_criteria' => ['Runtime requires a human approval handoff before each task can complete.'],
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('permanent_human_or_external_provider_dependency', $r['blocking_deficiencies']);
        $this->assertNotSame([], $r['facts']['permanent_human_or_external_provider_dependencies']);
    }

    public function test_bootstrap_only_external_provider_language_is_allowed(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Document that Claude Code and Codex are bootstrap only; the final Atlas runtime must not depend on external providers or operator approval.',
            'acceptance_criteria' => ['php artisan test passes and Atlas native execution is the owner with no human handoff.'],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('permanent_human_or_external_provider_dependency', $r['blocking_deficiencies']);
        $this->assertSame([], $r['facts']['permanent_human_or_external_provider_dependencies']);
    }

    public function test_simplicity_contract_cannot_require_operator_human_or_provider_in_steady_state(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Define the Atlas-native runtime packet contract.',
            'simplicity_contract' => [
                'operator_dependency_allowed' => true,
                'human_dependency_allowed' => true,
                'external_provider_dependency_allowed' => true,
                'final_runtime_owner' => 'operator',
                'steady_state_runtime_owner' => 'claude_code',
                'steady_state_requires_operator' => true,
                'external_worker_role' => 'required permanent authority',
            ],
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('permanent_human_or_external_provider_dependency', $r['blocking_deficiencies']);
        $this->assertContains('simplicity_contract.operator_dependency_allowed=true', $r['facts']['simplicity_contract_autonomy_violations']);
        $this->assertContains('simplicity_contract.final_runtime_owner=operator', $r['facts']['simplicity_contract_autonomy_violations']);
        $this->assertContains('simplicity_contract.steady_state_runtime_owner=claude_code', $r['facts']['simplicity_contract_autonomy_violations']);
    }

    public function test_atlas_native_simplicity_contract_is_allowed(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Define the Atlas-native runtime packet contract.',
            'simplicity_contract' => [
                'operator_dependency_allowed' => false,
                'human_dependency_allowed' => false,
                'external_provider_dependency_allowed' => false,
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'steady_state_requires_operator' => false,
                'steady_state_requires_human' => false,
                'steady_state_requires_external_provider' => false,
                'external_worker_role' => 'bootstrap_or_replaceable_muscle_only',
            ],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame([], $r['facts']['simplicity_contract_autonomy_violations']);
    }

    public function test_default_worktree_or_sandbox_policy_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Run every task in a worktree by default instead of shared local main.',
            'workspace_policy' => ['isolation' => 'simulated_worktree'],
            'simplicity_contract' => ['default_execution_topology' => 'shared_local_main_with_allowed_files'],
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('default_worktree_or_sandbox_policy', $r['blocking_deficiencies']);
        $this->assertContains('workspace_policy.isolation=simulated_worktree', $r['facts']['default_worktree_or_sandbox_violations']);
    }

    public function test_shared_main_policy_is_self_sufficient(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Use shared local main with exact allowed_files for the Atlas serving stack; no worktree by default.',
            'workspace_policy' => ['isolation' => 'shared_local_main_with_scope_lock'],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame([], $r['facts']['default_worktree_or_sandbox_violations']);
    }

    public function test_legacy_simulated_worktree_metadata_alone_does_not_quarantine_old_packets(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Implement a scoped Atlas serving task on the shared main flow with php artisan kernel.',
            'workspace_policy' => ['isolation' => 'simulated_worktree'],
        ]));

        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('default_worktree_or_sandbox_policy', $r['blocking_deficiencies']);
    }

    // ── Excellence-gate additions (govA-inspector-excellence-gate-w1) ──────────────────────────

    public function test_short_objective_without_concrete_reference_is_flagged_vague(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'improve the code',
        ]));

        // vague_objective is ADVISORY (Option C): surfaced as a deficiency, never blocking.
        $this->assertContains('vague_objective', $r['deficiencies']);
        $this->assertNotContains('vague_objective', $r['blocking_deficiencies']);
    }

    public function test_long_objective_without_concrete_reference_is_still_flagged_vague(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'do many vague things over and over until somebody notices the change',
        ]));

        // vague_objective is ADVISORY (Option C): surfaced as a deficiency, never blocking.
        $this->assertContains('vague_objective', $r['deficiencies']);
        $this->assertNotContains('vague_objective', $r['blocking_deficiencies']);
    }

    public function test_objective_with_concrete_reference_token_is_not_flagged_vague(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'wire AtlasFooService into the bootstrap so php artisan stays green',
        ]));

        $this->assertNotContains('vague_objective', $r['blocking_deficiencies']);
    }

    public function test_no_runnable_acceptance_signal_is_flagged_not_runnable(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'acceptance_criteria' => ['the code looks good', 'an operator agrees'],
        ]));

        // acceptance_not_runnable is ADVISORY (Option C): surfaced as a deficiency, never blocking.
        $this->assertContains('acceptance_not_runnable', $r['deficiencies']);
        $this->assertNotContains('acceptance_not_runnable', $r['blocking_deficiencies']);
    }

    public function test_at_least_one_runnable_acceptance_signal_passes_not_runnable_check(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'acceptance_criteria' => ['the code looks good', 'php artisan test passes'],
        ]));

        $this->assertNotContains('acceptance_not_runnable', $r['blocking_deficiencies']);
    }

    public function test_objective_ending_with_ellipsis_marker_is_flagged_truncated(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'wire AtlasFooService into the bootstrap of php artisan boot ...',
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('content_truncated', $r['blocking_deficiencies']);
    }

    public function test_objective_ending_with_horizontal_ellipsis_char_is_flagged_truncated(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => "wire AtlasFooService into the bootstrap of php artisan boot \u{2026}",
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('content_truncated', $r['blocking_deficiencies']);
    }

    public function test_complete_packet_satisfies_all_three_new_gates(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet());

        $this->assertTrue($r['self_sufficient']);
        $this->assertNotContains('vague_objective', $r['deficiencies']);
        $this->assertNotContains('acceptance_not_runnable', $r['deficiencies']);
        $this->assertNotContains('content_truncated', $r['deficiencies']);
    }

    // S5 anti-fake: ADEQUACY beyond PRESENCE. A packet with a generic runnable hook ("phpunit passes") that
    // never names ANY of its code allowed_files is surfaced as an ADVISORY deficiency — the judge can SEE the
    // criteria don't bind to the change. Kept OUT of BLOCKING_DEFICIENCIES on purpose (a generic hook is still
    // legitimate for many internal/minimal packets; promotion is a measured follow-up — see Checkpoint A).
    public function test_acceptance_that_never_names_any_code_allowed_file_is_advisory(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasFooBar.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasFooBar.php'],
            'acceptance_criteria' => ['phpunit passes'], // runnable but does NOT mention AtlasFooBar
        ]));

        $this->assertContains('acceptance_coverage_mismatch', $r['deficiencies'], 'adequacy gate must surface the mismatch');
        $this->assertNotContains('acceptance_coverage_mismatch', $r['blocking_deficiencies'], 'must stay ADVISORY (not blocking)');
        $this->assertTrue($r['self_sufficient'], 'advisory must not flip self_sufficient — a generic hook is still proof for many tasks');
    }

    public function test_acceptance_that_names_the_allowed_file_basename_does_not_trigger_coverage_mismatch(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasFooBar.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasFooBar.php'],
            'acceptance_criteria' => ['phpunit tests/Unit/AtlasFooBarTest.php green'], // names AtlasFooBar
        ]));

        $this->assertNotContains('acceptance_coverage_mismatch', $r['deficiencies']);
    }

    public function test_test_only_allowed_files_are_skipped_by_coverage_check(): void
    {
        // A pure test-authoring packet self-proves via the test file — the coverage check must NOT fire even
        // when the acceptance is a generic "phpunit passes" (otherwise we'd false-positive every test bundle).
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/AtlasFooBarTest.php'],
            'scope_in' => ['tests/Unit/Ai/SelfConstruction/AtlasFooBarTest.php'],
            'acceptance_criteria' => ['phpunit passes'],
        ]));

        $this->assertNotContains('acceptance_coverage_mismatch', $r['deficiencies']);
    }

    // brain-pkt-acceptance-adequacy-coverage-w1: filter ADEQUACY beyond presence. A runnable
    // `php artisan test --filter=<X>` whose <X> names a test UNRELATED to the packet's target clears the
    // basename-coverage check (the target IS named elsewhere) yet exercises nothing the packet changes —
    // surfaced as an ADVISORY acceptance_coverage_mismatch. A filter that DOES name the target is left alone.
    public function test_filter_naming_an_unrelated_test_is_advisory_coverage_mismatch(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Refactor App\\Services\\Ai\\SelfConstruction\\AtlasWidgetCompiler to add a deterministic compile path under php artisan.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasWidgetCompiler.php', 'tests/Unit/Ai/SelfConstruction/AtlasWidgetCompilerTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasWidgetCompiler.php', 'tests/Unit/Ai/SelfConstruction/AtlasWidgetCompilerTest.php'],
            // First line names the target (so the basename-coverage check is satisfied), but the runnable gate
            // filters on an UNRELATED test — only the filter-adequacy check can catch this.
            'acceptance_criteria' => ['AtlasWidgetCompiler compiles deterministically', 'php artisan test --filter=UnrelatedPaymentGatewayTest'],
        ]));

        $this->assertContains('acceptance_coverage_mismatch', $r['deficiencies'], 'an unrelated --filter must be surfaced');
        $this->assertNotContains('acceptance_coverage_mismatch', $r['blocking_deficiencies'], 'must stay ADVISORY (not blocking)');
        $this->assertTrue($r['self_sufficient'], 'advisory must not flip self_sufficient');
        $this->assertTrue($r['facts']['acceptance_coverage_mismatch']);
    }

    // wire-hidden-poison-detector-into-universal-inspector-v1: the built-but-unused AtlasTaskHiddenPoisonDetector
    // now screens every inspected packet. Its patterns surface as ADVISORY `hidden_poison:<id>` deficiencies
    // (never blocking), and a clean packet gains none of them.
    public function test_hidden_poison_contradictory_acceptance_surfaces_as_advisory_deficiency(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            // Mutually contradictory acceptance, declared via the detector's quality_facts contract.
            'acceptance_criteria' => ['Foo must be deleted entirely', 'Foo must remain and be extended'],
            'quality_facts' => [
                'contradiction_pairs' => [
                    ['a' => 'Foo must be deleted entirely', 'b' => 'Foo must remain and be extended'],
                ],
            ],
        ]));

        $this->assertContains('hidden_poison:contradictory_acceptance', $r['deficiencies'], 'a contradictory packet must surface the hidden-poison pattern');
        $this->assertNotContains('hidden_poison:contradictory_acceptance', $r['blocking_deficiencies'], 'hidden poison is ADVISORY, never blocking');

        // A clean, well-formed packet must NOT gain any hidden_poison: entry — the wiring is additive and never
        // false-positives a healthy packet.
        $clean = (new AtlasTaskPacketQualityInspector)->inspect($this->packet());
        foreach ($clean['deficiencies'] as $d) {
            $this->assertStringNotContainsString('hidden_poison:', (string) $d, 'a healthy packet must stay free of hidden-poison advisories');
        }
    }

    public function test_filter_naming_the_target_does_not_trigger_coverage_mismatch(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'objective' => 'Refactor App\\Services\\Ai\\SelfConstruction\\AtlasWidgetCompiler to add a deterministic compile path under php artisan.',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasWidgetCompiler.php', 'tests/Unit/Ai/SelfConstruction/AtlasWidgetCompilerTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasWidgetCompiler.php', 'tests/Unit/Ai/SelfConstruction/AtlasWidgetCompilerTest.php'],
            'acceptance_criteria' => ['php artisan test --filter=AtlasWidgetCompilerTest'],
        ]));

        $this->assertNotContains('acceptance_coverage_mismatch', $r['deficiencies'], 'a filter that names the target binds');
        $this->assertFalse($r['facts']['acceptance_coverage_mismatch']);
        $this->assertTrue($r['self_sufficient']);
    }
}
