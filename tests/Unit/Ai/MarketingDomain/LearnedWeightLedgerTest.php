<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\LearnedWeightLedger;
use PHPUnit\Framework\TestCase;

/**
 * Locks the learning math of the flywheel without a database: feed pre-fetched rows of
 * (present_patterns + conversion_rate) and verify that high-converting patterns earn a higher
 * weight than ubiquitous baseline patterns (Bayesian-smoothed). The persistence layer is the
 * Eloquent model; this test focuses purely on the math that calibrates weights from outcomes.
 */
class LearnedWeightLedgerTest extends TestCase
{
    public function test_returns_empty_under_minimum_rows(): void
    {
        $ledger = new LearnedWeightLedger;
        $rows = array_fill(0, 5, ['present_patterns' => ['persuasion:x'], 'conversion_rate' => 0.03]);
        $this->assertSame([], $ledger->computeWeights($rows, 30));
    }

    public function test_learned_weights_lift_high_converting_patterns(): void
    {
        $ledger = new LearnedWeightLedger;
        $rows = [];
        // 30 high-converting pages have killer_mechanism + common_authority
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['present_patterns' => ['persuasion:killer_mechanism', 'persuasion:common_authority'], 'conversion_rate' => 0.08];
        }
        // 30 low-converting pages only have common_authority
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['present_patterns' => ['persuasion:common_authority'], 'conversion_rate' => 0.02];
        }

        $w = $ledger->computeWeights($rows, 30);

        $this->assertArrayHasKey('persuasion:killer_mechanism', $w);
        $this->assertArrayHasKey('persuasion:common_authority', $w);
        $this->assertGreaterThan($w['persuasion:common_authority'], $w['persuasion:killer_mechanism'],
            'killer_mechanism (only on high-converting pages) must learn a higher weight');
    }

    public function test_weights_are_capped_at_one_and_sorted_descending(): void
    {
        $ledger = new LearnedWeightLedger;
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['present_patterns' => ['persuasion:huge_lift'], 'conversion_rate' => 0.50];
        }
        for ($i = 0; $i < 50; $i++) {
            $rows[] = ['present_patterns' => ['persuasion:baseline'], 'conversion_rate' => 0.01];
        }

        $w = $ledger->computeWeights($rows, 30);
        $values = array_values($w);

        $this->assertLessThanOrEqual(1.0, max($values), 'Weight must be capped at 1.0');
        $this->assertSame($values, array_values($w), 'Weights should be returned sorted descending');
        $this->assertGreaterThan($w['persuasion:baseline'], $w['persuasion:huge_lift']);
    }
}
