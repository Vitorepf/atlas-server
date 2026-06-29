<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the hung-grind detector is live at the operator surface: a long-running grind with stale progress is
 * flagged `hung`, while a long-running grind with RECENT progress is `slow_but_live` (never hung). The probe and
 * progress oracle are injected so the test touches no real processes or DB.
 */
final class AtlasLoopHungGrindCommandTest extends TestCase
{
    public function test_hung_grind_is_flagged_and_a_live_grind_is_never_hung(): void
    {
        // Two grind processes (atlas:loop:run-scenario), both past the etime threshold. ps cols:
        // pid ppid etime etimes(=etime_s) pcpu pmem command
        $ps = implode("\n", [
            '111 1 27:46:40 100000 0.0 1.5 php artisan atlas:loop:run-scenario --campaign-id=camp-hung',
            '222 1 20:00 1200 5.0 1.5 php artisan atlas:loop:run-scenario --campaign-id=camp-live',
        ]);
        $this->app->bind(
            AtlasLoopProcessTopologyProbe::class,
            fn (): AtlasLoopProcessTopologyProbe => new AtlasLoopProcessTopologyProbe($ps, 'test-host'),
        );
        // Oracle: pid 111 has stale progress (beyond threshold) ⇒ hung; pid 222 has recent progress ⇒ live.
        $this->app->bind(
            'atlas.loop.hung_grind.progress_oracle',
            fn (): Closure => fn (int $pid, ?string $campaignId, string $command): ?int => $pid === 111 ? 9999 : 1,
        );

        $exit = Artisan::call('atlas:loop:hung-grind', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $byPid = [];
        foreach ($decoded['verdicts'] as $verdict) {
            $byPid[$verdict['pid']] = $verdict;
        }

        $this->assertSame('hung', $byPid[111]['verdict']);
        $this->assertSame(100000, $byPid[111]['evidence']['etime_s']);
        $this->assertSame(9999, $byPid[111]['evidence']['last_progress_age_s']);

        $this->assertSame('slow_but_live', $byPid[222]['verdict'], 'recent progress is never hung');
    }
}
