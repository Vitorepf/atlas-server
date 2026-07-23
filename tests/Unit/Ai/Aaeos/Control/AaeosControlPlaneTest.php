<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionPolicy;
use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosDifficultyLevel;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosModeToDualCoreRoute;
use App\Services\Ai\Aaeos\Control\AaeosOrgStateProjector;
use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Aaeos\Spine\AaeosSpineGate;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasSourceConnectorsAndCaptureService;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use PHPUnit\Framework\TestCase;

final class AaeosControlPlaneTest extends TestCase
{
    public function test_autonomos_cycle_is_zero_operator_and_dispatches_brain_task_path(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runAutonomosCycle('evolve atlas memory quality with proof', [], true);

        $this->assertSame(AaeosCycleRuntime::SCHEMA, $receipt['schema']);
        $this->assertSame('dispatched', $receipt['status']);
        $this->assertTrue($receipt['runtime_write_performed']);
        $this->assertTrue($receipt['dry_run']);
        $this->assertSame(AaeosExecutorMode::AUTONOMOS, $receipt['mode']['mode']);
        $this->assertFalse($receipt['human_in_engineering_loop']);
        $this->assertTrue($receipt['admission']['allows_execution']);
        $this->assertContains('atlas:brain:next', $receipt['dispatch']['operate_path']);
        $this->assertContains('atlas:task next', $receipt['dispatch']['operate_path']);
        $this->assertSame('N9', $receipt['dispatch']['spine']['delivery']);
        $this->assertSame('N11', $receipt['dispatch']['spine']['evidence']);
        $this->assertTrue($receipt['dispatch']['seed_gate_required']);
        $this->assertTrue($receipt['dispatch']['scoped_commit_required']);
    }

    public function test_interactive_intent_selects_dev_mode(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('fix login validation edge case', [
            'source' => 'human',
            'interactive' => true,
        ], [], true);

        $this->assertSame(AaeosExecutorMode::DEV, $receipt['mode']['mode']);
        $this->assertTrue($receipt['human_in_engineering_loop']);
        $this->assertTrue($receipt['elite_same_bar']);
    }

    public function test_obra_markers_select_forge_mode(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('run multi-packet obra with SDD for auth subsystem', [
            'source' => 'forge',
            'interactive' => false,
            'multi_packet' => true,
        ], [], true);

        $this->assertSame(AaeosExecutorMode::FORGE, $receipt['mode']['mode']);
        $this->assertGreaterThanOrEqual(AaeosDifficultyLevel::L4, $receipt['difficulty']['level']);
    }

    public function test_irreversible_objective_halts_sovereign(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('production wipe of billing database', [
            'source' => 'autonomos',
            'self_evolve' => true,
            'irreversible' => true,
        ], [], true);

        $this->assertSame('halted', $receipt['status']);
        $this->assertSame(AaeosAdmissionVerdict::HALT_SOVEREIGN, $receipt['admission']['verdict']);
        $this->assertFalse($receipt['admission']['allows_execution']);
    }

    public function test_world_incident_blocks_high_difficulty(): void
    {
        $policy = new AaeosAdmissionPolicy;
        $admit = $policy->admit(
            ['irreversible' => false, 'business_ambiguous' => false],
            ['level' => AaeosDifficultyLevel::L4],
            ['mode' => AaeosExecutorMode::AUTONOMOS],
            ['incident_open' => true],
        );

        $this->assertSame(AaeosAdmissionVerdict::HALT_SOVEREIGN, $admit['verdict']);
    }

    public function test_org_state_projector_is_read_only(): void
    {
        $state = (new AaeosOrgStateProjector)->project();
        $this->assertSame(AaeosOrgStateProjector::SCHEMA, $state['schema']);
        $this->assertFalse($state['runtime_write_performed']);
        $this->assertTrue($state['human_out_of_loop_default']);
        $this->assertContains(AaeosExecutorMode::AUTONOMOS, $state['executor_modes']);
    }

    public function test_engineering_spine_forbids_parallel_ledger(): void
    {
        $spine = new AaeosEngineeringSpine;
        $ok = $spine->assertShared('dev', []);
        $this->assertTrue($ok['ok']);

        $bad = $spine->assertShared('forge', [
            'delivery_runtime_class' => 'App\\Other\\PrivateDelivery',
            'parallel_ledger' => true,
        ]);
        $this->assertFalse($bad['ok']);
        $this->assertContains('delivery_runtime_must_be_shared_n9', $bad['violations']);
        $this->assertContains('parallel_ledger_forbidden', $bad['violations']);
    }

    public function test_spine_gate_stamps_contract(): void
    {
        $gate = new AaeosSpineGate;
        $stamped = $gate->stamp(['foo' => 1], AaeosExecutorMode::DEV);
        $this->assertArrayHasKey('aaeos_spine_gate', $stamped);
        $this->assertTrue($stamped['aaeos_spine_gate']['ok']);
        $this->assertSame('passed', $stamped['aaeos_spine_gate']['status']);
    }

    public function test_mode_maps_to_dualcore_route(): void
    {
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS, AaeosModeToDualCoreRoute::map(AaeosExecutorMode::AUTONOMOS));
        $this->assertContains(DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS, DualCoreRouteDecisionCanon::ROUTES);
    }

    public function test_learning_candidate_pending_review_on_halt(): void
    {
        $runtime = new AaeosCycleRuntime;
        $receipt = $runtime->runCycle('production wipe', ['irreversible' => true, 'source' => 'autonomos'], [], true);
        $learning = (new AaeosCycleOutcomeRecorder)->record($receipt);
        $this->assertSame('pending_review', $learning['status']);
        $this->assertFalse($learning['auto_promoted']);
    }

    public function test_scorecard_composite_structure(): void
    {
        $card = (new AaeosScorecardProjector)->project([
            'operate_path_wiring' => 9.2,
            'spine_enforced' => 9.2,
            'antifragile_loop' => 9.0,
        ]);
        $this->assertArrayHasKey('composite', $card);
        $this->assertArrayHasKey('dimensions', $card);
        $this->assertSame(0, $card['quarantine_production_imports']);
    }

    public function test_source_connectors_live_in_brain_not_quarantine(): void
    {
        $this->assertSame(
            'App\\Services\\Ai\\AutonomousEvolution\\Brain\\AtlasSourceConnectorsAndCaptureService',
            AtlasSourceConnectorsAndCaptureService::class,
        );
        $this->assertSame('atlas.evidence_source.v1', AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA);

        $ref = new \ReflectionClass(AtlasSourceConnectorsAndCaptureService::class);
        $path = $ref->getFileName();
        $this->assertIsString($path);
        $this->assertStringContainsString('/AutonomousEvolution/Brain/', $path);
        $this->assertStringNotContainsString('/Aaeos/Quarantine/', $path);
    }

    public function test_aaeos_tree_is_control_and_spine_only(): void
    {
        $card = (new AaeosScorecardProjector)->project();
        $this->assertTrue($card['aaeos_tree']['pure']);
        $this->assertSame(0, $card['orphan_generated_tests']);
        $this->assertSame([], $card['aaeos_tree']['foreign']);
    }
}
