<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\PredictedImpactBand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1704PredictedImpactBandTest extends TestCase
{
    #[Test]
    public function band_is_purely_derived_from_rung_rank_and_yield(): void
    {
        $high = PredictedImpactBand::classify(['rung' => 'obra', 'rank' => 1, 'path_yield' => 0.8]);
        $low = PredictedImpactBand::classify(['rung' => 'task', 'rank' => 12, 'path_yield' => 0.1]);

        $this->assertSame('high', $high['band']);
        $this->assertSame('low', $low['band']);
        $this->assertFalse($high['source']['influences_pick']);
    }

    #[Test]
    public function caller_declared_band_is_ignored(): void
    {
        $out = PredictedImpactBand::classify([
            'rung' => 'task',
            'rank' => 99,
            'path_yield' => 0.0,
            'band' => 'high',
        ]);

        $this->assertSame('low', $out['band']);
    }

    #[Test]
    public function unresolved_tasks_do_not_count_as_realized(): void
    {
        $curve = PredictedImpactBand::calibration([
            ['band' => 'high', 'realized' => true],
            ['band' => 'high', 'status' => 'unresolved'],
        ]);

        $this->assertSame(1, $curve['bands']['high']['n_realized']);
        $this->assertSame(1, $curve['bands']['high']['unresolved']);
    }
}
