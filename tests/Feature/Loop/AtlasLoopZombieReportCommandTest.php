<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the zombie reaper is live at the operator surface (report-only): a claim whose pid is ABSENT from the
 * live topology, past grace, and still 'claimed' is reported reaped (would-release, released=false under the
 * forced dry-run), while a claim whose pid IS alive is spared.
 */
final class AtlasLoopZombieReportCommandTest extends TestCase
{
    private const TABLE = 'atlas_loop_zombie_probe';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->integer('worker_pid')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('status')->nullable();
        });
        config(['atlas.loop.zombie_reaper.tables' => [self::TABLE]]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    public function test_zombie_claim_reported_and_live_claim_spared(): void
    {
        $stale = Carbon::now('UTC')->subHours(2);
        DB::table(self::TABLE)->insert([
            ['worker_pid' => 999001, 'claimed_at' => $stale, 'status' => 'claimed'], // pid absent from topology
            ['worker_pid' => 111, 'claimed_at' => $stale, 'status' => 'claimed'],    // pid alive
        ]);

        // Live topology carries pid 111 (a grind) but not 999001.
        $ps = '111 1 27:46:40 100000 0.0 1.5 php artisan atlas:loop:run-scenario --campaign-id=c';
        $this->app->bind(
            AtlasLoopProcessTopologyProbe::class,
            fn (): AtlasLoopProcessTopologyProbe => new AtlasLoopProcessTopologyProbe($ps, 'test-host'),
        );

        $exit = Artisan::call('atlas:loop:zombie-report', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['dry_run'], 'the report-only command forces dry_run');

        $reapedByPid = [];
        foreach ($report['reaped'] as $receipt) {
            $reapedByPid[$receipt['pid']] = $receipt;
        }
        $sparedByPid = [];
        foreach ($report['spared'] as $receipt) {
            $sparedByPid[$receipt['pid']] = $receipt;
        }

        $this->assertArrayHasKey(999001, $reapedByPid, 'a pid absent from the live topology is reported reaped');
        $this->assertSame('pid_absent_from_topology', $reapedByPid[999001]['reason']);
        $this->assertFalse($reapedByPid[999001]['released'], 'dry_run ⇒ nothing is actually released');

        $this->assertArrayHasKey(111, $sparedByPid, 'a live pid is spared');
        $this->assertSame('pid_alive', $sparedByPid[111]['reason']);

        // Report-only: the row is untouched (still claimed by the zombie pid).
        $this->assertSame('claimed', (string) DB::table(self::TABLE)->where('worker_pid', 999001)->value('status'));
    }
}
