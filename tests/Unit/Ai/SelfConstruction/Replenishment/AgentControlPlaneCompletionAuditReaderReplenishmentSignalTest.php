<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenishment;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneCompletionAuditReader;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneCompletionAuditReaderReplenishmentSignalTest extends TestCase
{
    private function reader(): AgentControlPlaneCompletionAuditReader
    {
        return new AgentControlPlaneCompletionAuditReader;
    }

    public function test_draining_completion_velocity_emits_replenish_hint_with_counts(): void
    {
        $result = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 12,
            'completed_dry_run_count_previous' => 9,
            'claimable_per_active_worker' => 2,
        ]);

        $this->assertTrue($result['completion_velocity_replenish']);
        $this->assertSame(3, $result['completed_dry_run_delta']);
        $this->assertSame(2.0, $result['claimable_per_active_worker']);
    }

    public function test_stable_claimable_depth_emits_no_replenish_hint(): void
    {
        $result = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 12,
            'completed_dry_run_count_previous' => 12,
            'claimable_per_active_worker' => 10,
        ]);

        $this->assertFalse($result['completion_velocity_replenish']);
        $this->assertArrayNotHasKey('completed_dry_run_delta', $result);
    }

    public function test_high_claimable_depth_does_not_emit_even_with_completions(): void
    {
        $result = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 20,
            'completed_dry_run_count_previous' => 10,
            'claimable_per_active_worker' => 8,
        ]);

        $this->assertFalse($result['completion_velocity_replenish']);
    }

    public function test_existing_audit_methods_are_unaffected(): void
    {
        $reader = $this->reader();
        $payload = $reader->completionAuditPayload(['completion_audit' => ['x' => 1]]);

        $this->assertSame(['x' => 1], $payload);
    }
}
