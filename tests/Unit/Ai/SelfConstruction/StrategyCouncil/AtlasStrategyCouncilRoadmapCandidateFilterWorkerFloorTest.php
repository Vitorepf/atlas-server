<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRoadmapCandidateFilter;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilRoadmapCandidateFilterWorkerFloorTest extends TestCase
{
    private function filter(): AtlasStrategyCouncilRoadmapCandidateFilter
    {
        return new AtlasStrategyCouncilRoadmapCandidateFilter;
    }

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'organ' => 'cortex',
            'evidence_path' => 'docs/evidence.md',
            'kind' => 'worker_floor',
            'stale' => true,
        ], $overrides);
    }

    public function test_stale_worker_floor_candidate_with_fresh_queue_refs_is_kept(): void
    {
        $result = $this->filter()->filter([
            $this->candidate('wf-1', [
                'queue_health_ref' => 'evidence:queue_health:123',
                'queued_targets_ref' => 'evidence:queued_targets:456',
            ]),
        ]);

        $keptIds = array_column($result['kept'], 'candidate_id');
        $this->assertSame(['wf-1'], $keptIds);
        $this->assertSame([], $result['dropped']);
    }

    public function test_stale_worker_floor_candidate_missing_queue_refs_is_dropped_with_stale_queue_context(): void
    {
        $result = $this->filter()->filter([
            $this->candidate('wf-2'),
        ]);

        $this->assertSame([], $result['kept']);
        $this->assertCount(1, $result['dropped']);
        $this->assertSame('wf-2', $result['dropped'][0]['candidate_id']);
        $this->assertSame('dropped:stale_queue_context', $result['dropped'][0]['drop_reason']);
    }

    public function test_stale_worker_floor_candidate_with_only_one_fresh_ref_is_dropped(): void
    {
        $result = $this->filter()->filter([
            $this->candidate('wf-3', ['queue_health_ref' => 'evidence:queue_health:123']),
        ]);

        $this->assertSame([], $result['kept']);
        $this->assertSame('dropped:stale_queue_context', $result['dropped'][0]['drop_reason']);
    }

    public function test_stale_non_worker_floor_candidate_still_drops_with_generic_stale_reason(): void
    {
        $result = $this->filter()->filter([
            $this->candidate('other-1', [
                'kind' => 'capability_gap',
                'queue_health_ref' => 'evidence:queue_health:123',
                'queued_targets_ref' => 'evidence:queued_targets:456',
            ]),
        ]);

        $this->assertSame([], $result['kept']);
        $this->assertSame('dropped:stale', $result['dropped'][0]['drop_reason']);
    }

    // ── worker-floor admission (separate from the stale-exemption tests above) ───────────────

    private function roadmapCandidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'organ' => 'cortex',
            'evidence_path' => 'docs/evidence.md',
            'kind' => 'capability_gap',
            'stale' => false,
            'implementation_scope' => true,
            'runnable_acceptance' => true,
            'near_term_queue_feed_value' => true,
        ], $overrides);
    }

    public function test_worker_floor_low_defers_candidate_missing_implementation_scope(): void
    {
        $result = $this->filter()->filter(
            [$this->roadmapCandidate('roadmap-1', ['implementation_scope' => false])],
            [],
            true,
        );

        $this->assertSame([], $result['kept']);
        $this->assertSame('deferred:worker_floor_missing_implementation_scope', $result['dropped'][0]['drop_reason']);
    }

    public function test_worker_floor_low_defers_candidate_missing_runnable_acceptance(): void
    {
        $result = $this->filter()->filter(
            [$this->roadmapCandidate('roadmap-2', ['runnable_acceptance' => false])],
            [],
            true,
        );

        $this->assertSame([], $result['kept']);
        $this->assertSame('deferred:worker_floor_missing_runnable_acceptance', $result['dropped'][0]['drop_reason']);
    }

    public function test_worker_floor_low_defers_candidate_without_near_term_queue_feed_value(): void
    {
        $result = $this->filter()->filter(
            [$this->roadmapCandidate('roadmap-3', ['near_term_queue_feed_value' => false])],
            [],
            true,
        );

        $this->assertSame([], $result['kept']);
        $this->assertSame('deferred:worker_floor_no_near_term_queue_feed_value', $result['dropped'][0]['drop_reason']);
    }

    public function test_worker_floor_low_keeps_fully_qualified_candidate(): void
    {
        $result = $this->filter()->filter(
            [$this->roadmapCandidate('roadmap-4')],
            [],
            true,
        );

        $this->assertSame(['roadmap-4'], array_column($result['kept'], 'candidate_id'));
        $this->assertSame([], $result['dropped']);
    }

    public function test_worker_floor_healthy_preserves_existing_admission_behavior(): void
    {
        $result = $this->filter()->filter(
            [$this->roadmapCandidate('roadmap-5', [
                'implementation_scope' => false,
                'runnable_acceptance' => false,
                'near_term_queue_feed_value' => false,
            ])],
            [],
            false,
        );

        $this->assertSame(['roadmap-5'], array_column($result['kept'], 'candidate_id'));
        $this->assertSame([], $result['dropped']);
    }
}
