<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\Support\CommandOutcomeScoringSupport;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CommandOutcomeScoringSupportTest extends TestCase
{
    #[Test]
    public function duration_bucket_and_percentile_are_stable(): void
    {
        $this->assertSame('under_10s', CommandOutcomeScoringSupport::durationBucket(9_999));
        $this->assertSame('10s_to_60s', CommandOutcomeScoringSupport::durationBucket(10_001));
        $this->assertSame('over_15m', CommandOutcomeScoringSupport::durationBucket(901_000));

        $this->assertNull(CommandOutcomeScoringSupport::durationPercentile([], 0.95));
        $this->assertSame(100, CommandOutcomeScoringSupport::durationPercentile([100], 0.95));
        $this->assertSame(50, CommandOutcomeScoringSupport::durationPercentile([10, 20, 30, 40, 50], 1.0));
    }

    #[Test]
    public function performance_score_and_grade_map_duration_bands(): void
    {
        $this->assertSame(2, CommandOutcomeScoringSupport::commandPerformanceScore(['duration_ms_p95' => 5_000]));
        $this->assertSame('fast', CommandOutcomeScoringSupport::commandPerformanceGrade(['duration_ms_p95' => 5_000]));
        $this->assertSame(-3, CommandOutcomeScoringSupport::commandPerformanceScore(['duration_ms_avg' => 1_000_000]));
        $this->assertSame('slow', CommandOutcomeScoringSupport::commandPerformanceGrade(['duration_ms_avg' => 1_000_000]));
        $this->assertSame(0, CommandOutcomeScoringSupport::commandPerformanceScore([]));
        $this->assertSame('unknown', CommandOutcomeScoringSupport::commandPerformanceGrade([]));
    }

    #[Test]
    public function outcome_polarity_classifies_success_and_failure(): void
    {
        $this->assertSame(1, CommandOutcomeScoringSupport::outcomePolarity('passed'));
        $this->assertSame(-1, CommandOutcomeScoringSupport::outcomePolarity('FAILED'));
        $this->assertSame(0, CommandOutcomeScoringSupport::outcomePolarity('pending'));
    }

    #[Test]
    public function command_recency_score_uses_day_bands(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00'));
        try {
            $this->assertSame(2, CommandOutcomeScoringSupport::commandRecencyScore('2026-07-23T12:00:00Z'));
            $this->assertSame(1, CommandOutcomeScoringSupport::commandRecencyScore('2026-07-15T12:00:00Z'));
            $this->assertSame(-1, CommandOutcomeScoringSupport::commandRecencyScore('2026-01-01T12:00:00Z'));
            $this->assertSame(0, CommandOutcomeScoringSupport::commandRecencyScore(''));
            $this->assertSame(0, CommandOutcomeScoringSupport::commandRecencyScore('not-a-date'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function effectiveness_stats_empty_accumulate_and_finalize(): void
    {
        $bucket = CommandOutcomeScoringSupport::emptyEffectivenessStats('policy_ref', 'policy:abc');
        $this->assertSame('policy:abc', $bucket['policy_ref']);
        $this->assertSame(0, $bucket['success_count']);
        $this->assertSame([], $bucket['commands']);

        CommandOutcomeScoringSupport::accumulateEffectivenessStats($bucket, [
            'success_count' => 2,
            'failure_count' => 1,
            'neutral_count' => 0,
            'total_count' => 3,
            'score' => 4,
        ]);
        $this->assertSame(2, $bucket['success_count']);
        $this->assertSame(1, $bucket['failure_count']);
        $this->assertSame(3, $bucket['total_count']);

        $finalized = CommandOutcomeScoringSupport::finalizeEffectivenessStats(['policy:abc' => $bucket]);
        $this->assertSame(0.67, $finalized['policy:abc']['success_rate']);
        $this->assertSame('mixed', $finalized['policy:abc']['effectiveness']);
    }

    #[Test]
    public function normalize_cache_backed_hash_ref_accepts_canonical_and_cache_forms(): void
    {
        $hash = str_repeat('a', 64);
        $this->assertSame(
            'execution_optimization_policy:'.$hash,
            CommandOutcomeScoringSupport::normalizeCacheBackedHashRef(
                'execution_optimization_policy:'.$hash,
                'execution_optimization_policy',
            ),
        );
        $this->assertSame(
            'execution_optimization_policy:'.$hash,
            CommandOutcomeScoringSupport::normalizeCacheBackedHashRef(
                'awis_cache:execution_optimization_policy:'.$hash,
                'execution_optimization_policy',
            ),
        );
        $this->assertSame(
            '',
            CommandOutcomeScoringSupport::normalizeCacheBackedHashRef('awis_cache:other:'.$hash, 'execution_optimization_policy'),
        );
        $this->assertSame(
            '',
            CommandOutcomeScoringSupport::normalizeCacheBackedHashRef('not-a-ref', 'execution_optimization_policy'),
        );
    }

    #[Test]
    public function record_performance_profile_accumulates_buckets_and_grade(): void
    {
        $profiles = [];
        CommandOutcomeScoringSupport::recordPerformanceProfile($profiles, 'app/Services', 5_000);
        CommandOutcomeScoringSupport::recordPerformanceProfile($profiles, 'app/Services', 7_500);

        $this->assertSame(2, $profiles['app/Services']['observed_count']);
        $this->assertSame(6250, $profiles['app/Services']['duration_ms_avg']);
        $this->assertSame(2, $profiles['app/Services']['duration_bucket_counts']['under_10s']);
        $this->assertSame('fast', $profiles['app/Services']['performance_grade']);
    }
}
