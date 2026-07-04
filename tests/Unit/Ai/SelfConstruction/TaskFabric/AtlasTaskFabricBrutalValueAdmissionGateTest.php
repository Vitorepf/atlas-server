<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricBrutalValueAdmissionGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricBrutalValueAdmissionGateTest extends TestCase
{
    private AtlasTaskFabricBrutalValueAdmissionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasTaskFabricBrutalValueAdmissionGate();
    }

    // AC 2: plausible but isolated convenience task → reject as low_compound_impact
    public function test_isolated_convenience_rejected(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Add a convenience helper',
            'impact_signals' => ['is_isolated_convenience' => true],
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => true],
        ]);

        $this->assertSame('reject', $result['verdict']);
        $this->assertTrue(
            count(array_filter($result['reasons'], fn ($r) => str_contains($r, 'low_compound_impact'))) > 0
        );
    }

    // AC 3: task that reduces give_back risk + has runnable proof → admitted
    public function test_reduces_give_back_risk_with_proof_admitted(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Fix give_back storm in Brain family',
            'impact_signals' => [
                'reduces_give_back_risk' => true,
                'unblocks_dependent_family' => true,
            ],
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => true, 'proof_type' => 'unit_test'],
        ]);

        $this->assertSame('admit', $result['verdict']);
    }

    // AC 4: proxy observability tasks rejected unless they change a downstream decision
    public function test_proxy_without_decision_change_rejected(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Add a dashboard metric',
            'impact_signals' => ['reduces_give_back_risk' => true],
            'is_proxy_observability' => true,
            'changes_downstream_decision' => false,
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => true],
        ]);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('proxy_observability_without_decision_change', $result['reasons']);
    }

    public function test_proxy_with_decision_change_admitted(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Gate that changes worker assignment',
            'impact_signals' => ['reduces_give_back_risk' => true],
            'is_proxy_observability' => true,
            'changes_downstream_decision' => true,
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => true],
        ]);

        $this->assertSame('admit', $result['verdict']);
    }

    public function test_no_positive_signals_rejected(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Do something',
            'impact_signals' => [],
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => true],
        ]);

        $this->assertSame('reject', $result['verdict']);
    }

    public function test_not_self_sufficient_rejected(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Depends on external state',
            'impact_signals' => ['reduces_give_back_risk' => true],
            'implementability' => ['self_sufficient' => false],
            'proof' => ['has_runnable_proof' => true],
        ]);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('not_self_sufficient', $result['reasons']);
    }

    public function test_no_runnable_proof_rejected(): void
    {
        $result = $this->gate->evaluate([
            'objective' => 'Important change',
            'impact_signals' => ['reduces_give_back_risk' => true],
            'implementability' => ['self_sufficient' => true],
            'proof' => ['has_runnable_proof' => false],
        ]);

        $this->assertSame('reject', $result['verdict']);
        $this->assertContains('no_runnable_proof', $result['reasons']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // decide() — proof_floor check (AC2)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_high_compound_impact_without_proof_floor_rejected(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Design a completely new architecture for the entire brain evolution loop that unlocks massive downstream throughput.',
            'compound_impact_score' => 0.50,   // >= 0.30 floor
            'allowed_files' => ['app/Services/Ai/NewArch/NewArchService.php', 'tests/Unit/Ai/NewArch/NewArchServiceTest.php'],
            'runnable_acceptance' => true,
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertContains(
            'proof_floor_deficiency:compound_impact_above_floor_without_proof_or_evidence_floor',
            $result['rejection_reasons'],
        );
    }

    public function test_high_compound_impact_with_proof_floor_admitted(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Design a completely new architecture for the entire brain evolution loop that unlocks massive downstream throughput.',
            'compound_impact_score' => 0.50,
            'proof_floor' => ['proven_behavior_change', 'three_independent_proofs'],
            'allowed_files' => ['app/Services/Ai/NewArch/NewArchService.php', 'tests/Unit/Ai/NewArch/NewArchServiceTest.php'],
            'runnable_acceptance' => true,
        ]);

        $this->assertTrue($result['admitted']);
        $reasons = implode(' ', $result['rejection_reasons'] ?? []);
        $this->assertStringNotContainsString('proof_floor_deficiency', $reasons);
    }

    public function test_high_compound_impact_with_evidence_floor_admitted(): void
    {
        // evidence_floor (not proof_floor) also satisfies the requirement.
        $result = $this->gate->decide([
            'objective' => 'Design a completely new architecture for the entire brain evolution loop that unlocks massive downstream throughput.',
            'compound_impact_score' => 0.50,
            'evidence_floor' => 0.8,
            'allowed_files' => ['app/Services/Ai/NewArch/NewArchService.php', 'tests/Unit/Ai/NewArch/NewArchServiceTest.php'],
            'runnable_acceptance' => true,
        ]);

        $this->assertTrue($result['admitted']);
    }

    public function test_low_compound_impact_not_affected_by_missing_proof_floor(): void
    {
        // impact below floor → rejected as compound_impact_low, not proof_floor_deficiency.
        $result = $this->gate->decide([
            'objective' => 'Make a small tweak that has minor positive effect but not a major architecture shift.',
            'compound_impact_score' => 0.10,   // < 0.30 floor
            'allowed_files' => ['app/Services/Ai/Tweak/TweakService.php', 'tests/Unit/Ai/Tweak/TweakServiceTest.php'],
            'runnable_acceptance' => true,
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertContains('compound_impact_low', $result['rejection_reasons']);
        $reasons = implode(' ', $result['rejection_reasons']);
        $this->assertStringNotContainsString('proof_floor_deficiency', $reasons);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // decide() — worker_continuity exception (AC3)
    // ═══════════════════════════════════════════════════════════════════════

    public function test_worker_continuity_with_impl_test_and_runnable_is_admitted(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Worker replenish feed: normal maintenance follow-up with concrete file targets and runnable acceptance.',
            'compound_impact_score' => 0.20,   // below 0.30 floor
            'allowed_files' => ['app/Services/Ai/Feed/ReplenishService.php', 'tests/Unit/Ai/Feed/ReplenishServiceTest.php'],
            'runnable_acceptance' => true,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
        ]);

        $this->assertTrue($result['admitted'], 'worker_continuity with impl+test+runnable must be admitted');
        $this->assertTrue($result['admitted_via_worker_continuity_exception']);
    }

    public function test_worker_continuity_without_impl_is_rejected(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Worker replenish feed that only touches test files.',
            'compound_impact_score' => 0.20,
            'allowed_files' => ['tests/Unit/Ai/Feed/SomeTest.php'],
            'runnable_acceptance' => true,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
        ]);

        $this->assertFalse($result['admitted'], 'worker_continuity without impl file must be rejected');
        $this->assertContains('implementability_weak:no_implementation_file', $result['rejection_reasons']);
    }

    public function test_worker_continuity_without_test_is_rejected(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Worker replenish feed that only touches implementation without test coverage.',
            'compound_impact_score' => 0.20,
            'allowed_files' => ['app/Services/Ai/Feed/ReplenishService.php'],
            'runnable_acceptance' => true,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
        ]);

        $this->assertFalse($result['admitted'], 'worker_continuity without test file must be rejected');
        $this->assertContains('implementability_weak:no_test_file', $result['rejection_reasons']);
    }

    public function test_worker_continuity_without_runnable_acceptance_is_rejected(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Worker replenish feed with no runnable acceptance proof.',
            'compound_impact_score' => 0.20,
            'allowed_files' => ['app/Services/Ai/Feed/ReplenishService.php', 'tests/Unit/Ai/Feed/ReplenishServiceTest.php'],
            'runnable_acceptance' => false,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
        ]);

        $this->assertFalse($result['admitted'], 'worker_continuity without runnable_acceptance must be rejected');
        $this->assertContains('compound_impact_low', $result['rejection_reasons']);
    }

    public function test_worker_continuity_template_farm_rejected_before_exception(): void
    {
        // Template farm + duplicate checks happen before the worker continuity exception.
        $result = $this->gate->decide([
            'objective' => 'Short',  // less than MIN_OBJECTIVE_CHARS (30) → template_farm
            'compound_impact_score' => 0.20,
            'allowed_files' => ['app/Services/Ai/Feed/ReplenishService.php', 'tests/Unit/Ai/Feed/ReplenishServiceTest.php'],
            'runnable_acceptance' => true,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
        ]);

        $this->assertFalse($result['admitted'], 'template_farm must be rejected even with worker_continuity');
        $this->assertContains('template_farm', $result['rejection_reasons']);
    }

    public function test_worker_continuity_duplicate_target_rejected_before_exception(): void
    {
        $result = $this->gate->decide([
            'objective' => 'Worker replenish feed with concrete file targets and runnable acceptance for the ongoing session.',
            'target' => 'app/Services/Ai/Feed/ReplenishService.php',
            'compound_impact_score' => 0.20,
            'allowed_files' => ['app/Services/Ai/Feed/ReplenishService.php', 'tests/Unit/Ai/Feed/ReplenishServiceTest.php'],
            'runnable_acceptance' => true,
            'worker_floor_context' => true,
            'impact_reason' => 'worker_continuity',
            'known_targets' => ['app/Services/Ai/Feed/ReplenishService.php'],
        ]);

        $this->assertFalse($result['admitted'], 'semantic duplicate must be rejected even with worker_continuity');
        $this->assertContains('semantic_duplicate', $result['rejection_reasons']);
    }
}
