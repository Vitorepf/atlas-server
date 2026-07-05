<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilRepairExpansionBalancePolicy;
use PHPUnit\Framework\TestCase;

final class AtlasStrategyCouncilRepairExpansionBalancePolicyTest extends TestCase
{
    private AtlasStrategyCouncilRepairExpansionBalancePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasStrategyCouncilRepairExpansionBalancePolicy;
    }

    // ── AC: unhealthy facts choose repair ──

    public function test_unhealthy_facts_choose_repair(): void
    {
        $result = $this->policy->balance([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => [
                'healthy' => false,
                'malformed_count' => 2,
                'lease_leak_detected' => true,
            ],
            'outcomes' => [],
        ]);

        $this->assertSame(AtlasStrategyCouncilRepairExpansionBalancePolicy::MODE_REPAIR, $result['mode']);
        $this->assertTrue($result['repair_first']);
        $this->assertFalse($result['allow_expansion']);
    }

    // ── AC: duplicate pressure chooses consolidation ──

    public function test_duplicate_pressure_chooses_consolidation(): void
    {
        $result = $this->policy->balance([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => ['healthy' => true],
            'outcomes' => [
                'give_back_count' => 1,
                'duplicate_count' => 3,
                'success_count' => 6,
            ],
        ]);

        $this->assertSame(AtlasStrategyCouncilRepairExpansionBalancePolicy::MODE_CONSOLIDATION, $result['mode']);
    }

    // ── AC: clean drain chooses expansion ──

    public function test_clean_drain_chooses_expansion(): void
    {
        $result = $this->policy->balance([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => [
                'healthy' => true,
                'claimable_depth' => 2,
                'active_workers' => 2,
            ],
            'outcomes' => [
                'give_back_count' => 1,
                'duplicate_count' => 0,
                'success_count' => 9,
            ],
        ]);

        $this->assertSame(AtlasStrategyCouncilRepairExpansionBalancePolicy::MODE_EXPANSION, $result['mode']);
        $this->assertTrue($result['allow_expansion']);
        $this->assertTrue($result['worker_floor_breached']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->policy->balance([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => [],
            'outcomes' => [],
        ]);

        $this->assertSame(AtlasStrategyCouncilRepairExpansionBalancePolicy::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('mode', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('repair_first', $result);
        $this->assertArrayHasKey('allow_expansion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'health' => ['healthy' => true],
            'outcomes' => [
                'give_back_count' => 0,
                'duplicate_count' => 0,
                'success_count' => 10,
            ],
        ];

        $a = $this->policy->balance($input);
        $b = $this->policy->balance($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
