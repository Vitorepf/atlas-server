<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidSet;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderBid;
use Tests\TestCase;

final class BidSetTest extends TestCase
{
    private function bid(array $overrides = []): ProviderBid
    {
        $base = $overrides + [
            'providerId' => 'codex',
            'capabilityScore' => 80,
            'eligibilityBool' => true,
            'ineligibilityReasons' => [],
            'declaredCostUnits' => 500,
            'declaredEtaMs' => 1200,
            'bidHash' => str_repeat('a', 64),
        ];

        return new ProviderBid(
            providerId: $base['providerId'],
            capabilityScore: $base['capabilityScore'],
            eligibilityBool: $base['eligibilityBool'],
            ineligibilityReasons: $base['ineligibilityReasons'],
            declaredCostUnits: $base['declaredCostUnits'],
            declaredEtaMs: $base['declaredEtaMs'],
            bidHash: $base['bidHash'],
        );
    }

    // ── AC: duplicate provider bids in one set are rejected or collapsed deterministically ──

    public function test_duplicate_provider_bids_are_flagged_and_ordered_deterministically(): void
    {
        $rows = [
            ['provider_id' => 'codex'],
            ['provider_id' => 'fable'],
            ['provider_id' => 'codex'],
        ];

        $result = BidSet::validateRows('task-1', $rows);

        $this->assertFalse($result['ok']);
        $this->assertContains('duplicate_provider_bids', $result['reasons']);
        $this->assertSame(['codex'], $result['duplicate_providers']);
    }

    public function test_no_duplicates_passes_validation(): void
    {
        $rows = [
            ['provider_id' => 'codex'],
            ['provider_id' => 'fable'],
        ];

        $result = BidSet::validateRows('task-1', $rows);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['duplicate_providers']);
    }

    // ── AC: mixed task ids in one set are rejected ──────────────────────────────

    public function test_mixed_task_ids_in_one_set_are_rejected(): void
    {
        $rows = [
            ['provider_id' => 'codex', 'task_id' => 'task-1'],
            ['provider_id' => 'fable', 'task_id' => 'task-2'],
        ];

        $result = BidSet::validateRows('task-1', $rows);

        $this->assertFalse($result['ok']);
        $this->assertContains('mixed_task_ids', $result['reasons']);
        $this->assertSame(['task-2'], $result['mixed_task_ids']);
    }

    public function test_all_rows_matching_expected_task_id_passes(): void
    {
        $rows = [
            ['provider_id' => 'codex', 'task_id' => 'task-1'],
            ['provider_id' => 'fable', 'task_id' => 'task-1'],
        ];

        $result = BidSet::validateRows('task-1', $rows);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['mixed_task_ids']);
    }

    public function test_rows_without_task_id_are_not_flagged(): void
    {
        $rows = [
            ['provider_id' => 'codex'],
        ];

        $result = BidSet::validateRows('task-1', $rows);

        $this->assertTrue($result['ok']);
    }

    public function test_validate_rows_reports_bid_count(): void
    {
        $result = BidSet::validateRows('task-1', [
            ['provider_id' => 'a'],
            ['provider_id' => 'b'],
            ['provider_id' => 'c'],
        ]);

        $this->assertSame(3, $result['bid_count']);
    }

    // ── AC: toArray includes eligibility summary and bid count ─────────────────
    // toArray() itself stays a plain list (AtlasTaskMaestroBidCommand emits it positionally),
    // so the eligibility summary + bid count are exposed via the new summary() method.

    public function test_summary_includes_bid_count_and_eligibility_split(): void
    {
        $set = new BidSet([
            $this->bid(['providerId' => 'a', 'eligibilityBool' => true]),
            $this->bid(['providerId' => 'b', 'eligibilityBool' => false]),
            $this->bid(['providerId' => 'c', 'eligibilityBool' => true]),
        ]);

        $summary = $set->summary();

        $this->assertSame(3, $summary['bid_count']);
        $this->assertSame(2, $summary['eligible_count']);
        $this->assertSame(1, $summary['ineligible_count']);
    }

    public function test_summary_flags_duplicate_providers_in_typed_bid_set(): void
    {
        $set = new BidSet([
            $this->bid(['providerId' => 'a']),
            $this->bid(['providerId' => 'a']),
        ]);

        $summary = $set->summary();

        $this->assertSame(['a'], $summary['duplicate_providers']);
    }

    public function test_to_array_remains_a_plain_positional_list(): void
    {
        $set = new BidSet([
            $this->bid(['providerId' => 'a', 'bidHash' => str_repeat('1', 64)]),
        ]);

        $arr = $set->toArray();

        $this->assertSame(['a'], array_column($arr, 'provider_id'));
        $this->assertArrayNotHasKey('bid_count', $arr);
    }
}
