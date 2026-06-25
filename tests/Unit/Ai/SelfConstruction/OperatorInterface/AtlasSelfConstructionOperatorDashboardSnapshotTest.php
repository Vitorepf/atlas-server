<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorInterface;

use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorDashboardSnapshot;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorDashboardSnapshotTest extends TestCase
{
    public function test_healthy_snapshot_reports_atlas_native_progress(): void
    {
        $snap = (new AtlasSelfConstructionOperatorDashboardSnapshot)->snapshot([
            'autonomy_mode' => 'execute',
            'queue' => ['pending' => 3, 'leases' => 2, 'backlog' => 1],
            'blockers' => [],
            'organs' => ['cortex' => 'ready', 'goal_value' => 'ready'],
            'emergency_state' => 'off',
        ]);

        $this->assertSame(AtlasSelfConstructionOperatorDashboardSnapshot::SURFACE_ROLE, $snap['surface_role']);
        $this->assertSame('execute', $snap['autonomy_mode']);
        $this->assertSame(AtlasSelfConstructionOperatorDashboardSnapshot::HEALTH_HEALTHY, $snap['queue_health']['state']);
        $this->assertTrue($snap['atlas_native_progress']);
    }

    public function test_blocked_snapshot_reports_blocked_health(): void
    {
        $snap = (new AtlasSelfConstructionOperatorDashboardSnapshot)->snapshot([
            'autonomy_mode' => 'execute',
            'queue' => ['pending' => 0, 'leases' => 0, 'backlog' => 5],
            'blockers' => ['verification_court_red'],
            'organs' => ['verification_court' => 'blocked'],
            'emergency_state' => 'off',
        ]);

        $this->assertSame(AtlasSelfConstructionOperatorDashboardSnapshot::HEALTH_BLOCKED, $snap['queue_health']['state']);
        $this->assertContains('verification_court_red', $snap['blockers']);
        $this->assertFalse($snap['atlas_native_progress']);
    }

    public function test_emergency_visible_state_is_surfaced(): void
    {
        $snap = (new AtlasSelfConstructionOperatorDashboardSnapshot)->snapshot([
            'autonomy_mode' => 'observe',
            'queue' => ['pending' => 1, 'leases' => 1, 'backlog' => 0],
            'blockers' => [],
            'organs' => ['cortex' => 'ready'],
            'emergency_state' => AtlasSelfConstructionOperatorDashboardSnapshot::EMERGENCY_VISIBLE,
        ]);

        $this->assertSame(AtlasSelfConstructionOperatorDashboardSnapshot::EMERGENCY_VISIBLE, $snap['emergency_state']);
        $this->assertFalse($snap['atlas_native_progress'], 'visible emergency switch must NOT report Atlas-native progress');
    }

    public function test_snapshot_does_not_mutate_input(): void
    {
        $input = [
            'autonomy_mode' => 'execute',
            'queue' => ['pending' => 1, 'leases' => 1, 'backlog' => 0],
            'blockers' => [],
            'organs' => ['cortex' => 'ready'],
            'emergency_state' => 'off',
        ];
        $before = json_encode($input);

        (new AtlasSelfConstructionOperatorDashboardSnapshot)->snapshot($input);

        $this->assertSame($before, json_encode($input), 'snapshot must NOT mutate the runtime fact map');
    }

    public function test_surface_role_label_is_visibility_only(): void
    {
        $snap = (new AtlasSelfConstructionOperatorDashboardSnapshot)->snapshot([]);
        $this->assertStringContainsString('visibility', $snap['surface_role']);
        $this->assertStringNotContainsString('control', $snap['surface_role']);
    }
}
