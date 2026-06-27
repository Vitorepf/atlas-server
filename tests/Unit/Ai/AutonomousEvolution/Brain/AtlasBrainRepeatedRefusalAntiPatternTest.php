<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainRepeatedRefusalAntiPattern;
use Tests\TestCase;

final class AtlasBrainRepeatedRefusalAntiPatternTest extends TestCase
{
    private function rs(string $hint, string $kind): array
    {
        return ['action_hint' => $hint, 'result_kind' => $kind];
    }

    public function test_no_anti_patterns_when_acceptances_exist(): void
    {
        $tail = [
            $this->rs('compound', 'refused'),
            $this->rs('compound', 'refused'),
            $this->rs('compound', 'refused'),
            $this->rs('compound', 'accepted'),
        ];
        $r = (new AtlasBrainRepeatedRefusalAntiPattern)->detect($tail);
        self::assertSame([], $r['anti_patterns']);
    }

    public function test_detects_pure_failure_hint(): void
    {
        $tail = array_fill(0, 5, $this->rs('harvest_frontier', 'refused'));
        $r = (new AtlasBrainRepeatedRefusalAntiPattern)->detect($tail);
        self::assertCount(1, $r['anti_patterns']);
        self::assertSame('harvest_frontier', $r['anti_patterns'][0]['hint']);
        self::assertSame(5, $r['anti_patterns'][0]['refused']);
    }

    public function test_min_refusals_threshold_respected(): void
    {
        $tail = array_fill(0, 2, $this->rs('compound', 'refused'));
        $r = (new AtlasBrainRepeatedRefusalAntiPattern)->detect($tail, 3);
        self::assertSame([], $r['anti_patterns']);
    }

    public function test_sorted_by_refused_desc(): void
    {
        $tail = array_merge(
            array_fill(0, 4, $this->rs('a', 'refused')),
            array_fill(0, 8, $this->rs('b', 'refused')),
        );
        $r = (new AtlasBrainRepeatedRefusalAntiPattern)->detect($tail);
        self::assertSame('b', $r['anti_patterns'][0]['hint']);
        self::assertSame('a', $r['anti_patterns'][1]['hint']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainRepeatedRefusalAntiPattern.php',
                true
            )
        );
    }
}
