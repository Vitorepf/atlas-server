<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWaveProofBudgetPlanner;
use PHPUnit\Framework\TestCase;

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

    // ── output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => []]);

        foreach ([
            'per_task_required_checks', 'wave_total_estimated_minutes',
            'proof_budget_status', 'over_budget_tasks', 'proof_slimming_recommendations',
        ] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
    }

    public function test_empty_wave_is_within_budget(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => []]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_WITHIN_BUDGET, $result['proof_budget_status']);
        $this->assertSame([], $result['over_budget_tasks']);
        $this->assertSame(0.0, $result['wave_total_estimated_minutes']);
    }

    // ── AC1: accepts acceptance criteria, evidence, cost, risk, file families ──

    public function test_single_healthy_task_is_within_budget_with_checks_derived(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [$this->task()]]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_WITHIN_BUDGET, $result['proof_budget_status']);
        $entry = $result['per_task_required_checks'][0];
        $this->assertSame('task-1', $entry['task_id']);
        $this->assertNotEmpty($entry['required_checks']);
        $this->assertContains('acceptance:tests pass', $entry['required_checks']);
        $this->assertContains('evidence:./vendor/bin/phpunit tests/Unit/FooTest.php', $entry['required_checks']);
        $this->assertSame(5.0, $entry['estimated_minutes']);
    }

    // ── AC2: wave_total_estimated_minutes sums across tasks ──────────────────

    public function test_wave_total_estimated_minutes_sums_task_costs(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['task_id' => 't1', 'estimated_test_minutes' => 5.0]),
            $this->task(['task_id' => 't2', 'estimated_test_minutes' => 10.0]),
        ]]);

        $this->assertSame(15.0, $result['wave_total_estimated_minutes']);
    }

    // ── AC3: refuses wave as proof_over_budget when evidence is missing ──────

    public function test_missing_required_evidence_is_refused_as_over_budget(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task(['required_evidence' => []]),
        ]]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_PROOF_OVER_BUDGET, $result['proof_budget_status']);
        $this->assertContains('task-1', $result['over_budget_tasks']);
        $this->assertTrue($result['per_task_required_checks'][0]['missing_evidence']);

        $recommendation = array_values(array_filter(
            $result['proof_slimming_recommendations'],
            fn ($r) => $r['task_id'] === 'task-1',
        ))[0];
        $this->assertContains('required_evidence_is_empty', $recommendation['reasons']);
    }

    // ── AC3: refuses wave when required gates are too broad for muscle count ──

    public function test_task_with_too_many_required_checks_is_refused_as_over_budget(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [
            $this->task([
                'acceptance_criteria' => ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'],
                'risk_level' => 'high',
            ]),
        ]]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_PROOF_OVER_BUDGET, $result['proof_budget_status']);
        $this->assertContains('task-1', $result['over_budget_tasks']);
        $this->assertTrue($result['per_task_required_checks'][0]['too_broad']);
    }

    public function test_wave_over_minute_budget_with_low_muscle_count_is_over_budget(): void
    {
        $result = $this->planner()->plan([
            'wave_tasks' => [
                $this->task(['task_id' => 't1', 'estimated_test_minutes' => 100.0]),
            ],
            'muscle_count' => 1,
            'per_muscle_minute_budget' => 45.0,
        ]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_PROOF_OVER_BUDGET, $result['proof_budget_status']);
    }

    public function test_higher_muscle_count_can_absorb_the_same_wave_within_budget(): void
    {
        $result = $this->planner()->plan([
            'wave_tasks' => [
                $this->task(['task_id' => 't1', 'estimated_test_minutes' => 100.0]),
            ],
            'muscle_count' => 5,
            'per_muscle_minute_budget' => 45.0,
        ]);

        $this->assertSame(AtlasExternalBrainWaveProofBudgetPlanner::STATUS_WITHIN_BUDGET, $result['proof_budget_status']);
    }

    // ── risk level escalates required checks via composed proof demand ───────

    public function test_high_risk_task_gets_more_required_checks_than_low_risk(): void
    {
        $low = $this->planner()->plan(['wave_tasks' => [$this->task(['risk_level' => 'low'])]]);
        $high = $this->planner()->plan(['wave_tasks' => [$this->task(['risk_level' => 'high'])]]);

        $this->assertGreaterThan(
            $low['per_task_required_checks'][0]['check_count'],
            $high['per_task_required_checks'][0]['check_count'],
        );
    }

    // ── determinism ────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $facts = ['wave_tasks' => [$this->task(), $this->task(['task_id' => 'task-2'])]];

        $a = $this->planner()->plan($facts);
        $b = $this->planner()->plan($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_plan_never_mutates_queue(): void
    {
        $result = $this->planner()->plan(['wave_tasks' => [$this->task()]]);
        $this->assertFalse($result['mutates_queue']);
    }
}
