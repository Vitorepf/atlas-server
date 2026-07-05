<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneCycleHorizonAnalyzer;
use Tests\TestCase;

final class AgentControlPlaneCycleHorizonAnalyzerTest extends TestCase
{
    public function test_cycle_integrity_ok_for_unknown_pointer(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity(
            deepChain: [],
            currentNextRequiredSlice: 'some-pointer',
        );

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['cycle_detected']);
    }

    public function test_cycle_integrity_detects_intentional_reentry(): void
    {
        $activateKey = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity(
            deepChain: [],
            currentNextRequiredSlice: $activateKey,
        );

        $this->assertTrue($result['intentional_reentry_detected']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['cycle_detected']);
    }

    public function test_cycle_integrity_detects_unintentional_regression(): void
    {
        // Use a key that IS in $previouslyCertifiedActivateKeys but NOT in
        // $intentionalReentryActivateKeys (the operator_handoff key).
        $previouslyCertified = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_contract';
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity(
            deepChain: [],
            currentNextRequiredSlice: $previouslyCertified,
        );

        $this->assertTrue($result['unintentional_cycle_detected']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_cycle_integrity_detects_repeated_slices(): void
    {
        $deepChain = [
            ['slice_key' => 's1', 'activate_key' => 'a1'],
            ['slice_key' => 's1', 'activate_key' => 'a1'],
        ];

        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity(
            deepChain: $deepChain,
            currentNextRequiredSlice: 'something-else',
        );

        $this->assertSame('warning', $result['status']);
    }

    public function test_cycle_integrity_has_terminal_horizons(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity(
            deepChain: [],
            currentNextRequiredSlice: 'some-pointer',
        );

        $this->assertGreaterThan(20, count($result['terminal_horizons']));
        $this->assertNotNull($result['terminal_horizon']);
    }

    public function test_cycle_integrity_is_deterministic(): void
    {
        $a = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'some-pointer');
        $b = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'some-pointer');

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_terminal_horizon_blocked_unknown(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis(
            deepChain: [],
            currentNextRequiredSlice: 'unknown-horizon',
            cycleIntegrity: ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        $this->assertSame('blocked_unknown', $result['horizon_type']);
        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_terminal_horizon_intentional_reentry(): void
    {
        $activateKey = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';
        $cycleIntegrity = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], $activateKey);

        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis(
            deepChain: [],
            currentNextRequiredSlice: $activateKey,
            cycleIntegrity: $cycleIntegrity,
        );

        $this->assertTrue($result['horizon_ok']);
        $this->assertSame('intentional_reentry', $result['horizon_type']);
    }

    public function test_terminal_horizon_linear_next(): void
    {
        $deepChain = [
            ['slice_key' => 's1', 'activate_key' => 'ak-1'],
            ['slice_key' => 's2', 'activate_key' => 'ak-2'],
        ];

        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis(
            deepChain: $deepChain,
            currentNextRequiredSlice: 'ak-1',
            cycleIntegrity: ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        $this->assertSame('linear_next', $result['horizon_type']);
        $this->assertTrue($result['horizon_ok']);
    }

    public function test_terminal_horizon_runtime_migration(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis(
            deepChain: [],
            currentNextRequiredSlice: 'apply_agent_control_plane_runtime_schema_migration',
            cycleIntegrity: ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        $this->assertSame('terminal_runtime_gate', $result['horizon_type']);
        $this->assertTrue($result['horizon_ok']);
    }

    public function test_terminal_horizon_never_allows_completion(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis(
            deepChain: [],
            currentNextRequiredSlice: 'some-pointer',
            cycleIntegrity: ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        $this->assertFalse($result['completion_claim_allowed']);
    }

    public function test_dead_end_chain_is_classified_as_dead_end(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::analyzeChainHorizon([
            ['task_id' => 'T1', 'status' => 'blocked', 'family' => 'x'],
            ['task_id' => 'T2', 'status' => 'quarantined', 'family' => 'y'],
        ]);

        $this->assertTrue($result['dead_end']);
        $this->assertSame('dead_end', $result['classification']);
        $this->assertEmpty($result['servable_descendants']);
    }

    public function test_repeated_give_back_families_are_surfaced_as_poison_family_risk(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::analyzeChainHorizon([
            ['task_id' => 'T1', 'status' => 'give_back', 'family' => 'scope_repair_doomed'],
            ['task_id' => 'T2', 'status' => 'give_back', 'family' => 'scope_repair_doomed'],
        ]);

        $this->assertCount(1, $result['poison_family_risk']);
        $this->assertSame('scope_repair_doomed', $result['poison_family_risk'][0]['family']);
    }

    public function test_healthy_chain_with_servable_descendant_is_not_flagged_as_dead_end(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::analyzeChainHorizon([
            ['task_id' => 't-queued', 'status' => 'queued', 'family' => 'main'],
            ['task_id' => 't-blocked', 'status' => 'blocked', 'family' => 'main'],
        ]);

        $this->assertFalse($result['dead_end']);
        $this->assertSame('healthy', $result['classification']);
        $this->assertContains('t-queued', $result['servable_descendants']);
    }

    public function test_analyze_chain_horizon_is_deterministic_and_emits_repair_hints_array(): void
    {
        $packets = [
            ['task_id' => 'T1', 'status' => 'queued', 'family' => 'family-a'],
        ];

        $a = AgentControlPlaneCycleHorizonAnalyzer::analyzeChainHorizon($packets);
        $b = AgentControlPlaneCycleHorizonAnalyzer::analyzeChainHorizon($packets);

        self::assertSame(json_encode($a), json_encode($b));
        self::assertArrayHasKey('repair_hints', $a);
    }

    // --- computeTerminalCycleHorizon ---------------------------------

    private function baseDeepChain(): array
    {
        return [
            ['slice_key' => 's1', 'activate_key' => 'ak-1'],
            ['slice_key' => 's2', 'activate_key' => 'ak-2'],
            ['slice_key' => 's3', 'activate_key' => 'ak-3'],
        ];
    }

    public function test_compute_terminal_cycle_horizon_returns_hold_when_pointer_unknown(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(),
            'unknown-activate-key',
            ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        self::assertSame('hold', $result['horizon_status']);
        self::assertArrayHasKey('horizon_reason', $result);
        self::assertArrayHasKey('current_next_required_slice', $result);
        self::assertArrayHasKey('repair_action', $result);
    }

    public function test_compute_terminal_cycle_horizon_returns_hold_for_runtime_migration(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(),
            'apply_agent_control_plane_runtime_schema_migration',
            ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        self::assertSame('hold', $result['horizon_status']);
        self::assertSame('apply_runtime_schema_migration', $result['repair_action']);
    }

    public function test_compute_terminal_cycle_horizon_returns_advance_for_clean_linear_next(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(),
            'ak-2',
            ['cycle_warnings' => [], 'unintentional_cycle_detected' => false],
        );

        self::assertSame('advance', $result['horizon_status']);
        self::assertSame('', $result['repair_action']);
    }

    public function test_compute_terminal_cycle_horizon_returns_collect_proof_when_cycle_warnings_exist(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(),
            'ak-2',
            [
                'cycle_warnings' => [['code' => 'repeated_slice_family_detected']],
                'unintentional_cycle_detected' => false,
            ],
        );

        self::assertSame('collect_proof', $result['horizon_status']);
        self::assertStringContainsString('cycle_warnings', $result['repair_action']);
    }

    public function test_compute_terminal_cycle_horizon_returns_collect_proof_when_unintentional_cycle(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(),
            'ak-2',
            [
                'cycle_warnings' => [],
                'unintentional_cycle_detected' => true,
            ],
        );

        self::assertSame('collect_proof', $result['horizon_status']);
    }

    public function test_compute_terminal_cycle_horizon_is_deterministic(): void
    {
        $a = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(), 'unknown-key', [],
        );
        $b = AgentControlPlaneCycleHorizonAnalyzer::computeTerminalCycleHorizon(
            $this->baseDeepChain(), 'unknown-key', [],
        );

        self::assertSame($a, $b);
    }
}
