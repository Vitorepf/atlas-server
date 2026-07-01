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
}
