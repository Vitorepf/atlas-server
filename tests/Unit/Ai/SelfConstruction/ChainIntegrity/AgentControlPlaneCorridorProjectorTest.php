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

    public function test_first_bottleneck_is_first_missing_slice_when_all_absent(): void
    {
        $result = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'x');

        $this->assertSame('post_start_receipt_contract', $result['first_bottleneck']);
        $this->assertNotEmpty($result['missing_artifact_kinds']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_bottleneck_fields_are_empty_when_all_slices_ok(): void
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

        $this->assertSame('', $result['first_bottleneck']);
        $this->assertSame([], $result['missing_artifact_kinds']);
        $this->assertSame('', $result['next_repair_hint']);
    }

    public function test_first_bottleneck_exposes_only_false_artifact_checks(): void
    {
        $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract';
        $reports = [
            ['slice_key' => $sliceKey, 'ok' => false, 'checks' => ['contract_method_exists' => true]],
        ];
        $result = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor($reports, 'x');

        $this->assertSame('post_start_receipt_contract', $result['first_bottleneck']);
        $this->assertNotContains('contract_method_exists', $result['missing_artifact_kinds']);
        $this->assertContains('preflight_method_exists', $result['missing_artifact_kinds']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_provider_to_runtime_first_bottleneck_exposes_only_false_artifact_checks(): void
    {
        $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate';
        $reports = [
            ['slice_key' => $sliceKey, 'ok' => false, 'checks' => ['contract_method_exists' => true]],
        ];
        $result = AgentControlPlaneCorridorProjector::providerToRuntimeCorridor($reports, 'x');

        $this->assertSame('post_start_provider_start_driver_gate', $result['first_bottleneck']);
        $this->assertNotContains('contract_method_exists', $result['missing_artifact_kinds']);
        $this->assertContains('preflight_method_exists', $result['missing_artifact_kinds']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_implementation_to_operator_first_bottleneck_exposes_only_false_artifact_checks(): void
    {
        $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate';
        $reports = [
            ['slice_key' => $sliceKey, 'ok' => false, 'checks' => ['contract_method_exists' => true]],
        ];
        $result = AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor($reports, 'x');

        $this->assertSame('post_start_implementation_boundary_gate', $result['first_bottleneck']);
        $this->assertNotContains('contract_method_exists', $result['missing_artifact_kinds']);
        $this->assertContains('preflight_method_exists', $result['missing_artifact_kinds']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_bottleneck_fields_are_deterministic(): void
    {
        $a = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'x');
        $b = AgentControlPlaneCorridorProjector::postStartEvidenceCorridor([], 'x');
        $this->assertSame($a['first_bottleneck'], $b['first_bottleneck']);
        $this->assertSame($a['missing_artifact_kinds'], $b['missing_artifact_kinds']);
        $this->assertSame($a['next_repair_hint'], $b['next_repair_hint']);
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

    // ── AC: projectCorridor unified projection ──────────────────────────────

    private function greenSliceReport(string $key): array
    {
        return [
            'slice_key' => $key,
            'ok' => true,
            'checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => true,
                'implementation_packet_method_exists' => true,
                'status_method_exists' => true,
                'invoker_class_exists' => true,
                'invoker_prepare_method_exists' => true,
            ],
        ];
    }

    private function redSliceReport(string $key): array
    {
        return [
            'slice_key' => $key,
            'ok' => false,
            'checks' => [
                'contract_method_exists' => true,
                'preflight_method_exists' => false,
            ],
        ];
    }

    public function test_project_corridor_complete_when_all_slices_ok(): void
    {
        $slices = ['slice-A', 'slice-B', 'slice-C'];
        $reports = [$this->greenSliceReport('slice-A'), $this->greenSliceReport('slice-B'), $this->greenSliceReport('slice-C')];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertTrue($result['corridor_complete']);
        self::assertSame('corridor_complete_all_proofs_present', $result['next_safe_action']);
        self::assertSame('', $result['current_position']);
        self::assertSame(3, $result['complete_count']);
        self::assertSame(0, $result['missing_count']);
        self::assertSame(0, $result['blocked_count']);
    }

    public function test_project_corridor_emits_ordered_required_slices(): void
    {
        $slices = ['slice-A', 'slice-B'];
        $reports = [$this->greenSliceReport('slice-A'), $this->greenSliceReport('slice-B')];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertCount(2, $result['required_slices']);
        self::assertSame(1, $result['required_slices'][0]['order']);
        self::assertSame('slice-A', $result['required_slices'][0]['slice_key']);
        self::assertSame(2, $result['required_slices'][1]['order']);
    }

    public function test_project_corridor_identifies_missing_slices(): void
    {
        $slices = ['slice-A', 'slice-B', 'slice-C'];
        $reports = [$this->greenSliceReport('slice-A')]; // B and C missing

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertFalse($result['corridor_complete']);
        self::assertContains('slice-B', $result['missing_links']);
        self::assertContains('slice-C', $result['missing_links']);
        self::assertSame(2, $result['missing_count']);
    }

    public function test_project_corridor_identifies_blocked_slices(): void
    {
        $slices = ['slice-A', 'slice-B'];
        $reports = [$this->greenSliceReport('slice-A'), $this->redSliceReport('slice-B')];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertFalse($result['corridor_complete']);
        self::assertContains('slice-B', $result['blocked_slices']);
        self::assertSame(1, $result['blocked_count']);
    }

    public function test_project_corridor_next_safe_action_for_first_missing(): void
    {
        $slices = ['slice-A', 'slice-B'];
        $reports = [$this->greenSliceReport('slice-A')]; // B missing

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertSame('slice-B', $result['current_position']);
        self::assertSame('implement_missing_slice:slice-B', $result['next_safe_action']);
    }

    public function test_project_corridor_next_safe_action_for_first_blocked(): void
    {
        $slices = ['slice-A', 'slice-B'];
        $reports = [$this->redSliceReport('slice-A'), $this->greenSliceReport('slice-B')];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertSame('slice-A', $result['current_position']);
        self::assertSame('repair_blocked_slice_proofs:slice-A', $result['next_safe_action']);
    }

    public function test_project_corridor_never_skips_missing_required_slice(): void
    {
        $slices = ['slice-A', 'slice-B', 'slice-C'];
        $reports = [
            $this->redSliceReport('slice-A'),
            $this->greenSliceReport('slice-B'),
            $this->greenSliceReport('slice-C'),
        ];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertSame('slice-A', $result['current_position']);
        self::assertStringStartsWith('repair_blocked_slice_proofs', $result['next_safe_action']);
    }

    public function test_project_corridor_never_claims_complete_without_all_proofs(): void
    {
        $slices = ['slice-A', 'slice-B'];
        $reports = [$this->greenSliceReport('slice-A'), $this->redSliceReport('slice-B')];

        $result = AgentControlPlaneCorridorProjector::projectCorridor($reports, $slices);

        self::assertFalse($result['corridor_complete']);
    }

    public function test_project_corridor_required_slices_include_proof_list(): void
    {
        $result = AgentControlPlaneCorridorProjector::projectCorridor(
            [$this->greenSliceReport('slice-A')],
            ['slice-A'],
        );

        self::assertContains('contract_method_exists', $result['required_slices'][0]['required_proofs']);
        self::assertContains('invoker_prepare_method_exists', $result['required_slices'][0]['required_proofs']);
        self::assertNotEmpty($result['required_slices'][0]['observed_proofs']);
    }

    public function test_project_corridor_empty_required_slices(): void
    {
        $result = AgentControlPlaneCorridorProjector::projectCorridor([], []);

        self::assertTrue($result['corridor_complete']);
        self::assertSame([], $result['required_slices']);
    }

    public function test_project_corridor_observed_proofs_from_checks(): void
    {
        $result = AgentControlPlaneCorridorProjector::projectCorridor(
            [$this->greenSliceReport('slice-A')],
            ['slice-A'],
        );

        self::assertContains('contract_method_exists', $result['required_slices'][0]['observed_proofs']);
    }
}
