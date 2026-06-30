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

        foreach (['schema', 'plan_id', 'passed', 'blocking_dependencies', 'optional_dependencies', 'next_unblock_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainOperatorIndependenceVerifier::SCHEMA, $result['schema']);
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
