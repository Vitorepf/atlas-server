<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeSignalProjector;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeSignalProjectorTest extends TestCase
{
    public function test_fresh_real_outcome_and_recurrence_are_projected_with_confidence(): void
    {
        $result = (new AtlasExternalBrainOutcomeSignalProjector)->project(
            [
                ['candidate_id' => 'bottleneck', 'task_family' => 'dependency'],
                ['candidate_id' => 'shallow', 'task_family' => 'cosmetic'],
            ],
            [[
                'candidate_id' => 'bottleneck',
                'outcome' => 'delivered',
                'outcome_id' => 'outcome-1',
                'observed_at' => '2026-07-10T00:00:00Z',
            ]],
            ['task_family:dependency' => 4],
            new DateTimeImmutable('2026-07-12T00:00:00Z'),
        );

        self::assertSame(AtlasExternalBrainOutcomeSignalProjector::SCHEMA, $result['schema']);
        self::assertSame('positive', $result['signals']['bottleneck']['outcome_signal']);
        self::assertSame(1.0, $result['signals']['bottleneck']['outcome_freshness']);
        self::assertGreaterThan(0.0, $result['signals']['bottleneck']['outcome_confidence']);
        self::assertSame(4, $result['signals']['bottleneck']['recurrence']);
        self::assertSame('unknown', $result['signals']['shallow']['outcome_signal']);
        self::assertSame(1, $result['signals']['shallow']['outcome_gap']);
        self::assertSame(0.0, $result['signals']['shallow']['outcome_confidence']);
    }

    public function test_stale_or_invalid_outcomes_never_become_positive_evidence(): void
    {
        $result = (new AtlasExternalBrainOutcomeSignalProjector)->project(
            [['candidate_id' => 'stale']],
            [[
                'candidate_id' => 'stale',
                'outcome' => 'delivered',
                'observed_at' => '2025-01-01T00:00:00Z',
            ], [
                'candidate_id' => 'stale',
                'outcome' => 'success',
                'observed_at' => 'not-a-date',
            ]],
            [],
            new DateTimeImmutable('2026-07-12T00:00:00Z'),
        );

        self::assertSame('unknown', $result['signals']['stale']['outcome_signal']);
        self::assertSame(0.0, $result['signals']['stale']['outcome_freshness']);
        self::assertSame(1, $result['signals']['stale']['outcome_gap']);
        self::assertSame(0.0, $result['signals']['stale']['outcome_confidence']);
    }
}
