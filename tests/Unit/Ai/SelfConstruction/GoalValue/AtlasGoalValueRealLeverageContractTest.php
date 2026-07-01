<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueRealLeverageContract;
use Tests\TestCase;

final class AtlasGoalValueRealLeverageContractTest extends TestCase
{
    public function test_real_leverage_with_proper_evidence_passes_dimensions(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_CAPABILITY_LIFT => [
                'evidence_kind' => 'new_capability_demonstration',
                'evidence_refs' => ['receipt:abc'],
                'before_fact' => 'feature absent',
                'after_fact' => 'feature ships and green',
            ],
            AtlasGoalValueRealLeverageContract::DIM_QUALITY_HARDENING => [
                'evidence_kind' => 'mutation_kills_added',
                'evidence_refs' => ['mutop:def'],
                'before_fact' => '0 mutation kills',
                'after_fact' => '12 mutation kills',
            ],
        ]);

        $this->assertTrue($verdict['real_leverage']);
        $this->assertFalse($verdict['proxy_only']);
        $this->assertSame(2, $verdict['passed_count']);
    }

    public function test_proxy_only_evidence_is_rejected_and_not_real_leverage(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_CAPABILITY_LIFT => [
                'evidence_kind' => 'task_count',
                'evidence_refs' => ['receipt:bogus'],
            ],
            AtlasGoalValueRealLeverageContract::DIM_QUALITY_HARDENING => [
                'evidence_kind' => 'green_self_report',
                'evidence_refs' => ['receipt:bogus'],
            ],
            AtlasGoalValueRealLeverageContract::DIM_SIMPLIFICATION => [
                'evidence_kind' => 'line_churn',
                'evidence_refs' => ['receipt:bogus'],
            ],
            AtlasGoalValueRealLeverageContract::DIM_AUTONOMY_LIFT => [
                'evidence_kind' => 'cosmetic_docs',
                'evidence_refs' => ['receipt:bogus'],
            ],
        ]);

        $this->assertFalse($verdict['real_leverage'], 'proxy-only evidence MUST NOT pass the contract');
        foreach ($verdict['dimensions'] as $row) {
            if ($row['evidence_kind'] !== null) {
                $this->assertFalse($row['passed'], "dimension {$row['id']} with rejected kind must NOT pass");
            }
        }
    }

    public function test_mixed_real_and_proxy_evidence_still_counts_proxy_as_not_leverage(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_FAILURE_REMOVAL => [
                'evidence_kind' => 'red_test_removed',
                'evidence_refs' => ['test:foo'],
                'before_fact' => '3 red tests',
                'after_fact' => '0 red tests',
            ],
            AtlasGoalValueRealLeverageContract::DIM_SIMPLIFICATION => [
                'evidence_kind' => 'line_churn', // proxy
                'evidence_refs' => ['diff:bar'],
            ],
        ]);

        $this->assertTrue($verdict['real_leverage'], 'one real-evidence dimension is enough to escape proxy-only');
        $this->assertFalse($verdict['proxy_only']);
        $this->assertSame(1, $verdict['passed_count']);
        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertFalse($byId[AtlasGoalValueRealLeverageContract::DIM_SIMPLIFICATION]['passed']);
    }

    public function test_no_evidence_at_all_yields_no_dimension_passed_blocker(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([]);

        $this->assertFalse($verdict['real_leverage']);
        $this->assertSame(0, $verdict['passed_count']);
        $this->assertContains('no_dimension_passed', $verdict['blockers']);
    }

    public function test_evidence_kind_present_but_no_refs_is_blocked(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_SPEED_LEVERAGE => [
                'evidence_kind' => 'benchmark_lift',
                'evidence_refs' => [],
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertFalse($byId[AtlasGoalValueRealLeverageContract::DIM_SPEED_LEVERAGE]['passed']);
        $this->assertStringContainsString('missing_evidence_refs', $byId[AtlasGoalValueRealLeverageContract::DIM_SPEED_LEVERAGE]['reason']);
    }

    public function test_non_proxy_evidence_without_before_after_is_blocked_as_missing_outcome_delta(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_AUTONOMY_LIFT => [
                'evidence_kind' => 'autonomy_unlock',
                'evidence_refs' => ['receipt:xyz'],
                // deliberately omitting before_fact and after_fact
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $dim = $byId[AtlasGoalValueRealLeverageContract::DIM_AUTONOMY_LIFT];
        $this->assertFalse($dim['passed']);
        $this->assertStringContainsString('missing_outcome_delta', $dim['reason']);
        $this->assertFalse($verdict['real_leverage']);
    }

    public function test_real_dimension_passes_when_refs_before_and_after_fact_all_present(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_SPEED_LEVERAGE => [
                'evidence_kind' => 'benchmark_lift',
                'evidence_refs' => ['bench:run-1'],
                'before_fact' => 'p95 latency 800ms',
                'after_fact' => 'p95 latency 120ms',
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertTrue($byId[AtlasGoalValueRealLeverageContract::DIM_SPEED_LEVERAGE]['passed']);
        $this->assertTrue($verdict['real_leverage']);
        $this->assertFalse($verdict['proxy_only']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([]);
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key), 'verdict must NOT carry a hype score field');
        }
    }

    // ── structural_unlock dimension ───────────────────────────────────────────

    public function test_structural_unlock_passes_with_unlocked_downstream_lane(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK => [
                'evidence_kind' => 'lane_unblock_receipt',
                'evidence_refs' => ['receipt:unlock-1'],
                'before_fact' => 'multi-file tasks blocked behind missing planner',
                'after_fact' => 'multi-file lane now open; 5 tasks can proceed',
                'unlocked_downstream_lane' => 'multi_file_impl',
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertTrue($byId[AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK]['passed']);
        $this->assertTrue($verdict['real_leverage']);
    }

    public function test_structural_unlock_passes_with_blocked_work_removed(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK => [
                'evidence_kind' => 'dependency_elimination',
                'evidence_refs' => ['receipt:dep-rm'],
                'before_fact' => 'external provider required for X',
                'after_fact' => 'atlas-native impl; no external provider',
                'blocked_work_removed' => 'external_provider_dependency',
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertTrue($byId[AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK]['passed']);
    }

    public function test_structural_unlock_fails_without_downstream_unlock_fact(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK => [
                'evidence_kind' => 'lane_unblock_receipt',
                'evidence_refs' => ['receipt:unlock-2'],
                'before_fact' => 'queue blocked',
                'after_fact' => 'queue clear',
                // no unlocked_downstream_lane or blocked_work_removed
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $dim = $byId[AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK];
        $this->assertFalse($dim['passed']);
        $this->assertStringContainsString('missing_downstream_unlock_fact', $dim['reason']);
        $this->assertFalse($verdict['real_leverage']);
    }

    public function test_queue_count_is_rejected_proxy_evidence_kind(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK => [
                'evidence_kind' => 'queue_count',
                'evidence_refs' => ['stat:q42'],
                'before_fact' => '10 tasks',
                'after_fact' => '20 tasks',
                'unlocked_downstream_lane' => 'lane_x',
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $dim = $byId[AtlasGoalValueRealLeverageContract::DIM_STRUCTURAL_UNLOCK];
        $this->assertFalse($dim['passed']);
        $this->assertStringContainsString('rejected_proxy_kind:queue_count', $dim['reason']);
    }

    public function test_evaluation_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasGoalValueRealLeverageContract;
        $input = [
            AtlasGoalValueRealLeverageContract::DIM_CAPABILITY_LIFT => [
                'evidence_kind' => 'new_capability', 'evidence_refs' => ['r1'],
                'before_fact' => 'absent', 'after_fact' => 'present',
            ],
        ];
        $this->assertSame(json_encode($svc->evaluate($input)), json_encode($svc->evaluate($input)));
    }

    // ── AC: autonomy_gain, risk_reduction, worker_throughput, future_unlock dimension verdicts ──

    private function realEvidence(string $kind): array
    {
        return [
            'evidence_kind' => $kind,
            'evidence_refs' => ['ref:'.$kind],
            'before_fact' => 'before:'.$kind,
            'after_fact' => 'after:'.$kind,
        ];
    }

    public function test_evaluator_returns_verdicts_for_all_named_ac_dimensions(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([]);
        $ids = array_column($verdict['dimensions'], 'id');

        foreach (['autonomy_gain', 'risk_reduction', 'simplification', 'worker_throughput', 'future_unlock'] as $expected) {
            $this->assertContains($expected, $ids);
        }
        $this->assertArrayHasKey('real_leverage', $verdict);
    }

    public function test_strong_multi_dimension_leverage_across_named_dimensions(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_AUTONOMY_GAIN => $this->realEvidence('autonomy_lift_demonstrated'),
            AtlasGoalValueRealLeverageContract::DIM_RISK_REDUCTION => $this->realEvidence('failure_class_eliminated'),
            AtlasGoalValueRealLeverageContract::DIM_WORKER_THROUGHPUT => $this->realEvidence('lease_cycle_time_reduced'),
            AtlasGoalValueRealLeverageContract::DIM_FUTURE_UNLOCK => $this->realEvidence('downstream_lane_opened'),
        ]);

        $this->assertTrue($verdict['real_leverage']);
        $this->assertFalse($verdict['proxy_only']);
        $this->assertSame(4, $verdict['passed_count']);
    }

    public function test_high_risk_despite_impact_still_reports_risk_reduction_as_failed(): void
    {
        // capability_lift (impact) passes with real evidence, but risk_reduction has none —
        // overall real_leverage stays true (impact is sufficient), yet risk_reduction must
        // still surface as a failed dimension so risk is never hidden by unrelated impact.
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_CAPABILITY_LIFT => $this->realEvidence('new_capability_demonstration'),
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertTrue($verdict['real_leverage']);
        $this->assertFalse($byId[AtlasGoalValueRealLeverageContract::DIM_RISK_REDUCTION]['passed']);
        $this->assertContains('risk_reduction:no_evidence', $verdict['blockers']);
    }

    public function test_proxy_kind_on_named_dimensions_is_rejected(): void
    {
        $verdict = (new AtlasGoalValueRealLeverageContract)->evaluate([
            AtlasGoalValueRealLeverageContract::DIM_WORKER_THROUGHPUT => [
                'evidence_kind' => 'task_count',
                'evidence_refs' => ['receipt:bogus'],
            ],
        ]);

        $byId = array_column($verdict['dimensions'], null, 'id');
        $this->assertFalse($byId[AtlasGoalValueRealLeverageContract::DIM_WORKER_THROUGHPUT]['passed']);
        $this->assertFalse($verdict['real_leverage']);
    }

    public function test_named_dimensions_are_deterministic_and_reasons_are_provider_safe_strings(): void
    {
        $svc = new AtlasGoalValueRealLeverageContract;
        $input = [
            AtlasGoalValueRealLeverageContract::DIM_AUTONOMY_GAIN => $this->realEvidence('autonomy_lift_demonstrated'),
        ];
        $a = $svc->evaluate($input);
        $b = $svc->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
        foreach ($a['dimensions'] as $row) {
            $this->assertIsString($row['reason']);
            $this->assertMatchesRegularExpression('/^[a-z0-9_:.\-]+$/', $row['reason']);
        }
    }
}
