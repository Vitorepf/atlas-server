<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorContextRoiScorer;
use Tests\TestCase;

final class AemorContextRoiScorerTest extends TestCase
{
    private const BASE = 75;

    private const HELPFUL = 5;

    private const IRRELEVANT = 4;

    private const STALE = 8;

    private const MISSING = 12;

    public function test_neutral_baseline_scores_seventy_five_and_is_good(): void
    {
        $result = (new AemorContextRoiScorer())->score(0, 0, 0, 0);

        $expected = self::BASE;

        $this->assertSame($expected, $result['score']);
        $this->assertSame(75, $result['score']);
        $this->assertSame('good', $result['status']);
        $this->assertSame('atlas.aemor.context_roi_score.v1', $result['schema_version']);
    }

    public function test_helpful_sources_add_five_each_and_stay_good(): void
    {
        $result = (new AemorContextRoiScorer())->score(3, 0, 0, 0);

        $expected = self::BASE + (3 * self::HELPFUL);

        $this->assertSame($expected, $result['score']);
        $this->assertSame(90, $result['score']);
        $this->assertSame('good', $result['status']);
        $this->assertSame(3, $result['helpful_sources_count']);
    }

    public function test_missing_sources_subtract_twelve_each_into_poor_band(): void
    {
        $result = (new AemorContextRoiScorer())->score(0, 0, 0, 3);

        $expected = self::BASE - (3 * self::MISSING);

        $this->assertSame($expected, $result['score']);
        $this->assertSame(39, $result['score']);
        $this->assertSame('poor', $result['status']);
        $this->assertSame(3, $result['missing_sources_count']);
    }

    public function test_irrelevant_and_stale_weights_land_in_watch_band(): void
    {
        $result = (new AemorContextRoiScorer())->score(0, 2, 1, 0);

        $expected = self::BASE - (2 * self::IRRELEVANT) - (1 * self::STALE);

        $this->assertSame($expected, $result['score']);
        $this->assertSame(59, $result['score']);
        $this->assertSame('watch', $result['status']);
        $this->assertSame(2, $result['irrelevant_sources_count']);
        $this->assertSame(1, $result['stale_sources_count']);
    }

    public function test_score_clamps_to_floor_and_never_goes_negative(): void
    {
        $result = (new AemorContextRoiScorer())->score(0, 0, 0, 10);

        $raw = self::BASE - (10 * self::MISSING);

        $this->assertSame(-45, $raw);
        $this->assertSame(0, $result['score']);
        $this->assertSame(max(0, $raw), $result['score']);
        $this->assertSame('poor', $result['status']);
    }

    public function test_score_clamps_to_ceiling_and_never_exceeds_one_hundred(): void
    {
        $result = (new AemorContextRoiScorer())->score(6, 0, 0, 0);

        $raw = self::BASE + (6 * self::HELPFUL);

        // Raw overshoots the ceiling; min(100, ...) must clamp it back to 100.
        $this->assertSame(105, $raw);
        $this->assertSame(100, $result['score']);
        $this->assertSame(min(100, $raw), $result['score']);
        $this->assertSame('good', $result['status']);
    }

    public function test_status_bands_use_inclusive_lower_boundaries(): void
    {
        $scorer = new AemorContextRoiScorer();

        // score === 70 must be 'good' (>= 70), not 'watch'; a `> 70` regression would fail here.
        $good = $scorer->score(3, 0, 1, 1);
        $this->assertSame(self::BASE + (3 * self::HELPFUL) - self::STALE - self::MISSING, $good['score']);
        $this->assertSame(70, $good['score']);
        $this->assertSame('good', $good['status']);

        // One point below the good cutoff lands in 'watch'.
        $watchTop = $scorer->score(2, 0, 2, 0);
        $this->assertSame(69, $watchTop['score']);
        $this->assertSame('watch', $watchTop['status']);

        // score === 45 must be 'watch' (>= 45), not 'poor'; a `> 45` regression would fail here.
        $watchFloor = $scorer->score(2, 0, 2, 2);
        $this->assertSame(45, $watchFloor['score']);
        $this->assertSame('watch', $watchFloor['status']);

        // One point below the watch cutoff lands in 'poor'.
        $poorTop = $scorer->score(1, 0, 0, 3);
        $this->assertSame(44, $poorTop['score']);
        $this->assertSame('poor', $poorTop['status']);
    }
}
