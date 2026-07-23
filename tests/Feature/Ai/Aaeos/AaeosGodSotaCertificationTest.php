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
    public function test_autonomos_cycle_zero_human_same_bar(): void
    {
        $receipt = app(AaeosCycleRuntime::class)->runAutonomosCycle('certify', [], true);
        $this->assertSame(AaeosExecutorMode::AUTONOMOS, $receipt['mode']['mode']);
        $this->assertFalse($receipt['human_in_engineering_loop']);
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

    public function test_scorecard_god_sota(): void
    {
        $card = app(AaeosScorecardProjector::class)->project([
            'operate_path_wiring' => 9.2,
            'spine_enforced' => 9.2,
            'antifragile_loop' => 9.0,
        ]);
        $this->assertGreaterThanOrEqual(9.0, $card['composite']);
        $this->assertTrue($card['god_sota']);
        $this->assertSame(0, $card['quarantine_production_imports']);
    }
}
