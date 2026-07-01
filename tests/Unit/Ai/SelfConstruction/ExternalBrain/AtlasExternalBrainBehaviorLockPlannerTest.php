<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBehaviorLockPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainBehaviorLockPlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainBehaviorLockPlanner
    {
        return new AtlasExternalBrainBehaviorLockPlanner;
    }

    private function safeTarget(array $overrides = []): array
    {
        return array_merge([
            'target_id'                       => 'organ-1',
            'requested_action'                 => 'delete',
            'behavior_bearing'                 => true,
            'parity_tests_available'           => true,
            'consumer_impact_proof_available'  => true,
            'rollback_evidence_available'      => true,
            'risk_level'                       => 'low',
            'test_coverage'                    => true,
        ], $overrides);
    }

    // ── proof gates required before destructive actions ───────────────────────

    public function test_destructive_action_with_no_proof_gates_is_downgraded_to_lock_first(): void
    {
        $result = $this->svc()->plan([$this->safeTarget([
            'parity_tests_available' => false,
            'consumer_impact_proof_available' => false,
            'rollback_evidence_available' => false,
        ])]);

        $decision = $result['decisions'][0];
        $this->assertSame(AtlasExternalBrainBehaviorLockPlanner::DECISION_LOCK_FIRST, $decision['decision']);
        $this->assertContains('missing_parity_tests', $decision['blockers']);
        $this->assertContains('missing_consumer_impact_proof', $decision['blockers']);
        $this->assertContains('missing_rollback_evidence', $decision['blockers']);
    }

    public function test_destructive_action_with_all_proof_gates_and_low_risk_and_coverage_proceeds(): void
    {
        $result = $this->svc()->plan([$this->safeTarget()]);

        $decision = $result['decisions'][0];
        $this->assertSame('delete', $decision['decision']);
        $this->assertSame([], $decision['blockers']);
    }

    public function test_merge_and_simplify_also_require_proof_gates(): void
    {
        foreach (['merge', 'simplify'] as $action) {
            $result = $this->svc()->plan([$this->safeTarget([
                'requested_action' => $action,
                'rollback_evidence_available' => false,
            ])]);

            $decision = $result['decisions'][0];
            $this->assertSame(AtlasExternalBrainBehaviorLockPlanner::DECISION_LOCK_FIRST, $decision['decision'], "$action must require proof gates");
            $this->assertContains('missing_rollback_evidence', $decision['blockers']);
        }
    }

    // ── high-risk or uncovered targets downgrade to lock_first ────────────────

    public function test_high_risk_target_with_full_proof_gates_is_still_downgraded_to_lock_first(): void
    {
        $result = $this->svc()->plan([$this->safeTarget(['risk_level' => 'high'])]);

        $decision = $result['decisions'][0];
        $this->assertSame(AtlasExternalBrainBehaviorLockPlanner::DECISION_LOCK_FIRST, $decision['decision']);
        $this->assertContains('high_risk_target', $decision['blockers']);
    }

    public function test_uncovered_target_with_full_proof_gates_is_still_downgraded_to_lock_first(): void
    {
        $result = $this->svc()->plan([$this->safeTarget(['test_coverage' => false])]);

        $decision = $result['decisions'][0];
        $this->assertSame(AtlasExternalBrainBehaviorLockPlanner::DECISION_LOCK_FIRST, $decision['decision']);
        $this->assertContains('uncovered_target', $decision['blockers']);
    }

    // ── non-destructive / non-behavior-bearing bypass the gate ────────────────

    public function test_non_destructive_requested_action_bypasses_proof_gates(): void
    {
        $result = $this->svc()->plan([$this->safeTarget([
            'requested_action' => 'keep',
            'parity_tests_available' => false,
            'consumer_impact_proof_available' => false,
            'rollback_evidence_available' => false,
        ])]);

        $decision = $result['decisions'][0];
        $this->assertSame('keep', $decision['decision']);
        $this->assertSame([], $decision['blockers']);
    }

    public function test_non_behavior_bearing_target_bypasses_proof_gates(): void
    {
        $result = $this->svc()->plan([$this->safeTarget([
            'behavior_bearing' => false,
            'parity_tests_available' => false,
            'consumer_impact_proof_available' => false,
            'rollback_evidence_available' => false,
        ])]);

        $decision = $result['decisions'][0];
        $this->assertSame('delete', $decision['decision']);
        $this->assertSame([], $decision['blockers']);
    }

    // ── proof_gate_matrix ──────────────────────────────────────────────────────

    public function test_proof_gate_matrix_reflects_each_target(): void
    {
        $result = $this->svc()->plan([
            $this->safeTarget(['target_id' => 'a']),
            $this->safeTarget(['target_id' => 'b', 'rollback_evidence_available' => false]),
        ]);

        $byId = array_column($result['proof_gate_matrix'], null, 'target_id');
        $this->assertTrue($byId['a']['all_gates_passed']);
        $this->assertFalse($byId['b']['all_gates_passed']);
        $this->assertFalse($byId['b']['rollback_evidence_available']);
    }

    public function test_lock_first_count_aggregates_downgraded_targets(): void
    {
        $result = $this->svc()->plan([
            $this->safeTarget(['target_id' => 'a']),
            $this->safeTarget(['target_id' => 'b', 'risk_level' => 'high']),
            $this->safeTarget(['target_id' => 'c', 'test_coverage' => false]),
        ]);

        $this->assertSame(2, $result['lock_first_count']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.behavior_lock_planner.v1', AtlasExternalBrainBehaviorLockPlanner::SCHEMA);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $targets = [$this->safeTarget(), $this->safeTarget(['target_id' => 'organ-2', 'risk_level' => 'high'])];

        $this->assertSame(json_encode($svc->plan($targets)), json_encode($svc->plan($targets)));
    }
}
