<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneConvergenceRuntimeBridge;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneConvergenceRuntimeBridgeTest extends TestCase
{
    public function test_low_blocked_ratio_translates_to_low_simplification_pressure(): void
    {
        $signals = (new AtlasExternalBrainControlPlaneConvergenceRuntimeBridge)->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 90.0,
            'blocked_organs' => ['OrganA'],
            'stop_go_reasons' => [],
        ]);

        self::assertSame('low', $signals['simplification_pressure']);
        self::assertSame(90.0, $signals['integration_coverage_percent']);
        self::assertSame(0.1, $signals['blocked_organ_ratio']);
    }

    public function test_high_blocked_ratio_translates_to_high_simplification_pressure(): void
    {
        $signals = (new AtlasExternalBrainControlPlaneConvergenceRuntimeBridge)->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 30.0,
            'blocked_organs' => ['OrganA', 'OrganB', 'OrganC', 'OrganD', 'OrganE', 'OrganF'],
            'stop_go_reasons' => ['integration_coverage_below_floor'],
        ]);

        self::assertSame('high', $signals['simplification_pressure']);
    }

    public function test_snapshot_forces_consolidate_when_convergence_shows_high_ornamental_ratio(): void
    {
        $signals = (new AtlasExternalBrainControlPlaneConvergenceRuntimeBridge)->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 30.0,
            'blocked_organs' => ['OrganA', 'OrganB', 'OrganC', 'OrganD', 'OrganE', 'OrganF'],
            'stop_go_reasons' => ['integration_coverage_below_floor'],
        ]);

        $snapshot = (new AtlasExternalBrainUnifiedControlPlaneSnapshot)->compose([
            'integration_coverage_percent' => $signals['integration_coverage_percent'],
            'simplification_pressure' => $signals['simplification_pressure'],
        ]);

        self::assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::DECISION_CONSOLIDATE, $snapshot['next_decision']);
        self::assertContains('integration_coverage_status:weak', $snapshot['top_risks']);
        self::assertNotSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $snapshot['status']);
    }

    public function test_snapshot_stays_green_go_when_convergence_is_healthy(): void
    {
        $signals = (new AtlasExternalBrainControlPlaneConvergenceRuntimeBridge)->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 95.0,
            'blocked_organs' => [],
            'stop_go_reasons' => [],
        ]);

        $snapshot = (new AtlasExternalBrainUnifiedControlPlaneSnapshot)->compose([
            'integration_coverage_percent' => $signals['integration_coverage_percent'],
            'simplification_pressure' => $signals['simplification_pressure'],
        ]);

        self::assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::STATUS_GREEN, $snapshot['status']);
        self::assertSame(AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_GO, $snapshot['stop_go_verdict']);
    }
}
