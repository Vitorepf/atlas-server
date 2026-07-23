<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOperatorIndependenceVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOperatorIndependenceVerifierTest extends TestCase
{
    private AtlasExternalBrainOperatorIndependenceVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasExternalBrainOperatorIndependenceVerifier;
    }

    private function nativeStep(string $step = 'run_atlas_gate'): array
    {
        return [
            'step'                    => $step,
            'dependency_type'         => AtlasExternalBrainOperatorIndependenceVerifier::DEP_ATLAS_NATIVE,
            'on_critical_path'        => true,
            'is_bootstrap_only'       => false,
            'atlas_native_path_exists' => true,
        ];
    }

    private function bootstrapStep(string $depType, string $step = 'bootstrap_via_external'): array
    {
        return [
            'step'                    => $step,
            'dependency_type'         => $depType,
            'on_critical_path'        => true,
            'is_bootstrap_only'       => true,
            'atlas_native_path_exists' => true,
        ];
    }

    private function blockingStep(string $depType, string $step = 'depends_on_human'): array
    {
        return [
            'step'                    => $step,
            'dependency_type'         => $depType,
            'on_critical_path'        => true,
            'is_bootstrap_only'       => false,
            'atlas_native_path_exists' => false,
        ];
    }

    private function plan(array $steps, string $planId = 'plan-001'): array
    {
        return ['plan_id' => $planId, 'steps' => $steps];
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->verifier->verify($this->plan([]));

        foreach (['schema', 'plan_id', 'passed', 'blocking_dependencies', 'optional_dependencies', 'next_unblock_action',
                  'autonomy_status', 'blocking_dependency', 'remediation_hint'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOperatorIndependenceVerifier::SCHEMA, $result['schema']);
    }

    // ── steady_state vs exceptional_audit_or_policy_review ────────────────────

    public function test_exceptional_audit_review_human_dependency_is_optional_not_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'quarterly_policy_review',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN,
                'on_critical_path'         => true,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => false,
                'dependency_context'       => AtlasExternalBrainOperatorIndependenceVerifier::CONTEXT_EXCEPTIONAL,
            ],
        ]));

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blocking_dependencies']);
        $this->assertCount(1, $result['optional_dependencies']);
        $this->assertSame('exceptional_audit_or_policy_review_not_steady_state', $result['optional_dependencies'][0]['reason']);
        $this->assertSame('fully_autonomous_steady_state', $result['autonomy_status']);
    }

    public function test_exceptional_audit_review_operator_dependency_is_optional(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'annual_audit',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR,
                'on_critical_path'         => true,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => false,
                'dependency_context'       => AtlasExternalBrainOperatorIndependenceVerifier::CONTEXT_EXCEPTIONAL,
            ],
        ]));

        $this->assertTrue($result['passed']);
    }

    public function test_default_dependency_context_is_steady_state_and_blocks(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR),
        ]));

        $this->assertFalse($result['passed']);
        $this->assertSame('steady_state_human_dependency_detected', $result['autonomy_status']);
    }

    public function test_exceptional_context_does_not_exempt_claude_codex_or_external_provider(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'codex_every_cycle',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_CLAUDE_CODEX,
                'on_critical_path'         => true,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => false,
                'dependency_context'       => AtlasExternalBrainOperatorIndependenceVerifier::CONTEXT_EXCEPTIONAL,
            ],
        ]));

        $this->assertFalse($result['passed']);
    }

    // ── autonomy_status / blocking_dependency / remediation_hint ──────────────

    public function test_blocking_dependency_is_null_when_passed(): void
    {
        $result = $this->verifier->verify($this->plan([$this->nativeStep()]));

        $this->assertTrue($result['passed']);
        $this->assertNull($result['blocking_dependency']);
        $this->assertNull($result['remediation_hint']);
    }

    public function test_blocking_dependency_matches_first_blocker_when_failed(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN, 'step_a'),
        ]));

        $this->assertSame('step_a', $result['blocking_dependency']['step']);
        $this->assertNotNull($result['remediation_hint']);
        $this->assertSame($result['next_unblock_action'], $result['remediation_hint']);
    }

    // ── AC3: all-native plan passes ───────────────────────────────────────────

    public function test_plan_with_only_atlas_native_steps_passes(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->nativeStep('run_gate_a'),
            $this->nativeStep('run_gate_b'),
        ]));

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blocking_dependencies']);
        $this->assertNull($result['next_unblock_action']);
    }

    // ── AC3: bootstrap external agents are optional, not blocking ─────────────

    public function test_bootstrap_only_external_with_native_path_is_optional_not_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->nativeStep(),
            $this->bootstrapStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_CLAUDE_CODEX, 'initial_seed_via_codex'),
        ]));

        $this->assertTrue($result['passed']);
        $this->assertCount(1, $result['optional_dependencies']);
        $this->assertSame('initial_seed_via_codex', $result['optional_dependencies'][0]['step']);
    }

    public function test_bootstrap_only_external_provider_with_native_path_is_optional(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->bootstrapStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_EXTERNAL_PROVIDER),
        ]));

        $this->assertTrue($result['passed']);
        $this->assertCount(1, $result['optional_dependencies']);
    }

    // ── AC2: blocking — operator approval on steady-state critical path ───────

    public function test_operator_approval_on_critical_path_is_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR),
        ]));

        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['blocking_dependencies']);
        $this->assertSame(
            AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR,
            $result['blocking_dependencies'][0]['dependency_type'],
        );
        $this->assertSame(
            'replace_operator_approval_with_atlas_gate_or_evidence_check',
            $result['blocking_dependencies'][0]['unblock_action'],
        );
        $this->assertSame($result['next_unblock_action'], $result['blocking_dependencies'][0]['unblock_action']);
    }

    public function test_human_dependency_on_critical_path_is_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN),
        ]));

        $this->assertFalse($result['passed']);
        $this->assertSame(
            AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN,
            $result['blocking_dependencies'][0]['dependency_type'],
        );
        $this->assertSame(
            'replace_human_step_with_atlas_native_automation',
            $result['blocking_dependencies'][0]['unblock_action'],
        );
        $this->assertSame($result['next_unblock_action'], $result['blocking_dependencies'][0]['unblock_action']);
    }

    public function test_claude_codex_without_proven_native_path_is_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'ask_codex_every_cycle',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_CLAUDE_CODEX,
                'on_critical_path'         => true,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => false,
            ],
        ]));

        $this->assertFalse($result['passed']);
        $this->assertSame(
            'mark_as_bootstrap_only_and_prove_atlas_native_path',
            $result['blocking_dependencies'][0]['unblock_action'],
        );
    }

    public function test_external_provider_on_critical_path_without_bootstrap_flag_is_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'call_external_api_each_cycle',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_EXTERNAL_PROVIDER,
                'on_critical_path'         => true,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => true,
            ],
        ]));

        $this->assertFalse($result['passed']);
        $this->assertSame(
            'mark_as_bootstrap_only_and_prove_atlas_native_path',
            $result['blocking_dependencies'][0]['unblock_action'],
        );
    }

    // ── next_unblock_action ───────────────────────────────────────────────────

    public function test_next_unblock_action_targets_first_blocker(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN, 'step_a'),
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR, 'step_b'),
        ]));

        $this->assertNotNull($result['next_unblock_action']);
        $this->assertIsString($result['next_unblock_action']);
        $this->assertStringContainsString('human', $result['next_unblock_action']);
    }

    // ── Off-critical-path deps are not blocking ───────────────────────────────

    public function test_human_dep_not_on_critical_path_is_not_blocking(): void
    {
        $result = $this->verifier->verify($this->plan([
            [
                'step'                     => 'optional_manual_review',
                'dependency_type'          => AtlasExternalBrainOperatorIndependenceVerifier::DEP_HUMAN,
                'on_critical_path'         => false,
                'is_bootstrap_only'        => false,
                'atlas_native_path_exists' => false,
            ],
        ]));

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blocking_dependencies']);
    }

    // ── plan_id echoed ────────────────────────────────────────────────────────

    public function test_plan_id_echoed_in_result(): void
    {
        $result = $this->verifier->verify($this->plan([], 'my-plan-xyz'));

        $this->assertSame('my-plan-xyz', $result['plan_id']);
    }

    // ── Mixed plan ────────────────────────────────────────────────────────────

    public function test_mixed_plan_correctly_separates_blocking_and_optional(): void
    {
        $result = $this->verifier->verify($this->plan([
            $this->nativeStep('step_native'),
            $this->bootstrapStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_CLAUDE_CODEX, 'step_bootstrap'),
            $this->blockingStep(AtlasExternalBrainOperatorIndependenceVerifier::DEP_OPERATOR, 'step_blocks'),
        ]));

        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['blocking_dependencies']);
        $this->assertCount(1, $result['optional_dependencies']);
        $this->assertSame('step_blocks', $result['blocking_dependencies'][0]['step']);
        $this->assertSame('step_bootstrap', $result['optional_dependencies'][0]['step']);
    }
}
