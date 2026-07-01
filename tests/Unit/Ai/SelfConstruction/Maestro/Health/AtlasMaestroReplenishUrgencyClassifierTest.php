<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use Tests\TestCase;

final class AtlasMaestroReplenishUrgencyClassifierTest extends TestCase
{
    public function test_empty_queue_is_high_with_queue_dry_reason(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 0, 'p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 0, 'serve_rate_per_minute' => 5.0, 'seconds_until_dry' => 900],
        )->classify();

        $this->assertSame('atlas.maestro.health.replenish_urgency.v1', $result['schema']);
        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame(['queue_dry'], $result['reasons']);
        $this->assertSame(0, $result['inputs']['claimable_depth']);
    }

    public function test_short_seconds_until_dry_is_high(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 20, 'p95_seconds' => 20],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 3, 'serve_rate_per_minute' => 3.0, 'seconds_until_dry' => 59],
            thresholdHighSeconds: 60,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame(['seconds_until_dry_below_threshold_high'], $result['reasons']);
        $this->assertSame(59, $result['inputs']['seconds_until_dry']);
        $this->assertSame(60, $result['inputs']['threshold_high_seconds']);
    }

    public function test_stale_p95_claimable_age_is_mid(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 1200, 'p95_seconds' => 900],
            lease: ['p95_seconds' => 30, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 9, 'serve_rate_per_minute' => 1.0, 'seconds_until_dry' => 2000],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertSame(['p95_claimable_age_above_threshold_stale'], $result['reasons']);
        $this->assertSame(1200, $result['inputs']['oldest_claimable_seconds']);
        $this->assertSame(30, $result['inputs']['p95_lease_lifetime_seconds']);
    }

    public function test_healthy_state_is_low_and_deterministic(): void
    {
        $classifier = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
        );

        $first = $classifier->classify();
        $second = $classifier->classify();

        $this->assertSame('LOW', $first['urgency']);
        $this->assertSame(['no_replenish_pressure'], $first['reasons']);
        $this->assertSame($first, $second);
    }

    public function test_suspected_stuck_leases_above_zero_adds_mid_reason(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 1],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000, 'active_claimed_workers' => 0, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdHighStuckLeases: 3,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertContains('suspected_stuck_leases_threaten_throughput', $result['reasons']);
        $this->assertSame(1, $result['inputs']['suspected_stuck_leases']);
    }

    public function test_high_stuck_leases_raise_urgency_to_high(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 3],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000, 'active_claimed_workers' => 0, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdHighStuckLeases: 3,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('high_stuck_lease_threat', $result['reasons']);
    }

    public function test_low_claimable_depth_with_active_workers_is_high_before_queue_dry(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 2, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 3, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('low_claimable_depth_with_active_worker_pressure', $result['reasons']);
    }

    public function test_healthy_deep_queue_with_active_workers_stays_low(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 5, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('LOW', $result['urgency']);
        $this->assertContains('no_replenish_pressure', $result['reasons']);
    }

    /**
     * @param  array<string,mixed>  $queue
     * @param  array<string,mixed>  $lease
     * @param  array<string,mixed>  $idle
     */
    private function classifier(
        array $queue,
        array $lease,
        array $idle,
        int $thresholdHighSeconds = 300,
        int $thresholdMidSeconds = 1800,
        int $thresholdStaleClaimableAgeSeconds = 3600,
        int $thresholdLowClaimableDepth = 5,
        int $thresholdMinActiveWorkers = 2,
        int $thresholdHighStuckLeases = 3,
    ): AtlasMaestroReplenishUrgencyClassifier {
        return new AtlasMaestroReplenishUrgencyClassifier(
            new class($queue)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function histogram(): array
                {
                    return $this->facts;
                }
            },
            new class($lease)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function histogram(): array
                {
                    return $this->facts;
                }
            },
            new class($idle)
            {
                public function __construct(private readonly array $facts) {}

                /** @return array<string,mixed> */
                public function project(): array
                {
                    return $this->facts;
                }
            },
            $thresholdHighSeconds,
            $thresholdMidSeconds,
            $thresholdStaleClaimableAgeSeconds,
            $thresholdLowClaimableDepth,
            $thresholdMinActiveWorkers,
            $thresholdHighStuckLeases,
        );
    }

    // ── next_action classification ───────────────────────────────────────────

    public function test_low_depth_with_active_workers_recommends_originate(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 3, 'active_claimed_workers' => 3, 'seconds_until_dry' => 900, 'serve_rate_per_minute' => 2.0],
        )->classify();

        $this->assertSame('originate', $result['next_action']);
    }

    public function test_poison_pressure_recommends_drain_poison(): void
    {
        $result = $this->classifier(
            queue: ['p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 10, 'poison_pressure' => 5, 'serve_rate_per_minute' => 1.0],
        )->classify();

        $this->assertContains('poison_pressure_detected', $result['reasons']);
        $this->assertSame('drain_poison', $result['next_action']);
    }

    public function test_stuck_leases_recommends_unblock(): void
    {
        $result = $this->classifier(
            queue: ['p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 4],
            idle: ['claimable_depth' => 10, 'poison_pressure' => 0],
        )->classify();

        $this->assertSame('unblock', $result['next_action']);
    }

    public function test_healthy_depth_recommends_monitoring_without_stopping_origination(): void
    {
        $result = $this->classifier(
            queue: ['p95_seconds' => 10],
            lease: ['p95_seconds' => 10, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 50, 'seconds_until_dry' => 7200, 'serve_rate_per_minute' => 5.0],
        )->classify();

        $this->assertSame('LOW', $result['urgency']);
        $this->assertSame('monitor_idle_supply', $result['next_action']);
        $this->assertSame('monitor_idle_supply', $result['replenish_action']);
    }

    public function test_result_includes_next_action_urgency_reasons_and_inputs(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 0, 'p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 0, 'seconds_until_dry' => 900],
        )->classify();

        $this->assertArrayHasKey('next_action', $result);
        $this->assertArrayHasKey('urgency', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('inputs', $result);
    }

    // ── stale age alone → MID + monitor, not originate ────────────────────────

    public function test_stale_age_alone_with_healthy_depth_no_stuck_no_dry_eta_recommends_monitor(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 1200, 'p95_seconds' => 900],
            lease: ['p95_seconds' => 30, 'suspected_stuck_count' => 0],
            // claimable_depth=9 is healthy relative to thresholdLowClaimableDepth=5; no seconds_until_dry key.
            idle: ['claimable_depth' => 9, 'serve_rate_per_minute' => 1.0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertSame(['p95_claimable_age_above_threshold_stale'], $result['reasons']);
        $this->assertSame('monitor_idle_supply', $result['next_action']);
        $this->assertNotSame('originate', $result['next_action']);
    }

    public function test_stale_age_with_known_dry_eta_still_originates(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 1200, 'p95_seconds' => 900],
            lease: ['p95_seconds' => 30, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 9, 'serve_rate_per_minute' => 1.0, 'seconds_until_dry' => 1000],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
        )->classify();

        // seconds_until_dry=1000 < thresholdMidSeconds=600? No, 1000 > 600 so that reason won't
        // fire either — both stale-age and dry-eta-known are visibility-only here, but a known dry
        // ETA (even if not below threshold) means we are NOT in the "no dry ETA known" case.
        $this->assertSame('MID', $result['urgency']);
    }

    public function test_stale_age_with_low_depth_still_originates_via_other_branch(): void
    {
        // claimable_depth=3 is below thresholdLowClaimableDepth=5 — not the "healthy depth" case.
        $result = $this->classifier(
            queue: ['oldest_seconds' => 1200, 'p95_seconds' => 900],
            lease: ['p95_seconds' => 30, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 3, 'serve_rate_per_minute' => 1.0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
        )->classify();

        $this->assertSame('originate', $result['next_action']);
    }

    // ── existing next_action priorities preserved for HIGH-priority reasons ────

    public function test_queue_dry_preserves_originate_next_action(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 0, 'p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 0, 'serve_rate_per_minute' => 5.0, 'seconds_until_dry' => 900],
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame(['queue_dry'], $result['reasons']);
        $this->assertSame('originate', $result['next_action']);
    }

    public function test_low_claimable_depth_with_active_worker_pressure_preserves_originate(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 2, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 3, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('low_claimable_depth_with_active_worker_pressure', $result['reasons']);
        $this->assertSame('originate', $result['next_action']);
    }

    public function test_poison_pressure_preserves_drain_poison_next_action(): void
    {
        $result = $this->classifier(
            queue: ['p95_seconds' => 0],
            lease: ['p95_seconds' => 0, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 10, 'poison_pressure' => 5, 'serve_rate_per_minute' => 1.0],
        )->classify();

        $this->assertContains('poison_pressure_detected', $result['reasons']);
        $this->assertSame('drain_poison', $result['next_action']);
    }

    public function test_stuck_lease_reasons_preserve_unblock_next_action(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 100, 'p95_seconds' => 100],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 1],
            idle: ['claimable_depth' => 20, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 2000, 'active_claimed_workers' => 0, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdHighStuckLeases: 3,
        )->classify();

        $this->assertSame('MID', $result['urgency']);
        $this->assertContains('suspected_stuck_leases_threaten_throughput', $result['reasons']);
        $this->assertSame('unblock', $result['next_action']);
    }

    // ── active_workers fallback when active_claimed_workers is absent ──────────

    public function test_active_workers_key_is_accepted_when_active_claimed_workers_is_absent(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            // 'active_workers' instead of 'active_claimed_workers' — must still be read as 3, not 0.
            idle: ['claimable_depth' => 2, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_workers' => 3, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame('HIGH', $result['urgency']);
        $this->assertContains('low_claimable_depth_with_active_worker_pressure', $result['reasons']);
        $this->assertSame(3, $result['inputs']['active_claimed_workers']);
    }

    // ── classifyReplenishDecision: active drain / quality floor / high-value targets ──────

    public function test_sufficient_depth_but_high_drain_with_valuable_targets_recommends_originate_more(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 200,           // depth looks plenty sufficient
            'active_muscle_count' => 4,
            'drain_forecast' => 3,               // 4 - 3 = 1 projected active, at/below floor
            'worker_floor' => 2,
            'high_value_unqueued_targets' => 5,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_ORIGINATE_MORE, $result['decision']);
        $this->assertSame('drain_threatens_worker_floor_with_valuable_targets', $result['reason']);
    }

    public function test_safe_hold_when_active_muscles_stay_above_floor(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 50,
            'active_muscle_count' => 10,
            'drain_forecast' => 1,
            'worker_floor' => 2,
            'high_value_unqueued_targets' => 5,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_SUFFICIENT, $result['decision']);
        $this->assertNull($result['reason']);
    }

    public function test_repair_first_wins_over_drain_threat_when_quality_floor_breached(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 200,
            'active_muscle_count' => 4,
            'drain_forecast' => 3,
            'worker_floor' => 2,
            'high_value_unqueued_targets' => 5,
            'task_quality_floor_breached' => true,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_HOLD_OR_REPAIR, $result['decision']);
        $this->assertSame('task_quality_floor_breached', $result['reason']);
    }

    public function test_poison_risk_unsafe_recommends_hold_or_repair(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 0,
            'poison_risk_unsafe' => true,
        ]);

        // Safety gate must win even over the queue_dry branch.
        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_HOLD_OR_REPAIR, $result['decision']);
        $this->assertSame('poison_risk_unsafe', $result['reason']);
    }

    public function test_malformed_risk_unsafe_recommends_hold_or_repair(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'malformed_risk_unsafe' => true,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_HOLD_OR_REPAIR, $result['decision']);
        $this->assertSame('malformed_risk_unsafe', $result['reason']);
    }

    public function test_dry_queue_recommends_urgent_originate_more(): void
    {
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 0,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_ORIGINATE_MORE, $result['decision']);
        $this->assertSame('queue_dry', $result['reason']);
    }

    public function test_drain_threat_without_valuable_targets_stays_sufficient(): void
    {
        // Drain threatens the floor, but there's nothing valuable to originate — no point flagging.
        $result = (new AtlasMaestroReplenishUrgencyClassifier)->classifyReplenishDecision([
            'claimable_depth' => 50,
            'active_muscle_count' => 4,
            'drain_forecast' => 3,
            'worker_floor' => 2,
            'high_value_unqueued_targets' => 0,
        ]);

        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::DECISION_SUFFICIENT, $result['decision']);
    }

    public function test_active_claimed_workers_key_takes_precedence_over_active_workers_when_both_present(): void
    {
        $result = $this->classifier(
            queue: ['oldest_seconds' => 50, 'p95_seconds' => 50],
            lease: ['p95_seconds' => 20, 'suspected_stuck_count' => 0],
            idle: ['claimable_depth' => 2, 'serve_rate_per_minute' => 2.0, 'seconds_until_dry' => 9000, 'active_claimed_workers' => 0, 'active_workers' => 99, 'poison_pressure' => 0],
            thresholdHighSeconds: 60,
            thresholdMidSeconds: 600,
            thresholdStaleClaimableAgeSeconds: 600,
            thresholdLowClaimableDepth: 5,
            thresholdMinActiveWorkers: 2,
        )->classify();

        $this->assertSame(0, $result['inputs']['active_claimed_workers']);
    }
}
