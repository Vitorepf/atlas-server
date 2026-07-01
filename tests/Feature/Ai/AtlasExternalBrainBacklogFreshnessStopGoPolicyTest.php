<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogFreshnessStopGoPolicy;
use Tests\TestCase;

final class AtlasExternalBrainBacklogFreshnessStopGoPolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainBacklogFreshnessStopGoPolicy
    {
        return new AtlasExternalBrainBacklogFreshnessStopGoPolicy;
    }

    public function test_high_malformed_rate_returns_repair_queue_with_health_evidence(): void
    {
        $r = $this->policy()->decide([
            'health_snapshot' => ['malformed_rate' => 0.5],
        ]);

        self::assertSame('repair_queue', $r['decision']);
        self::assertContains('health_snapshot.malformed_rate', $r['required_evidence']);
        self::assertContains('health_snapshot.give_back_rate', $r['required_evidence']);
    }

    public function test_high_give_back_rate_returns_repair_queue_with_health_evidence(): void
    {
        $r = $this->policy()->decide([
            'health_snapshot' => ['give_back_rate' => 0.9],
        ]);

        self::assertSame('repair_queue', $r['decision']);
        self::assertContains('health_snapshot.give_back_rate', $r['required_evidence']);
    }

    public function test_stale_claimable_backlog_with_no_consumption_returns_drain_existing(): void
    {
        $r = $this->policy()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 20, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
        ]);

        self::assertSame('drain_existing', $r['decision']);
    }

    public function test_stale_claimable_backlog_with_batch_fixing_bottleneck_returns_create_more(): void
    {
        $r = $this->policy()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 20, 'oldest_age_p95_seconds' => 7200, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'proposed_batch_leverage' => ['fixes_bottleneck' => true],
        ]);

        self::assertSame('create_more', $r['decision']);
    }

    public function test_blocked_quarantined_debt_cannot_masquerade_as_healthy_when_worker_feed_below_floor(): void
    {
        $r = $this->policy()->decide([
            'health_snapshot' => ['dry_queue' => false],
            'queue_age_histogram' => ['claimable_depth' => 0, 'oldest_age_p95_seconds' => 100, 'stale_threshold_seconds' => 3600],
            'worker_idle_prediction' => ['observed_consumption_count' => 0],
            'backlog_composition' => ['blocked_count' => 10, 'quarantined_count' => 2],
            'worker_feed' => ['active_worker_count' => 3, 'claimable_per_active_worker' => 0.5, 'floor' => 2.0],
        ]);

        self::assertSame('repair_queue', $r['decision']);
        self::assertContains('create_more', $r['blocked_next_actions']);
        self::assertArrayHasKey('allowed_next_actions', $r);
        self::assertArrayHasKey('blocked_next_actions', $r);
    }

    public function test_output_lists_allowed_and_blocked_next_actions_for_healthy_state(): void
    {
        $r = $this->policy()->decide([]);

        self::assertArrayHasKey('allowed_next_actions', $r);
        self::assertArrayHasKey('blocked_next_actions', $r);
        self::assertNotEmpty($r['allowed_next_actions']);
    }
}
