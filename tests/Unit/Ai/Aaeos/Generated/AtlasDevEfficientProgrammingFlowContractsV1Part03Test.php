<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsV1Part03Service;
use Tests\TestCase;

/**
 * Pins the ten documented invariants of §4.3 MiniProgrammingSpec.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md
 */
final class AtlasDevEfficientProgrammingFlowContractsV1Part03Test extends TestCase
{
    private AtlasDevEfficientProgrammingFlowContractsV1Part03Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDevEfficientProgrammingFlowContractsV1Part03Service;
    }

    /**
     * A canonical, fully-legal spec (the doc's Repair-R2 example) must validate
     * clean AND be allowed to progress (no blocking assumption).
     */
    public function test_canonical_repair_r2_spec_is_valid_and_can_progress(): void
    {
        $verdict = $this->service->validateMiniSpec($this->validSpec());

        $this->assertTrue($verdict['valid'], 'canonical spec should be invariant-legal; got: '.json_encode($verdict['violations']));
        $this->assertTrue($verdict['can_progress']);
        $this->assertSame([], $verdict['violations']);
        $this->assertSame('atlas.dev.mini_programming_spec.v1', $verdict['contract']);
    }

    /**
     * I1 — empty goal and goal over 240 chars are both rejected with the right rule.
     */
    public function test_i1_goal_empty_and_over_240_chars_are_rejected(): void
    {
        $empty = $this->service->validateMiniSpec($this->mutate(['goal' => '']));
        $this->assertFalse($empty['valid']);
        $this->assertContains('goal_non_empty', array_column($empty['violations'], 'rule'));

        $long = $this->service->validateMiniSpec($this->mutate(['goal' => str_repeat('a', 241)]));
        $this->assertFalse($long['valid']);
        $this->assertContains('goal_max_240_chars', array_column($long['violations'], 'rule'));

        // Boundary: exactly 240 chars is still legal for the length rule.
        $boundary = $this->service->validateMiniSpec($this->mutate(['goal' => 'Corrigir '.str_repeat('x', 231)]));
        $this->assertNotContains('goal_max_240_chars', array_column($boundary['violations'], 'rule'));
    }

    /**
     * I2 — non_goals must be present for risk >= R2; an R1 spec with empty
     * non_goals does NOT trip I2 (edge of the threshold).
     */
    public function test_i2_non_goals_required_only_for_risk_ge_r2(): void
    {
        $r3NoGoals = $this->service->validateMiniSpec($this->mutate(['risk_level' => 'R3', 'non_goals' => []]));
        $this->assertContains('non_goals_required_for_risk_ge_r2', array_column($r3NoGoals['violations'], 'rule'));

        $r1NoGoals = $this->service->validateMiniSpec($this->mutate(['risk_level' => 'R1', 'non_goals' => []]));
        $this->assertNotContains('non_goals_required_for_risk_ge_r2', array_column($r1NoGoals['violations'], 'rule'));
    }

    /**
     * I3 — task_kind=question is exempt from the canonical_context ref rule, but a
     * non-question task with empty context trips I3.
     */
    public function test_i3_canonical_context_ref_required_unless_question(): void
    {
        $patchNoCtx = $this->service->validateMiniSpec($this->mutate([
            'task_kind' => 'patch',
            'canonical_context' => [],
        ]));
        $this->assertContains('canonical_context_requires_ref', array_column($patchNoCtx['violations'], 'rule'));

        $questionNoCtx = $this->service->validateMiniSpec($this->mutate([
            'task_kind' => 'question',
            'canonical_context' => [],
            'mode' => 'read_only', // a question is not a write, so I6 stays silent too
        ]));
        $this->assertNotContains('canonical_context_requires_ref', array_column($questionNoCtx['violations'], 'rule'));
    }

    /**
     * I4 + I5 — expected_files must be a subset of allowed_files, and allowed and
     * forbidden sets must be disjoint. Both fire independently.
     */
    public function test_i4_and_i5_file_set_invariants(): void
    {
        // I4: expected file not in allowed.
        $i4 = $this->service->validateMiniSpec($this->mutate([
            'expected_files' => ['app/NotAllowed.php'],
            'allowed_files' => ['app/Allowed.php'],
            'forbidden_files' => ['vendor/*'],
        ]));
        $this->assertContains('expected_files_subset_of_allowed', array_column($i4['violations'], 'rule'));

        // I5: a path in both allowed and forbidden.
        $i5 = $this->service->validateMiniSpec($this->mutate([
            'expected_files' => ['app/Shared.php'],
            'allowed_files' => ['app/Shared.php'],
            'forbidden_files' => ['app/Shared.php'],
        ]));
        $this->assertContains('allowed_and_forbidden_disjoint', array_column($i5['violations'], 'rule'));
    }

    /**
     * I6 + I7 — a write mode with empty acceptance_criteria trips I6; a criterion
     * whose verification is `test` but lacks verification_ref trips I7, while a
     * `manual_review` criterion without a ref is fine.
     */
    public function test_i6_and_i7_acceptance_criteria_invariants(): void
    {
        $i6 = $this->service->validateMiniSpec($this->mutate(['mode' => 'patch', 'acceptance_criteria' => []]));
        $this->assertContains('acceptance_criteria_required_for_write', array_column($i6['violations'], 'rule'));

        $i7 = $this->service->validateMiniSpec($this->mutate([
            'acceptance_criteria' => [
                ['id' => 'ac_1', 'description' => 'x', 'verification' => 'test', 'verification_ref' => null],
            ],
        ]));
        $this->assertContains('verification_ref_required_when_applicable', array_column($i7['violations'], 'rule'));

        $manualOk = $this->service->validateMiniSpec($this->mutate([
            'acceptance_criteria' => [
                ['id' => 'ac_1', 'description' => 'x', 'verification' => 'manual_review', 'verification_ref' => null],
            ],
        ]));
        $this->assertNotContains('verification_ref_required_when_applicable', array_column($manualOk['violations'], 'rule'));
    }

    /**
     * I8 + I9 — verification_plan.profile must equal the CompactSDD profile, and a
     * no_test_reason is only legal under generic_no_test.
     */
    public function test_i8_profile_mismatch_and_i9_no_test_reason_misuse(): void
    {
        $i8 = $this->service->validateMiniSpec($this->mutate([
            'compact_sdd_verification_profile' => 'php_laravel',
            'verification_plan' => ['profile' => 'ts_react', 'commands' => [], 'no_test_reason' => null],
        ]));
        $this->assertContains('profile_matches_compact_sdd', array_column($i8['violations'], 'rule'));

        $i9 = $this->service->validateMiniSpec($this->mutate([
            'compact_sdd_verification_profile' => 'php_laravel',
            'verification_plan' => ['profile' => 'php_laravel', 'commands' => [], 'no_test_reason' => 'nao tem teste'],
        ]));
        $this->assertContains('no_test_reason_only_for_generic_no_test', array_column($i9['violations'], 'rule'));

        // generic_no_test legitimately carries a no_test_reason (no I9 violation).
        $generic = $this->service->validateMiniSpec($this->mutate([
            'task_kind' => 'patch',
            'mode' => 'patch',
            'compact_sdd_verification_profile' => 'generic_no_test',
            'verification_plan' => ['profile' => 'generic_no_test', 'commands' => [], 'no_test_reason' => 'doc-only change'],
        ]));
        $this->assertNotContains('no_test_reason_only_for_generic_no_test', array_column($generic['violations'], 'rule'));
    }

    /**
     * I10 — a blocking assumption keeps a structurally-valid spec from progressing:
     * valid stays true, but can_progress flips to false and I10 is recorded.
     */
    public function test_i10_blocking_assumption_halts_progress(): void
    {
        $verdict = $this->service->validateMiniSpec($this->mutate([
            'assumptions' => [
                ['text' => 'depende de decisao de arquitetura', 'confidence' => 'blocking'],
            ],
        ]));

        $this->assertFalse($verdict['valid'], 'blocking assumption is recorded as a violation');
        $this->assertFalse($verdict['can_progress']);
        $this->assertContains('blocking_assumption_must_resolve_first', array_column($verdict['violations'], 'rule'));
    }

    /**
     * The manifest pins the documented constants (240-char cap, write modes, the
     * sole no-test profile) so they cannot silently drift.
     */
    public function test_manifest_pins_documented_constants(): void
    {
        $manifest = $this->service->manifest();

        $this->assertSame(240, $manifest['goal_max_chars']);
        $this->assertSame(['patch', 'repair'], $manifest['write_modes']);
        $this->assertSame('generic_no_test', $manifest['no_test_profile']);
        $this->assertCount(10, $manifest['invariants']);
    }

    /**
     * The doc's valid Repair-R2 example.
     *
     * @return array<string,mixed>
     */
    private function validSpec(): array
    {
        return [
            'goal' => 'Corrigir AtlasCliDevWorkflowServiceTest::test_workspace_resolution para passar com git root null',
            'risk_level' => 'R2',
            'task_kind' => 'repair',
            'mode' => 'repair',
            'compact_sdd_verification_profile' => 'php_laravel',
            'non_goals' => ['nao mudar API publica do WorkflowService', 'nao mexer em outros testes'],
            'canonical_context' => [
                ['kind' => 'file', 'ref' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php', 'reason' => 'alvo do bug'],
            ],
            'assumptions' => [
                ['text' => 'git root nullable e caso valido', 'confidence' => 'inference'],
            ],
            'expected_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'allowed_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'forbidden_files' => ['vendor/*', 'node_modules/*'],
            'acceptance_criteria' => [
                [
                    'id' => 'ac_1',
                    'description' => 'teste passa',
                    'verification' => 'test',
                    'verification_ref' => 'tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution',
                ],
            ],
            'verification_plan' => [
                'profile' => 'php_laravel',
                'commands' => ['composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution'],
                'no_test_reason' => null,
            ],
        ];
    }

    /**
     * Start from the valid spec and override the given keys.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function mutate(array $overrides): array
    {
        return array_merge($this->validSpec(), $overrides);
    }
}
