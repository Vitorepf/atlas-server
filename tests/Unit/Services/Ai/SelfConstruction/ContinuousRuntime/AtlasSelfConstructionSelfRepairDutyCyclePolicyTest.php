<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionSelfRepairDutyCyclePolicy;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSelfRepairDutyCyclePolicyTest extends TestCase
{
    private AtlasSelfConstructionSelfRepairDutyCyclePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasSelfConstructionSelfRepairDutyCyclePolicy;
    }

    public function test_malformed_pressure_favors_repair(): void
    {
        $result = $this->policy->evaluate([
            'malformed_count' => 3,
            'claimable_depth' => 10,
        ]);

        $this->assertSame('repair', $result['duty']);
    }

    public function test_excessive_claimable_depth_favors_consolidation(): void
    {
        $result = $this->policy->evaluate([
            'malformed_count' => 0,
            'claimable_depth' => 30,
            'outcome_quality' => 0.9,
        ]);

        $this->assertSame('consolidate', $result['duty']);
    }

    public function test_dry_queue_favors_replenishment(): void
    {
        $result = $this->policy->evaluate([
            'malformed_count' => 0,
            'claimable_depth' => 0,
        ]);

        $this->assertSame('replenish', $result['duty']);
    }

    public function test_schema_present(): void
    {
        $result = $this->policy->evaluate([]);
        $this->assertSame(AtlasSelfConstructionSelfRepairDutyCyclePolicy::SCHEMA, $result['schema']);
    }
}
