<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFailureHandleSource;
use App\Services\Ai\Cognitive\Failure\SuiteRedTestHandleHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S3 — the harvester's FLAKY-FILTER. Reuses SuiteRedTriageHelper::classifyTestFailure to keep
 * ONLY 'real_failure' reds: an environmental red (e.g. 'SQLSTATE connection refused') and an
 * 'unknown' red are DROPPED; only the real_failure ('Failed asserting ...') produces a handle row.
 * RED if the classification filter is removed (an environmental red would leak into the supply).
 */
final class AtlasLoopSuiteRedTestHandleHarvesterTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_failure_handles')) {
            (require base_path('database/migrations/2026_06_19_000100_create_atlas_loop_failure_handles_table.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function reportPath(array $report): string
    {
        $d = sys_get_temp_dir().'/atlas-fhharvest-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d);
        $path = $d.'/report.json';
        File::put($path, (string) json_encode($report));

        return $path;
    }

    public function test_only_real_failure_red_produces_a_handle(): void
    {
        config(['atlas.loop.failure_handle_harvest_enabled' => true]);

        $path = $this->reportPath([
            'tests' => [
                [
                    'name' => 'test_db_connects',
                    'status' => 'error',
                    'message' => 'SQLSTATE[HY000] connection refused',
                    'test_path' => 'tests/Feature/Services/DbGatewayTest.php',
                    'target_path' => 'app/Services/DbGateway.php',
                ],
                [
                    'name' => 'test_divide_guards_against_zero',
                    'status' => 'failed',
                    'message' => 'Failed asserting that 0 matches expected 1.',
                    'test_path' => 'tests/Feature/Services/CalculatorTest.php',
                    'target_path' => 'app/Services/Calculator.php',
                ],
            ],
        ]);

        $report = (new SuiteRedTestHandleHarvester())->harvest($path);

        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['scanned'], 'both reds were examined');
        $this->assertSame(1, $report['harvested'], 'ONLY the real_failure red produced a handle');
        $this->assertSame(1, $report['dropped_environmental'], 'the SQLSTATE red was filtered as environmental');

        // The DB confirms the filter: exactly the real_failure target was written, the environmental was NOT.
        $rows = DB::table(AtlasLoopFailureHandleSource::TABLE)->pluck('path')->all();
        $this->assertSame(['app/Services/Calculator.php'], $rows, 'only the real_failure handle is persisted');
    }

    public function test_unknown_red_is_dropped(): void
    {
        config(['atlas.loop.failure_handle_harvest_enabled' => true]);

        $path = $this->reportPath([
            'tests' => [
                [
                    'name' => 'test_mysterious',
                    'status' => 'failed',
                    'message' => 'something happened that matches no known signal',
                    'test_path' => 'tests/Feature/Services/MysteryTest.php',
                    'target_path' => 'app/Services/Mystery.php',
                ],
            ],
        ]);

        $report = (new SuiteRedTestHandleHarvester())->harvest($path);

        $this->assertSame(1, $report['scanned']);
        $this->assertSame(0, $report['harvested'], 'an unknown-classification red is not manufactured into work');
        $this->assertSame(1, $report['dropped_unknown']);
        $this->assertSame(0, DB::table(AtlasLoopFailureHandleSource::TABLE)->count());
    }

    public function test_disabled_without_force_is_a_noop(): void
    {
        config(['atlas.loop.failure_handle_harvest_enabled' => false]);

        $path = $this->reportPath([
            'tests' => [[
                'name' => 'test_x', 'status' => 'failed',
                'message' => 'Failed asserting that 0 matches expected 1.',
                'test_path' => 'tests/Feature/Services/CalculatorTest.php',
                'target_path' => 'app/Services/Calculator.php',
            ]],
        ]);

        $report = (new SuiteRedTestHandleHarvester())->harvest($path);
        $this->assertSame('disabled', $report['status']);
        $this->assertSame(0, DB::table(AtlasLoopFailureHandleSource::TABLE)->count());
    }
}
