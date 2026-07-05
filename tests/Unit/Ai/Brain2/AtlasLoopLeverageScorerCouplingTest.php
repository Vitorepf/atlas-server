<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopLeverageScorer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopLeverageScorer accepts an optional multi_file_coupling signal
 * (0..1) as a soft boost, so a multi-file hub outranks an equivalent orphan.
 */
final class AtlasLoopLeverageScorerCouplingTest extends TestCase
{
    public function test_coupling_boost_ranks_hub_above_orphan(): void
    {
        $scorer = new AtlasLoopLeverageScorer;

        // Two candidates with identical base signals except coupling.
        $base = [
            'caller_count' => 5,
            'cyclomatic' => 10,
            'cost' => 0.5,
            'risk' => 0.5,
            'verifiable' => true,
        ];

        $hub = $base + ['path' => 'hub/FiveFileService.php', 'multi_file_coupling' => 0.8];
        $orphan = $base + ['path' => 'orphan/SingleFileUtil.php', 'multi_file_coupling' => 0.0];

        $hubScore = $scorer->score($hub);
        $orphanScore = $scorer->score($orphan);

        // Hub must rank STRICTLY higher.
        $this->assertGreaterThan(
            $orphanScore['leverage'],
            $hubScore['leverage'],
            'high-coupling hub must outrank equivalent low-coupling orphan',
        );

        // coupling components present.
        $this->assertArrayHasKey('multi_file_coupling', $hubScore['components']);
        $this->assertArrayHasKey('multi_file_coupling', $orphanScore['components']);
        $this->assertSame(0.8, $hubScore['components']['multi_file_coupling']);
        $this->assertSame(0.0, $orphanScore['components']['multi_file_coupling']);
    }

    public function test_coupling_defaults_to_zero_when_absent(): void
    {
        $scorer = new AtlasLoopLeverageScorer;

        $score = $scorer->score([
            'caller_count' => 5,
            'cyclomatic' => 10,
            'cost' => 0.5,
            'risk' => 0.5,
            'verifiable' => true,
        ]);

        $this->assertSame(0.0, $score['components']['multi_file_coupling']);
    }

    public function test_coupling_clamped_to_01(): void
    {
        $scorer = new AtlasLoopLeverageScorer;

        $negative = $scorer->score(['multi_file_coupling' => -0.5, 'verifiable' => true]);
        $overOne = $scorer->score(['multi_file_coupling' => 1.5, 'verifiable' => true]);

        $this->assertSame(0.0, $negative['components']['multi_file_coupling']);
        $this->assertSame(1.0, $overOne['components']['multi_file_coupling']);
    }

    public function test_rank_places_high_coupling_first(): void
    {
        $scorer = new AtlasLoopLeverageScorer;

        $ranked = $scorer->rank([
            ['path' => 'a', 'multi_file_coupling' => 0.1, 'caller_count' => 5, 'cyclomatic' => 10, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true],
            ['path' => 'b', 'multi_file_coupling' => 0.9, 'caller_count' => 5, 'cyclomatic' => 10, 'cost' => 0.5, 'risk' => 0.5, 'verifiable' => true],
        ]);

        $this->assertSame('b', $ranked[0]['path']);
        $this->assertSame('a', $ranked[1]['path']);
    }
}
