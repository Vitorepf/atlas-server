<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainNextPathSuggester;
use Tests\TestCase;

final class AtlasBrainNextPathSuggesterTest extends TestCase
{
    public function test_starved_path_takes_precedence_over_winning(): void
    {
        $r = (new AtlasBrainNextPathSuggester)->suggest(
            ['compounding', 'simulation-twin'],
            [['path' => 'pattern-design', 'served_rate_pct' => 90, 'total' => 10]],
        );
        self::assertSame('compounding', $r['suggested']);
        self::assertSame('starved_path_unblock', $r['reason']);
    }

    public function test_winning_path_picked_when_no_starvation(): void
    {
        $r = (new AtlasBrainNextPathSuggester)->suggest(
            [],
            [['path' => 'pattern-design', 'served_rate_pct' => 80, 'total' => 5]],
        );
        self::assertSame('pattern-design', $r['suggested']);
        self::assertSame('winning_path_reinforce', $r['reason']);
    }

    public function test_fallback_when_nothing_qualifies(): void
    {
        $r = (new AtlasBrainNextPathSuggester)->suggest([], []);
        self::assertSame('adversarial-critique', $r['suggested']);
        self::assertSame('fallback_canonical', $r['reason']);
    }

    public function test_suggester_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextPathSuggester.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
