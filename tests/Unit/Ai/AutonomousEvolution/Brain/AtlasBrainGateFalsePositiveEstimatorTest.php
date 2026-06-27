<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGateFalsePositiveEstimator;
use Tests\TestCase;

final class AtlasBrainGateFalsePositiveEstimatorTest extends TestCase
{
    private function row(bool $refused, bool $wasReal): array
    {
        return ['refused_by_gate' => $refused, 'was_real_in_hindsight' => $wasReal];
    }

    public function test_insufficient_sample(): void
    {
        $r = (new AtlasBrainGateFalsePositiveEstimator)->estimate([$this->row(true, false), $this->row(true, true)]);
        self::assertSame('insufficient_sample', $r['status']);
    }

    public function test_healthy_rate(): void
    {
        // 10 refused, 0 real-in-hindsight → fp 0
        $sample = array_fill(0, 10, $this->row(true, false));
        $r = (new AtlasBrainGateFalsePositiveEstimator)->estimate($sample);
        self::assertSame('healthy', $r['status']);
        self::assertSame(0.0, $r['fp_rate']);
    }

    public function test_overrefusing_rate(): void
    {
        // 10 refused, 3 real-in-hindsight → fp 0.3
        $sample = array_merge(
            array_fill(0, 3, $this->row(true, true)),
            array_fill(0, 7, $this->row(true, false)),
        );
        $r = (new AtlasBrainGateFalsePositiveEstimator)->estimate($sample);
        self::assertSame('overrefusing', $r['status']);
        self::assertSame(0.3, $r['fp_rate']);
    }

    public function test_accepted_rows_ignored(): void
    {
        $sample = array_merge(
            array_fill(0, 100, $this->row(false, true)),  // accepted - all ignored
            array_fill(0, 5, $this->row(true, false)),    // 5 refused, 0 real → healthy
        );
        $r = (new AtlasBrainGateFalsePositiveEstimator)->estimate($sample);
        self::assertSame(5, $r['total_refused']);
        self::assertSame('healthy', $r['status']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainGateFalsePositiveEstimator.php',
                true
            )
        );
    }
}
