<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationDecisionJournal;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationDecisionJournalTest extends TestCase
{
    private function journal(): AtlasExternalBrainSimplificationDecisionJournal
    {
        return new AtlasExternalBrainSimplificationDecisionJournal;
    }

    public function test_schema_present(): void
    {
        $result = $this->journal()->summarize([]);

        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::SCHEMA, $result['schema']);
    }

    public function test_empty_input_yields_empty_summaries_and_biases(): void
    {
        $result = $this->journal()->summarize(['decisions' => []]);

        $this->assertSame([], $result['pattern_summaries']);
        $this->assertSame([], $result['next_decision_biases']);
    }

    // ── pattern summary aggregation ──────────────────────────────────────────

    public function test_summary_reports_approval_rate_hold_causes_and_reverted_count(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'wrapper_merge', 'decision' => 'approved', 'line_gain' => 40],
            ['pattern' => 'wrapper_merge', 'decision' => 'approved', 'line_gain' => 60],
            ['pattern' => 'wrapper_merge', 'decision' => 'hold', 'hold_reason' => 'missing_test_coverage'],
            ['pattern' => 'wrapper_merge', 'decision' => 'reverted'],
        ]]);

        $summary = $result['pattern_summaries'][0];
        $this->assertSame('wrapper_merge', $summary['pattern']);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['approved_count']);
        $this->assertEqualsWithDelta(0.5, $summary['approval_rate'], 0.001);
        $this->assertSame(1, $summary['hold_count']);
        $this->assertContains('missing_test_coverage', $summary['hold_causes']);
        $this->assertSame(1, $summary['reverted_count']);
        $this->assertSame(100, $summary['measured_line_gain']);
    }

    public function test_multiple_patterns_are_summarized_independently_and_sorted(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'zeta_pattern', 'decision' => 'approved', 'line_gain' => 10],
            ['pattern' => 'alpha_pattern', 'decision' => 'approved', 'line_gain' => 20],
        ]]);

        $patterns = array_column($result['pattern_summaries'], 'pattern');
        $this->assertSame(['alpha_pattern', 'zeta_pattern'], $patterns);
    }

    // ── promote_success_pattern_case ─────────────────────────────────────────

    public function test_high_approval_rate_with_positive_gain_promotes_pattern(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'stale_scaffold_delete', 'decision' => 'approved', 'line_gain' => 50],
            ['pattern' => 'stale_scaffold_delete', 'decision' => 'approved', 'line_gain' => 30],
            ['pattern' => 'stale_scaffold_delete', 'decision' => 'approved', 'line_gain' => 20],
            ['pattern' => 'stale_scaffold_delete', 'decision' => 'hold'],
        ]]);

        $bias = $result['next_decision_biases'][0];
        $this->assertSame('stale_scaffold_delete', $bias['pattern']);
        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PROMOTE, $bias['bias']);
    }

    public function test_high_approval_rate_without_measured_gain_is_neutral_not_promoted(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'no_gain_pattern', 'decision' => 'approved'],
            ['pattern' => 'no_gain_pattern', 'decision' => 'approved'],
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_NEUTRAL, $result['next_decision_biases'][0]['bias']);
    }

    public function test_fitness_gain_alone_is_sufficient_for_promotion(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'fitness_pattern', 'decision' => 'approved', 'fitness_gain' => 0.15],
            ['pattern' => 'fitness_pattern', 'decision' => 'approved', 'fitness_gain' => 0.10],
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PROMOTE, $result['next_decision_biases'][0]['bias']);
    }

    // ── penalize_reverted_pattern_case ───────────────────────────────────────

    public function test_any_reversion_penalizes_pattern_regardless_of_approval_history(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'risky_pattern', 'decision' => 'approved', 'line_gain' => 100],
            ['pattern' => 'risky_pattern', 'decision' => 'approved', 'line_gain' => 100],
            ['pattern' => 'risky_pattern', 'decision' => 'approved', 'line_gain' => 100],
            ['pattern' => 'risky_pattern', 'decision' => 'approved', 'line_gain' => 100],
            ['pattern' => 'risky_pattern', 'decision' => 'reverted'],
        ]]);

        $bias = $result['next_decision_biases'][0];
        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PENALIZE, $bias['bias']);
    }

    public function test_penalize_wins_over_promote_even_with_high_approval_and_gain(): void
    {
        // 4/5 approved with strong gain would normally promote, but one reversion must still win.
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'p', 'decision' => 'approved', 'line_gain' => 200],
            ['pattern' => 'p', 'decision' => 'approved', 'line_gain' => 200],
            ['pattern' => 'p', 'decision' => 'approved', 'line_gain' => 200],
            ['pattern' => 'p', 'decision' => 'approved', 'line_gain' => 200],
            ['pattern' => 'p', 'decision' => 'reverted'],
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PENALIZE, $result['next_decision_biases'][0]['bias']);
        $this->assertNotSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PROMOTE, $result['next_decision_biases'][0]['bias']);
    }

    // ── mixed batch ───────────────────────────────────────────────────────────

    public function test_mixed_batch_promotes_and_penalizes_independently(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['pattern' => 'good_pattern', 'decision' => 'approved', 'line_gain' => 80],
            ['pattern' => 'good_pattern', 'decision' => 'approved', 'line_gain' => 80],
            ['pattern' => 'bad_pattern', 'decision' => 'reverted'],
        ]]);

        $byPattern = array_column($result['next_decision_biases'], 'bias', 'pattern');
        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PROMOTE, $byPattern['good_pattern']);
        $this->assertSame(AtlasExternalBrainSimplificationDecisionJournal::BIAS_PENALIZE, $byPattern['bad_pattern']);
    }

    public function test_rows_without_pattern_are_ignored(): void
    {
        $result = $this->journal()->summarize(['decisions' => [
            ['decision' => 'approved', 'line_gain' => 50],
            ['pattern' => '', 'decision' => 'approved'],
        ]]);

        $this->assertSame([], $result['pattern_summaries']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $journal = $this->journal();
        $decisions = ['decisions' => [
            ['pattern' => 'p1', 'decision' => 'approved', 'line_gain' => 10],
            ['pattern' => 'p1', 'decision' => 'reverted'],
        ]];

        $this->assertSame($journal->summarize($decisions), $journal->summarize($decisions));
    }
}
