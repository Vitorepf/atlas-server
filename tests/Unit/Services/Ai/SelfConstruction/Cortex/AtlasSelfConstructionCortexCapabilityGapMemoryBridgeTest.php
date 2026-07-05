<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Cortex;

use App\Services\Ai\SelfConstruction\Cortex\AtlasSelfConstructionCortexCapabilityGapMemoryBridge;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCortexCapabilityGapMemoryBridgeTest extends TestCase
{
    private AtlasSelfConstructionCortexCapabilityGapMemoryBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new AtlasSelfConstructionCortexCapabilityGapMemoryBridge;
    }

    // ── AC: fresh gap memories become constraints ──

    public function test_fresh_gap_memory_becomes_constraint(): void
    {
        $result = $this->bridge->bridge([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'now_unix' => 100000,
            'freshness_window_seconds' => 3600,
            'gap_memories' => [
                [
                    'memory_id' => 'mem-1',
                    'source' => 'runtime_evidence',
                    'last_unix' => 99500,
                    'hash' => 'abc123',
                    'capability' => 'sensing',
                    'gap' => 'no_health_probe',
                ],
            ],
        ]);

        $this->assertSame(1, $result['constraint_count']);
        $this->assertTrue($result['trusted']);
        $this->assertSame('address_capability_gap:sensing:no_health_probe', $result['constraints'][0]['constraint']);
        $this->assertSame(AtlasSelfConstructionCortexCapabilityGapMemoryBridge::STATUS_FRESH, $result['constraints'][0]['status']);
    }

    // ── AC: stale memories require refresh ──

    public function test_stale_memory_requires_refresh(): void
    {
        $result = $this->bridge->bridge([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'now_unix' => 100000,
            'freshness_window_seconds' => 3600,
            'gap_memories' => [
                [
                    'memory_id' => 'mem-2',
                    'source' => 'runtime_evidence',
                    'last_unix' => 90000,
                    'hash' => 'def456',
                    'capability' => 'acting',
                    'gap' => 'no_actuator',
                ],
            ],
        ]);

        $this->assertSame(0, $result['constraint_count']);
        $this->assertSame(1, $result['refresh_count']);
        $this->assertFalse($result['trusted']);
        $this->assertSame('mem-2', $result['refresh_needed'][0]['memory_id']);
        $this->assertStringContainsString('stale', $result['refresh_needed'][0]['reason']);
    }

    // ── AC: projection-only memories require refresh ──

    public function test_projection_only_memory_requires_refresh(): void
    {
        $result = $this->bridge->bridge([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'now_unix' => 100000,
            'gap_memories' => [
                [
                    'memory_id' => 'mem-3',
                    'source' => 'provider_projection',
                    'last_unix' => 99500,
                    'hash' => 'ghi789',
                    'capability' => 'learning',
                    'gap' => 'no_policy_update',
                ],
            ],
        ]);

        $this->assertSame(0, $result['constraint_count']);
        $this->assertSame(1, $result['refresh_count']);
        $this->assertSame('projection_only_not_trusted', $result['refresh_needed'][0]['reason']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->bridge->bridge([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gap_memories' => [],
        ]);

        $this->assertSame(AtlasSelfConstructionCortexCapabilityGapMemoryBridge::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('constraints', $result);
        $this->assertArrayHasKey('refresh_needed', $result);
        $this->assertArrayHasKey('trusted', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'now_unix' => 100000,
            'freshness_window_seconds' => 3600,
            'gap_memories' => [
                [
                    'memory_id' => 'mem-1',
                    'source' => 'runtime_evidence',
                    'last_unix' => 99500,
                    'hash' => 'abc123',
                    'capability' => 'sensing',
                    'gap' => 'no_health_probe',
                ],
            ],
        ];

        $a = $this->bridge->bridge($input);
        $b = $this->bridge->bridge($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
