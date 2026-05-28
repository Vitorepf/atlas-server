<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
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
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($this->tmp.'/quarantine');

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

    public function test_live_loop_recovers_orphaned_same_host_lock_before_lease_expiry(): void
    {
        $service = $this->service();
        $lockPath = $service->lockPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($lockPath));
        File::put($lockPath, json_encode([
            'schema_version' => 'atlas.software_company_stewardship.ap790_loop_lock.v1',
            'run_id' => 'crashed_runner',
            'pid' => 99999999,
            'host' => gethostname() ?: 'unknown',
            'acquired_at_epoch' => microtime(true),
            'lease_ttl_seconds' => 7200,
        ], JSON_UNESCAPED_SLASHES));

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->mergedCycle($n)));

        $report = $service->run($this->input(['max_cycles' => 1]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame(1, $report['cycles_this_run']);
        $this->assertSame(1, $report['merges_total']);
        $this->assertFileDoesNotExist($lockPath);
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

    public function test_merged_cycle_invokes_safe_worktree_cleanup_when_enabled(): void
    {
        $this->mock(AreaFocusBranchSandboxMaterializer::class, function ($mock): void {
            $mock->shouldReceive('cleanupSandbox')->atLeast()->once()->withArgs(function (array $input): bool {
                return ($input['sandbox_id'] ?? '') === 'afsb_cleanup'
                    && ($input['area_id'] ?? '') === 'agentic_engineering_os'
                    && ($input['remove_sandbox'] ?? false) === true
                    && ($input['delete_branch'] ?? false) === true
                    && ($input['only_if_merged'] ?? false) === true
                    && ($input['only_if_clean'] ?? false) === true;
            })->andReturn([
                'status' => AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED,
                'sandbox_id' => 'afsb_cleanup',
                'cleaned' => true,
            ]);
        });

        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(function (int $n): array {
            return $this->mergedCycle($n) + ['sandbox_id' => 'afsb_cleanup'];
        }));

        $report = $service->run($this->input([
            'max_cycles' => 1,
            'cleanup_worktrees' => true,
        ]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame(1, $report['merges_total']);
    }

    public function test_loop_sweeps_only_merged_clean_legacy_sandboxes_before_running(): void
    {
        $materializer = new class implements AreaFocusBranchSandboxMaterializer
        {
            /** @var list<array<string,mixed>> */
            public array $cleanupCalls = [];

            public function materialize(array $input): array
            {
                return [];
            }

            public function listSandboxes(?string $areaId = null): array
            {
                return [
                    'sandboxes' => [
                        ['sandbox_id' => 'afsb_old_merged_clean', 'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED],
                        ['sandbox_id' => 'afsb_old_dirty', 'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED],
                        ['sandbox_id' => 'afsb_old_unmerged', 'status' => AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED],
                    ],
                ];
            }

            public function cleanupSandbox(array $input): array
            {
                $this->cleanupCalls[] = $input;
                $sandboxId = (string) ($input['sandbox_id'] ?? '');
                $execute = (bool) ($input['remove_sandbox'] ?? false);
                if ($execute) {
                    return [
                        'status' => AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED,
                        'sandbox_id' => $sandboxId,
                        'cleaned' => true,
                    ];
                }

                return [
                    'status' => AreaFocusBranchSandboxMaterializerService::STATUS_PLANNED,
                    'sandbox_id' => $sandboxId,
                    'blockers' => [],
                    'safety' => [
                        'branch_merged_into_head' => $sandboxId !== 'afsb_old_unmerged',
                        'worktree_dirty' => $sandboxId === 'afsb_old_dirty',
                    ],
                ];
            }
        };

        $service = new Reliable24hLoopRunnerService(
            app(AutonomousEvolutionSessionService::class),
            $materializer,
            app(AreaFocusCandidateQuarantineService::class),
        );
        $service->setStorageRootForTesting($this->tmp);
        $service->setSleeperForTesting(static fn (int $s): null => null);
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->progressCycle($n)));

        $report = $service->run($this->input([
            'max_cycles' => 1,
            'cleanup_worktrees' => true,
        ]));

        $executedCleanupIds = array_values(array_map(
            static fn (array $call): string => (string) ($call['sandbox_id'] ?? ''),
            array_filter($materializer->cleanupCalls, static fn (array $call): bool => (bool) ($call['remove_sandbox'] ?? false))
        ));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame(['afsb_old_merged_clean'], $executedCleanupIds);
    }

    public function test_blocked_cycle_stops_loop_without_continue_on_blocked(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input(['continue_on_blocked' => false, 'max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BLOCKED_STOP, $report['status']);
        $this->assertSame(1, $report['cycles_this_run']);
    }

    /** AP-790 regression: blocked cycles must surface blockers in report and append-only ledger. */
    public function test_blocked_cycle_records_blockers_in_report_and_ledger(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input(['continue_on_blocked' => false, 'max_cycles' => 5]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BLOCKED_STOP, $report['status']);
        $this->assertSame('blocked_cycle_without_continue_on_blocked', $report['stop_reason']);
        $this->assertSame(1, $report['cycles_this_run']);
        $this->assertSame(1, $report['blocked_in_row']);
        $this->assertCount(1, $report['cycles']);
        $this->assertSame('blocked', $report['cycles'][0]['outcome']);
        $this->assertSame('blocked', $report['cycles'][0]['cycle_final_status']);
        $this->assertSame('find_1', $report['cycles'][0]['finding_key']);
        $this->assertContains('full_atlas_forge_flow_required', $report['cycles'][0]['blockers']);

        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        $this->assertFileExists($ledger);
        $lines = file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);
        $record = json_decode((string) $lines[0], true);
        $this->assertSame(Reliable24hLoopRunnerService::LEDGER_SCHEMA, $record['schema_version']);
        $this->assertSame('blocked', $record['outcome']);
        $this->assertSame('blocked', $record['cycle_final_status']);
        $this->assertContains('full_atlas_forge_flow_required', $record['blockers']);
        $this->assertSame('inbox_1', $record['inbox_item_id']);
        $this->assertSame(1, $record['cumulative']['blocked_in_row']);
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

    public function test_repeated_finding_with_new_blocker_is_blocked_not_repeated(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, json_encode([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'prior_run',
            'cycle_index' => 10,
            'finding_key' => 'find_1',
            'outcome' => 'merged',
            'merge_performed' => true,
            'merge_hash' => 'abc123',
            'cycle_final_status' => 'cycle_completed',
            'blockers' => [],
            'cumulative' => ['merges_total' => 1, 'blocked_in_row' => 0],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle(1)));

        $report = $service->run($this->input([
            'continue_on_blocked' => true,
            'max_cycles' => 1,
            'max_blocked_in_row' => 10,
        ]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame(1, $report['cycles_this_run']);
        $this->assertSame('blocked', $report['cycles'][0]['outcome']);
        $this->assertSame('find_1', $report['cycles'][0]['finding_key']);
        $this->assertContains('full_atlas_forge_flow_required', $report['cycles'][0]['blockers']);
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
            'merge_performed' => true,
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

    public function test_crash_resume_does_not_consume_dry_run_findings(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, json_encode([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'prior_dry_run',
            'cycle_index' => 4,
            'finding_key' => 'find_dry',
            'finding_keys' => ['sha256:find_dry'],
            'outcome' => 'progress',
            'session_status' => Reliable24hLoopRunnerService::STATUS_DRY_RUN,
            'cycle_final_status' => 'dry_run_planned',
            'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 0],
        ]).PHP_EOL);

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => [
            'cycle_id' => 'c_execute_after_dry',
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'selected_finding' => ['finding_id' => 'find_dry'],
            'merge_performed' => false,
            'blockers' => [],
        ]));

        $report = $service->run($this->input(['max_cycles' => 1]));

        $this->assertSame(4, $report['resumed_from_cycle_index']);
        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame('progress', $report['cycles'][0]['outcome']);
        $this->assertSame('find_dry', $report['cycles'][0]['finding_key']);
    }

    public function test_crash_resume_forwards_only_merged_or_repeated_seen_finding_keys_to_ap786(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, implode(PHP_EOL, [
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_blocked',
                'cycle_index' => 1,
                'finding_key' => 'find_blocked',
                'finding_keys' => ['sha256:find_blocked', 'Blocked finding title'],
                'outcome' => 'blocked',
                'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 1],
            ]),
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_progress',
                'cycle_index' => 2,
                'finding_key' => 'find_progress',
                'finding_keys' => ['sha256:find_progress', 'Progress finding title'],
                'outcome' => 'progress',
                'cumulative' => ['cycles_this_run' => 2, 'merges_total' => 0, 'blocked_in_row' => 0],
            ]),
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_merged',
                'cycle_index' => 3,
                'finding_key' => 'find_merged',
                'finding_keys' => ['sha256:find_merged', 'Merged finding title'],
                'outcome' => 'merged',
                'merge_performed' => true,
                'cycle_final_status' => 'cycle_completed',
                'session_status' => 'completed',
                'cumulative' => ['cycles_this_run' => 3, 'merges_total' => 1, 'blocked_in_row' => 0],
            ]),
            '',
        ]));

        $captured = [];
        $service->setSessionRunnerForTesting(function (array $input) use (&$captured): array {
            $captured = (array) ($input['session_review_locked'] ?? []);

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [$this->progressCycle(2)],
            ];
        });

        $service->run($this->input(['max_cycles' => 1]));

        $this->assertArrayNotHasKey('find_blocked', $captured);
        $this->assertArrayNotHasKey('sha256:find_blocked', $captured);
        $this->assertArrayNotHasKey('Blocked finding title', $captured);
        $this->assertArrayNotHasKey('find_progress', $captured);
        $this->assertArrayNotHasKey('sha256:find_progress', $captured);
        $this->assertArrayNotHasKey('Progress finding title', $captured);
        $this->assertTrue($captured['find_merged'] ?? false);
        $this->assertTrue($captured['sha256:find_merged'] ?? false);
        $this->assertTrue($captured['Merged finding title'] ?? false);
    }

    public function test_forwards_forge_authority_inputs_to_wrapped_ap786_session(): void
    {
        $service = $this->service();
        $captured = [];
        $service->setSessionRunnerForTesting(function (array $input) use (&$captured): array {
            $captured = $input;

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [$this->progressCycle(1)],
            ];
        });

        $service->run($this->input([
            'max_cycles' => 1,
            'forge_obra' => '11111111-2222-3333-4444-555555555555',
            'forge_live_topology' => ['status' => 'live', 'driver' => 'cursor_cli'],
            'forge_live_decision' => ['decision' => 'dispatch', 'operator_actor' => 'operator'],
            'forge_dispatch_mode' => 'forge_runtime_dispatch',
            'forge_role' => 'primary_builder',
        ]));

        $this->assertSame('11111111-2222-3333-4444-555555555555', $captured['forge_obra'] ?? null);
        $this->assertSame('live', data_get($captured, 'forge_live_topology.status'));
        $this->assertSame('dispatch', data_get($captured, 'forge_live_decision.decision'));
        $this->assertSame('forge_runtime_dispatch', $captured['forge_dispatch_mode'] ?? null);
        $this->assertSame('primary_builder', $captured['forge_role'] ?? null);
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
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(function (int $n): array {
            $cycle = $this->blockedCycle($n);
            $cycle['selected_finding'] = ['finding_id' => 'find_blocked_repeat', 'finding_hash' => 'sha256:find_blocked_repeat'];

            return $cycle;
        }));

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

    public function test_quarantined_finding_is_not_selected_again(): void
    {
        $service = $this->service();
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($this->tmp.'/quarantine');
        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            ['finding_id' => 'find_quarantined', 'finding_hash' => 'sha256:find_quarantined', 'title' => 'Quarantined'],
            ['owner_runtime_routing_not_executable'],
            ['reason' => 'routing_not_executable'],
        );

        $calls = 0;
        $service->setSessionRunnerForTesting(function (array $input) use (&$calls, $quarantine): array {
            $calls++;
            if ($calls === 1) {
                $this->assertNotEmpty(array_intersect_key(
                    (array) ($input['session_review_locked'] ?? []),
                    array_flip(['find_quarantined', 'sha256:find_quarantined', 'Quarantined']),
                ));
            }

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [[
                    'cycle_id' => 'c_'.$calls,
                    'final_status' => 'blocked',
                    'selected_finding' => ['finding_id' => 'find_'.$calls],
                    'merge_performed' => false,
                    'blockers' => ['no_candidate_with_allowed_files'],
                    'selection_rejections' => $calls === 1
                        ? [['finding_id' => 'find_quarantined', 'reason' => 'candidate_quarantined']]
                        : [],
                ]],
            ];
        });

        $report = $service->run($this->input([
            'continue_on_blocked' => true,
            'max_cycles' => 2,
            'max_blocked_in_row' => 10,
        ]));

        $this->assertSame(2, $report['cycles_this_run']);
        $this->assertFileExists($quarantine->ledgerPath('agentic_engineering_os', 'dev_forge'));
        $this->assertGreaterThanOrEqual(2, $calls);
    }

    public function test_different_blocked_findings_continue_past_blocked_in_row_budget(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->blockedCycle($n)));

        $report = $service->run($this->input([
            'continue_on_blocked' => true,
            'max_cycles' => 4,
            'max_blocked_in_row' => 2,
        ]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertStringContainsString('max_cycles', $report['stop_reason']);
        $this->assertSame(4, $report['cycles_this_run']);
        $this->assertLessThanOrEqual(2, $report['blocked_in_row']);
    }

    public function test_cycle_summary_surfaces_repair_and_quarantine_metadata(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting(function (array $input): array {
            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'partial',
                'cycles' => [[
                    'cycle_id' => 'c_quarantine',
                    'final_status' => 'cycle_completed_waiting_review_or_merge',
                    'selected_finding' => ['finding_id' => 'find_q'],
                    'merge_performed' => false,
                    'blockers' => ['owner_runtime_no_patch_needed'],
                    'quarantined' => true,
                    'retried' => true,
                    'repaired' => false,
                    'quarantine' => ['reason' => 'no_patch_needed'],
                ]],
            ];
        });

        $report = $service->run($this->input(['max_cycles' => 1, 'continue_on_blocked' => true]));

        $summary = $report['cycles'][0];
        $this->assertTrue($summary['quarantined']);
        $this->assertTrue($summary['retried']);
        $this->assertFalse($summary['repaired']);
        $this->assertSame('no_patch_needed', $summary['quarantine_reason']);
    }

    public function test_merged_cycle_summary_surfaces_merge_hash_and_loop_receipt_integrity(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
            'cycle_id' => 'c'.$n,
            'final_status' => 'cycle_completed',
            'selected_finding' => ['finding_id' => 'find_'.$n],
            'merge_performed' => true,
            'blockers' => [],
            'loop_receipt' => [
                'merge_hash' => 'abc123merge',
                'integrity' => 'ok',
                'receipt_hash' => 'sha256:receipt',
            ],
        ]));

        $report = $service->run($this->input(['max_cycles' => 1]));
        $ledger = json_decode((string) file($service->ledgerPath('agentic_engineering_os', 'dev_forge'))[0], true);

        $this->assertSame('abc123merge', $report['cycles'][0]['merge_hash']);
        $this->assertSame('ok', $report['cycles'][0]['loop_receipt_integrity']);
        $this->assertSame('abc123merge', $ledger['merge_hash']);
        $this->assertSame('sha256:receipt', $ledger['loop_receipt_hash']);
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

    public function test_cycle_completed_without_merge_performed_does_not_count_as_merge(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
            'cycle_id' => 'c'.$n,
            'final_status' => 'cycle_completed',
            'selected_finding' => ['finding_id' => 'find_'.$n],
            'merge_performed' => false,
            'blockers' => [],
        ]));

        $report = $service->run($this->input(['max_cycles' => 2, 'continue_on_blocked' => true]));

        $this->assertSame(0, $report['merges_total']);
        $this->assertSame('progress', $report['cycles'][0]['outcome']);
        $this->assertFalse($report['cycles'][0]['merge_performed']);
    }

    public function test_crash_resume_counts_only_ledger_merged_with_merge_performed_true(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, json_encode([
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'run_id' => 'prior_run',
            'cycle_index' => 1,
            'finding_key' => 'find_inflated',
            'outcome' => 'merged',
            'merge_performed' => false,
            'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 5, 'blocked_in_row' => 0],
        ]).PHP_EOL);

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n) => $this->mergedCycle($n)));
        $report = $service->run($this->input(['max_cycles' => 1]));

        $this->assertSame(1, $report['merges_total']);
        $this->assertSame('merged', $report['cycles'][0]['outcome']);
        $this->assertTrue($report['cycles'][0]['merge_performed']);
    }

    public function test_resume_does_not_review_lock_previously_blocked_findings(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, implode(PHP_EOL, [
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_blocked',
                'cycle_index' => 1,
                'finding_key' => 'find_blocked',
                'finding_keys' => ['find_blocked_hash'],
                'outcome' => 'blocked',
                'cycle_final_status' => 'blocked',
                'session_status' => 'partial',
                'merge_performed' => false,
                'blockers' => ['owner_runtime_senior_loop_execution_not_passed'],
                'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 1],
            ]),
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_merged',
                'cycle_index' => 2,
                'finding_key' => 'find_merged',
                'outcome' => 'merged',
                'cycle_final_status' => 'cycle_completed',
                'session_status' => 'completed',
                'merge_performed' => true,
                'blockers' => [],
                'cumulative' => ['cycles_this_run' => 2, 'merges_total' => 1, 'blocked_in_row' => 0],
            ]),
            '',
        ]));

        $capturedReviewLocked = null;
        $service->setSessionRunnerForTesting(function (array $input) use (&$capturedReviewLocked): array {
            $capturedReviewLocked = $input['session_review_locked'] ?? null;

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => 'completed',
                'cycles' => [[
                    'cycle_id' => 'c_after_resume',
                    'final_status' => 'cycle_completed',
                    'selected_finding' => ['finding_id' => 'find_after_resume'],
                    'merge_performed' => true,
                    'blockers' => [],
                ]],
            ];
        });

        $report = $service->run($this->input(['max_cycles' => 1]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertIsArray($capturedReviewLocked);
        $this->assertArrayNotHasKey('find_blocked', $capturedReviewLocked);
        $this->assertArrayNotHasKey('find_blocked_hash', $capturedReviewLocked);
        $this->assertSame(true, $capturedReviewLocked['find_merged'] ?? null);
    }

    public function test_execute_mode_defaults_continue_on_blocked_for_24h_recovery(): void
    {
        $service = $this->service();
        $service->setSessionRunnerForTesting($this->fakeSessionRunner(function (int $n): array {
            return $n === 1 ? $this->blockedCycle(1) : $this->mergedCycle($n);
        }));

        $report = $service->run($this->input(['max_cycles' => 4]));

        $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
        $this->assertSame(4, $report['cycles_this_run']);
        $this->assertSame(3, $report['merges_total']);
        $this->assertSame(0, $report['blocked_in_row']);
        foreach (array_slice($report['cycles'], 1) as $cycle) {
            $this->assertSame('merged', $cycle['outcome']);
            $this->assertTrue($cycle['merge_performed']);
        }
    }

    /** AP-790: materialize continuous_24h_scheduler backlog with bounded blocked/merged/recovered observability. */
    public function test_continuous_24h_scheduler_backlog_observability_surfaces_bounded_blocked_merged_and_recovered_cycles(): void
    {
        $service = $this->service();
        $ledger = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($ledger));
        File::put($ledger, implode(PHP_EOL, [
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_crash',
                'cycle_index' => 1,
                'finding_key' => 'find_prior_blocked',
                'outcome' => 'blocked',
                'cycle_final_status' => 'blocked',
                'blockers' => ['full_atlas_forge_flow_required'],
                'cumulative' => ['cycles_this_run' => 1, 'merges_total' => 0, 'blocked_in_row' => 1],
            ]),
            json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'run_id' => 'prior_crash',
                'cycle_index' => 2,
                'finding_key' => 'find_prior_merged',
                'outcome' => 'merged',
                'merge_performed' => true,
                'cycle_final_status' => 'cycle_completed',
                'cumulative' => ['cycles_this_run' => 2, 'merges_total' => 1, 'blocked_in_row' => 0],
            ]),
            '',
        ]));

        $service->setSessionRunnerForTesting($this->fakeSessionRunner(function (int $n): array {
            return $n === 1 ? $this->blockedCycle(1) : $this->mergedCycle($n);
        }));

        $report = $service->run($this->input(['max_cycles' => 2]));

        $this->assertSame([
            'blocked' => 1,
            'merged' => 1,
            'progress' => 0,
            'repeated_finding' => 0,
        ], $report['cycle_outcomes_this_run']);

        $bridge = $report['scheduler_backlog'];
        $this->assertSame(Reliable24hLoopRunnerService::SCHEDULER_BACKLOG_BRIDGE_SCHEMA, $bridge['schema_version']);
        $this->assertSame(Reliable24hLoopRunnerService::AP790_BACKLOG_CONTINUOUS_24H_SCHEDULER, $bridge['ap790_backlog_item']);
        $this->assertSame(Reliable24hLoopRunnerService::DEFAULT_BOUNDED_CYCLE_WINDOW, $bridge['bounded_by']['recent_cycles_limit']);
        $this->assertTrue($bridge['recovery']['recovered']);
        $this->assertSame(4, $bridge['recovery']['last_cycle_index']);
        $this->assertSame(2, $bridge['recovery']['merges_total']);
        $this->assertGreaterThanOrEqual(2, $bridge['outcome_counts']['blocked']);
        $this->assertGreaterThanOrEqual(2, $bridge['outcome_counts']['merged']);
        $this->assertCount(4, $bridge['recent_cycles']);
        $this->assertSame('blocked', $bridge['recent_cycles'][0]['outcome']);
        $this->assertSame('merged', $bridge['recent_cycles'][3]['outcome']);
        $this->assertLessThanOrEqual(
            Reliable24hLoopRunnerService::DEFAULT_BOUNDED_CYCLE_WINDOW,
            count($bridge['recent_cycles']),
        );
        $this->assertTrue($report['claim_policy']['continuous_24h_scheduler_backlog_observable']);
        $this->assertTrue($report['claim_policy']['bounded_cycle_window']);
    }
}
