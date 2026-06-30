<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use Tests\TestCase;

class AtlasSelfConstructionUnattendedStallClassifierWorkerFeedTest extends TestCase
{
    private function classifier(): AtlasSelfConstructionUnattendedStallClassifier
    {
        return new AtlasSelfConstructionUnattendedStallClassifier;
    }

    private function snapshot(array $queueOverride): array
    {
        return [
            'facts' => [
                'queue' => array_merge([
                    'depth' => 5,
                    'claimable_count' => 5,
                    'safety_stop' => false,
                ], $queueOverride),
                'heartbeat' => ['is_stale' => false],
                'native_worker' => ['ready' => true],
                'verification' => ['failed_run_count' => 0],
                'merge' => ['blocked' => false],
                'replenisher' => ['last_run_status' => 'ok'],
                'brain_quota' => [],
            ],
        ];
    }

    public function test_thin_buffer_with_active_workers_and_recent_no_claimable_classifies_feed_starvation_risk(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'active_leases' => 4,
            'claimable_count' => 8,
            'recent_no_claimable_count' => 2,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::FEED_STARVATION_RISK, $result['classification']);
        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::SEVERITY_MEDIUM, $result['severity']);
        $this->assertTrue($result['recovery_needed']);
    }

    public function test_no_active_workers_preserves_healthy_classification(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'active_leases' => 0,
            'claimable_count' => 5,
            'recent_no_claimable_count' => 3,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $result['classification']);
    }

    public function test_comfortable_buffer_preserves_healthy_classification(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'active_leases' => 2,
            'claimable_count' => 20,
            'recent_no_claimable_count' => 1,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $result['classification']);
    }

    public function test_thin_buffer_without_recent_no_claimable_observations_does_not_fire(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'active_leases' => 4,
            'claimable_count' => 4,
            'recent_no_claimable_count' => 0,
        ]));

        $this->assertNotSame(AtlasSelfConstructionUnattendedStallClassifier::FEED_STARVATION_RISK, $result['classification']);
    }

    public function test_worker_unavailable_still_takes_precedence_over_feed_starvation(): void
    {
        $snapshot = $this->snapshot([
            'active_leases' => 4,
            'claimable_count' => 4,
            'recent_no_claimable_count' => 2,
        ]);
        $snapshot['facts']['native_worker'] = ['ready' => false];

        $result = $this->classifier()->classify($snapshot);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE, $result['classification']);
    }

    // ── AC1: lease_leak_detected / leases_match_claimed=false yield a reap/recover action ──

    public function test_lease_leak_detected_yields_reap_leases_action_before_wait(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'lease_leak_detected' => true,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK, $result['classification']);
        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::ACTION_REAP_LEASES, $result['recommended_action']);
        $this->assertTrue($result['recovery_needed']);
    }

    public function test_leases_match_claimed_false_yields_reap_leases_action(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'leases_match_claimed' => false,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK, $result['classification']);
        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::ACTION_REAP_LEASES, $result['recommended_action']);
    }

    public function test_lease_leak_takes_precedence_over_feed_starvation_and_waiting_states(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'lease_leak_detected' => true,
            'claimable_count' => 0,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK, $result['classification']);
        $this->assertNotSame(AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES, $result['classification']);
        $this->assertNotSame(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY, $result['classification']);
    }

    public function test_worker_unavailable_still_takes_precedence_over_lease_leak(): void
    {
        $snapshot = $this->snapshot(['lease_leak_detected' => true]);
        $snapshot['facts']['native_worker'] = ['ready' => false];

        $result = $this->classifier()->classify($snapshot);

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE, $result['classification']);
    }

    public function test_clean_lease_state_does_not_trigger_lease_leak(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'lease_leak_detected' => false,
            'leases_match_claimed' => true,
        ]));

        $this->assertNotSame(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK, $result['classification']);
        $this->assertNull($result['recommended_action']);
    }

    public function test_healthy_snapshot_with_comfortable_worker_feed_has_no_recommended_action(): void
    {
        $result = $this->classifier()->classify($this->snapshot([
            'active_leases' => 2,
            'claimable_count' => 20,
            'recent_no_claimable_count' => 0,
        ]));

        $this->assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $result['classification']);
        $this->assertFalse($result['recovery_needed']);
        $this->assertNull($result['recommended_action']);
    }
}
