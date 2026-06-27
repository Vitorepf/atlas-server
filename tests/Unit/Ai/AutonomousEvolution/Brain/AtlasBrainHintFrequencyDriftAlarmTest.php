<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintFrequencyDriftAlarm;
use Tests\TestCase;

final class AtlasBrainHintFrequencyDriftAlarmTest extends TestCase
{
    private function tail(array $hints): array
    {
        return array_map(static fn (string $h) => ['action_hint' => $h], $hints);
    }

    public function test_no_alerts_when_tail_too_small(): void
    {
        $r = (new AtlasBrainHintFrequencyDriftAlarm)->detect($this->tail(['harvest_frontier', 'compound']));
        self::assertSame([], $r['alerts']);
    }

    public function test_detects_surge(): void
    {
        // baseline (8): 2 compound, 6 harvest; current (8): 8 compound
        $r = (new AtlasBrainHintFrequencyDriftAlarm)->detect($this->tail(array_merge(
            ['compound', 'compound', 'harvest_frontier', 'harvest_frontier', 'harvest_frontier', 'harvest_frontier', 'harvest_frontier', 'harvest_frontier'],
            array_fill(0, 8, 'compound'),
        )));
        $byHint = [];
        foreach ($r['alerts'] as $a) {
            $byHint[$a['hint']] = $a['direction'];
        }
        self::assertSame('surge', $byHint['compound'] ?? null);
        self::assertSame('collapse', $byHint['harvest_frontier'] ?? null);
    }

    public function test_baseline_under_threshold_skipped(): void
    {
        // single baseline occurrence: cannot establish baseline, no alert
        $r = (new AtlasBrainHintFrequencyDriftAlarm)->detect($this->tail(array_merge(
            ['compound', 'harvest_frontier', 'harvest_frontier', 'harvest_frontier'],
            array_fill(0, 4, 'compound'),
        )));
        $hints = array_column($r['alerts'], 'hint');
        self::assertNotContains('compound', $hints);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintFrequencyDriftAlarm.php',
                true
            )
        );
    }
}
