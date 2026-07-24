<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosSovereignContinuationCoordinator;
use Tests\TestCase;

/**
 * P2e / R100: A awaits H1–H7 (no reserved effect); B settles; A resumes exactly once.
 */
final class AaeosSovereignContinuationTest extends TestCase
{
    public function test_r100_two_work_item_a_waits_b_settles_a_resumes_once(): void
    {
        $coord = new AaeosSovereignContinuationCoordinator;
        $actionHashA = hash('sha256', 'work-a|action-v1');

        $proof = $coord->proveR100TwoWorkItemProgress(
            'work-A',
            'work-B',
            [
                'action_hash' => $actionHashA,
                'continuation_ref' => 'cont:work-A:v1',
                'h_gates' => ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7'],
            ],
            'worker-b',
            [
                'landed_sha' => str_repeat('1', 40),
                'settlement_event_id' => 'settle-B-1',
                'observer_identity' => 'test.settler',
            ],
            'decision-event-A-1',
        );

        $this->assertTrue($proof['ok'], json_encode($proof));
        $this->assertSame('R100', $proof['rule']);
        $this->assertFalse($proof['global_halt']);
        $this->assertTrue($proof['progress_witness']['b_settled_before_a_decision']);
        $this->assertTrue($proof['progress_witness']['a_resumed_exactly_once']);
        $this->assertTrue($proof['progress_witness']['second_resume_refused']);

        $a = $coord->item('work-A');
        $b = $coord->item('work-B');
        $this->assertSame(AaeosSovereignContinuationCoordinator::STATUS_RESUMED, $a['status'] ?? null);
        $this->assertSame(1, (int) ($a['resume_count'] ?? 0));
        $this->assertFalse((bool) ($a['reserved_effect'] ?? true));
        $this->assertSame(AaeosSovereignContinuationCoordinator::STATUS_SETTLED, $b['status'] ?? null);
        $this->assertTrue((bool) ($b['settled'] ?? false));
        $this->assertSame($actionHashA, $a['action_hash'] ?? null);
        $this->assertSame('cont:work-A:v1', $a['continuation_ref'] ?? null);
    }

    public function test_awaiting_sovereign_does_not_block_unrelated_claim(): void
    {
        $coord = new AaeosSovereignContinuationCoordinator;
        $a = $coord->awaitSovereign('item-A', [
            'action_hash' => hash('sha256', 'a'),
            'h_gates' => ['H1'],
        ]);
        $this->assertTrue($a['ok']);
        $this->assertFalse($a['blocks_unrelated_work']);
        $this->assertFalse($a['produces_reserved_effect']);

        $b = $coord->claim('item-B', 'w1', ['action_hash' => hash('sha256', 'b')]);
        $this->assertTrue($b['ok']);
        $this->assertSame(AaeosSovereignContinuationCoordinator::STATUS_CLAIMED, $b['status']);
    }

    public function test_resume_requires_matching_action_hash_and_decision(): void
    {
        $coord = new AaeosSovereignContinuationCoordinator;
        $hash = hash('sha256', 'resume-hash');
        $coord->awaitSovereign('item-R', [
            'action_hash' => $hash,
            'continuation_ref' => 'cont:R',
            'h_gates' => ['H3'],
        ]);

        $noDecision = $coord->resume('item-R', 'cont:R', $hash);
        $this->assertFalse($noDecision['ok']);
        $this->assertSame('resume_requires_decision_issued', $noDecision['reason']);

        $coord->issueDecision('item-R', 'dec-1');
        $badHash = $coord->resume('item-R', 'cont:R', hash('sha256', 'other'));
        $this->assertFalse($badHash['ok']);
        $this->assertSame('action_hash_mismatch', $badHash['reason']);

        $ok = $coord->resume('item-R', 'cont:R', $hash);
        $this->assertTrue($ok['ok']);
        $again = $coord->resume('item-R', 'cont:R', $hash);
        $this->assertFalse($again['ok']);
        $this->assertSame('resume_requires_awaiting_sovereign', $again['reason']);
    }

    public function test_journal_orders_b_settle_before_a_resume(): void
    {
        $coord = new AaeosSovereignContinuationCoordinator;
        $coord->proveR100TwoWorkItemProgress(
            'A',
            'B',
            ['action_hash' => hash('sha256', 'A'), 'continuation_ref' => 'cA', 'h_gates' => ['H1']],
            'wb',
            ['landed_sha' => str_repeat('2', 40)],
            'dec-A',
        );
        $events = array_column($coord->journal(), 'event');
        $settleIdx = array_search('settled', $events, true);
        $resumeIdx = array_search('resumed', $events, true);
        $this->assertNotFalse($settleIdx);
        $this->assertNotFalse($resumeIdx);
        $this->assertLessThan($resumeIdx, $settleIdx);
    }
}
