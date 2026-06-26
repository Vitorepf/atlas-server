<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneCycleHorizonAnalyzer;
use Tests\TestCase;

class AgentControlPlaneCycleHorizonAnalyzerTest extends TestCase
{
    public function test_cycle_integrity_ok_for_unknown_pointer(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'unknown_pointer');

        self::assertSame('ok', $result['status']);
        self::assertFalse($result['cycle_detected']);
        self::assertFalse($result['intentional_reentry_detected']);
        self::assertTrue($result['cycle_ok']);
    }

    public function test_cycle_integrity_detects_intentional_reentry(): void
    {
        $pointer = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], $pointer);

        self::assertTrue($result['intentional_reentry_detected']);
        self::assertTrue($result['cycle_detected']);
        self::assertSame($pointer, $result['intentional_reentry_activate_key']);
    }

    public function test_cycle_integrity_detects_unintentional_regression(): void
    {
        $pointer = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_contract';
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], $pointer);

        self::assertSame('blocked', $result['status']);
        self::assertTrue($result['unintentional_cycle_detected']);
        self::assertFalse($result['cycle_ok']);
    }

    public function test_cycle_integrity_detects_repeated_slices(): void
    {
        $deepChain = [
            ['slice_key' => 'a', 'activate_key' => 'ak_a'],
            ['slice_key' => 'a', 'activate_key' => 'ak_a'],
        ];

        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity($deepChain, 'unknown');

        self::assertCount(1, $result['repeated_slice_families']);
        self::assertSame('a', $result['repeated_slice_families'][0]['slice_key']);
    }

    public function test_cycle_integrity_has_terminal_horizons(): void
    {
        $result = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'unknown');

        self::assertNotEmpty($result['terminal_horizons']);
        self::assertNotEmpty($result['terminal_horizon']);
    }

    public function test_cycle_integrity_is_deterministic(): void
    {
        $a = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'x');
        $b = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'x');

        self::assertSame($a, $b);
    }

    public function test_terminal_horizon_blocked_unknown(): void
    {
        $cycle = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'unknown');
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis([], 'unknown', $cycle);

        self::assertSame('blocked_unknown', $result['horizon_type']);
        self::assertFalse($result['horizon_ok']);
    }

    public function test_terminal_horizon_intentional_reentry(): void
    {
        $pointer = 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';
        $cycle = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], $pointer);
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis([], $pointer, $cycle);

        self::assertSame('intentional_reentry', $result['horizon_type']);
        self::assertTrue($result['horizon_ok']);
        self::assertSame('reentry_into_post_start_evidence_corridor', $result['next_safe_macro_batch']);
    }

    public function test_terminal_horizon_linear_next(): void
    {
        $deepChain = [
            ['slice_key' => 'a', 'activate_key' => 'ak_a'],
            ['slice_key' => 'b', 'activate_key' => 'ak_b'],
        ];

        $cycle = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity($deepChain, 'ak_a');
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis($deepChain, 'ak_a', $cycle);

        self::assertSame('linear_next', $result['horizon_type']);
        self::assertTrue($result['horizon_ok']);
        self::assertSame('ak_b', $result['next_safe_macro_batch']);
        self::assertCount(1, $result['remaining_known_slices_after_horizon']);
    }

    public function test_terminal_horizon_runtime_migration(): void
    {
        $cycle = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'unknown');
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis([], 'apply_agent_control_plane_runtime_schema_migration', $cycle);

        self::assertSame('terminal_runtime_gate', $result['horizon_type']);
        self::assertTrue($result['horizon_ok']);
    }

    public function test_terminal_horizon_never_allows_completion(): void
    {
        $cycle = AgentControlPlaneCycleHorizonAnalyzer::cycleIntegrity([], 'unknown');
        $result = AgentControlPlaneCycleHorizonAnalyzer::terminalHorizonAnalysis([], 'unknown', $cycle);

        self::assertFalse($result['completion_claim_allowed']);
    }
}
