<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosThresholdLadderNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosThresholdLadderNormalizerTest extends TestCase
{
    public function test_level_ladder_normalizes_numeric_threshold_values(): void
    {
        $ladder = AtlasAaeosThresholdLadderNormalizer::levelLadder([
            [
                'level' => 'L3',
                'thresholds' => [
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 1],
                ],
            ],
        ]);

        $this->assertSame('L3', $ladder[0]['level']);
        $this->assertSame(1.0, $ladder[0]['thresholds'][0]['value']);
    }

    public function test_ranked_band_ladder_preserves_band_rank_and_threshold_shape(): void
    {
        $ladder = AtlasAaeosThresholdLadderNormalizer::rankedBandLadder([
            [
                'band' => 'L4',
                'rank' => 4,
                'thresholds' => [
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.02],
                ],
            ],
        ]);

        $this->assertSame('L4', $ladder[0]['band']);
        $this->assertSame(4, $ladder[0]['rank']);
        $this->assertSame('rollback_rate', $ladder[0]['thresholds'][0]['metric']);
    }

    public function test_invalid_ladder_shape_returns_empty_array(): void
    {
        $this->assertSame([], AtlasAaeosThresholdLadderNormalizer::levelLadder([
            'not-a-list' => ['level' => 'L3', 'thresholds' => []],
        ]));

        $this->assertSame([], AtlasAaeosThresholdLadderNormalizer::rankedBandLadder([
            ['band' => 'L3', 'rank' => '3', 'thresholds' => []],
        ]));
    }
}
