<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogFreshnessStopGoPolicy;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBreakthroughPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainBacklogFreshnessStopGoPolicy is wired into a real call path:
 * AtlasExternalBrainBreakthroughPlanner now consults it before investigating a quota stall
 * further. It is no longer an orphan.
 */
final class AtlasExternalBrainBacklogFreshnessStopGoPolicyWiringWiredTest extends TestCase
{
    public function test_stop_go_policy_is_evaluated_and_surfaced_on_every_plan(): void
    {
        $result = (new AtlasExternalBrainBreakthroughPlanner)->plan([
            'verified_count' => 1,
            'requested_target' => 5,
        ]);

        $this->assertArrayHasKey('backlog_freshness_stop_go', $result);
        $this->assertSame(
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_CREATE_MORE,
            $result['backlog_freshness_stop_go']['decision'],
        );
        $this->assertNotEmpty($result['investigations']);
    }

    public function test_non_create_more_decision_blocks_investigation_instead_of_padding_it(): void
    {
        $result = (new AtlasExternalBrainBreakthroughPlanner)->plan([
            'verified_count' => 1,
            'requested_target' => 5,
            'backlog_freshness_facts' => [
                'health_snapshot' => ['malformed_rate' => 0.5, 'give_back_rate' => 0.5],
            ],
        ]);

        $this->assertSame(
            AtlasExternalBrainBacklogFreshnessStopGoPolicy::DECISION_REPAIR_QUEUE,
            $result['backlog_freshness_stop_go']['decision'],
        );
        $this->assertSame([], $result['investigations']);
        $this->assertSame('backlog_freshness_blocked:repair_queue', $result['next_mode']);
    }
}
