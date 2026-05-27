<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

final class Reliable24hLoopRunnerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap790_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): Reliable24hLoopRunnerService
    {
        $service = app(Reliable24hLoopRunnerService::class);
        $service->setStorageRootForTesting($this->tmp);
        $service->setSleeperForTesting(static fn (int $s): null => null);

        return $service;
    }

    /**
     * Test double (NOT runtime authority): a Fake AP-786 session runner that returns
     * scripted cycles. Confined to this test; never wired at runtime.
     *
     * @param  callable(int):array<string,mixed>  $cycleFor  given the 1-based call number, returns a cycle array
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function fakeSessionRunner(callable $cycleFor): callable
    {
        $calls = 0;

        return function (array $input) use (&$calls, $cycleFor): array {
            $calls++;

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [$cycleFor($calls)],
            ];
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function progressCycle(int $n): array
    {
        return [
            'cycle_id' => 'c'.$n,
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => ['finding_id' => 'find_'.$n],
            'merge_performed' => false,
            'blockers' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedCycle(int $n): array
    {
        return [
            'cycle_id' => 'c'.$n,
            'final_status' => 'blocked',
            'selected_finding' => ['finding_id' => 'find_'.$n],
            'merge_performed' => false,
            'blockers' => ['full_atlas_forge_flow_required'],
            'inbox_item_id' => 'inbox_'.$n,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mergedCycle(int $n): array
    {
        return [
            'cycle_id' => 'c'.$n,
            'final_status' => 'cycle_completed',
            'selected_finding' => ['finding_id' => 'find_'.$n],
            'merge_performed' => true,
            'blockers' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'execute' => true,
            'max_runtime_minutes' => 1440,
        ], $overrides);
    }

    public function test_lock_blocks_a_second_instance(): void
    {
        $service = $this->service();
        $lockPath = $service->lockPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($lockPath));
        File::put($lockPath, json_encode([
            'run_id' => 'other_instance',
            'acquired_at_epoch' => microtime(true),
            'lease_ttl_seconds' => 3600,
        ]));

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));
        $report = $service->run($this->input(['max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_LOCK_HELD, $report['status']);
        $this->assertSame(0, $report['cycles_this_run']);
        // The other instance's lock must remain untouched.
        $this->assertFileExists($lockPath);
        $this->assertSame('other_instance', json_decode((string) file_get_contents($lockPath), true)['run_id']);
    }

    public function test_kill_switch_stops_cleanly(): void
    {
        $service = $this->service();
        File::ensureDirectoryExists($service->storageDir());
        File::put($service->killSwitchPath('agentic_engineering_os', 'dev_forge'), 'stop');
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));

        $report = $service->run($this->input(['max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_KILLED, $report['status']);
        $this->assertSame(0, $report['cycles_this_run']);
        // No lock should have been acquired.
        $this->assertFileDoesNotExist($service->lockPath('agentic_engineering_os', 'dev_forge'));
    }

    public function test_pause_stops_cleanly(): void
    {
        $service = $this->service();
        File::ensureDirectoryExists($service->storageDir());
        File::put($service->pausePath('agentic_engineering_os', 'dev_forge'), 'pause');
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));

        $report = $service->run($this->input(['max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_PAUSED, $report['status']);
        $this->assertSame(0, $report['cycles_this_run']);
    }

    public function test_blocked_cycle_does_not_break_loop_when_continue_on_blocked(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input([
            'continue_on_blocked' => true,
            'max_cycles' => 3,
            'max_blocked_in_row' => 10,
        ]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertStringContainsString('max_cycles', $report['stop_reason']);
        $this->assertSame(3, $report['cycles_this_run']);
    }

    public function test_blocked_cycle_stops_loop_without_continue_on_blocked(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input(['continue_on_blocked' => false, 'max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BLOCKED_STOP, $report['status']);
        $this->assertSame(1, $report['cycles_this_run']);
    }

    public function test_repeated_finding_does_not_repeat(): void
    {
        $service = $this->service();
        // Same finding id every cycle.
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle(1)));

        $report = $service->run($this->input(['continue_on_blocked' => true, 'max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_REPEATED, $report['status']);
        $this->assertStringContainsString('repeated_finding:find_1', $report['stop_reason']);
        $this->assertSame(2, $report['cycles_this_run']);
    }

    public function test_crash_resume_reads_ledger_seen_findings(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, json_encode([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'prior_run',
            'cycle_index' => 2,
            'finding_key' => 'find_old',
            'outcome' => 'merged',
            'cumulative' => ['cycles_this_run' => 2, 'merges_total' => 1, 'blocked_in_row' => 0],
        ]).PHP_EOL);

        // First cycle returns a finding already seen in the ledger -> repeated stop.
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => [
            'cycle_id' => 'c_resume',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => ['finding_id' => 'find_old'],
            'merge_performed' => false,
            'blockers' => [],
        ]));
        $report = $service->run($this->input(['max_cycles' => 5]));

        $this->assertSame(2, $report['resumed_from_cycle_index']);
        $this->assertSame(Reliable24hLoopRunnerService::STATUS_REPEATED, $report['status']);
        $this->assertStringContainsString('find_old', $report['stop_reason']);
        // Cumulative merges from the ledger were carried forward.
        $this->assertSame(1, $report['merges_total']);
        $this->assertSame(3, $report['cycles_total']);
    }

    public function test_max_merges_budget_stops_loop(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->mergedCycle($n)));

        $report = $service->run($this->input(['max_merges' => 2, 'max_cycles' => 50]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertStringContainsString('max_merges', $report['stop_reason']);
        $this->assertSame(2, $report['merges_total']);
    }

    public function test_max_blocked_in_row_budget_stops_loop(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input(['continue_on_blocked' => true, 'max_blocked_in_row' => 2, 'max_cycles' => 50]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertStringContainsString('max_blocked_in_row', $report['stop_reason']);
    }

    public function test_max_runtime_minutes_budget_stops_loop(): void
    {
        $service = $this->service();
        $t = 1000.0;
        $service->setClockForTesting(function () use (&$t): float {
            $now = $t;
            $t += 50.0; // each clock read advances 50s

            return $now;
        });
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));

        $report = $service->run($this->input(['max_runtime_minutes' => 1])); // 60s budget

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertStringContainsString('max_runtime_minutes', $report['stop_reason']);
        $this->assertGreaterThanOrEqual(1, $report['cycles_this_run']);
    }

    public function test_appends_to_ledger_and_releases_lock(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));

        $report = $service->run($this->input(['max_cycles' => 2]));

        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        $this->assertFileExists($ledger);
        $this->assertCount(2, file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        // Lock released after the run.
        $this->assertFileDoesNotExist($service->lockPath('agentic_engineering_os', 'dev_forge'));
        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
    }

    public function test_runtime_default_uses_real_ap786_session_no_test_double(): void
    {
        $service = app(Reliable24hLoopRunnerService::class);
        $ref = new ReflectionClass($service);

        $session = $ref->getProperty('session');
        $session->setAccessible(true);
        $this->assertInstanceOf(AutonomousEvolutionSessionService::class, $session->getValue($service));

        // No test double is wired by default — runtime drives the real AP-786 session.
        foreach (['sessionRunner', 'clock', 'sleeper'] as $seam) {
            $prop = $ref->getProperty($seam);
            $prop->setAccessible(true);
            $this->assertNull($prop->getValue($service), $seam.' must be null (no runtime test double)');
        }

        // claim_policy asserts the real-authority rule.
        $service->setStorageRootForTesting($this->tmp);
        $report = $service->run(['kill_switch' => true]); // short-circuits without invoking a session
        $this->assertTrue($report['claim_policy']['no_test_doubles_at_runtime']);
        $this->assertTrue($report['claim_policy']['synthetic_or_valid_shape_input_cannot_produce_progress']);
        $this->assertTrue($report['claim_policy']['wraps_ap786_does_not_reimplement_selection_or_execution']);
    }
}
