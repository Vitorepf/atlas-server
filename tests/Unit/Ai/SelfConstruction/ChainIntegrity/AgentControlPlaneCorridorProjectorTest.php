<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneCorridorProjector;
use Tests\TestCase;

class AgentControlPlaneCorridorProjectorTest extends TestCase
{
    public function test_all_corridor_slices_ok_returns_true_when_all_ok(): void
    {
        $sliceStatus = ['a' => ['all_artifacts_ok' => true], 'b' => ['all_artifacts_ok' => true]];
        self::assertTrue(AgentControlPlaneCorridorProjector::allCorridorSlicesOk(['a', 'b'], $sliceStatus));
    }

    public function test_all_corridor_slices_ok_returns_false_when_missing(): void
    {
        $sliceStatus = ['a' => ['all_artifacts_ok' => true]];
        self::assertFalse(AgentControlPlaneCorridorProjector::allCorridorSlicesOk(['a', 'b'], $sliceStatus));
    }

    public function test_all_corridor_slices_ok_returns_false_when_not_ok(): void
    {
        $sliceStatus = ['a' => ['all_artifacts_ok' => true], 'b' => ['all_artifacts_ok' => false]];
        self::assertFalse(AgentControlPlaneCorridorProjector::allCorridorSlicesOk(['a', 'b'], $sliceStatus));
    }

    public function test_all_corridor_slices_ok_returns_true_for_empty_list(): void
    {
        self::assertTrue(AgentControlPlaneCorridorProjector::allCorridorSlicesOk([], []));
    }

    public function test_post_start_evidence_corridor_empty_reports(): void
    {
        $result = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'irrelevant');

        self::assertFalse($result['evidence_to_dispatch_chain_ok']);
        self::assertNotEmpty($result['violations']);
        self::assertSame(8, $result['corridor_total_count']);
        self::assertSame(0, $result['corridor_ok_count']);
    }

    public function test_post_start_evidence_corridor_all_ok(): void
    {
        $keys = [
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff',
            'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor',
        ];
        $reports = array_map(fn (string $k): array => ['slice_key' => $k, 'ok' => true, 'checks' => []], $keys);

        $result = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor($reports, 'test');

        self::assertTrue($result['evidence_to_dispatch_chain_ok']);
        self::assertTrue($result['dispatch_authorization_chain_ok']);
        self::assertTrue($result['receipt_use_chain_ok']);
        self::assertSame([], $result['violations']);
        self::assertSame(8, $result['corridor_ok_count']);
    }

    public function test_provider_to_runtime_corridor_empty_reports(): void
    {
        $result = AgentControlPlaneCorridorProjector::providerToRuntimeCorridor([], 'irrelevant');

        self::assertFalse($result['provider_to_runtime_chain_ok']);
        self::assertNotEmpty($result['violations']);
        self::assertSame(13, $result['corridor_total_count']);
    }

    public function test_provider_to_runtime_corridor_returns_edge_status(): void
    {
        $result = AgentControlPlaneCorridorProjector::providerToRuntimeCorridor([], 'irrelevant');

        self::assertArrayHasKey('corridor_edge_status', $result);
        self::assertArrayHasKey('corridor_gap_summary', $result);
    }

    public function test_implementation_to_operator_handoff_corridor_empty_reports(): void
    {
        $result = AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor([], 'irrelevant');

        self::assertFalse($result['implementation_to_operator_handoff_chain_ok']);
        self::assertNotEmpty($result['violations']);
        self::assertSame(13, $result['corridor_total_count']);
    }

    public function test_implementation_to_operator_handoff_returns_edge_and_gap(): void
    {
        $result = AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor([], 'irrelevant');

        self::assertArrayHasKey('corridor_edge_status', $result);
        self::assertArrayHasKey('corridor_gap_summary', $result);
    }

    public function test_all_three_corridors_are_deterministic(): void
    {
        $a = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'x');
        $b = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'x');
        self::assertSame($a, $b);

        $c = AgentControlPlaneCorridorProjector::providerToRuntimeCorridor([], 'x');
        $d = AgentControlPlaneCorridorProjector::providerToRuntimeCorridor([], 'x');
        self::assertSame($c, $d);

        $e = AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor([], 'x');
        $f = AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor([], 'x');
        self::assertSame($e, $f);
    }

    public function test_post_start_evidence_corridor_slice_status_has_check_fields(): void
    {
        $reports = [
            ['slice_key' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', 'ok' => true, 'checks' => ['contract_method_exists' => true]],
        ];

        $result = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor($reports, 'x');
        $status = $result['corridor_slice_status']['post_start_receipt_contract'];

        self::assertTrue($status['present_in_deep_chain']);
        self::assertTrue($status['all_artifacts_ok']);
        self::assertTrue($status['contract_method_exists']);
        self::assertFalse($status['preflight_method_exists']);
    }
}
