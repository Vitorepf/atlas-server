<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryReadinessStateMachine;
use PHPUnit\Framework\TestCase;

final class QualityFoundryReadinessStateMachineTest extends TestCase
{
    public function test_green_implementation_cannot_jump_to_cutover_soak_or_claims(): void
    {
        $report = (new QualityFoundryReadinessStateMachine)->evaluate([
            'implementation_complete' => true,
        ]);

        self::assertSame('implemented_not_cutover_ready', $report['implementation_state']);
        self::assertSame('implemented_not_cutover_ready', $report['cutover_state']);
        self::assertSame('implemented_not_cutover_ready', $report['highest_honest_state']);
        self::assertSame('pending', $report['temporal_state']);
        self::assertSame(['0h', '24h', '7d', '30d', '90d', '150d'], array_keys($report['temporal']['observation_schedule']));
        self::assertNull($report['temporal']['observation_schedule']['150d']['observed_at']);
        self::assertSame('world_10x_quality_proof_pending', $report['comparative_state']);
        self::assertFalse($report['quality_foundry_ready']);
        self::assertFalse($report['world_10x_quality_proven']);
        self::assertContains('cutover_gate_missing:p0_13_closed', $report['blockers']);
    }

    public function test_all_cutover_gates_are_required_and_windows_are_real_receipts(): void
    {
        $evidence = ['implementation_complete' => true];
        foreach ([
            'p0_13_closed', 'p0_19_closed', 'vertical_e2e', 'mutative_coverage_100',
            'four_manifests', 'n_minus_1_compatible', 'rollback_exercised',
            'outcome_writers_active', 'zero_known_bypass',
        ] as $gate) {
            $evidence[$gate] = true;
        }

        $report = (new QualityFoundryReadinessStateMachine)->evaluate($evidence);

        self::assertSame('cutover_ready', $report['cutover_state']);
        self::assertSame('cutover_ready', $report['highest_honest_state']);
        self::assertSame('pending', $report['temporal_state']);
        self::assertContains('temporal_window_pending:0h', $report['blockers']);
    }

    public function test_invalid_temporal_rows_and_non_rivals_claims_remain_blocked(): void
    {
        $report = (new QualityFoundryReadinessStateMachine)->evaluate([
            'implementation_complete' => true,
            'p0_13_closed' => true,
            'p0_19_closed' => true,
            'vertical_e2e' => true,
            'mutative_coverage_100' => true,
            'four_manifests' => true,
            'n_minus_1_compatible' => true,
            'rollback_exercised' => true,
            'outcome_writers_active' => true,
            'zero_known_bypass' => true,
            'observations' => ['0h' => ['observed' => true, 'receipt_hash' => 'hash']],
            'rivals_claim' => ['issuer' => 'kernel', 'level' => 'world_10x_quality_proven', 'eligible' => true],
        ]);

        self::assertSame('pending', $report['temporal_state']);
        self::assertSame('world_10x_quality_proof_pending', $report['comparative_state']);
        self::assertContains('temporal_window_pending:0h', $report['blockers']);
        self::assertContains('rivals_comparative_evidence_pending', $report['blockers']);
    }

    public function test_cutover_performed_is_never_converted_into_readiness(): void
    {
        $report = (new QualityFoundryReadinessStateMachine)->evaluate([
            'implementation_complete' => true,
            'cutover_performed' => true,
        ]);

        self::assertTrue($report['cutover_performed']);
        self::assertSame('implemented_not_cutover_ready', $report['cutover_state']);
        self::assertContains('cutover_performed_without_authorized_state_transition', $report['blockers']);
    }

    public function test_v1_removal_requires_zero_use_closed_rollback_replay_export_and_n_minus_1_clearance(): void
    {
        $machine = new QualityFoundryReadinessStateMachine;
        $blocked = $machine->evaluate([
            'v1_observed_use_count' => 0,
            'v1_rollback_window_closed' => true,
            'v1_replay_export_verified' => true,
        ]);
        self::assertSame('v1_removal_blocked', $blocked['v1_removal_state']);
        self::assertContains('v1_removal_gate_missing:n_minus_1_no_longer_needed', $blocked['blockers']);

        $ready = $machine->evaluate([
            'v1_observed_use_count' => 0,
            'v1_rollback_window_closed' => true,
            'v1_replay_export_verified' => true,
            'n_minus_1_no_longer_needed' => true,
        ]);
        self::assertSame('v1_removal_ready', $ready['v1_removal_state']);
        self::assertFalse($ready['v1_removal']['code_removal_performed']);
    }
}
