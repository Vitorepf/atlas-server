<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Support\UsageCaptureContract;
use PHPUnit\Framework\TestCase;

class UsageCaptureContractTest extends TestCase
{
    public function test_hermes_production_requires_tokens_and_wall(): void
    {
        $contract = new UsageCaptureContract;
        $blockers = $contract->validate([
            'claim_tier' => 'production',
            'harness_only' => false,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'wall_ms' => 0,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => false, 'reason' => 'lcb_omits_usage'],
                'tokens_out' => ['present' => false, 'reason' => 'lcb_omits_usage'],
                'wall_ms' => ['present' => false, 'reason' => 'lcb_omits_timing'],
                'cost_usd' => ['present' => false, 'reason' => 'usage_missing'],
            ],
        ]);
        $this->assertNotEmpty($blockers);
        $this->assertNotEmpty(array_filter(
            $blockers,
            fn ($b) => str_contains((string) $b, 'tokens_in'),
        ));
    }

    public function test_verboo_zero_cost_allowed_with_usage(): void
    {
        $contract = new UsageCaptureContract;
        $this->assertTrue($contract->isSatisfied([
            'claim_tier' => 'production',
            'harness_only' => false,
            'tokens_in' => 120,
            'tokens_out' => 40,
            'wall_ms' => 1500,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => true, 'reason' => null],
                'tokens_out' => ['present' => true, 'reason' => null],
                'wall_ms' => ['present' => true, 'reason' => null],
                'cost_usd' => ['present' => true, 'reason' => 'verboo_subscription_marginal'],
            ],
        ]));
    }

    public function test_zero_cost_without_verboo_basis_is_blocked(): void
    {
        $contract = new UsageCaptureContract;
        $blockers = $contract->validate([
            'claim_tier' => 'production',
            'harness_only' => false,
            'tokens_in' => 10,
            'tokens_out' => 5,
            'wall_ms' => 100,
            'cost_usd' => 0.0,
            'field_presence' => [
                'tokens_in' => ['present' => true, 'reason' => null],
                'tokens_out' => ['present' => true, 'reason' => null],
                'wall_ms' => ['present' => true, 'reason' => null],
                'cost_usd' => ['present' => true, 'reason' => 'unknown'],
            ],
        ]);
        $this->assertContains('usage_capture_zero_cost_without_verboo_basis', $blockers);
    }

    public function test_harness_receipts_are_skipped(): void
    {
        $contract = new UsageCaptureContract;
        $this->assertSame([], $contract->validate([
            'claim_tier' => 'harness',
            'harness_only' => true,
            'tokens_in' => 0,
            'field_presence' => [],
        ]));
    }
}
