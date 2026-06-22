<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionLedger;
use PHPUnit\Framework\TestCase;

/**
 * Pure (no-DB) proof that the compounding summary computes win-rates correctly — the math
 * that lets Atlas prefer levers that have actually worked for a symptom before.
 */
class MarketingDecisionLedgerSummaryTest extends TestCase
{
    public function test_win_rate_counts_only_resolved_decisions(): void
    {
        $entries = [
            ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'outcome' => 'improved'],
            ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'outcome' => 'improved'],
            ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'outcome' => 'worse'],
            ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'outcome' => 'no_change'],
            ['stage' => 'ad_ctr', 'action' => 'edit_bridge_headline', 'outcome' => 'pending'],
        ];

        $summary = MarketingDecisionLedger::summarizeOutcomes($entries);
        $a = $summary['by_action']['edit_bridge_headline'];

        $this->assertSame(5, $a['total']);
        $this->assertSame(2, $a['improved']);
        $this->assertSame(1, $a['worse']);
        $this->assertSame(1, $a['no_change']);
        $this->assertSame(1, $a['pending']);
        // win_rate = improved / (improved + worse) = 2/3
        $this->assertSame(0.667, $a['win_rate']);
    }

    public function test_win_rate_is_null_when_nothing_resolved(): void
    {
        $entries = [
            ['stage' => 'scale', 'action' => 'raise_bid', 'outcome' => 'pending'],
            ['stage' => 'scale', 'action' => 'raise_bid', 'outcome' => 'no_change'],
        ];

        $summary = MarketingDecisionLedger::summarizeOutcomes($entries);
        $this->assertNull($summary['by_action']['raise_bid']['win_rate']);
    }

    public function test_empty_ledger_summarizes_to_empty_buckets(): void
    {
        $summary = MarketingDecisionLedger::summarizeOutcomes([]);
        $this->assertSame([], $summary['by_action']);
        $this->assertSame([], $summary['by_stage']);
    }
}
