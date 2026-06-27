<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScoreLedger;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * FROZEN proof of the brain trend command — summarizes the L99 ledger tail.
 */
final class AtlasBrainTrendCommandTest extends TestCase
{
    public function test_trend_emits_first_last_min_max_delta(): void
    {
        $root = sys_get_temp_dir().'/atlas-brain-trend-'.bin2hex(random_bytes(6));
        config()->set('atlas.brain.health_score_root', $root);
        config()->set('atlas.brain.scopes.loop', ['label' => 'test', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.default_scope', 'loop');

        $ledger = new AtlasBrainHealthScoreLedger($root);
        foreach ([60, 75, 50, 80] as $i => $score) {
            $ledger->append('loop', $score, 100 + $i);
        }

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:trend', [], $buf);
        $r = json_decode(trim($buf->fetch()), true);

        self::assertSame(4, $r['count']);
        self::assertSame(60, $r['first']);
        self::assertSame(80, $r['last']);
        self::assertSame(50, $r['min']);
        self::assertSame(80, $r['max']);
        self::assertSame(20, $r['delta']);
        self::assertSame('improving', $r['direction']);
        self::assertSame(4, strlen($r['sparkline']));
    }

    public function test_trend_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainTrendCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
