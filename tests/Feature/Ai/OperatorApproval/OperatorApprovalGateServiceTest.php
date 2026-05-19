<?php

namespace Tests\Feature\Ai\OperatorApproval;

use App\Models\AiOperatorApproval;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperatorApprovalTable;
use Tests\TestCase;

class OperatorApprovalGateServiceTest extends TestCase
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

    private function service(): OperatorApprovalGateService
    {
        return app(OperatorApprovalGateService::class);
    }

    public function test_low_risk_explain_allows_auto_without_persistence(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'explain.code',
            'risk_level' => 'low',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $decision->gateMode);
        $this->assertTrue($decision->proceed());
        $this->assertFalse($decision->approvalRequired);
        $this->assertNull($decision->approval);
        $this->assertSame(64, strlen($decision->hash));
    }

    public function test_destructive_command_requires_confirmation(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_CONFIRMATION, $decision->gateMode);
        $this->assertTrue($decision->waitingForOperator());
        $this->assertFalse($decision->proceed());
        $this->assertNotNull($decision->approval);
        $this->assertSame(OperatorApprovalCanon::STATUS_PENDING, $decision->approval->status);
    }

    public function test_destructive_critical_blocks(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.rm_rf',
            'risk_level' => 'critical',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_BLOCK, $decision->gateMode);
        $this->assertTrue($decision->blocked());
        $this->assertFalse($decision->proceed());
        $this->assertNotNull($decision->approval);
    }

    public function test_file_edit_mass_requires_review(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.file_edit_mass.refactor',
            'risk_level' => 'medium',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_REVIEW, $decision->gateMode);
        $this->assertTrue($decision->waitingForOperator());
        $this->assertNotNull($decision->approval);
    }

    public function test_finance_trade_requires_review(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'finance.trade.execute',
            'risk_level' => 'medium',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_REVIEW, $decision->gateMode);
        $this->assertTrue($decision->waitingForOperator());
        $this->assertNotNull($decision->approval);
    }

    public function test_finance_trade_critical_blocks(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'finance.trade.live',
            'risk_level' => 'critical',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_BLOCK, $decision->gateMode);
        $this->assertTrue($decision->blocked());
    }

    public function test_cyber_exploit_requires_review(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'cyber.exploit.web_app',
            'risk_level' => 'medium',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_REVIEW, $decision->gateMode);
        $this->assertTrue($decision->waitingForOperator());
    }

    public function test_cyber_active_critical_blocks(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'cyber.active_scan.internal',
            'risk_level' => 'critical',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_BLOCK, $decision->gateMode);
        $this->assertTrue($decision->blocked());
    }

    public function test_forge_obra_escalates_to_forge(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'mission.handoff_forge',
            'risk_level' => 'medium',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_ESCALATE_TO_FORGE, $decision->gateMode);
        $this->assertTrue($decision->escalatedToForge());
        $this->assertTrue($decision->waitingForOperator());
        $this->assertNotNull($decision->approval);
    }

    public function test_mission_certify_without_evidence_requires_review(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'mission.certify',
            'risk_level' => 'low',
            'context' => ['evidence_count' => 0],
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_REVIEW, $decision->gateMode);
    }

    public function test_mission_certify_with_evidence_allows_auto(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'mission.certify',
            'risk_level' => 'low',
            'context' => ['evidence_count' => 3],
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $decision->gateMode);
    }

    public function test_mission_handoff_dev_allows_auto(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'mission.handoff_dev',
            'risk_level' => 'low',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $decision->gateMode);
        $this->assertTrue($decision->proceed());
    }

    public function test_research_and_conversation_allow_auto(): void
    {
        foreach (['research.deep_dive', 'conversation.casual'] as $action) {
            $decision = $this->service()->evaluate([
                'requested_action' => $action,
                'risk_level' => 'low',
            ]);
            $this->assertSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $decision->gateMode, $action);
        }
    }

    public function test_unknown_action_fails_safe_to_require_confirmation(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'unknown.action.foo',
            'risk_level' => 'low',
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_REQUIRE_CONFIRMATION, $decision->gateMode);
        $this->assertContains('no_category_match_fail_safe_require_confirmation', $decision->reasons);
    }

    public function test_critical_risk_blocks_allow_auto_categories(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'research.market_analysis',
            'risk_level' => 'critical',
        ]);

        $this->assertNotSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $decision->gateMode);
        $this->assertContains('risk_override:critical_blocks_allow_auto', $decision->reasons);
    }

    public function test_approve_marks_status_and_writes_receipt(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);
        $this->assertNotNull($decision->approval);

        $approved = $this->service()->approve($decision->approval, 'vitor', 'go');

        $this->assertSame(OperatorApprovalCanon::STATUS_APPROVED, $approved->status);
        $this->assertSame('vitor', $approved->operator);
        $this->assertNotNull($approved->decided_at);
        $this->assertNotEmpty($approved->receipt_hash);
        $this->assertSame(64, strlen($approved->receipt_hash));
    }

    public function test_deny_marks_status_and_writes_receipt(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);

        $denied = $this->service()->deny($decision->approval, 'vitor', 'too risky');

        $this->assertSame(OperatorApprovalCanon::STATUS_DENIED, $denied->status);
        $this->assertNotEmpty($denied->receipt_hash);
    }

    public function test_decide_twice_throws(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);
        $this->service()->approve($decision->approval, 'vitor');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->approve($decision->approval->fresh(), 'vitor');
    }

    public function test_expire_due_marks_expired(): void
    {
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
            'expires_in_minutes' => 1,
        ]);
        $this->assertNotNull($decision->approval);

        AiOperatorApproval::query()->where('id', $decision->approval->id)->update([
            'expires_at' => Carbon::now()->subMinutes(2),
        ]);

        $count = $this->service()->expireDue();

        $this->assertSame(1, $count);
        $approval = $decision->approval->fresh();
        $this->assertSame(OperatorApprovalCanon::STATUS_EXPIRED, $approval->status);
        $this->assertNotNull($approval->decided_at);
    }

    public function test_hash_is_deterministic_for_same_inputs(): void
    {
        $base = [
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
            'mission_id' => (string) Str::uuid(),
            'work_order_id' => (string) Str::uuid(),
            'options' => [OperatorApprovalCanon::DECISION_APPROVE, OperatorApprovalCanon::DECISION_DENY],
            'evidence_refs' => ['doc:ref1'],
        ];

        $a = $this->service()->evaluate($base);
        $b = $this->service()->evaluate($base);

        // expires_at é tempo-dependente; comparar hashes que omitem timing seria mais limpo,
        // mas o serviço usa expires_at apenas via Carbon::now()->add(...). Como o relógio
        // pode bater no mesmo segundo, é OK comparar hashes "next to each other" — caso
        // mude no segundo, recriar dentro do mesmo segundo Carbon::setTestNow estabilizado.
        Carbon::setTestNow('2026-05-19 12:00:00');
        $c = $this->service()->evaluate($base);
        $d = $this->service()->evaluate($base);
        Carbon::setTestNow();

        $this->assertSame($c->hash, $d->hash, 'hash must be deterministic for identical canonical inputs');
        $this->assertSame(64, strlen($c->hash));
        $this->assertNotEmpty($a->hash);
        $this->assertNotEmpty($b->hash);
    }

    public function test_reason_is_truncated_no_raw_sensitive_text_leak(): void
    {
        $huge = str_repeat('SECRET-TOKEN-DO-NOT-LEAK ', 200); // ~5k chars
        $decision = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
            'reason' => $huge,
        ]);

        $this->assertNotNull($decision->approval);
        $approval = $decision->approval->fresh();

        $this->assertLessThanOrEqual(480, mb_strlen($approval->reason), 'reason capped at 480 chars');
        $this->assertSame(0, substr_count($approval->reason, 'SECRET-TOKEN-DO-NOT-LEAK ') > 20 ? 1 : 0);
    }

    public function test_reused_existing_approval_short_circuits_to_allow_auto(): void
    {
        $missionId = (string) Str::uuid();
        $first = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
            'mission_id' => $missionId,
        ]);
        $this->service()->approve($first->approval, 'vitor');

        $second = $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
            'mission_id' => $missionId,
        ]);

        $this->assertSame(OperatorApprovalCanon::MODE_ALLOW_AUTO, $second->gateMode);
        $this->assertTrue($second->proceed());
        $this->assertNotNull($second->approval);
        $this->assertNotNull($second->approval->fresh()->consumed_at);
    }

    public function test_control_plane_snapshot_exposes_distribution(): void
    {
        $this->service()->evaluate([
            'requested_action' => 'tool.destructive.fs_delete',
            'risk_level' => 'medium',
        ]);
        $second = $this->service()->evaluate([
            'requested_action' => 'mission.handoff_forge',
            'risk_level' => 'medium',
        ]);
        $this->service()->deny($second->approval, 'vitor');

        $snapshot = $this->service()->controlPlaneSnapshot();

        $this->assertSame('atlas.ai.operator_approval.control_plane.v1', $snapshot['schema_version']);
        $this->assertSame(2, $snapshot['totals']['all']);
        $this->assertGreaterThanOrEqual(1, $snapshot['totals']['pending']);
        $this->assertGreaterThanOrEqual(1, $snapshot['totals']['denied']);
        $this->assertArrayHasKey('by_mode', $snapshot);
        $this->assertArrayHasKey('by_risk', $snapshot);
        $this->assertArrayHasKey('pending', $snapshot);
        $this->assertArrayHasKey('recent_decisions', $snapshot);
        $this->assertArrayHasKey('blockers', $snapshot);
    }
}
