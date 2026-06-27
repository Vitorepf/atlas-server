<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCompoundingVelocity;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use Tests\TestCase;

final class AtlasBrainCompoundingVelocityTest extends TestCase
{
    private function rs(string $hint, string $kind): array
    {
        return ['action_hint' => $hint, 'result_kind' => $kind];
    }

    public function test_empty_when_tail_too_small(): void
    {
        $r = (new AtlasBrainCompoundingVelocity)->compute(
            array_fill(0, 8, $this->rs('compound', 'accepted')),
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame([], $r['by_path']);
    }

    public function test_detects_accelerating_path(): void
    {
        $tail = array_merge(
            array_fill(0, 3, $this->rs('compound', 'refused')),
            [$this->rs('compound', 'refused'), $this->rs('compound', 'refused'), $this->rs('compound', 'accepted')],
            array_fill(0, 3, $this->rs('compound', 'accepted')),
        );
        $r = (new AtlasBrainCompoundingVelocity)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame('accelerating', $r['by_path']['compounding']['label']);
        self::assertGreaterThan(0, $r['by_path']['compounding']['acceleration']);
    }

    public function test_detects_decelerating_path(): void
    {
        // yields ~1.0, ~0.66, ~0.0: deltas -0.34, -0.66, accel = -0.32 → decelerating
        $tail = array_merge(
            array_fill(0, 3, $this->rs('harvest_frontier', 'accepted')),
            [$this->rs('harvest_frontier', 'accepted'), $this->rs('harvest_frontier', 'accepted'), $this->rs('harvest_frontier', 'refused')],
            array_fill(0, 3, $this->rs('harvest_frontier', 'refused')),
        );
        $r = (new AtlasBrainCompoundingVelocity)->compute($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame('decelerating', $r['by_path']['frontier-harvest']['label']);
        self::assertLessThan(0, $r['by_path']['frontier-harvest']['acceleration']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCompoundingVelocity.php',
                true
            )
        );
    }
}
