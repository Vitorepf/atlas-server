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
}
