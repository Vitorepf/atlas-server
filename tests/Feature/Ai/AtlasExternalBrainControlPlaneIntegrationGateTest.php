<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneIntegrationGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainControlPlaneIntegrationGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainControlPlaneIntegrationGate
    {
        return new AtlasExternalBrainControlPlaneIntegrationGate;
    }

    // ── AC1: no input source / output consumer / decision effect → not_integrated ─

    public function test_organ_with_no_wiring_at_all_is_not_integrated(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'    => 'orphan-organ',
            'is_important' => true,
        ]);

        $this->assertFalse($r['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::STATUS_NOT_INTEGRATED, $r['integration_status']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::PATH_NONE, $r['exposure_path']);
    }

    public function test_not_integrated_organ_has_no_decision_path_or_consumer(): void
    {
        $r = $this->gate()->evaluate(['organ_id' => 'o1', 'is_important' => true]);

        $this->assertSame('', $r['decision_path']);
        $this->assertSame('', $r['consumer']);
        $this->assertSame([], $r['evidence_requirements']);
    }

    public function test_not_integrated_lists_integration_blockers(): void
    {
        $r = $this->gate()->evaluate(['organ_id' => 'o1', 'is_important' => true]);

        $this->assertNotEmpty($r['integration_blockers']);
    }

    public function test_unimportant_organ_always_passes(): void
    {
        $r = $this->gate()->evaluate(['organ_id' => 'helper', 'is_important' => false]);

        $this->assertTrue($r['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::STATUS_NOT_REQUIRED, $r['integration_status']);
    }

    // ── AC2: wired organs report decision_path, consumer, evidence_requirements ─

    public function test_control_plane_wired_organ_reports_decision_path(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'              => 'cp-organ',
            'is_important'          => true,
            'control_plane_exposure' => true,
            'decision_effect'       => 'gates_run_policy',
            'consumer_links'        => ['AtlasRunPolicyCompiler'],
            'evidence_floor'        => 'run_policy_test_green',
        ]);

        $this->assertTrue($r['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::STATUS_CONTROL_PLANE_WIRED, $r['integration_status']);
        $this->assertSame('gates_run_policy', $r['decision_path']);
        $this->assertSame('AtlasRunPolicyCompiler', $r['consumer']);
        $this->assertContains('run_policy_test_green', $r['evidence_requirements']);
    }

    public function test_readiness_map_wired_organ_reports_consumer(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'              => 'rm-organ',
            'is_important'          => true,
            'readiness_map_exposure' => true,
            'consumer_links'        => ['ReadinessMapExporter'],
            'evidence_floor'        => 'readiness_test_green',
        ]);

        $this->assertTrue($r['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::STATUS_READINESS_MAP_WIRED, $r['integration_status']);
        $this->assertSame('ReadinessMapExporter', $r['consumer']);
        $this->assertContains('readiness_test_green', $r['evidence_requirements']);
    }

    public function test_standalone_wired_organ_reports_consumer_and_evidence(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'                   => 'sa-organ',
            'is_important'               => true,
            'consumer_links'             => ['StandaloneConsumer'],
            'standalone_reason'          => 'peer_reviewed_design_doc',
            'evidence_floor'             => 'design_doc_approved',
            'expiry_or_review_condition' => 'next_architecture_review',
        ]);

        $this->assertTrue($r['count_as_delivered']);
        $this->assertSame(AtlasExternalBrainControlPlaneIntegrationGate::STATUS_STANDALONE_WIRED, $r['integration_status']);
        $this->assertSame('StandaloneConsumer', $r['consumer']);
        $this->assertContains('design_doc_approved', $r['evidence_requirements']);
    }

    // ── AC3: read-only helper cannot claim final capability without control-plane ─

    public function test_read_only_helper_without_control_plane_is_blocked(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'              => 'ro-helper',
            'is_important'          => true,
            'is_read_only_helper'   => true,
            'control_plane_exposure' => false,
            'readiness_map_exposure' => true, // readiness alone not enough for read-only
        ]);

        $this->assertFalse($r['count_as_delivered']);
        $this->assertSame(
            AtlasExternalBrainControlPlaneIntegrationGate::STATUS_READ_ONLY_HELPER_BLOCKED,
            $r['integration_status'],
        );
    }

    public function test_read_only_helper_with_control_plane_passes(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'               => 'ro-wired',
            'is_important'           => true,
            'is_read_only_helper'    => true,
            'control_plane_exposure' => true,
            'consumer_links'         => ['PolicyCompiler'],
        ]);

        $this->assertTrue($r['count_as_delivered']);
        $this->assertSame(
            AtlasExternalBrainControlPlaneIntegrationGate::STATUS_CONTROL_PLANE_WIRED,
            $r['integration_status'],
        );
    }

    public function test_read_only_helper_blocked_lists_integration_blockers(): void
    {
        $r = $this->gate()->evaluate([
            'organ_id'            => 'ro-bare',
            'is_important'        => true,
            'is_read_only_helper' => true,
        ]);

        $blockers = implode('|', $r['integration_blockers']);
        $this->assertStringContainsString('read_only_helper', $blockers);
    }

    // ── AC4: deterministic ────────────────────────────────────────────────────

    public function test_evaluate_is_deterministic(): void
    {
        $organ = [
            'organ_id'               => 'det-organ',
            'is_important'           => true,
            'control_plane_exposure' => true,
            'decision_effect'        => 'controls_merge_gate',
            'consumer_links'         => ['MergeGovernor'],
            'evidence_floor'         => 'merge_gate_test_green',
        ];

        $a = $this->gate()->evaluate($organ);
        $b = $this->gate()->evaluate($organ);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
