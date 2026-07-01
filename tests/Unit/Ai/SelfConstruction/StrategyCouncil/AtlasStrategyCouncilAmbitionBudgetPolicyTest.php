<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAmbitionBudgetPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasStrategyCouncilAmbitionBudgetPolicy: high leverage + low/med risk + execute_continuous +
 * all deps ⇒ bold; execute_guarded ⇒ standard; propose/observe ⇒ narrow; missing evidence ⇒ hold;
 * budget exceeded ⇒ hold; risk_class above autonomy_mode ⇒ hold.
 */
final class AtlasStrategyCouncilAmbitionBudgetPolicyTest extends TestCase
{
    private function readyFacts(string $mode = 'execute_continuous'): array
    {
        return [
            'leverage_rank' => 'high',
            'risk_class' => 'low',
            'available_budget_units' => 100,
            'required_budget_units' => 10,
            'autonomy_mode' => $mode,
            'dependency_readiness' => ['verification' => true, 'rollback' => true, 'knowledge_sync' => true],
            'evidence_present' => true,
        ];
    }

    public function test_bold_when_high_leverage_low_risk_continuous_and_all_deps_ready(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($this->readyFacts());
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    public function test_standard_when_execute_guarded_only(): void
    {
        $f = $this->readyFacts('execute_guarded');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD, $r['ambition_level']);
    }

