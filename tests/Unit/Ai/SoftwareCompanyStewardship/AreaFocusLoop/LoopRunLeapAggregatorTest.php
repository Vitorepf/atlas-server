<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopRunLeapAggregator;
use PHPUnit\Framework\TestCase;

final class LoopRunLeapAggregatorTest extends TestCase
{
    private LoopRunLeapAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new LoopRunLeapAggregator();
    }

    /**
     * A per-cycle record shaped like CycleQualityScoreService::score(): only the
     * two read keys are load-bearing for the aggregator.
     *
     * @return array<string, mixed>
     */
    private function cycle(bool $realProductiveMerge, bool $countsAsLeap): array
    {
        return [
            'real_productive_merge' => $realProductiveMerge,
            'counts_as_leap' => $countsAsLeap,
        ];
    }

    public function testThreeMergesNoLeapsPlusTwoNonMergesIsValidButEmptyRun(): void
    {
        $result = $this->aggregator->aggregate([
            $this->cycle(true, false),
            $this->cycle(true, false),
            $this->cycle(true, false),
            $this->cycle(false, false),
            $this->cycle(false, false),
        ]);

        $this->assertSame(3, $result['valid_merge_count']);
        $this->assertSame(0, $result['leap_count']);
        $this->assertSame('valid_but_empty_run', $result['run_verdict']);
        $this->assertSame(0.0, $result['leap_ratio']);
        $this->assertSame(5, $result['cycle_count']);
    }

    public function testOneLeapAmongFourMergesIsCompoundingRunWithQuarterRatio(): void
    {
        $result = $this->aggregator->aggregate([
            $this->cycle(true, true),
            $this->cycle(true, false),
            $this->cycle(true, false),
            $this->cycle(true, false),
        ]);

        $this->assertSame(1, $result['leap_count']);
        $this->assertSame(4, $result['valid_merge_count']);
        $this->assertSame(0.25, $result['leap_ratio']);
        $this->assertSame('compounding_run', $result['run_verdict']);
    }

    public function testEmptyRunIsStalledWithZeroRatioAndZeroMerges(): void
    {
        $empty = $this->aggregator->aggregate([]);

        $this->assertSame('stalled_run', $empty['run_verdict']);
        $this->assertSame(0.0, $empty['leap_ratio']);
        $this->assertSame(0, $empty['valid_merge_count']);
        $this->assertSame(0, $empty['leap_count']);
        $this->assertSame(0, $empty['cycle_count']);
    }

    public function testAllNonMergeRunIsStalledWithZeroRatioAndZeroMerges(): void
    {
        $allNonMerge = $this->aggregator->aggregate([
            $this->cycle(false, false),
            $this->cycle(false, false),
            $this->cycle(false, false),
        ]);

        $this->assertSame('stalled_run', $allNonMerge['run_verdict']);
        $this->assertSame(0.0, $allNonMerge['leap_ratio']);
        $this->assertSame(0, $allNonMerge['valid_merge_count']);
        $this->assertSame(3, $allNonMerge['cycle_count']);
    }

    public function testLeapClaimWithoutRealProductiveMergeIsNotCountedAsLeap(): void
    {
        $result = $this->aggregator->aggregate([
            // counts_as_leap true but real_productive_merge false => NOT a leap.
            $this->cycle(false, true),
            // one genuine real productive merge that is not a leap.
            $this->cycle(true, false),
        ]);

        $this->assertSame(0, $result['leap_count']);
        $this->assertSame(1, $result['valid_merge_count']);
        $this->assertSame(0.0, $result['leap_ratio']);
        $this->assertSame('valid_but_empty_run', $result['run_verdict']);
    }

    public function testAddingFillerMergeRaisesValidMergeCountAndLowersRatio(): void
    {
        $base = [
            $this->cycle(true, true),
            $this->cycle(true, false),
        ];

        $before = $this->aggregator->aggregate($base);

        // A filler merge is a real productive merge that is NOT a leap.
        $after = $this->aggregator->aggregate([
            ...$base,
            $this->cycle(true, false),
        ]);

        $this->assertSame(2, $before['valid_merge_count']);
        $this->assertSame(0.5, $before['leap_ratio']);

        $this->assertSame(3, $after['valid_merge_count']);
        $this->assertSame(0.3333, $after['leap_ratio']);
        $this->assertLessThan($before['leap_ratio'], $after['leap_ratio']);
    }

    public function testAddingFillerMergeLeavesValidButEmptyVerdictUnchanged(): void
    {
        $base = [
            $this->cycle(true, false),
        ];

        $before = $this->aggregator->aggregate($base);

        $after = $this->aggregator->aggregate([
            ...$base,
            $this->cycle(true, false),
        ]);

        $this->assertSame('valid_but_empty_run', $before['run_verdict']);
        $this->assertSame('valid_but_empty_run', $after['run_verdict']);
        $this->assertSame(2, $after['valid_merge_count']);
        $this->assertSame(1, $before['valid_merge_count']);
    }
}
