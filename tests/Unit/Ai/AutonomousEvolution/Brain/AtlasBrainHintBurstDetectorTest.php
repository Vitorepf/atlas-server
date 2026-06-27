<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintBurstDetector;
use Tests\TestCase;

final class AtlasBrainHintBurstDetectorTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_no_burst_when_below_min_run(): void
    {
        $r = (new AtlasBrainHintBurstDetector)->detect($this->tail(['a', 'a', 'a', 'b', 'b']));
        self::assertSame([], $r['bursts']);
    }

    public function test_detects_single_burst(): void
    {
        $r = (new AtlasBrainHintBurstDetector)->detect($this->tail(['a', 'a', 'a', 'a', 'a', 'b']));
        self::assertCount(1, $r['bursts']);
        self::assertSame(['hint' => 'a', 'length' => 5, 'start_index' => 0], $r['bursts'][0]);
    }

    public function test_detects_multiple_bursts(): void
    {
        $tail = $this->tail(array_merge(
            array_fill(0, 6, 'a'),
            ['b'],
            array_fill(0, 5, 'c'),
        ));
        $r = (new AtlasBrainHintBurstDetector)->detect($tail);
        self::assertCount(2, $r['bursts']);
        self::assertSame('a', $r['bursts'][0]['hint']);
        self::assertSame('c', $r['bursts'][1]['hint']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintBurstDetector.php',
                true
            )
        );
    }
}
