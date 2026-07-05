<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncTaskOutcomeFreshnessGate;
use PHPUnit\Framework\TestCase;

final class AtlasKnowledgeSyncTaskOutcomeFreshnessGateTest extends TestCase
{
    private AtlasKnowledgeSyncTaskOutcomeFreshnessGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasKnowledgeSyncTaskOutcomeFreshnessGate;
    }

    public function test_stale_outcome_timestamp_blocks_context_use(): void
    {
        $result = $this->gate->evaluate([
            'outcome_timestamp' => '2020-01-01T00:00:00Z',
            'synced_timestamp' => date('c'),
        ]);

        $this->assertFalse($result['fresh']);
        $this->assertContains('stale_outcome_timestamp', $result['blockers']);
    }

    public function test_fresh_synced_timestamps_pass(): void
    {
        $now = date('c');
        $result = $this->gate->evaluate([
            'outcome_timestamp' => $now,
            'synced_timestamp' => $now,
        ]);

        $this->assertTrue($result['fresh']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_empty_timestamps_block(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertFalse($result['fresh']);
        $this->assertContains('stale_outcome_timestamp', $result['blockers']);
        $this->assertContains('stale_synced_timestamp', $result['blockers']);
    }

    public function test_stale_synced_timestamp_blocks(): void
    {
        $result = $this->gate->evaluate([
            'outcome_timestamp' => date('c'),
            'synced_timestamp' => '2020-01-01T00:00:00Z',
        ]);

        $this->assertFalse($result['fresh']);
        $this->assertContains('stale_synced_timestamp', $result['blockers']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gate->evaluate([]);
        $this->assertSame(AtlasKnowledgeSyncTaskOutcomeFreshnessGate::SCHEMA, $result['schema']);
    }
}
