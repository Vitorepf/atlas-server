<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionTwentyFourSevenReadinessGauge;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionTwentyFourSevenReadinessGaugeTest extends TestCase
{
    private AtlasSelfConstructionTwentyFourSevenReadinessGauge $gauge;

    protected function setUp(): void
    {
        $this->gauge = new AtlasSelfConstructionTwentyFourSevenReadinessGauge;
    }

    public function test_healthy_inputs_produce_ready(): void
    {
        $result = $this->gauge->evaluate([
            'queue_healthy' => true,
            'claimable_depth' => 10,
            'recoverable_lease_count' => 0,
            'malformed_blocker_count' => 0,
            'evidence_fresh' => true,
        ]);

        $this->assertSame('ready', $result['state']);
        $this->assertTrue($result['ready']);
    }

    public function test_recoverable_leases_produce_repair_first(): void
    {
        $result = $this->gauge->evaluate([
            'queue_healthy' => true,
            'claimable_depth' => 10,
            'recoverable_lease_count' => 3,
            'malformed_blocker_count' => 0,
            'evidence_fresh' => true,
        ]);

        $this->assertSame('repair_first', $result['state']);
    }

    public function test_malformed_blockers_produce_blocked(): void
    {
        $result = $this->gauge->evaluate([
            'queue_healthy' => true,
            'claimable_depth' => 10,
            'malformed_blocker_count' => 2,
            'evidence_fresh' => true,
        ]);

        $this->assertSame('blocked', $result['state']);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_dry_queue_produces_replenish(): void
    {
        $result = $this->gauge->evaluate([
            'queue_healthy' => true,
            'claimable_depth' => 0,
            'malformed_blocker_count' => 0,
            'evidence_fresh' => true,
        ]);

        $this->assertSame('replenish', $result['state']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gauge->evaluate([]);
        $this->assertSame(AtlasSelfConstructionTwentyFourSevenReadinessGauge::SCHEMA, $result['schema']);
    }
}
