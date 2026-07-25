<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\Support\ForgeFastPathLifecycleSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure lifecycle state machine tests — no I/O, no FastPathService, no AWIS gate.
 */
final class ForgeFastPathLifecycleSupportTest extends TestCase
{
    public function test_review_required_when_forge_passed_without_review_record(): void
    {
        $run = ['obra_id' => 'obra-1', 'status' => 'running', 'progress_percent' => 80];
        $async = ['status' => 'completed'];
        $forgeLive = [
            'status' => 'passed',
            'diff_scope' => ['completion_gate' => ['completion_claim_allowed' => true]],
        ];

        $review = ForgeFastPathLifecycleSupport::resolveReviewState($run, $async, $forgeLive, null);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $async);
        $lifecycle = ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $async, $forgeLive, $review, $repair);

        $this->assertTrue($review['review_required']);
        $this->assertSame('pending', $review['review_status']);
        $this->assertTrue($review['no_auto_completion_without_review']);
        $this->assertSame('review_required', $lifecycle);
        $this->assertSame(
            'open_human_review',
            ForgeFastPathLifecycleSupport::resolveNextAction($lifecycle, $review, $repair, $run),
        );
        $this->assertSame(90, ForgeFastPathLifecycleSupport::resolveProgressPercent($run, $async, $forgeLive, $lifecycle));
    }

    public function test_completed_when_forge_passed_and_review_approved(): void
    {
        $run = ['status' => 'running', 'progress_percent' => 95, 'updated_at' => '2026-01-01T00:00:00+00:00'];
        $async = ['status' => 'completed'];
        $forgeLive = ['status' => 'passed', 'last_run_at' => '2026-01-01T01:00:00+00:00'];
        $reviewRecord = ['decision' => 'approved', 'review_id' => 'rev-1'];

        $review = ForgeFastPathLifecycleSupport::resolveReviewState($run, $async, $forgeLive, $reviewRecord);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $async);
        $lifecycle = ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $async, $forgeLive, $review, $repair);

        $this->assertSame('approved', $review['review_status']);
        $this->assertSame('completed', $lifecycle);
        $this->assertSame('completed', ForgeFastPathLifecycleSupport::resolveNextAction($lifecycle, $review, $repair, $run));
        $this->assertSame(100, ForgeFastPathLifecycleSupport::resolveProgressPercent($run, $async, $forgeLive, $lifecycle));
        $this->assertSame(
            '2026-01-01T00:00:00+00:00',
            ForgeFastPathLifecycleSupport::resolveCompletedAt($run, $lifecycle, $async, $forgeLive, 'NOW'),
        );
    }

    public function test_repair_available_on_degraded_with_blockers(): void
    {
        $run = ['status' => 'running', 'progress_percent' => 50];
        $async = ['status' => 'completed'];
        $forgeLive = [
            'status' => 'degraded',
            'remaining_blockers' => ['diff_out_of_scope'],
            'repair_loop' => ['status' => 'pending', 'triggered' => false],
        ];

        $review = ForgeFastPathLifecycleSupport::resolveReviewState($run, $async, $forgeLive, null);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $async);
        $lifecycle = ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $async, $forgeLive, $review, $repair);

        $this->assertTrue($repair['repair_available']);
        $this->assertSame('degraded', $lifecycle);
        $this->assertSame('run_repair', ForgeFastPathLifecycleSupport::resolveNextAction($lifecycle, $review, $repair, $run));
        $this->assertSame(75, ForgeFastPathLifecycleSupport::resolveProgressPercent($run, $async, $forgeLive, $lifecycle));
    }

    public function test_queued_and_running_async_statuses(): void
    {
        $run = ['status' => 'queued', 'progress_percent' => 10];
        $forgeLive = [];

        $asyncQueued = ['status' => 'queued'];
        $review = ForgeFastPathLifecycleSupport::resolveReviewState($run, $asyncQueued, $forgeLive, null);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $asyncQueued);
        $this->assertSame(
            'queued',
            ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $asyncQueued, $forgeLive, $review, $repair),
        );

        $asyncRunning = ['status' => 'running'];
        $this->assertSame(
            'running',
            ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $asyncRunning, $forgeLive, $review, $repair),
        );
        $this->assertSame(
            'wait_for_async_execution',
            ForgeFastPathLifecycleSupport::resolveNextAction('running', $review, $repair, $run),
        );
        $this->assertSame(60, ForgeFastPathLifecycleSupport::resolveProgressPercent($run, $asyncRunning, $forgeLive, 'running'));
    }

    public function test_blocked_and_failed_take_precedence(): void
    {
        $runBlocked = ['status' => 'blocked', 'progress_percent' => 13];
        $forgeLive = ['status' => 'passed'];
        $async = ['status' => 'completed'];
        $review = ForgeFastPathLifecycleSupport::resolveReviewState($runBlocked, $async, $forgeLive, null);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $async);

        $this->assertSame(
            'blocked',
            ForgeFastPathLifecycleSupport::resolveLifecycleStatus($runBlocked, $async, $forgeLive, $review, $repair),
        );

        $runOk = ['status' => 'running', 'progress_percent' => 40];
        $forgeFailed = ['status' => 'failed', 'remaining_blockers' => []];
        $review2 = ForgeFastPathLifecycleSupport::resolveReviewState($runOk, $async, $forgeFailed, null);
        $repair2 = ForgeFastPathLifecycleSupport::resolveRepairState($forgeFailed, $async);
        $this->assertSame(
            'failed',
            ForgeFastPathLifecycleSupport::resolveLifecycleStatus($runOk, $async, $forgeFailed, $review2, $repair2),
        );
        $this->assertSame(
            'inspect_blocker',
            ForgeFastPathLifecycleSupport::resolveNextAction('failed', $review2, $repair2, $runOk),
        );
    }

    public function test_pure_projections(): void
    {
        $this->assertNull(ForgeFastPathLifecycleSupport::asyncProjection(null));
        $this->assertNull(ForgeFastPathLifecycleSupport::forgeLiveProjection([]));

        $async = ForgeFastPathLifecycleSupport::asyncProjection([
            'execution_id' => 'ex-1',
            'status' => 'queued',
            'queued_at' => 't0',
            'extra_ignored' => true,
        ]);
        $this->assertSame('ex-1', $async['execution_id']);
        $this->assertSame('queued', $async['status']);
        $this->assertArrayNotHasKey('extra_ignored', $async);

        $forge = ForgeFastPathLifecycleSupport::forgeLiveProjection([
            'status' => 'passed',
            'last_run_at' => 't1',
            'run_id' => 'r1',
            'evidence_id' => 'e1',
            'evidence_ref_count' => 2,
            'ledger_event_count' => 3,
            'remaining_blockers' => ['x'],
            'diff_scope' => ['completion_gate' => ['completion_claim_allowed' => true]],
        ]);
        $this->assertSame('passed', $forge['status']);
        $this->assertTrue($forge['completion_claim_allowed']);
        $this->assertSame(['x'], $forge['remaining_blockers']);
    }

    public function test_prepared_run_without_forge_keeps_run_status(): void
    {
        $run = [
            'status' => 'prepared',
            'progress_percent' => 40,
            'next_action' => 'review_spec_plan_then_dispatch_forge',
        ];
        $review = ForgeFastPathLifecycleSupport::resolveReviewState($run, null, [], null);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState([], null);
        $lifecycle = ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, null, [], $review, $repair);

        $this->assertFalse($review['review_required']);
        $this->assertSame('prepared', $lifecycle);
        $this->assertSame(
            'review_spec_plan_then_dispatch_forge',
            ForgeFastPathLifecycleSupport::resolveNextAction($lifecycle, $review, $repair, $run),
        );
    }

    public function test_resolve_completed_at_uses_caller_now_iso_without_side_effects(): void
    {
        $run = ['status' => 'running'];
        $forgeLive = [];

        $this->assertSame(
            'FALLBACK-NOW',
            ForgeFastPathLifecycleSupport::resolveCompletedAt($run, 'completed', null, $forgeLive, 'FALLBACK-NOW'),
        );
        $this->assertNull(
            ForgeFastPathLifecycleSupport::resolveCompletedAt($run, 'running', null, $forgeLive, 'FALLBACK-NOW'),
        );
    }
}