    public function test_narrow_when_propose_or_observe_mode(): void
    {
        $f = $this->readyFacts('propose');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_NARROW, $r['ambition_level']);
    }

    public function test_hold_when_evidence_missing(): void
    {
        $f = $this->readyFacts();
        $f['evidence_present'] = false;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:evidence_missing', $r['reasons']);
    }

    public function test_hold_when_budget_exceeded(): void
    {
        $f = $this->readyFacts();
        $f['available_budget_units'] = 5;
        $f['required_budget_units'] = 50;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:budget_exceeded', $r['reasons']);
    }

    public function test_hold_when_risk_high_but_mode_only_propose(): void
    {
        $f = $this->readyFacts('propose');
        $f['risk_class'] = 'high';
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:risk_exceeds_mode', $r['reasons']);
    }

    public function test_hold_when_autonomy_disabled(): void
    {
        $f = $this->readyFacts('disabled');
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        $this->assertContains('hold:autonomy_disabled', $r['reasons']);
    }

    public function test_bold_blocked_when_any_dependency_not_ready(): void
    {
        $f = $this->readyFacts();
        $f['dependency_readiness']['knowledge_sync'] = false;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);
        // Not bold — falls to standard because mode=execute_continuous is still valid.
        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_STANDARD, $r['ambition_level']);
    }

    public function test_allocate_balanced_budget_distributes_evenly_across_lanes(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->allocate([
            'total_budget_units' => 100,
            'quality_score' => 9,
            'bugfix_critical' => false,
        ]);

        $this->assertTrue($r['quality_floor_met']);
        $this->assertSame(20, $r['lanes']['bugfix']);
        $this->assertSame(20, $r['lanes']['capability']);
        $this->assertSame(20, $r['lanes']['refactor']);
        $this->assertSame(20, $r['lanes']['proof']);
        $this->assertSame(20, $r['lanes']['expansion']);
        $this->assertContains('balanced:even_distribution', $r['reasons']);
    }

    public function test_allocate_bugfix_emergency_override_takes_all_budget(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->allocate([
            'total_budget_units' => 80,
            'quality_score' => 9,
            'bugfix_critical' => true,
        ]);

        $this->assertSame(80, $r['lanes']['bugfix']);
        $this->assertSame(0, $r['lanes']['capability']);
        $this->assertSame(0, $r['lanes']['refactor']);
        $this->assertSame(0, $r['lanes']['proof']);
        $this->assertSame(0, $r['lanes']['expansion']);
        $this->assertContains('bugfix_emergency:all_budget_to_bugfix', $r['reasons']);
    }

    public function test_allocate_expansion_refused_when_quality_floor_not_met(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->allocate([
            'total_budget_units' => 90,
            'quality_score' => AtlasStrategyCouncilAmbitionBudgetPolicy::QUALITY_FLOOR_THRESHOLD - 1,
            'bugfix_critical' => false,
        ]);

        $this->assertSame(0, $r['lanes']['expansion']);
        $this->assertSame(0, $r['lanes']['capability']);
        $this->assertGreaterThan(0, $r['lanes']['bugfix']);
        $this->assertGreaterThan(0, $r['lanes']['refactor']);
        $this->assertGreaterThan(0, $r['lanes']['proof']);
        $this->assertFalse($r['quality_floor_met']);
        $this->assertContains('quality_floor:expansion_refused', $r['reasons']);
        $this->assertContains('quality_floor:capability_refused_repair_refactor_proof_reserved', $r['reasons']);
    }

    public function test_allocate_quality_floor_met_flag_matches_threshold(): void
    {
        $policy = new AtlasStrategyCouncilAmbitionBudgetPolicy;

        $below = $policy->allocate(['total_budget_units' => 40, 'quality_score' => AtlasStrategyCouncilAmbitionBudgetPolicy::QUALITY_FLOOR_THRESHOLD - 1]);
        $this->assertFalse($below['quality_floor_met']);

        $at = $policy->allocate(['total_budget_units' => 40, 'quality_score' => AtlasStrategyCouncilAmbitionBudgetPolicy::QUALITY_FLOOR_THRESHOLD]);
        $this->assertTrue($at['quality_floor_met']);
    }

    public function test_allocate_reasons_are_deterministic_for_same_input(): void
    {
        $policy = new AtlasStrategyCouncilAmbitionBudgetPolicy;
        $facts = ['total_budget_units' => 60, 'quality_score' => 9, 'bugfix_critical' => false];

        $a = $policy->allocate($facts);
        $b = $policy->allocate($facts);

        $this->assertSame($a['reasons'], $b['reasons']);
        $this->assertSame($a['lanes'], $b['lanes']);
    }

    // ── AC: weak queue/worker/context facts force hold or narrow even at high leverage ──

    public function test_hold_when_queue_health_is_weak_despite_high_leverage(): void
    {
        $f = $this->readyFacts();
        $f['queue_health'] = 0.2;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:queue_health_weak', $r['reasons']);
    }

    public function test_hold_when_worker_throughput_is_weak(): void
    {
        $f = $this->readyFacts();
        $f['worker_throughput'] = 0.1;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:worker_throughput_weak', $r['reasons']);
    }

    public function test_hold_when_context_is_stale(): void
    {
        $f = $this->readyFacts();
        $f['context_fresh'] = false;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_HOLD, $r['ambition_level']);
        $this->assertContains('hold:context_stale', $r['reasons']);
    }

    // ── AC: bold requires strong proof coverage, dedup state, and worker capacity ────────

    public function test_bold_blocked_when_proof_coverage_is_weak(): void
    {
        $f = $this->readyFacts();
        $f['proof_coverage'] = 0.3;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertNotSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    public function test_bold_blocked_when_dedup_state_is_not_clean(): void
    {
        $f = $this->readyFacts();
        $f['dedup_state'] = 'duplicates_present';
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertNotSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    public function test_bold_blocked_when_worker_capacity_is_weak(): void
    {
        $f = $this->readyFacts();
        $f['worker_capacity'] = 0.2;
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertNotSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    public function test_bold_when_all_strong_signals_present(): void
    {
        $f = array_merge($this->readyFacts(), [
            'queue_health' => 1.0,
            'worker_throughput' => 1.0,
            'context_fresh' => true,
            'proof_coverage' => 0.95,
            'dedup_state' => 'clean',
            'worker_capacity' => 0.9,
        ]);
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->decide($f);

        $this->assertSame(AtlasStrategyCouncilAmbitionBudgetPolicy::LEVEL_BOLD, $r['ambition_level']);
    }

    // ── AC: allocateBudgetSlices() emits research/refactor/task_fabric/proof/knowledge_sync ──

    public function test_allocate_budget_slices_emits_five_named_lanes(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->allocateBudgetSlices(['total_budget_units' => 100]);

        foreach (['research', 'refactor', 'task_fabric', 'proof', 'knowledge_sync'] as $lane) {
            $this->assertArrayHasKey($lane, $r['lanes']);
        }
        $this->assertSame(20, $r['lanes']['research']);
    }

    public function test_allocate_budget_slices_redirects_farmed_lane_to_proof(): void
    {
        $r = (new AtlasStrategyCouncilAmbitionBudgetPolicy)->allocateBudgetSlices([
            'total_budget_units' => 100,
            'farmed_lanes' => ['research'],
        ]);

        $this->assertSame(0, $r['lanes']['research']);
        $this->assertSame(40, $r['lanes']['proof']);
        $this->assertContains('research', $r['farmed_lanes_redirected']);
    }
}
