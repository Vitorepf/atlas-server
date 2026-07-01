<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWaveProofBudgetPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the allocation-by-risk-factors surface of AtlasExternalBrainWaveProofBudgetPlanner:
 * refactor blast radius, model weakness, and expected leverage each grow the required proof
 * set and budget headroom beyond a flat evidence floor, and under-provisioned waves are flagged
 * with a concrete split/strengthen/defer action.
 */
final class AtlasExternalBrainWaveProofBudgetPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainWaveProofBudgetPlanner
    {
        return new AtlasExternalBrainWaveProofBudgetPlanner;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'acceptance_criteria' => ['tests pass'],
            'required_evidence' => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
            'estimated_test_minutes' => 5.0,
            'risk_level' => 'low',
            'affected_file_families' => ['app/Services/Foo.php'],
        ], $overrides);
    }

    // ── AC1: low-risk wave — flat floor, no blast/weak-model/leverage bonus ──

    public function test_low_risk_wave_is_within_budget_with_flat_proof_floor(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [$this->task()]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_WITHIN_BUDGET, $result['proof_budget_status']);
        $this->assertSame(0, $entry['refactor_blast_radius']);
        $this->assertSame(0.0, $entry['model_weakness_score']);
        $this->assertSame(0.0, $entry['expected_leverage']);
        $this->assertNull($entry['recommended_action']);
        $this->assertNotContains('proof:consumer_impact', $entry['required_checks']);
        $this->assertNotContains('proof:collision_sweep', $entry['required_checks']);
    }

    // ── AC1: high-risk refactor wave — blast radius earns extra checks + budget ──

    public function test_high_risk_refactor_wave_with_high_blast_radius_adds_consumer_and_rollback_checks(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['risk_level' => 'high', 'refactor_blast_radius' => 5]),
        ]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertSame(5, $entry['refactor_blast_radius']);
        $this->assertContains('proof:consumer_impact', $entry['required_checks']);
        $this->assertContains('proof:rollback_plan', $entry['required_checks']);
    }

    public function test_high_blast_radius_grants_larger_risk_adjusted_budget_than_same_risk_without_it(): void
    {
        $withBlast = $this->planner()->plan(['wave_tasks' => [
            $this->task(['risk_level' => 'medium', 'refactor_blast_radius' => 5]),
        ]]);
        $withoutBlast = $this->planner()->plan(['wave_tasks' => [
            $this->task(['risk_level' => 'medium', 'refactor_blast_radius' => 0]),
        ]]);

        $this->assertGreaterThan(
            $withoutBlast['per_task_required_checks'][0]['risk_adjusted_budget'],
            $withBlast['per_task_required_checks'][0]['risk_adjusted_budget'],
        );
    }

    // ── AC1: weak-model wave — model weakness earns an extra collision_sweep check ──

    public function test_weak_model_wave_adds_collision_sweep_check(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['model_weakness_score' => 0.8]),
        ]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertSame(0.8, $entry['model_weakness_score']);
        $this->assertContains('proof:collision_sweep', $entry['required_checks']);
    }

    public function test_model_weakness_below_threshold_does_not_add_collision_sweep(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['model_weakness_score' => 0.2]),
        ]]);

        $this->assertNotContains('proof:collision_sweep', $result['per_task_required_checks'][0]['required_checks']);
    }

    // ── expected_leverage grants budget headroom ──────────────────────────────

    public function test_high_expected_leverage_grants_larger_budget(): void
    {
        $highLeverage = $this->planner()->plan(['wave_tasks' => [
            $this->task(['risk_level' => 'medium', 'expected_leverage' => 0.9]),
        ]]);
        $lowLeverage = $this->planner()->plan(['wave_tasks' => [
            $this->task(['risk_level' => 'medium', 'expected_leverage' => 0.1]),
        ]]);

        $this->assertGreaterThan(
            $lowLeverage['per_task_required_checks'][0]['risk_adjusted_budget'],
            $highLeverage['per_task_required_checks'][0]['risk_adjusted_budget'],
        );
    }

    // ── AC2: under-budget rejection recommends split, strengthen, or defer ───

    public function test_missing_evidence_recommends_strengthen(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['required_evidence' => []]),
        ]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::ACTION_STRENGTHEN, $entry['recommended_action']);

        $recommendation = $result['proof_slimming_recommendations'][0];
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::ACTION_STRENGTHEN, $recommendation['recommended_action']);
    }

    public function test_too_broad_without_blast_or_weak_model_recommends_split(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task([
                'acceptance_criteria' => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'c9', 'c10', 'c11', 'c12', 'c13'],
                'risk_level' => 'high',
            ]),
        ]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertTrue($entry['too_broad']);
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::ACTION_SPLIT, $entry['recommended_action']);
    }

    public function test_too_broad_with_high_blast_radius_and_weak_model_recommends_defer(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task([
                'acceptance_criteria' => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'c9', 'c10', 'c11', 'c12', 'c13', 'c14', 'c15', 'c16', 'c17', 'c18', 'c19', 'c20'],
                'risk_level' => 'high',
                'refactor_blast_radius' => 6,
                'model_weakness_score' => 0.9,
            ]),
        ]]);

        $entry = $result['per_task_required_checks'][0];
        $this->assertTrue($entry['too_broad']);
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::ACTION_DEFER, $entry['recommended_action']);

        $recommendation = $result['proof_slimming_recommendations'][0];
        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::ACTION_DEFER, $recommendation['recommended_action']);
        $this->assertStringContainsString('defer', $recommendation['recommendation']);
    }

    public function test_within_budget_task_has_no_recommended_action(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [$this->task()]]);

        $this->assertNull($result['per_task_required_checks'][0]['recommended_action']);
        $this->assertSame([], $result['proof_slimming_recommendations']);
    }

    // ── determinism ────────────────────────────────────────────────────────────

    public function test_plan_is_deterministic_with_new_risk_factors(): void
    {
        $facts = ['wave_tasks' => [
            $this->task(['refactor_blast_radius' => 4, 'model_weakness_score' => 0.7, 'expected_leverage' => 0.8]),
        ]];

        $a = $this->planner()->plan($facts);
        $b = $this->planner()->plan($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
