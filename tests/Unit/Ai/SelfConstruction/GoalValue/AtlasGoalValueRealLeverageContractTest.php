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
}
