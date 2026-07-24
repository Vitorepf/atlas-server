<?php

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use App\Services\Ai\Aaeos\Spine\AaeosSpineGate;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\Programming\ProgrammingSurfaceContractFactory;
use Tests\TestCase;

class AaeosGodSotaCertificationTest extends TestCase
{
    public function test_autonomos_cycle_same_bar_does_not_emit_a_human_loop_gate(): void
    {
        $receipt = app(AaeosCycleRuntime::class)->runAutonomosCycle('certify', [], true);
        $this->assertSame(AaeosExecutorMode::AUTONOMOS, $receipt['mode']['mode']);
        $this->assertArrayNotHasKey('human_in_engineering_loop', $receipt);
        $this->assertTrue($receipt['elite_same_bar']);
    }

    public function test_irreversible_halts(): void
    {
        $receipt = app(AaeosCycleRuntime::class)->runCycle('production wipe', [
            'irreversible' => true,
            'source' => 'autonomos',
        ], [], true);
        $this->assertSame('halted', $receipt['status']);
        $this->assertSame(AaeosAdmissionVerdict::HALT_SOVEREIGN, $receipt['admission']['verdict']);
    }

    public function test_spine_parallel_ledger_fails(): void
    {
        $gate = app(AaeosSpineGate::class)->evaluate('forge', [
            'delivery_runtime_class' => 'App\\Evil\\X',
            'parallel_ledger' => true,
        ]);
        $this->assertFalse($gate['ok']);
    }

    public function test_dualcore_has_autonomos_route_and_evidence_defaults(): void
    {
        $this->assertContains(
            DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS,
            DualCoreRouteDecisionCanon::ROUTES,
        );
        $evidence = DualCoreRouteDecisionCanon::defaultEvidenceRequired(
            DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS,
        );
        $this->assertContains('scoped_commit', $evidence);
        $this->assertFalse(
            DualCoreRouteDecisionCanon::defaultSddRequired(DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS),
        );
    }

    public function test_dev_and_forge_contracts_carry_spine_gate(): void
    {
        $factory = app(ProgrammingSurfaceContractFactory::class);
        $dev = $factory->chatDev(null, ['operator_intent' => ['kind' => 'repair']], null);
        $this->assertArrayHasKey('aaeos_spine_gate', $dev);
        $this->assertTrue($dev['aaeos_spine_gate']['ok']);

        $forge = $factory->forge([], ['status' => 'ok']);
        $this->assertArrayHasKey('aaeos_spine_gate', $forge);
        $this->assertTrue($forge['aaeos_spine_gate']['ok']);
    }

    public function test_empty_scorecard_does_not_claim_god_sota(): void
    {
        $card = app(AaeosScorecardProjector::class)->project();
        $this->assertNull($card['composite']);
        $this->assertFalse($card['god_sota']);
        $this->assertSame(0, $card['quarantine_production_imports']);
    }
}
