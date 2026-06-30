<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorProviderSkillReliabilityScorer;
use Tests\TestCase;

final class AemorProviderSkillReliabilityScorerTest extends TestCase
{
    public function test_full_positive_episode_caps_at_one_hundred(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded',
            true,
            2,
            'minimax',
            'engineering',
            'flow_x'
        );

        $this->assertSame(100, $result['reliability_score']);
        $this->assertSame('positive', $result['signal']);
        $this->assertSame('minimax', $result['provider']);
    }

    public function test_full_negative_episode_floors_at_zero_with_unknown_fallbacks(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'failed',
            false,
            0,
            null,
            null,
            null
        );

        $this->assertSame(0, $result['reliability_score']);
        $this->assertSame('negative', $result['signal']);
        $this->assertSame('unknown', $result['provider']);
        $this->assertSame('unknown', $result['domain']);
        $this->assertSame('unknown', $result['flow_id']);
    }

    public function test_null_tests_passed_yields_eighty_five_positive(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded',
            null,
            2,
            'p',
            'd',
            'f'
        );

        $this->assertSame(85, $result['reliability_score']);
        $this->assertSame('positive', $result['signal']);
    }

    public function test_succeeded_with_failed_tests_and_no_evidence_is_neutral_fifty(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded',
            false,
            0,
            'p',
            'd',
            'f'
        );

        $this->assertSame(50, $result['reliability_score']);
        $this->assertSame('neutral', $result['signal']);
    }

    public function test_invariants_hold_across_branches_and_evidence_refs_key_absent(): void
    {
        $scorer = new AemorProviderSkillReliabilityScorer;

        $branches = [
            $scorer->score('succeeded', true, 2, 'minimax', 'engineering', 'flow_x'),
            $scorer->score('failed', false, 0, null, null, null),
            $scorer->score('succeeded', null, 2, 'p', 'd', 'f'),
            $scorer->score('succeeded', false, 0, 'p', 'd', 'f'),
        ];

        foreach ($branches as $result) {
            $this->assertSame('single_episode_signal_not_global_ranking', $result['sample_policy']);
            $this->assertSame('observed', $result['status']);
            $this->assertFalse(array_key_exists('evidence_refs', $result));
        }
    }

    // ── AC2: new fields present in payload ────────────────────────────────────

    public function test_ac2_payload_contains_evidence_floor_met(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertArrayHasKey('evidence_floor_met', $result);
        $this->assertIsBool($result['evidence_floor_met']);
    }

    public function test_ac2_payload_contains_label_completeness(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertArrayHasKey('label_completeness', $result);
        $this->assertIsFloat($result['label_completeness']);
    }

    public function test_ac2_payload_contains_reliability_blockers(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertArrayHasKey('reliability_blockers', $result);
        $this->assertIsArray($result['reliability_blockers']);
    }

    public function test_ac2_full_label_set_gives_completeness_one(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 1, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertSame(1.0, $result['label_completeness']);
    }

    public function test_ac2_no_labels_gives_completeness_zero(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'failed', false, 0, null, null, null
        );

        $this->assertSame(0.0, $result['label_completeness']);
    }

    // ── AC3: succeeded+tests_passed=true+evidence=0 cannot produce positive signal ──

    public function test_ac3_succeeded_tests_passed_zero_evidence_is_not_positive(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 0, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertNotSame('positive', $result['signal'],
            'a succeeded outcome with tests_passed=true but zero evidence refs must not produce positive signal');
    }

    public function test_ac3_evidence_floor_not_met_adds_blocker(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 0, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertFalse($result['evidence_floor_met']);
        $this->assertContains('evidence_floor_not_met', $result['reliability_blockers']);
    }

    public function test_ac3_evidence_floor_met_with_one_ref(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 1, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertTrue($result['evidence_floor_met']);
        $this->assertNotContains('evidence_floor_not_met', $result['reliability_blockers']);
        $this->assertSame('positive', $result['signal']);
    }

    public function test_ac3_clean_episode_has_empty_reliability_blockers(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, 'minimax', 'engineering', 'flow_x'
        );

        $this->assertSame([], $result['reliability_blockers']);
    }

    // ── AC4: missing labels reduce reliability without breaking unknown fallback ──

    public function test_ac4_unknown_provider_reduces_score_versus_known(): void
    {
        $scorer = new AemorProviderSkillReliabilityScorer;

        $known   = $scorer->score('succeeded', true, 2, 'minimax', 'engineering', 'flow_x');
        $missing = $scorer->score('succeeded', true, 2, null, 'engineering', 'flow_x');

        $this->assertLessThan($known['reliability_score'], $missing['reliability_score'],
            'missing provider label must reduce reliability_score');
    }

    public function test_ac4_all_labels_unknown_does_not_break_fallback_values(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, null, null, null
        );

        $this->assertSame('unknown', $result['provider']);
        $this->assertSame('unknown', $result['domain']);
        $this->assertSame('unknown', $result['flow_id']);
    }

    public function test_ac4_partial_label_completeness(): void
    {
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'succeeded', true, 2, 'minimax', null, null
        );

        // 1 of 3 known → completeness = 0.33
        $this->assertEqualsWithDelta(0.33, $result['label_completeness'], 0.01);
    }

    public function test_ac4_unknown_labels_do_not_elevate_negative_signal(): void
    {
        // A negative episode stays negative even with unknown-label penalties applied.
        $result = (new AemorProviderSkillReliabilityScorer)->score(
            'failed', false, 0, null, null, null
        );

        $this->assertSame('negative', $result['signal']);
    }
}
