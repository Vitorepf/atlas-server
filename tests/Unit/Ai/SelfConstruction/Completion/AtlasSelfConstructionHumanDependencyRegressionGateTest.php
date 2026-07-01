<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionHumanDependencyRegressionGate;
use Tests\TestCase;

class AtlasSelfConstructionHumanDependencyRegressionGateTest extends TestCase
{
    public function test_clean_atlas_native_facts_pass(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
                ['id' => 'p2', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_server']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
        self::assertSame(AtlasSelfConstructionHumanDependencyRegressionGate::STATUS_PASSED, $verdict['status']);
        self::assertSame([], $verdict['blockers']);
    }

    public function test_blocks_when_ordinary_path_requires_non_atlas_actor(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'merge_path', 'kind' => 'ordinary', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('steady_state_non_atlas_actor:path=merge_path:actor=operator', $verdict['blockers']);
    }

    public function test_advisory_bootstrap_label_does_not_block_under_atlas_native_owner(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'init', 'kind' => 'bootstrap', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
        self::assertTrue($verdict['inspected_paths'][0]['advisory_exception_applied']);
    }

    public function test_advisory_emergency_label_does_not_block_under_atlas_native_owner(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'pager', 'kind' => 'emergency', 'steady_state_required' => ['oncall_human']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
    }

    public function test_visibility_label_also_advisory(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'dashboard', 'kind' => 'visibility', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
    }

    public function test_blocks_when_final_runtime_owner_is_not_atlas_native(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'external_assistant',
            'paths' => [
                ['id' => 'p', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('final_runtime_owner_not_atlas_native:external_assistant', $verdict['blockers']);
    }

    public function test_advisory_label_does_NOT_apply_when_final_owner_is_wrong(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'external_assistant',
            'paths' => [
                ['id' => 'init', 'kind' => 'bootstrap', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains('final_runtime_owner_not_atlas_native:external_assistant', $verdict['blockers']);
        self::assertContains('steady_state_non_atlas_actor:path=init:actor=operator', $verdict['blockers']);
    }

    public function test_ordinary_path_requiring_pasted_session_actor_is_blocked_with_specific_message(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'cycle_recover', 'kind' => 'ordinary', 'steady_state_required' => ['pasted_session']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertContains(
            'pasted_session_recovery_in_ordinary_path:path=cycle_recover:actor=pasted_session',
            $verdict['blockers'],
        );
        foreach ($verdict['blockers'] as $b) {
            self::assertStringNotContainsString('steady_state_non_atlas_actor', $b);
        }
    }

    public function test_atlas_native_recovery_path_passes_gate(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'recovery', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
            ],
        ]);

        self::assertTrue($verdict['passed']);
        self::assertSame([], $verdict['blockers']);
    }

    // ── AC: no regression / regressions array shape ───────────────────────────

    public function test_clean_facts_yield_no_regressions(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
            ],
        ]);

        self::assertSame([], $verdict['regressions']);
    }

    private function assertRegressionShape(array $regression, string $kind): void
    {
        self::assertSame($kind, $regression['kind']);
        self::assertNotEmpty($regression['severity']);
        self::assertNotEmpty($regression['blocking_reason']);
        self::assertNotEmpty($regression['native_replacement_hint']);
    }

    // ── AC: operator prompt regression ─────────────────────────────────────────

    public function test_operator_actor_yields_operator_prompt_required_regression(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'merge', 'kind' => 'ordinary', 'steady_state_required' => ['operator']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        self::assertCount(1, $verdict['regressions']);
        $this->assertRegressionShape($verdict['regressions'][0], AtlasSelfConstructionHumanDependencyRegressionGate::REGRESSION_OPERATOR_PROMPT);
    }

    public function test_manual_decision_actor_yields_manual_decision_required_regression(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'merge', 'kind' => 'ordinary', 'steady_state_required' => ['manual_approval']],
            ],
        ]);

        $this->assertRegressionShape($verdict['regressions'][0], AtlasSelfConstructionHumanDependencyRegressionGate::REGRESSION_MANUAL_DECISION);
    }

    // ── AC: provider dependency regression ─────────────────────────────────────

    public function test_provider_actor_yields_provider_required_regression(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'implement', 'kind' => 'ordinary', 'steady_state_required' => ['claude_code']],
            ],
        ]);

        self::assertFalse($verdict['passed']);
        $this->assertRegressionShape($verdict['regressions'][0], AtlasSelfConstructionHumanDependencyRegressionGate::REGRESSION_PROVIDER);
        self::assertSame('critical', $verdict['regressions'][0]['severity']);
    }

    // ── AC: human-only recovery regression ─────────────────────────────────────

    public function test_pasted_session_actor_yields_human_recovery_only_regression(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'cycle_recover', 'kind' => 'ordinary', 'steady_state_required' => ['pasted_session']],
            ],
        ]);

        $this->assertRegressionShape($verdict['regressions'][0], AtlasSelfConstructionHumanDependencyRegressionGate::REGRESSION_HUMAN_RECOVERY);
    }

    // ── undocumented handoff regression ─────────────────────────────────────────

    public function test_unnamed_non_atlas_actor_yields_undocumented_handoff_regression(): void
    {
        $verdict = (new AtlasSelfConstructionHumanDependencyRegressionGate)->check([
            'final_runtime_owner' => 'atlas_native',
            'paths' => [
                ['id' => 'weird', 'kind' => 'ordinary', 'steady_state_required' => ['some_third_party']],
            ],
        ]);

        $this->assertRegressionShape($verdict['regressions'][0], AtlasSelfConstructionHumanDependencyRegressionGate::REGRESSION_UNDOCUMENTED_HANDOFF);
    }
}
