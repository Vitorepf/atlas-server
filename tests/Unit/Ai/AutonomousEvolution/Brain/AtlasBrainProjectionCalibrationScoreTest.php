<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProjectionCalibrationScore;
use Tests\TestCase;

final class AtlasBrainProjectionCalibrationScoreTest extends TestCase
{
    private function pair(string $predicted, string $actual): array
    {
        return ['predicted_path' => $predicted, 'actual_path' => $actual];
    }

    public function test_insufficient_sample(): void
    {
        $r = (new AtlasBrainProjectionCalibrationScore)->score([$this->pair('a', 'a')]);
        self::assertSame('insufficient_sample', $r['status']);
    }

    public function test_well_calibrated(): void
    {
        $sample = array_fill(0, 10, $this->pair('compounding', 'compounding'));
        $r = (new AtlasBrainProjectionCalibrationScore)->score($sample);
        self::assertSame('well_calibrated', $r['status']);
        self::assertSame(1.0, $r['hit_rate']);
    }

    public function test_miscalibrated(): void
    {
        $sample = array_merge(
            array_fill(0, 1, $this->pair('a', 'a')),
            array_fill(0, 9, $this->pair('a', 'b')),
        );
        $r = (new AtlasBrainProjectionCalibrationScore)->score($sample);
        self::assertSame('miscalibrated', $r['status']);
        self::assertSame(0.1, $r['hit_rate']);
    }

    public function test_empty_fields_skipped(): void
    {
        $sample = array_merge(
            array_fill(0, 5, $this->pair('a', 'a')),
            [$this->pair('', 'x'), $this->pair('y', '')],
        );
        $r = (new AtlasBrainProjectionCalibrationScore)->score($sample);
        self::assertSame(5, $r['total']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProjectionCalibrationScore.php',
                true
            )
        );
    }
}
