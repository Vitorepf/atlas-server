<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedQueueHealingPolicy;
use Tests\TestCase;

final class AtlasSelfConstructionUnattendedQueueHealingPolicyTest extends TestCase
{
    private function policy(): AtlasSelfConstructionUnattendedQueueHealingPolicy
    {
        return new AtlasSelfConstructionUnattendedQueueHealingPolicy;
    }

    // ── AC: recoverable leases request reap ──

    public function test_recoverable_leases_request_reap(): void
    {
        $result = $this->policy()->decide([
            'recoverable_total' => 3,
            'malformed_blockers' => [],
            'collision_pressure' => 'none',
            'healthy' => false,
        ]);

        $this->assertSame('reap_leases', $result['action']);
        $this->assertFalse($result['starts_background_automation']);
        $this->assertFalse($result['spends_tokens']);
    }

    // ── AC: malformed blockers request sweep ──

    public function test_malformed_blockers_request_sweep(): void
    {
        $result = $this->policy()->decide([
            'recoverable_total' => 0,
            'malformed_blockers' => ['missing_scope'],
            'collision_pressure' => 'none',
            'healthy' => false,
        ]);

        $this->assertSame('sweep_malformed', $result['action']);
    }

    // ── AC: collision pressure requests originator pivot ──

    public function test_collision_pressure_requests_originator_pivot(): void
    {
        $result = $this->policy()->decide([
            'recoverable_total' => 0,
            'malformed_blockers' => [],
            'collision_pressure' => 'high',
            'healthy' => false,
        ]);

        $this->assertSame('originator_pivot', $result['action']);
    }

    // ── AC: healthy queues request observe ──

    public function test_healthy_queue_requests_observe(): void
    {
        $result = $this->policy()->decide([
            'recoverable_total' => 0,
            'malformed_blockers' => [],
            'collision_pressure' => 'none',
            'healthy' => true,
        ]);

        $this->assertSame('observe', $result['action']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->policy()->decide([]);

        $this->assertSame(AtlasSelfConstructionUnattendedQueueHealingPolicy::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('starts_background_automation', $result);
        $this->assertArrayHasKey('spends_tokens', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $snapshot = ['recoverable_total' => 1, 'malformed_blockers' => [], 'collision_pressure' => 'none', 'healthy' => false];

        $a = $this->policy()->decide($snapshot);
        $b = $this->policy()->decide($snapshot);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
