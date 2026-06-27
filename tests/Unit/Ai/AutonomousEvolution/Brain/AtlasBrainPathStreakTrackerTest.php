<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHintToPathTranslator;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStreakTracker;
use Tests\TestCase;

final class AtlasBrainPathStreakTrackerTest extends TestCase
{
    private function rs(string $hint, string $kind): array
    {
        return ['action_hint' => $hint, 'result_kind' => $kind];
    }

    public function test_tracks_longest_accepted_and_refused(): void
    {
        $tail = [
            $this->rs('compound', 'accepted'),
            $this->rs('compound', 'accepted'),
            $this->rs('compound', 'accepted'),
            $this->rs('compound', 'refused'),
            $this->rs('compound', 'refused'),
            $this->rs('compound', 'accepted'),
        ];
        $r = (new AtlasBrainPathStreakTracker)->track($tail, new AtlasBrainHintToPathTranslator);
        self::assertSame(3, $r['by_path']['compounding']['longest_accepted']);
        self::assertSame(2, $r['by_path']['compounding']['longest_refused']);
    }

    public function test_separate_paths_tracked_independently(): void
    {
        $tail = [
            $this->rs('compound', 'accepted'),
            $this->rs('harvest_frontier', 'refused'),
            $this->rs('compound', 'accepted'),
            $this->rs('harvest_frontier', 'refused'),
        ];
        $r = (new AtlasBrainPathStreakTracker)->track($tail, new AtlasBrainHintToPathTranslator);
        // Interleaved hits to different paths still extend per-path streaks
        self::assertSame(2, $r['by_path']['compounding']['longest_accepted']);
        self::assertSame(2, $r['by_path']['frontier-harvest']['longest_refused']);
    }

    public function test_unknown_hints_skipped(): void
    {
        $r = (new AtlasBrainPathStreakTracker)->track(
            [$this->rs('unknown', 'accepted')],
            new AtlasBrainHintToPathTranslator,
        );
        self::assertSame([], $r['by_path']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathStreakTracker.php',
                true
            )
        );
    }
}
