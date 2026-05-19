<?php

namespace Tests\Feature\Ai\OperatorApproval;

use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\TestCase;

class OperatorApprovalControlPlaneTest extends TestCase
{
    use CreatesOperatorApprovalTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorApprovalTable();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorApprovalTable();
        parent::tearDown();
    }

    public function test_control_plane_snapshot_includes_operator_approvals_summary(): void
    {
        $gate = app(OperatorApprovalGateService::class);

        // pending
        $gate->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);

        // approved
        $decision = $gate->evaluate([
            'requested_action' => 'mission.handoff_forge',
            'risk_level' => 'medium',
        ]);
        $gate->approve($decision->approval, 'vitor');

        // blocked
        $gate->evaluate([
            'requested_action' => 'tool.destructive.rm_rf',
            'risk_level' => 'critical',
        ]);

        $snapshot = app(AtlasControlPlaneSnapshotService::class)->snapshot();

        $this->assertArrayHasKey('operator_approvals_summary', $snapshot);
        $summary = $snapshot['operator_approvals_summary'];
        $this->assertSame('atlas.ai.operator_approval.v1', $summary['schema_version']);
        $this->assertSame(3, $summary['total']);
        $this->assertGreaterThanOrEqual(1, $summary['approved']);
        $this->assertArrayHasKey('by_status', $summary);
        $this->assertArrayHasKey('by_mode', $summary);
        $this->assertArrayHasKey('risk_distribution', $summary);
        $this->assertContains(OperatorApprovalCanon::MODE_BLOCK, array_keys($summary['by_mode']));
    }

    public function test_control_plane_snapshot_handles_missing_table_gracefully(): void
    {
        $this->dropOperatorApprovalTable();

        $snapshot = app(AtlasControlPlaneSnapshotService::class)->snapshot();

        $this->assertArrayHasKey('operator_approvals_summary', $snapshot);
        $this->assertSame('missing', $snapshot['operator_approvals_summary']['status']);
    }
}
