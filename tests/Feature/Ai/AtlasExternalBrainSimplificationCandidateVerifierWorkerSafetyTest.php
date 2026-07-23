<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationCandidateVerifier;
use Tests\TestCase;

final class AtlasExternalBrainSimplificationCandidateVerifierWorkerSafetyTest extends TestCase
{
    private function baseCandidate(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => 'cand-1',
            'kind' => 'deletion',
            'consumer_count' => 0,
            'behavior_coverage' => true,
            'test_coverage' => true,
            'rollback_notes' => 'revert via git commit ref abc123',
            'lines_deleted' => 500,
        ], $overrides);
    }

    public function test_queue_serving_candidate_without_continuity_evidence_blocked_despite_high_lines_deleted(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate([
            'touches_queue_serving_organ' => true,
            'lines_deleted' => 5000,
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains(AtlasExternalBrainSimplificationCandidateVerifier::BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE, $result['blockers']);
        $this->assertSame(0, $result['net_reduction_score']);
        $this->assertFalse($result['worker_continuity_safe']);
    }

    public function test_queue_serving_candidate_with_partial_evidence_still_blocked(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate([
            'touches_queue_serving_organ' => true,
            'worker_continuity_evidence' => ['claimable_per_active_worker_before' => 1.0],
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains(AtlasExternalBrainSimplificationCandidateVerifier::BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE, $result['blockers']);
    }

    public function test_queue_serving_candidate_with_dropped_claimable_rate_blocked(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate([
            'touches_queue_serving_organ' => true,
            'worker_continuity_evidence' => [
                'claimable_per_active_worker_before' => 1.2,
                'claimable_per_active_worker_after' => 0.4,
                'no_claimable_task_incidents_before' => 0,
                'no_claimable_task_incidents_after' => 0,
            ],
        ]));

        $this->assertFalse($result['approved']);
        $this->assertContains(AtlasExternalBrainSimplificationCandidateVerifier::BLOCKER_MISSING_WORKER_CONTINUITY_SAFETY_EVIDENCE, $result['blockers']);
    }

    public function test_queue_serving_candidate_with_full_continuity_evidence_and_rollback_approved(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate([
            'touches_queue_serving_organ' => true,
            'consumer_count' => 2,
            'migration_plan' => 'route remaining consumers through AtlasTaskFabricReadyQueueValueBalancer',
            'worker_continuity_evidence' => [
                'claimable_per_active_worker_before' => 0.8,
                'claimable_per_active_worker_after' => 1.1,
                'no_claimable_task_incidents_before' => 5,
                'no_claimable_task_incidents_after' => 3,
            ],
        ]));

        $this->assertTrue($result['approved']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['worker_continuity_safe']);
        $this->assertGreaterThan(0, $result['net_reduction_score']);
    }

    public function test_merge_candidate_touching_queue_serving_organ_needs_both_behavior_tests_and_continuity(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate([
            'kind' => 'merge',
            'touches_queue_serving_organ' => true,
            'preserved_behavior_tests' => ['tests/Unit/QueueBalancerTest.php'],
            'worker_continuity_evidence' => [
                'claimable_per_active_worker_before' => 1.0,
                'claimable_per_active_worker_after' => 1.0,
                'no_claimable_task_incidents_before' => 2,
                'no_claimable_task_incidents_after' => 2,
            ],
        ]));

        $this->assertTrue($result['approved']);
    }

    public function test_non_queue_serving_candidate_unaffected_by_new_checks(): void
    {
        $verifier = new AtlasExternalBrainSimplificationCandidateVerifier();
        $result = $verifier->verify($this->baseCandidate(['touches_queue_serving_organ' => false]));

        $this->assertTrue($result['approved']);
        $this->assertNull($result['worker_continuity_safe']);
    }
}
