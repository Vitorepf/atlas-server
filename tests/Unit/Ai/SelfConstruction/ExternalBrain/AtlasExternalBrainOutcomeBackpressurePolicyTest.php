<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeBackpressurePolicy;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeBackpressurePolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainOutcomeBackpressurePolicy
    {
        return new AtlasExternalBrainOutcomeBackpressurePolicy;
    }

    private function outcomes(string ...$types): array
    {
        return array_map(static fn (string $t): array => ['outcome' => $t], $types);
    }

    private function evaluate(array $history, string $family = 'test-family', array $extra = []): array
    {
        return $this->policy()->evaluate(array_merge([
            'task_family'     => $family,
            'outcome_history' => $history,
        ], $extra));
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_always_present(): void
    {
        $r = $this->evaluate([]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::SCHEMA, $r['schema']);
    }

    public function test_output_keys_present(): void
    {
        $r = $this->evaluate([]);

        foreach (['schema', 'task_family', 'recommendation', 'confidence_score', 'safe_to_promote', 'outcome_summary'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_task_family_echoed(): void
    {
        $r = $this->evaluate([], 'my-family');

        $this->assertSame('my-family', $r['task_family']);
    }

    // ── AC2: repeated successful commits → promote/continue ───────────────────

    public function test_high_success_rate_recommends_promote(): void
    {
        $r = $this->evaluate($this->outcomes('success', 'success', 'success', 'success'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE, $r['recommendation']);
        $this->assertGreaterThanOrEqual(0.75, $r['confidence_score']);
        $this->assertTrue($r['safe_to_promote']);
    }

    public function test_confidence_score_computed_correctly(): void
    {
        // 3 success / 4 total = 0.75
        $r = $this->evaluate($this->outcomes('success', 'success', 'success', 'give_back'));

        $this->assertSame(0.75, $r['confidence_score']);
    }

    public function test_empty_history_recommends_continue(): void
    {
        $r = $this->evaluate([]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE, $r['recommendation']);
        $this->assertTrue($r['safe_to_promote']);
    }

    public function test_low_success_below_promote_threshold_recommends_continue(): void
    {
        // 2/3 success = 0.667 < custom promote_threshold=0.90
        // give_back_rate = 1/3 = 0.333 < custom respec_threshold=0.50 → no respec → continue
        $r = $this->evaluate(
            $this->outcomes('success', 'success', 'give_back'),
            'f',
            ['promote_threshold' => 0.90, 'respec_threshold' => 0.50],
        );

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE, $r['recommendation']);
        $this->assertTrue($r['safe_to_promote']);
    }

    // ── AC3: give_back → respec ───────────────────────────────────────────────

    public function test_high_give_back_rate_recommends_respec(): void
    {
        $r = $this->evaluate($this->outcomes('give_back', 'give_back', 'give_back', 'success'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RESPEC, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── AC3: quarantine → block ───────────────────────────────────────────────

    public function test_any_quarantine_recommends_block(): void
    {
        $r = $this->evaluate($this->outcomes('success', 'success', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_BLOCK, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── AC3: poison → retire ─────────────────────────────────────────────────

    public function test_high_poison_rate_recommends_retire(): void
    {
        $r = $this->evaluate($this->outcomes('poison', 'poison', 'success', 'success', 'success'));

        // poison_rate = 2/5 = 0.40 > 0.30 threshold → retire
        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RETIRE, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── decision priority ─────────────────────────────────────────────────────

    public function test_poison_beats_quarantine(): void
    {
        $r = $this->evaluate($this->outcomes('poison', 'poison', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RETIRE, $r['recommendation']);
    }

    public function test_quarantine_beats_respec(): void
    {
        $r = $this->evaluate($this->outcomes('give_back', 'give_back', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_BLOCK, $r['recommendation']);
    }

    // ── outcome_summary ───────────────────────────────────────────────────────

    public function test_outcome_summary_counts_all_types(): void
    {
        $r = $this->evaluate($this->outcomes('success', 'success', 'give_back', 'quarantine', 'poison'));

        $this->assertSame(2, $r['outcome_summary']['success']);
        $this->assertSame(1, $r['outcome_summary']['give_back']);
        $this->assertSame(1, $r['outcome_summary']['quarantine']);
        $this->assertSame(1, $r['outcome_summary']['poison']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_promote_threshold_respected(): void
    {
        // 2 success / 3 total = 0.67 — below default 0.75 but above custom 0.60
        $r = $this->evaluate(
            $this->outcomes('success', 'success', 'give_back'),
            'f',
            ['promote_threshold' => 0.60, 'respec_threshold' => 0.50], // raise respec thresh so no respec
        );

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE, $r['recommendation']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_deterministic(): void
    {
        $input = ['task_family' => 'fam', 'outcome_history' => $this->outcomes('success', 'give_back', 'poison')];

        $this->assertSame($this->policy()->evaluate($input), $this->policy()->evaluate($input));
    }
}
