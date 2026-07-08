<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneWorkerTaskEligibilityCertificationService::certify() exposes a
 * top-level `blocked_reasons` contract: non-empty and stable when status=blocked, empty when
 * status=available, derived from failed_check_ids and violation codes so callers never see
 * status=blocked with no actionable reason.
 */
final class AgentControlPlaneWorkerTaskEligibilityCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_blocked_status_has_non_empty_blocked_reasons(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-1',
            'task_packet_hash' => 'hash-1',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['blocked_reasons']);
        $this->assertContains(
            'claimable_or_claimed_task_requires_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_blocked_reasons_includes_failed_check_id(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-2',
            'task_packet_hash' => 'hash-2',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['failed_check_ids'],
        );
        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_available_status_has_empty_blocked_reasons_and_checks_all_true(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify();

        $this->assertSame('available', $result['status']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertTrue($result['checks_all_true']);
        $this->assertSame([], $result['failed_check_ids']);
    }

    public function test_blocked_reasons_is_deterministic_across_repeated_calls(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-3',
            'task_packet_hash' => 'hash-3',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $service = $this->service($queue);
        $first = $service->certify();
        $second = $service->certify();

        $this->assertSame($first['blocked_reasons'], $second['blocked_reasons']);
    }

    public function test_worker_feed_floor_required_is_active_worker_count_times_minimum_claimable_per_worker(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify([
            'active_worker_count' => 3,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame(6, $result['worker_feed_floor_required']);
    }

    public function test_worker_feed_floor_breach_blocks_with_named_reason_and_refill_recommendation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-refill-1',
            'task_packet_hash' => 'hash-refill-1',
            'status' => 'planned',
        ], ['tags' => ['refill-test']]);

        $result = $this->service($queue)->certify([
            'queue_tags' => ['refill-test'],
            'active_worker_count' => 5,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertTrue($result['worker_feed_floor_breached']);
        $this->assertContains('worker_feed_floor_breach', $result['blocked_reasons']);
        $this->assertArrayHasKey('refill_recommendation', $result);
        $this->assertSame(9, $result['refill_recommendation']['target_new_task_count']);
        $this->assertSame('worker_feed_floor_breached', $result['refill_recommendation']['reason']);
    }

    public function test_refill_recommendation_target_is_zero_when_floor_not_breached_and_certify_creates_no_tasks(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify([
            'active_worker_count' => 0,
            'minimum_claimable_per_worker' => 1,
        ]);

        $this->assertFalse($result['worker_feed_floor_breached']);
        $this->assertSame(0, $result['refill_recommendation']['target_new_task_count']);
        $this->assertTrue($result['checks']['auto_replenishment_did_not_create_tasks_during_certification']);
    }

    // ── AC: worker_executable=false, missing runnable proof, poisoned statuses, new output fields ──

    public function test_worker_executable_false_marks_task_ineligible(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-not-executable',
            'task_packet_hash' => 'hash-not-executable',
            'status' => 'planned',
            'continuation_context' => [
                'worker_executable' => false,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('claimable_or_claimed_task_not_worker_executable', $result['blocked_reasons']);
        $this->assertContains('claimable_tasks_are_worker_executable', $result['failed_check_ids']);
    }

    public function test_missing_runnable_proof_is_aggregated_as_a_violation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'no-proof-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-no-proof-'.$tag,
            'task_packet_hash' => 'hash-no-proof-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['the feature works'],
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag], 'require_runnable_proof' => true]);

        $this->assertContains('claimable_or_claimed_task_missing_runnable_proof', $result['blocked_reasons']);
        $this->assertArrayHasKey('claimable_or_claimed_task_missing_runnable_proof', $result['violation_summary_by_code']);
    }

    public function test_runnable_proof_marker_in_acceptance_criteria_clears_the_violation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'with-proof-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-with-proof-'.$tag,
            'task_packet_hash' => 'hash-with-proof-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test --filter=FooTest passes'],
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag], 'require_runnable_proof' => true]);

        $this->assertNotContains('claimable_or_claimed_task_missing_runnable_proof', $result['blocked_reasons']);
        $this->assertTrue($result['checks']['claimable_tasks_have_runnable_proof']);
    }

    public function test_runnable_proof_is_not_enforced_when_option_absent(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'proof-not-required-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-legacy-'.$tag,
            'task_packet_hash' => 'hash-legacy-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['the feature works'],
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag]]);

        $this->assertNotContains('claimable_or_claimed_task_missing_runnable_proof', $result['blocked_reasons']);
        $this->assertSame('available', $result['status']);
    }

    public function test_poisoned_status_above_threshold_is_aggregated_as_a_violation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'poisoned-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-poisoned-'.$tag,
            'task_packet_hash' => 'hash-poisoned-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test'],
            'poison_risk_score' => 0.85,
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag]]);

        $this->assertContains('claimable_or_claimed_task_poisoned_status', $result['blocked_reasons']);
        $this->assertFalse($result['checks']['claimable_tasks_are_not_poisoned']);
    }

    public function test_poison_risk_below_threshold_does_not_trigger_poisoned_violation(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'low-risk-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-low-risk-'.$tag,
            'task_packet_hash' => 'hash-low-risk-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test'],
            'poison_risk_score' => 0.2,
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag]]);

        $this->assertNotContains('claimable_or_claimed_task_poisoned_status', $result['blocked_reasons']);
        $this->assertTrue($result['checks']['claimable_tasks_are_not_poisoned']);
    }

    public function test_eligible_claimable_task_count_excludes_violating_tasks(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'eligible-count-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-clean-'.$tag,
            'task_packet_hash' => 'hash-clean-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test'],
        ], ['tags' => [$tag]]);
        $queue->enqueue([
            'task_packet_id' => 'tp-dirty-'.$tag,
            'task_packet_hash' => 'hash-dirty-'.$tag,
            'status' => 'planned',
            'continuation_context' => ['worker_executable' => false],
            'acceptance_criteria' => ['php artisan test'],
        ], ['tags' => [$tag]]);

        $result = $this->service($queue)->certify(['queue_tags' => [$tag]]);

        $this->assertSame(2, $result['claimable_task_count']);
        $this->assertSame(1, $result['eligible_claimable_task_count']);
    }

    public function test_feed_floor_status_reflects_breach_state(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $breachedTag = 'feed-floor-breached-'.bin2hex(random_bytes(4));
        $okTag = 'feed-floor-ok-'.bin2hex(random_bytes(4));

        $breached = $this->service($queue)->certify([
            'queue_tags' => [$breachedTag],
            'active_worker_count' => 2,
            'minimum_claimable_per_worker' => 1,
        ]);
        $this->assertSame('breached', $breached['feed_floor_status']);

        $queue->enqueue([
            'task_packet_id' => 'tp-feed-ok-'.$okTag,
            'task_packet_hash' => 'hash-feed-ok-'.$okTag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test'],
        ], ['tags' => [$okTag]]);
        $ok = $this->service($queue)->certify([
            'queue_tags' => [$okTag],
            'active_worker_count' => 1,
            'minimum_claimable_per_worker' => 1,
        ]);
        $this->assertSame('ok', $ok['feed_floor_status']);
    }

    public function test_certification_is_read_only_and_never_claims_creates_completes_dispatches_calls_provider_or_spends_tokens(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $tag = 'read-only-'.bin2hex(random_bytes(4));
        $queue->enqueue([
            'task_packet_id' => 'tp-read-only-'.$tag,
            'task_packet_hash' => 'hash-read-only-'.$tag,
            'status' => 'planned',
            'acceptance_criteria' => ['php artisan test'],
        ], ['tags' => [$tag]]);
        $countBefore = count($queue->list(['tag' => $tag]));

        $result = $this->service($queue)->certify(['queue_tags' => [$tag]]);

        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
        $this->assertSame($countBefore, count($queue->list(['tag' => $tag])), 'certify() must never mutate the queue');
    }

    private function service(AgentControlPlaneTaskPacketQueueRepository $queue): AgentControlPlaneWorkerTaskEligibilityCertificationService
    {
        return new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            app(AtlasSelfConstructionReadinessService::class),
            $queue,
        );
    }
}
