<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionLearningSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionLearningSynthesizerTest extends TestCase
{
    private function synthesizer(): AtlasExternalBrainCompressionLearningSynthesizer
    {
        return new AtlasExternalBrainCompressionLearningSynthesizer;
    }

    // ── AC: promote_high_gain_case ──────────────────────────────────────────

    public function test_promote_high_gain_case(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'DeleteDeadDelegate', 'outcome' => 'win', 'gain_score' => 0.8],
            ['pattern' => 'DeleteDeadDelegate', 'outcome' => 'win', 'gain_score' => 0.9],
            ['pattern' => 'DeleteDeadDelegate', 'outcome' => 'win', 'gain_score' => 0.7],
        ]]);

        $bias = $r['strategy_biases']['DeleteDeadDelegate'];
        $this->assertSame('promote', $bias['bias']);
        $this->assertSame(1.0, $bias['win_rate']);
    }

    // ── AC: demote_regression_case ──────────────────────────────────────────

    public function test_demote_regression_case_when_reversions_present(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'MergeIndirection', 'outcome' => 'win', 'gain_score' => 0.9],
            ['pattern' => 'MergeIndirection', 'outcome' => 'reverted'],
            ['pattern' => 'MergeIndirection', 'outcome' => 'win', 'gain_score' => 0.9],
        ]]);

        $bias = $r['strategy_biases']['MergeIndirection'];
        $this->assertSame('demote', $bias['bias']);
    }

    public function test_demote_regression_case_when_give_back_present(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'RiskyPattern', 'outcome' => 'give_back'],
            ['pattern' => 'RiskyPattern', 'outcome' => 'give_back'],
            ['pattern' => 'RiskyPattern', 'outcome' => 'win', 'gain_score' => 0.9],
        ]]);

        $this->assertSame('demote', $r['strategy_biases']['RiskyPattern']['bias']);
    }

    public function test_demote_regression_case_when_slo_miss_dominant(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'SlowPattern', 'outcome' => 'slo_miss'],
            ['pattern' => 'SlowPattern', 'outcome' => 'win', 'gain_score' => 0.9],
        ]]);

        $this->assertSame('demote', $r['strategy_biases']['SlowPattern']['bias']);
    }

    // ── neutral tier ─────────────────────────────────────────────────────────

    public function test_neutral_when_win_rate_high_but_gain_too_low(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'LowGainPattern', 'outcome' => 'win', 'gain_score' => 0.1],
            ['pattern' => 'LowGainPattern', 'outcome' => 'win', 'gain_score' => 0.1],
        ]]);

        $this->assertSame('neutral', $r['strategy_biases']['LowGainPattern']['bias']);
    }

    // ── multiple patterns ────────────────────────────────────────────────────

    public function test_multiple_patterns_biased_independently(): void
    {
        $r = $this->synthesizer()->synthesize(['outcomes' => [
            ['pattern' => 'Good', 'outcome' => 'win', 'gain_score' => 0.9],
            ['pattern' => 'Bad', 'outcome' => 'reverted'],
        ]]);

        $this->assertSame('promote', $r['strategy_biases']['Good']['bias']);
        $this->assertSame('demote', $r['strategy_biases']['Bad']['bias']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_synthesize_is_deterministic(): void
    {
        $facts = ['outcomes' => [['pattern' => 'X', 'outcome' => 'win', 'gain_score' => 0.9]]];
        $a = $this->synthesizer()->synthesize($facts);
        $b = $this->synthesizer()->synthesize($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->synthesizer()->synthesize([]);
        $this->assertSame(AtlasExternalBrainCompressionLearningSynthesizer::SCHEMA, $r['schema']);
    }

    public function test_empty_outcomes_yields_empty_biases(): void
    {
        $r = $this->synthesizer()->synthesize([]);
        $this->assertSame([], $r['strategy_biases']);
    }
}
