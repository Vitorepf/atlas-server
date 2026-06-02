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
}
