<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\Esp09IndependentChallengerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ESP-09 — Challenger independente (anti-alinhamento-cego).
 *
 * Advisory only: high-affinity / recursive / composed decisions get a
 * challenger block with challenger_engine_id ≠ author_engine_id. Low
 * affinity never pays the cost. Promotion without the block is DELAYED,
 * never vetoed.
 */
final class Esp09IndependentChallengerTest extends TestCase
{
    #[Test]
    public function schema_and_measure_are_pinned(): void
    {
        $this->assertSame('atlas.esp_09.challenger_advisory.v1', Esp09IndependentChallengerService::SCHEMA_VERSION);
        $this->assertSame('atlas.esp_09.challenger_advisory.v1', Esp09IndependentChallengerService::MEASURE_ID);
        $this->assertSame('advisory', Esp09IndependentChallengerService::MODE);
    }

    #[Test]
    public function low_affinity_decision_does_not_emit_challenger(): void
    {
        $out = Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => 'codex-author',
            'challenger_engine_id' => 'claude-challenger',
            'operator_alignment' => 0.42,
            'decision_kind' => 'ordinary_route',
            'proposed_choice' => 'ship_feature_x',
        ]);

        $this->assertSame('skipped', $out['status']);
        $this->assertSame('low_affinity', $out['skip_reason']);
        $this->assertNull($out['challenger']);
        $this->assertFalse($out['promotion_delayed']);
    }

    #[Test]
    public function high_affinity_emits_challenger_with_distinct_engine_ids(): void
    {
        $out = Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => 'codex-author',
            'challenger_engine_id' => 'claude-challenger',
            'operator_alignment' => 0.91,
            'decision_kind' => 'ordinary_route',
            'proposed_choice' => 'prefer_operator_habit',
            'alternative' => 'prefer_measured_yield',
            'refutation' => 'habit may be Goodharted; yield series disagrees',
        ]);

        $this->assertSame('advisory', $out['status']);
        $this->assertTrue($out['triggered']);
        $this->assertSame('high_operator_alignment', $out['trigger']);
        $challenger = $out['challenger'];
        $this->assertIsArray($challenger);
        $this->assertSame('codex-author', $challenger['author_engine_id']);
        $this->assertSame('claude-challenger', $challenger['challenger_engine_id']);
        $this->assertNotSame($challenger['author_engine_id'], $challenger['challenger_engine_id']);
        $this->assertSame('prefer_measured_yield', $challenger['alternative']);
        $this->assertStringContainsString('Goodharted', (string) $challenger['refutation']);
        $this->assertTrue($out['advisory_only']);
        $this->assertFalse($out['gates_override']);
    }

    #[Test]
    public function recursive_improvement_kind_triggers_even_at_mid_alignment(): void
    {
        $out = Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => 'cursor-author',
            'challenger_engine_id' => 'codex-challenger',
            'operator_alignment' => 0.55,
            'decision_kind' => 'recursive_improvement',
            'proposed_choice' => 'meta_loop_promote',
            'alternative' => 'keep_shadow',
            'refutation' => 'M<=1; flip is operator-only',
        ]);

        $this->assertSame('advisory', $out['status']);
        $this->assertSame('decision_kind', $out['trigger']);
        $this->assertSame('recursive_improvement', $out['challenger']['decision_kind']);
    }

    #[Test]
    public function composed_obra_kind_triggers_challenger(): void
    {
        $out = Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => 'brain-author',
            'challenger_engine_id' => 'judge-challenger',
            'operator_alignment' => 0.50,
            'decision_kind' => 'composed_obra',
            'proposed_choice' => 'arc_alpha',
            'alternative' => 'split_into_independent_tasks',
            'refutation' => 'arc kill-gate not proven',
        ]);

        $this->assertSame('advisory', $out['status']);
        $this->assertSame('decision_kind', $out['trigger']);
        $this->assertSame('composed_obra', $out['challenger']['decision_kind']);
    }

    #[Test]
    public function same_engine_ids_are_rejected_as_elev18_violation(): void
    {
        $out = Esp09IndependentChallengerService::evaluate([
            'author_engine_id' => 'same-engine',
            'challenger_engine_id' => 'same-engine',
            'operator_alignment' => 0.95,
            'decision_kind' => 'ordinary_route',
            'proposed_choice' => 'x',
            'alternative' => 'y',
            'refutation' => 'z',
        ]);

        $this->assertSame('invalid', $out['status']);
        $this->assertSame('challenger_engine_must_differ', $out['error']);
        $this->assertNull($out['challenger']);
    }

    #[Test]
    public function promotion_without_challenger_block_is_delayed_not_vetoed(): void
    {
        $gate = Esp09IndependentChallengerService::promotionGate([
            'requires_challenger' => true,
            'challenger_block_present' => false,
        ]);

        $this->assertSame('delayed', $gate['status']);
        $this->assertFalse($gate['vetoed']);
        $this->assertTrue($gate['promotion_delayed']);
        $this->assertSame('awaiting_challenger_block', $gate['reason']);
    }

    #[Test]
    public function promotion_with_challenger_block_is_not_delayed_by_esp09(): void
    {
        $gate = Esp09IndependentChallengerService::promotionGate([
            'requires_challenger' => true,
            'challenger_block_present' => true,
        ]);

        $this->assertSame('clear', $gate['status']);
        $this->assertFalse($gate['promotion_delayed']);
        $this->assertFalse($gate['vetoed']);
    }

    #[Test]
    public function low_affinity_promotion_never_requires_challenger(): void
    {
        $gate = Esp09IndependentChallengerService::promotionGate([
            'requires_challenger' => false,
            'challenger_block_present' => false,
        ]);

        $this->assertSame('clear', $gate['status']);
        $this->assertFalse($gate['promotion_delayed']);
    }

    #[Test]
    public function refutation_series_tracks_accepted_and_ignored_with_denominator(): void
    {
        $series = Esp09IndependentChallengerService::refutationSeries([
            ['outcome' => 'accepted'],
            ['outcome' => 'ignored'],
            ['outcome' => 'accepted'],
            ['outcome' => 'ignored'],
            ['outcome' => 'ignored'],
        ]);

        $this->assertSame(Esp09IndependentChallengerService::MEASURE_ID, $series['series']);
        $this->assertSame(5, $series['denominator']);
        $this->assertSame(2, $series['accepted']);
        $this->assertSame(3, $series['ignored']);
        $this->assertSame(0.4, $series['accepted_rate']);
        $this->assertArrayHasKey('death_review_candidate', $series);
    }

    #[Test]
    public function zero_acceptance_over_two_windows_flags_death_review(): void
    {
        $series = Esp09IndependentChallengerService::refutationSeries([
            ['outcome' => 'ignored', 'window' => 'w1'],
            ['outcome' => 'ignored', 'window' => 'w1'],
            ['outcome' => 'ignored', 'window' => 'w2'],
            ['outcome' => 'ignored', 'window' => 'w2'],
        ], minWindows: 2, minPerWindow: 2);

        $this->assertTrue($series['death_review_candidate']);
        $this->assertSame('near_zero_accepted_rate_across_windows', $series['death_review_reason']);
    }
}
