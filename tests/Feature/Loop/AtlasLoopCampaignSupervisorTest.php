<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDbResilience;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerHandle;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerPool;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerSpawnerContract;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Proves the 24h supervisor's MECHANISM cycles + recovers — without a 24h wait. The
 * provider is faked (deterministic) and the clock/sleeper/storage are injected, so the
 * full loop (claim -> grind -> stream-persist -> loop-back, under budget + lock + crash
 * recovery) is exercised end-to-end, fast and repeatably.
 */
final class AtlasLoopCampaignSupervisorTest extends TestCase
{
    private string $storageRoot;

    private string $emptyRepo;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'atlas.loop.parallel.enabled' => false,
            'atlas.loop.scenario_fanout.enabled' => false,
            'atlas.loop.universal_certification' => false,
            'atlas.loop.conductor_escalation_enabled' => false,
            'atlas.loop.cross_provider_best_of_n' => false,
            'atlas.loop.deep_strategy_portfolio' => false,
            'atlas.loop.refactor_multi_file_via_obra' => false,
            'atlas.loop.pattern_driver_enabled' => false,
        ]);
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        $this->storageRoot = sys_get_temp_dir().'/atlas-loop-test-storage-'.bin2hex(random_bytes(4));
        // An empty repo root so the refiller's discovery is a fast no-op (no provider call):
        // this test drives the loop over directly-seeded tasks.
        $this->emptyRepo = sys_get_temp_dir().'/atlas-loop-empty-repo-'.bin2hex(random_bytes(4));
        @mkdir($this->emptyRepo, 0o755, true);

        // Deterministic provider: turn the seeded 'broken' into 'fixed' in every src file,
        // so each distinct seeded class yields a distinct winning diff (distinct proposal).
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                foreach (glob($workspace.'/src/*.php') ?: [] as $file) {
                    file_put_contents($file, str_replace("'broken'", "'fixed'", (string) file_get_contents($file)));
                }

                return ['status' => 'completed'];
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->emptyRepo) && Schema::hasTable('atlas_loop_campaigns')) {
            $campaignIds = AtlasLoopCampaign::query()
                ->where('base_workspace', $this->emptyRepo)
                ->pluck('id')
                ->all();

            if ($campaignIds !== []) {
                AtlasLoopExploration::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopProposal::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopTask::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopCampaign::query()->whereIn('id', $campaignIds)->delete();
            }
        }

        foreach ([$this->storageRoot, $this->emptyRepo] as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
        parent::tearDown();
    }

    /** A monotonic injected clock: each call advances time, so budget accrual is deterministic. */
    private function supervisor(): AtlasLoopCampaignSupervisor
    {
        $s = $this->app->make(AtlasLoopCampaignSupervisor::class);
        $s->setStorageRootForTesting($this->storageRoot);
        $t = 1000;
        $s->setClockForTesting(function () use (&$t): int {
            $now = $t;
            $t += 50;

            return $now;
        });
        $s->setSleeperForTesting(fn (int $secs): null => null);

        return $s;
    }

    private function seedCampaign(int $maxSeconds = 0, int $maxUsdCents = 0, int $spendUsdCents = 0): AtlasLoopCampaign
    {
        $campaign = $this->app->make(AtlasLoopStore::class)->openCampaign('prove supervisor', $this->emptyRepo, [
            'max_seconds' => $maxSeconds,
            'max_usd_cents' => $maxUsdCents,
        ], ['scenarios_per_task' => 1]);
        if ($spendUsdCents > 0) {
            $campaign->forceFill(['spend_usd_cents' => $spendUsdCents])->save();
        }

        return $campaign;
    }

    private function seedTask(string $campaignId, string $class): AtlasLoopTask
    {
        $rel = 'src/'.$class.'.php';

        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => $rel,
            'objective' => "Make {$class}::v() return fixed. Edit {$rel} directly.",
            'payload' => [
                'target_relative_path' => $rel,
                'target_content' => "<?php\nnamespace S;\nfinal class {$class}{ public function v(): string { return 'broken'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/'.$class.'_test.php',
                    'content' => "<?php\nrequire __DIR__.'/../{$rel}';\n\$s=new \\S\\{$class}();\nif (\$s->v() !== 'fixed') { fwrite(STDERR,'red'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => ['commands' => ['php tests/'.$class.'_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**', 'composer.json'], 'metric_kind' => 'gate'],
                'allowed_files' => [$rel],
                'validation_commands' => ['php tests/'.$class.'_test.php'],
            ],
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.$class,
        ]);
    }

    public function test_full_cycle_grinds_distinct_tasks_persists_proposals_and_never_merges(): void
    {
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('queue_starved_no_refill', $result['stop_reason']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertGreaterThanOrEqual(2, $result['cycles']);

        $proposals = AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(2, $proposals); // two distinct targets -> two distinct certified proposals
        foreach ($proposals as $p) {
            $this->assertFalse((bool) $p->merged_to_main);
            $this->assertSame(AtlasLoopProposal::STATUS_CERTIFIED, $p->status);
            $this->assertNotEmpty($p->diff_text);
        }

        $campaign->refresh();
        $this->assertSame(2, $campaign->tasks_processed);
        $this->assertSame(2, $campaign->proposals_count);
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());
    }

    public function test_starvation_idle_mode_keeps_long_soak_alive_until_budget(): void
    {
        config([
            'atlas.loop.campaign.idle_on_starvation' => true,
            'atlas.loop.campaign.starvation_idle_seconds' => 5,
            'atlas.loop.territory_ladder_enabled' => false,
            'atlas.loop.taxa2_dials.enabled' => false,
        ]);
        $campaign = $this->seedCampaign(maxSeconds: 120);

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('time_budget_reached', $result['stop_reason']);
        $ledger = $this->supervisor()->readLedger($campaign->id, 100);
        $this->assertNotEmpty(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'starvation_idle'));

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertSame('time_budget_reached', $campaign->stop_reason);
    }

    public function test_taxa2_overlay_records_effective_dials_on_supervisor_boot(): void
    {
        Storage::fake('local');
        config([
            'atlas.loop.scenarios_per_task' => 3,
            'atlas.loop.max_scenarios_per_task' => 12,
            'atlas.loop.campaign.queue_low_watermark' => 4,
            'atlas.loop.campaign.refill_batch' => 6,
            'atlas.loop.taxa2_dials.enabled' => true,
            'atlas.loop.taxa2_dials.receipt_on_supervisor_boot' => true,
            'atlas.loop.taxa2_dials.max_delta_per_run' => 2,
            'atlas.loop.taxa2_dials.max_queue_low_watermark' => 12,
            'atlas.loop.taxa2_dials.max_refill_batch' => 24,
        ]);

        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertFalse($result['merged_to_main']);
        $ledger = $supervisor->readLedger($campaign->id, 20);
        $taxa2 = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'taxa2_dials'))[0] ?? null;

        $this->assertIsArray($taxa2);
        $this->assertSame('adjusted', $taxa2['status']);
        $this->assertTrue((bool) $taxa2['operator_scenarios_override']);
        $this->assertSame(1, data_get($taxa2, 'effective_dials.scenarios_per_task'));
        $this->assertGreaterThanOrEqual(5, data_get($taxa2, 'effective_dials.queue_low_watermark'));
        $this->assertLessThanOrEqual(6, data_get($taxa2, 'effective_dials.queue_low_watermark'));
        $this->assertGreaterThanOrEqual(7, data_get($taxa2, 'effective_dials.refill_batch'));
        $this->assertLessThanOrEqual(8, data_get($taxa2, 'effective_dials.refill_batch'));
        $this->assertContains('pending_queue_below_base_watermark', $taxa2['reasons']);
        Storage::disk('local')->assertExists((string) $taxa2['receipt_path']);
    }

    public function test_stops_on_wall_clock_budget_with_work_remaining(): void
    {
        $campaign = $this->seedCampaign(maxSeconds: 10); // tiny budget; the monotonic clock crosses it fast
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');
        $this->seedTask($campaign->id, 'Charlie');

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('time_budget_reached', $result['stop_reason']);
        $this->assertFalse($result['merged_to_main']);
        // Budget stopped it before the queue drained — real fixed-budget discipline.
        $this->assertGreaterThan(0, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());
    }

    public function test_cost_governor_throttles_scenarios_and_stops_on_cost_cap(): void
    {
        config([
            'atlas.loop.cost_governor.enabled' => true,
            'atlas.loop.cost_governor.throttle_at_pct' => 80.0,
            'atlas.loop.cost_governor.min_scenarios_per_task' => 1,
            'atlas.loop.campaign.queue_low_watermark' => 1,
        ]);
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                foreach (glob($workspace.'/src/*.php') ?: [] as $file) {
                    file_put_contents($file, str_replace("'broken'", "'fixed'", (string) file_get_contents($file)));
                }

                return ['status' => 'completed', 'cost_estimate_usd' => 0.06, 'tokens_used' => 600];
            }
        });

        $campaign = $this->seedCampaign(maxUsdCents: 10, spendUsdCents: 8);
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 3]);

        $this->assertSame('cost_cap', $result['stop_reason']);
        $this->assertSame(1, $result['cycles']);
        $this->assertFalse($result['merged_to_main']);

        $campaign->refresh();
        $this->assertSame(14, $campaign->spend_usd_cents);
        $this->assertSame(1, $campaign->scenarios_explored);
        $this->assertSame(1, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());

        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $throttle = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'cost_governor_throttle'))[0] ?? null;
        $this->assertIsArray($throttle);
        $this->assertSame('reduce_scenarios_per_task', $throttle['action']);
        $this->assertSame(3, $throttle['base_scenarios_per_task']);
        $this->assertSame(1, $throttle['effective_scenarios_per_task']);

        $cycle = array_values(array_filter($ledger, static fn (array $e): bool => ($e['cycle'] ?? null) === 1))[0] ?? null;
        $this->assertIsArray($cycle);
        $this->assertSame(6, $cycle['spend_usd_cents']);
        $this->assertSame('throttled', data_get($cycle, 'cost_governor.status'));
    }

    public function test_parallel_fleet_claims_four_distinct_tasks_when_enabled(): void
    {
        config([
            'atlas.loop.parallel.enabled' => true,
            'atlas.loop.parallel.max_workers' => 4,
            'atlas.loop.campaign.workers' => 4,
            'atlas.loop.campaign.queue_low_watermark' => 1,
            'atlas.loop.taxa2_dials.enabled' => false,
            'atlas.loop.cost_governor.enabled' => false,
        ]);
        $this->app->bind(LoopWorkerSpawnerContract::class, fn ($app) => new class($app->make(AtlasLoopStore::class)) implements LoopWorkerSpawnerContract
        {
            public function __construct(private readonly AtlasLoopStore $store) {}

            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $task = AtlasLoopTask::query()->find($taskId);
                if ($task instanceof AtlasLoopTask) {
                    $this->store->completeTask($taskId, $workerId, [
                        'has_winner' => false,
                        'scenarios_explored' => $scenarios,
                        'proposals' => 0,
                    ], true);
                    AtlasLoopCampaign::query()->whereKey($campaignId)->increment('tasks_processed');
                    AtlasLoopCampaign::query()->whereKey($campaignId)->increment('scenarios_explored', $scenarios);
                }

                $payload = json_encode([
                    'task_id' => $taskId,
                    'worker' => $workerId,
                    'status' => 'no_winner',
                    'has_winner' => false,
                    'proposals' => 0,
                    'scenarios_explored' => $scenarios,
                    'cost_cents' => 0,
                ], JSON_UNESCAPED_SLASHES);
                $process = new Process([PHP_BINARY, '-r', 'echo '.var_export(is_string($payload) ? $payload : '{}', true).';']);
                $process->start();

                return new LoopWorkerHandle($process, $taskId, $workerId);
            }
        });

        $campaign = $this->seedCampaign();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $class) {
            $this->seedTask($campaign->id, $class);
        }

        $supervisor = $this->supervisor();
        $result = $supervisor->run([
            'campaign_id' => $campaign->id,
            'workers' => 4,
            'scenarios' => 1,
            'sleep_seconds' => 0,
        ]);

        $this->assertSame('queue_starved_no_refill', $result['stop_reason']);
        $this->assertGreaterThanOrEqual(1, $result['cycles']);
        $this->assertSame(4, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_DONE)->count());
        $this->assertSame(4, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->distinct('claimed_by')->count('claimed_by'));

        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $boot = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'parallel_fleet_boot'))[0] ?? null;
        $this->assertIsArray($boot);
        $this->assertSame('parallel_pool', $boot['mode']);
        $this->assertSame(4, $boot['effective_workers']);

        $ticks = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'parallel_pool_tick'));
        $this->assertNotEmpty($ticks);
        $this->assertSame(4, max(array_map(static fn (array $e): int => (int) ($e['spawned'] ?? 0), $ticks)));
        $this->assertSame(4, max(array_map(static fn (array $e): int => (int) ($e['in_flight'] ?? 0), $ticks)));
    }

    public function test_parallel_refill_decision_keeps_free_slots_fed_while_a_worker_is_in_flight(): void
    {
        $pool = new LoopWorkerPool(new class implements LoopWorkerSpawnerContract
        {
            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $process = new Process([PHP_BINARY, '-r', 'usleep(500000);']);
                $process->start();

                return new LoopWorkerHandle($process, $taskId, $workerId);
            }
        }, new AtlasLoopResourceGate);
        $queue = [(new AtlasLoopTask)->forceFill(['id' => 'in-flight-one', 'claimed_by' => 'worker-one'])];
        $claimNext = function () use (&$queue): ?AtlasLoopTask {
            return array_shift($queue);
        };

        $pool->tick(4, 'campaign-one', $claimNext, 600, 60);
        $this->assertSame(1, $pool->inFlight());

        $supervisor = $this->supervisor();
        $method = new \ReflectionMethod($supervisor, 'shouldRefillQueue');
        $method->setAccessible(true);
        $watermarkMethod = new \ReflectionMethod($supervisor, 'refillWatermark');
        $watermarkMethod->setAccessible(true);

        $this->assertSame(4, $watermarkMethod->invoke($supervisor, 1, 4, $pool));
        $this->assertSame(6, $watermarkMethod->invoke($supervisor, 6, 4, $pool));
        $this->assertSame(1, $watermarkMethod->invoke($supervisor, 1, 4, null));

        $this->assertTrue((bool) $method->invoke($supervisor, 0, 4, $pool));
        $this->assertTrue((bool) $method->invoke($supervisor, 2, 4, $pool));
        $this->assertFalse((bool) $method->invoke($supervisor, 3, 4, $pool));
        $this->assertFalse((bool) $method->invoke($supervisor, 0, 1, $pool));
        $this->assertTrue((bool) $method->invoke($supervisor, 0, 4, null));
        $this->assertFalse((bool) $method->invoke($supervisor, 4, 4, null));

        $pool->drain(0.1);
    }

    public function test_parallel_worker_timeout_settle_fails_task_without_waiting_for_lease_expiry(): void
    {
        $campaign = $this->seedCampaign();
        $target = AtlasLoopTarget::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'src/TimeoutCase.php',
            'target_key' => 'timeout-case',
            'content_hash' => 'timeout-hash',
            'status' => AtlasLoopTarget::STATUS_QUEUED,
            'score' => 1.0,
            'self_contained_score' => 1.0,
            'improvement_score' => 1.0,
            'novelty_score' => 1.0,
            'signals' => [],
            'lineage' => [],
            'attempts' => 1,
            'max_attempts' => 3,
        ]);
        $task = $this->seedTask($campaign->id, 'TimeoutCase');
        $task->forceFill(['payload' => array_merge($task->payload, ['_target_id' => $target->id])])->save();
        $worker = 'pool-timeout-worker';
        $claimed = $this->app->make(AtlasLoopStore::class)->claimNextTask($campaign->id, $worker, 3600);
        $this->assertSame($task->id, $claimed?->id);

        $supervisor = $this->supervisor();
        $method = new \ReflectionMethod($supervisor, 'settleTimedOutParallelWorkers');
        $method->setAccessible(true);
        $settled = [[
            'task_id' => $task->id,
            'worker_id' => $worker,
            'timed_out' => true,
            'exit_code' => null,
            'duration_ms' => 1234,
        ]];
        $method->invoke($supervisor, $settled);

        $reflect = new \ReflectionMethod($supervisor, 'reflectParallelSettledTargets');
        $reflect->setAccessible(true);
        $reflect->invoke($supervisor, $campaign, $settled);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_FAILED, $task->status);
        $this->assertSame('parallel_worker_timeout', data_get($task->result, 'reason'));
        $this->assertSame(1234, data_get($task->result, 'duration_ms'));
        $this->assertNull($task->lease_expires_at);

        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('requeued_metric_miss', $target->reason);
        $this->assertNull($target->claimed_by);
        $campaign->refresh();
        $this->assertSame(1, $campaign->loopbacks);
    }

    public function test_reconciles_terminal_parallel_task_targets_left_queued_by_previous_run(): void
    {
        $campaign = $this->seedCampaign();
        $target = AtlasLoopTarget::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'src/StuckQueued.php',
            'target_key' => 'stuck-queued',
            'content_hash' => 'stuck-hash',
            'status' => AtlasLoopTarget::STATUS_QUEUED,
            'score' => 1.0,
            'self_contained_score' => 1.0,
            'improvement_score' => 1.0,
            'novelty_score' => 1.0,
            'signals' => [],
            'lineage' => [],
            'attempts' => 1,
            'max_attempts' => 3,
        ]);
        $task = $this->seedTask($campaign->id, 'StuckQueued');
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_FAILED,
            'payload' => array_merge($task->payload, ['_target_id' => $target->id]),
            'result' => ['status' => 'failed', 'reason' => 'parallel_worker_timeout'],
        ])->save();

        $supervisor = $this->supervisor();
        $method = new \ReflectionMethod($supervisor, 'reconcileUnreflectedParallelTargets');
        $method->setAccessible(true);
        $method->invoke($supervisor, $campaign);

        $target->refresh();
        $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $target->status);
        $this->assertSame('requeued_metric_miss', $target->reason);
        $this->assertNull($target->claimed_by);
        $campaign->refresh();
        $this->assertSame(1, $campaign->loopbacks);

        $events = array_filter(
            $supervisor->readLedger($campaign->id, 20),
            static fn (array $event): bool => ($event['event'] ?? null) === 'parallel_loop_back_reconcile'
        );
        $this->assertNotEmpty($events);
        $event = array_values($events)[0];
        $this->assertSame(1, $event['scanned']);
        $this->assertSame(1, $event['reflected']);
    }

    public function test_parallel_inflight_work_burns_time_budget_and_drains_task_terminally(): void
    {
        config([
            'atlas.loop.parallel.enabled' => true,
            'atlas.loop.parallel.max_workers' => 2,
            'atlas.loop.campaign.workers' => 2,
            'atlas.loop.campaign.queue_low_watermark' => 1,
            'atlas.loop.territory_ladder_enabled' => false,
            'atlas.loop.taxa2_dials.enabled' => false,
            'atlas.loop.cost_governor.enabled' => false,
        ]);
        $this->app->bind(LoopWorkerSpawnerContract::class, fn () => new class implements LoopWorkerSpawnerContract
        {
            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $process = new Process([PHP_BINARY, '-r', 'sleep(30);']);
                $process->start();

                return new LoopWorkerHandle($process, $taskId, $workerId);
            }
        });

        $campaign = $this->seedCampaign(maxSeconds: 120);
        $task = $this->seedTask($campaign->id, 'BudgetDrain');

        $supervisor = $this->supervisor();
        $result = $supervisor->run([
            'campaign_id' => $campaign->id,
            'workers' => 2,
            'scenarios' => 1,
            'sleep_seconds' => 0,
        ]);

        $this->assertSame('time_budget_reached', $result['stop_reason']);
        $campaign->refresh();
        $this->assertGreaterThanOrEqual(120, $campaign->elapsed_seconds);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_FAILED, $task->status);
        $this->assertSame('parallel_worker_budget_stop', data_get($task->result, 'reason'));
        $this->assertNull($task->lease_expires_at);

        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $this->assertNotEmpty(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'parallel_pool_drain'));
    }

    public function test_parallel_idle_on_starvation_keeps_campaign_alive_after_pool_drains(): void
    {
        config([
            'atlas.loop.parallel.enabled' => true,
            'atlas.loop.parallel.max_workers' => 2,
            'atlas.loop.campaign.workers' => 2,
            'atlas.loop.campaign.queue_low_watermark' => 1,
            'atlas.loop.campaign.idle_on_starvation' => true,
            'atlas.loop.campaign.starvation_idle_seconds' => 5,
            'atlas.loop.territory_ladder_enabled' => false,
            'atlas.loop.taxa2_dials.enabled' => false,
            'atlas.loop.cost_governor.enabled' => false,
        ]);
        $this->app->bind(LoopWorkerSpawnerContract::class, fn ($app) => new class($app->make(AtlasLoopStore::class)) implements LoopWorkerSpawnerContract
        {
            public function __construct(private readonly AtlasLoopStore $store) {}

            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $this->store->completeTask($taskId, $workerId, [
                    'has_winner' => false,
                    'scenarios_explored' => $scenarios,
                    'proposals' => 0,
                ], true);
                AtlasLoopCampaign::query()->whereKey($campaignId)->increment('tasks_processed');
                AtlasLoopCampaign::query()->whereKey($campaignId)->increment('scenarios_explored', $scenarios);

                $payload = json_encode([
                    'task_id' => $taskId,
                    'worker' => $workerId,
                    'status' => 'no_winner',
                    'has_winner' => false,
                    'proposals' => 0,
                    'scenarios_explored' => $scenarios,
                    'cost_cents' => 0,
                ], JSON_UNESCAPED_SLASHES);
                $process = new Process([PHP_BINARY, '-r', 'echo '.var_export(is_string($payload) ? $payload : '{}', true).';']);
                $process->start();

                return new LoopWorkerHandle($process, $taskId, $workerId);
            }
        });

        $campaign = $this->seedCampaign(maxSeconds: 600);
        $this->seedTask($campaign->id, 'AfterDrain');

        $supervisor = $this->supervisor();
        $result = $supervisor->run([
            'campaign_id' => $campaign->id,
            'workers' => 2,
            'scenarios' => 1,
            'sleep_seconds' => 0,
        ]);

        $this->assertSame('time_budget_reached', $result['stop_reason']);
        $ledger = $supervisor->readLedger($campaign->id, 1000);
        $this->assertNotEmpty(array_filter(
            $ledger,
            static fn (array $e): bool => ($e['event'] ?? null) === 'starvation_idle'
        ));
    }

    public function test_crash_resume_reclaims_inflight_task_and_does_not_duplicate_proposal(): void
    {
        $campaign = $this->seedCampaign();
        // Simulate a crash: a task left 'claimed' by a dead worker with an EXPIRED lease.
        $task = $this->seedTask($campaign->id, 'Alpha');
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_CLAIMED,
            'claimed_by' => 'dead-worker',
            'claimed_at' => Carbon::now()->subMinutes(30),
            'lease_expires_at' => Carbon::now()->subMinutes(20), // expired
            'attempts' => 1,
        ])->save();

        $result = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        // The crashed task was reclaimed (rebuildInFlight), re-ground, and persisted ONCE.
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertCount(1, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get());
        $this->assertFalse($result['merged_to_main']);
    }

    public function test_code_drift_exits_restartably_and_keeps_campaign_running_for_keepalive(): void
    {
        config(['atlas.loop.campaign.restart_on_code_drift' => true]);
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        $headA = str_repeat('a', 40);
        $headB = str_repeat('b', 40);
        $calls = 0;
        $supervisor = $this->supervisor();
        $supervisor->setGitHeadResolverForTesting(function () use (&$calls, $headA, $headB): string {
            $calls++;

            return $calls < 3 ? $headA : $headB;
        });
        // L4-5 refinado: o drift só reinicia quando o merge tocou o MOTOR do loop. Aqui o
        // HEAD novo trouxe uma mudança no pipeline (o grinder), então deve reciclar.
        $supervisor->setChangedFilesResolverForTesting(static fn (): array => [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
        ]);

        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('code_drift_restart', $result['stop_reason']);
        $this->assertTrue($result['restartable']);
        $this->assertSame(1, $result['cycles']);
        $this->assertFalse($result['merged_to_main']);
        $this->assertFileDoesNotExist($this->storageRoot.'/'.$campaign->id.'/lock.json');

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_RUNNING, $campaign->status);
        $this->assertSame('code_drift_restart', $campaign->stop_reason);
        $this->assertNull($campaign->completed_at);
        $this->assertCount(1, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get());
        $this->assertSame(1, AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('status', AtlasLoopTask::STATUS_PENDING)->count());

        $ledger = $supervisor->readLedger($campaign->id, 20);
        $this->assertNotEmpty(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'boot_git_head' && ($e['head'] ?? null) === $headA));
        $this->assertNotEmpty(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'code_drift_restart' && ($e['boot_head'] ?? null) === $headA && ($e['current_head'] ?? null) === $headB));
    }

    public function test_restarting_completed_campaign_clears_terminal_fields_and_persists_current_contract(): void
    {
        config(['atlas.loop.campaign.restart_on_code_drift' => true]);
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');

        $first = $this->supervisor()->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);
        $this->assertSame('queue_starved_no_refill', $first['stop_reason']);

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);
        $this->assertNotNull($campaign->finished_at);
        $this->assertNotNull($campaign->completed_at);

        $this->seedTask($campaign->id, 'Bravo');

        $headA = str_repeat('a', 40);
        $headB = str_repeat('b', 40);
        $calls = 0;
        $supervisor = $this->supervisor();
        $supervisor->setGitHeadResolverForTesting(function () use (&$calls, $headA, $headB): string {
            $calls++;

            return $calls < 3 ? $headA : $headB;
        });
        $supervisor->setChangedFilesResolverForTesting(static fn (): array => [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php',
        ]);

        $result = $supervisor->run([
            'campaign_id' => $campaign->id,
            'goal' => 'resumed live contract',
            'base_workspace' => $this->emptyRepo,
            'max_seconds' => 7200,
            'max_usd_cents' => 123,
            'scenarios' => 1,
            'workers' => 2,
            'shadow' => false,
        ]);

        $this->assertSame('code_drift_restart', $result['stop_reason']);
        $this->assertTrue($result['restartable']);

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_RUNNING, $campaign->status);
        $this->assertSame('code_drift_restart', $campaign->stop_reason);
        $this->assertNull($campaign->finished_at);
        $this->assertNull($campaign->completed_at);
        $this->assertSame('resumed live contract', $campaign->goal);
        $this->assertSame($this->emptyRepo, $campaign->base_workspace);
        $this->assertSame(7200, (int) $campaign->max_seconds);
        $this->assertSame(123, (int) $campaign->max_usd_cents);
        $this->assertFalse((bool) data_get($campaign->config, 'shadow'));
        $this->assertSame(2, (int) data_get($campaign->config, 'workers'));
    }

    public function test_kill_switch_set_mid_tick_stops_before_claiming_next_task(): void
    {
        config([
            'atlas.loop.campaign.restart_on_code_drift' => true,
            'atlas.loop.campaign.queue_low_watermark' => 1,
            'atlas.loop.taxa2_dials.enabled' => false,
        ]);
        $campaign = $this->seedCampaign();
        $task = $this->seedTask($campaign->id, 'Alpha');

        $headA = str_repeat('a', 40);
        $headB = str_repeat('b', 40);
        $calls = 0;
        $supervisor = $this->supervisor();
        $supervisor->setGitHeadResolverForTesting(function () use (&$calls, $headA, $headB): string {
            $calls++;

            return $calls === 1 ? $headA : $headB;
        });
        $supervisor->setChangedFilesResolverForTesting(function () use ($campaign): array {
            AtlasLoopCampaign::query()
                ->whereKey($campaign->id)
                ->update(['kill_switch' => true]);

            return [];
        });

        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('kill_switch', $result['stop_reason']);
        $this->assertSame(0, $result['cycles']);
        $this->assertFalse($result['merged_to_main']);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status);
        $this->assertSame(0, $task->attempts);
        $this->assertNull($task->claimed_by);
        $this->assertSame(0, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->count());
        $this->assertFileDoesNotExist($this->storageRoot.'/'.$campaign->id.'/lock.json');

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_ABORTED, $campaign->status);
        $this->assertTrue((bool) $campaign->kill_switch);
    }

    /**
     * The historical 8h crash: reclaimExpiredTasks threw SQLSTATE[08006] (Connection refused)
     * mid-loop and the supervisor died. With the fault confined to the first two reclaim calls,
     * the inner retry+reconnect must absorb it IN PLACE — the run completes exactly like the
     * healthy baseline, all proposals persisted, never crashed, never parked.
     */
    public function test_survives_transient_db_blip_on_reclaim_via_inner_retry(): void
    {
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        $remaining = ['reclaim_cycle' => 2];
        $this->bindFaultInjectingGuard($this->countdownInjector($remaining));

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('queue_starved_no_refill', $result['stop_reason']); // completed like the healthy run
        $this->assertFalse($result['merged_to_main']);
        $this->assertSame(0, $remaining['reclaim_cycle']);                     // the injected blips were actually hit
        $this->assertCount(2, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get());

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);       // terminal, not stuck running
        $this->assertFileDoesNotExist($this->storageRoot.'/'.$campaign->id.'/lock.json'); // lock released

        // Absorbed within the retries — no cycle had to be parked.
        foreach ($supervisor->readLedger($campaign->id, 100) as $entry) {
            $this->assertNotSame('db_outage', $entry['event'] ?? null);
        }
    }

    /**
     * A longer blip that EXHAUSTS the inner retry budget on one cycle must not crash either:
     * the supervisor parks that cycle (log + skip + continue) and rides on once the DB heals.
     */
    public function test_survives_db_blip_that_outlasts_inner_retry_by_parking_the_cycle(): void
    {
        config(['atlas.loop.campaign.db_retry_attempts' => 3]);          // exhaust quickly
        config(['atlas.loop.campaign.db_outage_abort_seconds' => 100000]); // never hit the abort ceiling here
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        // 3 transient throws on reclaim == the full 3-attempt budget on cycle 1 -> forced park, then heals.
        $remaining = ['reclaim_cycle' => 3];
        $this->bindFaultInjectingGuard($this->countdownInjector($remaining));

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $this->assertSame('queue_starved_no_refill', $result['stop_reason']); // survived to a clean finish
        $this->assertSame(0, $remaining['reclaim_cycle']);
        $this->assertCount(2, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->get()); // both proposals safe

        // The park was recorded as a recoverable hiccup — the cycle was skipped, not crashed.
        $parked = array_filter($supervisor->readLedger($campaign->id, 100), static fn (array $e): bool => ($e['event'] ?? null) === 'db_outage');
        $this->assertNotEmpty($parked);

        $campaign->refresh();
        $this->assertSame(AtlasLoopCampaign::STATUS_COMPLETED, $campaign->status);
    }

    /**
     * The exact reported failure mode: a sustained outage. The supervisor must NOT throw out of
     * run(); it parks, hits the sustained-outage ceiling, aborts with `db_unavailable`, and —
     * the crux of the fix — RELEASES THE LOCK so a restart can resume (the old `finally` re-threw
     * while PG was still down, stranding the campaign in status=running behind a dead lock).
     */
    public function test_sustained_db_outage_aborts_cleanly_and_releases_the_lock(): void
    {
        config(['atlas.loop.campaign.db_retry_attempts' => 2]);
        config(['atlas.loop.campaign.db_outage_abort_seconds' => 30]); // the monotonic test clock crosses this on the first park
        $campaign = $this->seedCampaign();
        $this->seedTask($campaign->id, 'Alpha');
        $this->seedTask($campaign->id, 'Bravo');

        // Healthy until cycle 1's reclaim runs once (Alpha ground + persisted), then PG "goes away"
        // for good — every subsequent durable write fails.
        $armed = false;
        $this->bindFaultInjectingGuard(function (string $label) use (&$armed): ?Throwable {
            if ($armed) {
                return $this->transientDbError();
            }
            if ($label === 'reclaim_cycle') {
                $armed = true; // arm AFTER cycle 1's reclaim has succeeded
            }

            return null;
        });

        $supervisor = $this->supervisor();
        $result = $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]); // must return, not throw

        $this->assertSame('db_unavailable', $result['stop_reason']);
        $this->assertFalse($result['merged_to_main']);
        // Cycle 1's proposal survived the outage that began afterwards.
        $this->assertGreaterThanOrEqual(1, AtlasLoopProposal::query()->where('campaign_id', $campaign->id)->count());
        // THE FIX: the lock is released even though the DB never came back, so a restart can resume.
        $this->assertFileDoesNotExist($this->storageRoot.'/'.$campaign->id.'/lock.json');
        // The outage was logged, not silently swallowed.
        $parked = array_filter($supervisor->readLedger($campaign->id, 100), static fn (array $e): bool => ($e['event'] ?? null) === 'db_outage');
        $this->assertNotEmpty($parked);
    }

    // --- transient-DB fault-injection seams ---

    /**
     * Bind a resilience guard that simulates a transient outage via the injector, with the real
     * DB::reconnect() and usleep() replaced — CRUCIAL: a real reconnect would drop the sqlite
     * :memory: connection and wipe the seeded campaign mid-test.
     *
     * @param  Closure(string,int):?Throwable  $injector
     */
    private function bindFaultInjectingGuard(Closure $injector): void
    {
        $guard = (new AtlasLoopDbResilience)
            ->setReconnectorForTesting(fn (): null => null)
            ->setSleeperForTesting(fn (int $ms): null => null)
            ->setFaultInjectorForTesting($injector);
        $this->app->instance(AtlasLoopDbResilience::class, $guard);
    }

    /**
     * An injector that throws a transient DB error the first N times a given label is wrapped,
     * then heals. $remaining is mutated by reference so the test can assert the blips were hit.
     *
     * @param  array<string,int>  $remaining
     * @return Closure(string,int):?Throwable
     */
    private function countdownInjector(array &$remaining): Closure
    {
        return function (string $label) use (&$remaining): ?Throwable {
            if (($remaining[$label] ?? 0) > 0) {
                $remaining[$label]--;

                return $this->transientDbError();
            }

            return null;
        };
    }

    /** The exact Postgres failure the supervisor historically died on. */
    private function transientDbError(): QueryException
    {
        return new QueryException(
            'pgsql',
            'update atlas_loop_tasks set status = ?',
            ['pending'],
            new PDOException('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: Connection refused'),
        );
    }
}
