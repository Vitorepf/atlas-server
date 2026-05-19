<?php

namespace Tests\Feature\Ai\OperatorApproval;

use App\Models\AiOperatorApproval;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\TestCase;

class AtlasAiApprovalCommandTest extends TestCase
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

    private function runCommand(array $params): array
    {
        $exit = Artisan::call('atlas:ai:approval', $params);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "command output should be valid JSON, got: {$output}");

        return ['exit' => $exit, 'payload' => $decoded];
    }

    private function seedPendingApproval(): AiOperatorApproval
    {
        $decision = app(OperatorApprovalGateService::class)->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);

        return $decision->approval;
    }

    public function test_list_returns_pending_approvals_in_json(): void
    {
        $this->seedPendingApproval();
        $this->seedPendingApproval();

        $result = $this->runCommand(['action' => 'list', '--status' => 'pending', '--json' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame('list', $result['payload']['action']);
        $this->assertSame(2, $result['payload']['count']);
    }

    public function test_show_returns_specific_approval(): void
    {
        $approval = $this->seedPendingApproval();

        $result = $this->runCommand([
            'action' => 'show',
            '--approval' => $approval->uuid,
            '--json' => true,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame($approval->uuid, $result['payload']['approval']['uuid']);
    }

    public function test_show_missing_returns_not_found(): void
    {
        $result = $this->runCommand([
            'action' => 'show',
            '--approval' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            '--json' => true,
        ]);

        $this->assertSame(1, $result['exit']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertSame('approval_not_found', $result['payload']['error']);
    }

    public function test_decide_approve_writes_receipt(): void
    {
        $approval = $this->seedPendingApproval();

        $result = $this->runCommand([
            'action' => 'decide',
            '--approval' => $approval->uuid,
            '--decision' => 'approve',
            '--operator' => 'vitor',
            '--note' => 'go ahead',
            '--json' => true,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame('approve', $result['payload']['decision']);
        $this->assertSame(OperatorApprovalCanon::STATUS_APPROVED, $result['payload']['approval']['status']);
        $this->assertSame('vitor', $result['payload']['approval']['operator']);
        $this->assertNotEmpty($result['payload']['approval']['receipt_hash']);
    }

    public function test_decide_deny_writes_receipt(): void
    {
        $approval = $this->seedPendingApproval();

        $result = $this->runCommand([
            'action' => 'decide',
            '--approval' => $approval->uuid,
            '--decision' => 'deny',
            '--operator' => 'vitor',
            '--json' => true,
        ]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $this->assertSame(OperatorApprovalCanon::STATUS_DENIED, $result['payload']['approval']['status']);
    }

    public function test_decide_invalid_decision_returns_usage_error(): void
    {
        $approval = $this->seedPendingApproval();

        $result = $this->runCommand([
            'action' => 'decide',
            '--approval' => $approval->uuid,
            '--decision' => 'maybe',
            '--json' => true,
        ]);

        $this->assertSame(2, $result['exit']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertSame('usage_error', $result['payload']['error']);
    }

    public function test_control_plane_returns_aggregated_snapshot(): void
    {
        $this->seedPendingApproval();
        $approval = $this->seedPendingApproval();
        app(OperatorApprovalGateService::class)->approve($approval, 'vitor');

        $result = $this->runCommand(['action' => 'control-plane', '--json' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertTrue($result['payload']['ok']);
        $snapshot = $result['payload']['snapshot'];
        $this->assertSame('atlas.ai.operator_approval.control_plane.v1', $snapshot['schema_version']);
        $this->assertGreaterThanOrEqual(2, $snapshot['totals']['all']);
        $this->assertGreaterThanOrEqual(1, $snapshot['totals']['approved']);
    }

    public function test_invalid_action_returns_usage_error(): void
    {
        $result = $this->runCommand(['action' => 'foo', '--json' => true]);

        $this->assertSame(2, $result['exit']);
        $this->assertFalse($result['payload']['ok']);
        $this->assertSame('usage_error', $result['payload']['error']);
    }
}
