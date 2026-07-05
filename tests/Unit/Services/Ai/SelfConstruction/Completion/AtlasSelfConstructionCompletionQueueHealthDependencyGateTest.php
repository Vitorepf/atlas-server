<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCompletionQueueHealthDependencyGate;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionQueueHealthDependencyGateTest extends TestCase
{
    private AtlasSelfConstructionCompletionQueueHealthDependencyGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasSelfConstructionCompletionQueueHealthDependencyGate;
    }

    public function test_lease_leak_blocks_readiness_with_verify_command(): void
    {
        $result = $this->gate->evaluate(['lease_leak_detected' => true]);

        $this->assertFalse($result['ready']);
        $this->assertContains('lease_leak_detected', $result['blockers']);
        $this->assertNotEmpty($result['verify_commands']);
    }

    public function test_malformed_blockers_block_readiness_with_verify_command(): void
    {
        $result = $this->gate->evaluate(['malformed_blocker_count' => 2]);

        $this->assertFalse($result['ready']);
        $this->assertContains('malformed_blockers:2', $result['blockers']);
        $this->assertNotEmpty($result['verify_commands']);
    }

    public function test_dry_queue_blocks_readiness_with_verify_command(): void
    {
        $result = $this->gate->evaluate(['claimable_depth' => 0]);

        $this->assertFalse($result['ready']);
        $this->assertContains('dry_queue', $result['blockers']);
        $this->assertNotEmpty($result['verify_commands']);
    }

    public function test_healthy_queue_passes(): void
    {
        $result = $this->gate->evaluate([
            'lease_leak_detected' => false,
            'malformed_blocker_count' => 0,
            'claimable_depth' => 10,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gate->evaluate([]);
        $this->assertSame(AtlasSelfConstructionCompletionQueueHealthDependencyGate::SCHEMA, $result['schema']);
    }
}
