<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilLeverageRanker;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilLeverageRankerWorkerContinuityTest extends TestCase
{
    private function ranker(): AtlasStrategyCouncilLeverageRanker
    {
        return new AtlasStrategyCouncilLeverageRanker;
    }

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'organ' => 'cortex',
            'capability_gap' => 1,
            'user_impact' => 1,
            'autonomy_unlock' => 0,
            'waste_reduction' => 0,
            'risk' => 0,
            'evidence_refs' => ['ref-1'],
            'dependency_count' => 0,
        ], $overrides);
    }

    public function test_worker_continuity_delta_outranks_otherwise_equal_candidate_without_it(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('with-continuity', ['worker_continuity_delta' => 3]),
            $this->candidate('without-continuity'),
        ]);

        $ids = array_column($result['ranked'], 'candidate_id');
        $this->assertSame(['with-continuity', 'without-continuity'], $ids);

        $winner = $result['ranked'][0];
        $this->assertSame(3, $winner['factors']['worker_continuity_delta']);
        $this->assertContains('worker_continuity_delta=3', $winner['reasons']);
        $this->assertSame('worker_continuity_delta=3_beats_0', $winner['dominance_trace']);
    }

    public function test_proxy_only_task_count_without_real_levers_is_still_rejected(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('proxy-only', [
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
                'worker_continuity_delta' => 0,
                'proxy_signals' => ['task_count'],
            ]),
        ]);

        $this->assertSame([], $result['ranked']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('proxy-only', $result['rejected'][0]['candidate_id']);
        $this->assertStringContainsString('proxy_signals_only', $result['rejected'][0]['reasons'][0]);
    }

    public function test_worker_continuity_delta_alone_counts_as_a_real_lever_and_survives_proxy_check(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('continuity-real-lever', [
                'capability_gap' => 0,
                'user_impact' => 0,
                'autonomy_unlock' => 0,
                'worker_continuity_delta' => 2,
                'proxy_signals' => ['task_count'],
            ]),
        ]);

        $this->assertCount(1, $result['ranked']);
        $this->assertSame('continuity-real-lever', $result['ranked'][0]['candidate_id']);
        $this->assertSame([], $result['rejected']);
    }

    // ── worker-floor veto (AC) ───────────────────────────────────────────────

    public function test_low_worker_floor_ranks_queue_feed_or_repair_above_higher_leverage_candidate(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('long-horizon', ['autonomy_unlock' => 10, 'capability_gap' => 10]),
            $this->candidate('queue-feed', ['is_queue_feed_or_repair' => true]),
        ], ['claimable_per_active_worker' => 1.5]);

        $ids = array_column($result['ranked'], 'candidate_id');
        $this->assertSame(['queue-feed', 'long-horizon'], $ids);

        $winner = $result['ranked'][0];
        $this->assertSame(1, $winner['factors']['worker_floor_veto']);
        $this->assertContains('worker_floor_veto=1', $winner['reasons']);
        $this->assertSame('worker_floor_veto=1_beats_0', $winner['dominance_trace']);
    }

    public function test_healthy_worker_floor_preserves_existing_leverage_ranking(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('long-horizon', ['autonomy_unlock' => 10, 'capability_gap' => 10]),
            $this->candidate('queue-feed', ['is_queue_feed_or_repair' => true]),
        ], ['claimable_per_active_worker' => 10.0]);

        $ids = array_column($result['ranked'], 'candidate_id');
        $this->assertSame(['long-horizon', 'queue-feed'], $ids);
    }

    public function test_queue_feed_flag_without_low_worker_floor_does_not_veto(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('long-horizon', ['autonomy_unlock' => 10, 'capability_gap' => 10]),
            $this->candidate('queue-feed', ['is_queue_feed_or_repair' => true]),
        ]);

        $ids = array_column($result['ranked'], 'candidate_id');
        $this->assertSame(['long-horizon', 'queue-feed'], $ids);
    }

    public function test_low_worker_floor_without_queue_feed_flag_does_not_veto(): void
    {
        $result = $this->ranker()->rank([
            $this->candidate('long-horizon', ['autonomy_unlock' => 10, 'capability_gap' => 10]),
            $this->candidate('other'),
        ], ['claimable_per_active_worker' => 1.5]);

        $ids = array_column($result['ranked'], 'candidate_id');
        $this->assertSame(['long-horizon', 'other'], $ids);
    }
}
