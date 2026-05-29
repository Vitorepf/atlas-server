<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AP-791 · focused same-name coverage for autonomous loop receipt integrity.
 *
 * Proves pre-merge inbox evidence is mandatory before merge and every lifecycle
 * state (merged, planned, blocked) emits a reviewable receipt with labeled
 * evidence refs, replay_command and next_action.
 */
final class AutonomousLoopReceiptIntegrityServiceTest extends TestCase
{
    private function service(): AutonomousLoopReceiptIntegrityService
    {
        return new AutonomousLoopReceiptIntegrityService();
    }

    public function test_pre_merge_gate_requires_operator_visible_inbox_or_result_bridge(): void
    {
        $gate = $this->service()->preMergeGate([
            'inbox_item_id' => '',
            'result_bridge_id' => '',
            'inbox_emitted_before_merge_attempt' => true,
        ]);

        $this->assertFalse($gate['merge_allowed']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::PRE_MERGE_INBOX_REQUIRED, $gate['reason']);
        $this->assertFalse($gate['inbox_pre_merge']['present']);

        $allowed = $this->service()->preMergeGate([
            'inbox_item_id' => 'inbox_pre_1',
            'result_bridge_id' => '',
            'inbox_emitted_before_merge_attempt' => true,
        ]);
        $this->assertTrue($allowed['merge_allowed']);
        $this->assertNull($allowed['reason']);
        $this->assertTrue($allowed['inbox_pre_merge']['present']);
        $this->assertTrue($allowed['inbox_pre_merge']['emitted_before_merge_attempt']);
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     */
    #[DataProvider('reviewableLifecycleReceiptProvider')]
    public function test_lifecycle_receipts_are_reviewable_for_operator_inbox(
        array $cycle,
        array $context,
        string $expectedState,
        bool $expectedCompleted,
    ): void {
        $receipt = $this->service()->receiptFor($cycle, $context);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::RECEIPT_SCHEMA, $receipt['schema_version']);
        $this->assertSame('AP-791', $receipt['ap_contract']);
        $this->assertSame($expectedState, $receipt['lifecycle_state']);
        $this->assertSame($expectedCompleted, $receipt['completed']);
        $this->assertNotSame('', $receipt['replay_command']);
        $this->assertNotSame('', $receipt['next_action']);
        $this->assertNotSame('', $receipt['receipt_hash']);
        $this->assertTrue(str_starts_with((string) $receipt['receipt_hash'], 'sha256:'));

        $summary = $this->service()->cycleInboxSummary($cycle, $context);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::INBOX_SUMMARY_SCHEMA, $summary['schema_version']);
        $this->assertSame($expectedState, $summary['decisao']);
        $this->assertSame($receipt['next_action'], $summary['next_operator_action']);
        $this->assertSame($receipt, $summary['loop_receipt']);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: array<string,mixed>, 2: string, 3: bool}>
     */
    public static function reviewableLifecycleReceiptProvider(): array
    {
        $baseFinding = ['finding_id' => 'afdf_review', 'title' => 'Receipt reviewability'];
        $session = ['session_id' => 'aess_review_791'];

        return [
            'merged' => [
                [
                    'cycle_id' => 'c_merged',
                    'final_status' => 'cycle_completed',
                    'merge_performed' => true,
                    'selected_finding' => $baseFinding,
                    'inbox_item_id' => 'inbox_merged',
                    'result_bridge_id' => 'srrb_merged',
                    'merge_governance' => ['status' => 'merged', 'merge_commit' => 'deadbeef'],
                ],
                $session,
                AutonomousLoopReceiptIntegrityService::STATE_MERGED,
                true,
            ],
            'planned' => [
                [
                    'cycle_id' => 'c_planned',
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'merge_performed' => false,
                    'owner' => 'forge',
                    'selected_finding' => $baseFinding,
                    'inbox_item_id' => 'inbox_planned',
                    'result_bridge_id' => 'srrb_planned',
                    'blockers' => ['forge_runtime_dispatch_planned_only'],
                ],
                $session,
                AutonomousLoopReceiptIntegrityService::STATE_PLANNED,
                false,
            ],
            'blocked' => [
                [
                    'cycle_id' => 'c_blocked',
                    'final_status' => 'blocked',
                    'merge_performed' => false,
                    'selected_finding' => $baseFinding,
                    'inbox_item_id' => 'inbox_blocked',
                    'blockers' => ['forge_obra_required'],
                ],
                $session,
                AutonomousLoopReceiptIntegrityService::STATE_BLOCKED,
                false,
            ],
        ];
    }

    public function test_evidence_refs_keep_operator_labels_for_review(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c_evidence',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Evidence labels'],
            'inbox_item_id' => 'inbox_ev_1',
            'result_bridge_id' => 'srrb_ev_1',
            'owner_flow' => [
                'owner_execution_id' => 'exec_1',
                'owner_sandbox_run_id' => 'run_1',
            ],
        ], ['session_id' => 'aess_ev']);

        $refs = $receipt['evidence_refs'];
        $this->assertArrayHasKey('result_bridge_id', $refs);
        $this->assertArrayHasKey('inbox_item_id', $refs);
        $this->assertSame('srrb_ev_1', $refs['result_bridge_id']);
        $this->assertSame('inbox_ev_1', $refs['inbox_item_id']);
        $this->assertSame('exec_1', $refs['owner_execution_id']);
        $this->assertSame('run_1', $refs['owner_sandbox_run_id']);
    }

    public function test_planned_cycle_without_pre_merge_inbox_is_incomplete(): void
    {
        $receipt = $this->service()->receiptFor([
            'cycle_id' => 'c_no_inbox',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'merge_performed' => false,
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'Missing pre-merge'],
        ], ['session_id' => 'aess_inc']);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::STATE_PLANNED, $receipt['lifecycle_state']);
        $this->assertSame(AutonomousLoopReceiptIntegrityService::INTEGRITY_INCOMPLETE, $receipt['integrity']);
        $this->assertContains('inbox_pre_merge', $receipt['missing']);
        $this->assertContains('evidence_refs', $receipt['missing']);
    }
}
