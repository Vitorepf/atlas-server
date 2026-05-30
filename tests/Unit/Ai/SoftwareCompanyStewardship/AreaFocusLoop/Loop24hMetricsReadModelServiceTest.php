<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hMetricsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Tests\TestCase;

final class Loop24hMetricsReadModelServiceTest extends TestCase
{
    private function service(): Loop24hMetricsReadModelService
    {
        return app(Loop24hMetricsReadModelService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function cycleRecord(array $overrides): array
    {
        return array_replace([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'outcome' => 'merged',
            'merge_performed' => true,
            'finding_key' => 'f1',
            'work_class' => Reliable24hLoopRunnerService::WORK_CLASS_PRODUCT,
        ], $overrides);
    }

    public function test_utilization_excludes_non_token_spending_cycles(): void
    {
        $records = [
            $this->cycleRecord(['outcome' => 'merged', 'merge_performed' => true, 'finding_key' => 'f1']),
            $this->cycleRecord(['outcome' => 'blocked', 'merge_performed' => false, 'finding_key' => 'f2']),
            // progress = no-op, not token-spending, must not dilute utilization
            $this->cycleRecord(['outcome' => 'progress', 'merge_performed' => false, 'finding_key' => 'f3']),
        ];

        $metrics = $this->service()->computeFromRecords('area', 'dev_forge', $records);

        $this->assertSame(3, $metrics['cycles']);
        $this->assertSame(2, $metrics['token_spending_cycles']);
        $this->assertSame(1, $metrics['merges']);
        $this->assertSame(1, $metrics['blocked_cycles']);
        // utilization = merges / token-spending cycles = 1/2
        $this->assertSame(0.5, $metrics['utilization']);
    }

    public function test_separates_product_and_self_maintenance_merges(): void
    {
        $records = [
            $this->cycleRecord(['finding_key' => 'p1', 'work_class' => Reliable24hLoopRunnerService::WORK_CLASS_PRODUCT]),
            $this->cycleRecord(['finding_key' => 'p2', 'work_class' => Reliable24hLoopRunnerService::WORK_CLASS_PRODUCT]),
            $this->cycleRecord(['finding_key' => 's1', 'work_class' => Reliable24hLoopRunnerService::WORK_CLASS_SELF_MAINTENANCE]),
        ];

        $metrics = $this->service()->computeFromRecords('area', 'dev_forge', $records);

        $this->assertSame(2, $metrics['merge_mix']['product_merges']);
        $this->assertSame(1, $metrics['merge_mix']['self_maintenance_merges']);
        // self-maintenance share = 1/3
        $this->assertSame(0.3333, $metrics['merge_mix']['self_maintenance_share']);
    }

    public function test_tokens_per_merge_reported_only_when_usage_recorded(): void
    {
        $withTokens = [
            $this->cycleRecord(['finding_key' => 'p1', 'tokens' => 100]),
            $this->cycleRecord(['finding_key' => 'p2', 'token_usage' => ['total' => 300]]),
        ];
        $metrics = $this->service()->computeFromRecords('area', 'dev_forge', $withTokens);
        $this->assertTrue($metrics['tokens']['available']);
        $this->assertSame(400, $metrics['tokens']['total']);
        $this->assertSame(200.0, $metrics['tokens']['per_merge']);

        $noTokens = [
            $this->cycleRecord(['finding_key' => 'p1']),
        ];
        $metricsNo = $this->service()->computeFromRecords('area', 'dev_forge', $noTokens);
        $this->assertFalse($metricsNo['tokens']['available']);
        $this->assertNull($metricsNo['tokens']['total']);
        $this->assertNull($metricsNo['tokens']['per_merge'], 'never fabricate tokens/merge when no usage was recorded');
    }

    public function test_gaps_discovered_vs_implemented(): void
    {
        $records = [
            // f1 discovered, blocked (not implemented)
            $this->cycleRecord(['outcome' => 'blocked', 'merge_performed' => false, 'finding_key' => 'f1']),
            // f1 re-attempt, merged → implemented
            $this->cycleRecord(['outcome' => 'merged', 'merge_performed' => true, 'finding_key' => 'f1']),
            // f2 discovered, blocked
            $this->cycleRecord(['outcome' => 'blocked', 'merge_performed' => false, 'finding_key' => 'f2']),
        ];

        $metrics = $this->service()->computeFromRecords('area', 'dev_forge', $records);

        $this->assertSame(2, $metrics['gaps']['discovered']);
        $this->assertSame(1, $metrics['gaps']['implemented']);
        $this->assertSame(0.5, $metrics['gaps']['conversion']);
    }

    public function test_ignores_non_cycle_ledger_records_and_empty_ledger(): void
    {
        $records = [
            ['schema_version' => 'atlas.something.else.v1', 'outcome' => 'merged'],
            ['record_type' => 'health_snapshot'],
            $this->cycleRecord(['finding_key' => 'f1']),
        ];

        $metrics = $this->service()->computeFromRecords('area', 'dev_forge', $records);
        $this->assertSame(1, $metrics['cycles']);
        $this->assertSame(1, $metrics['merges']);

        $empty = $this->service()->computeFromRecords('area', 'dev_forge', []);
        $this->assertSame(0, $empty['cycles']);
        $this->assertSame(0.0, $empty['utilization']);
        $this->assertSame(0.0, $empty['gaps']['conversion']);
        $this->assertFalse($empty['tokens']['available']);
    }
}
