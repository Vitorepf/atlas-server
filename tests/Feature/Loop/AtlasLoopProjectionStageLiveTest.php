<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObjectiveProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOriginationBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopStateOfAtlasReader;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * S2 — PROJECTION STAGE LIVE. Proves the projection stage is wired end-to-end, not theater:
 *   (1) FLAG-ON, the rédea's biggest leap is DISPATCHED into the projection stage (a pipeline_state row),
 *       NOT minted straight into a task — ZERO tasks after refill (the load-bearing zero-task assertion).
 *   (2) the worker PARKS a non-converged projection and mints NO task.
 *   (3) the worker emits exactly ONE task for a converged projection, with typed obligations attached to
 *       BOTH payload['_obligations'] and acceptance['obligations'], and the pipeline_state row is gone.
 *   (4) a dispatched-but-not-drained projection makes the supervisor's starvation clause FALSE
 *       (countOpenProjections === 1 ⇒ open work ⇒ never queue_starved).
 */
final class AtlasLoopProjectionStageLiveTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // The per-process :memory: sqlite DB does not run the full migration set (a pgsql-only
        // CREATE EXTENSION migration breaks sqlite), so build ONLY the loop runtime + pipeline schema
        // by hand — the same pattern AtlasLoopCampaignSupervisorTest uses.
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
        $this->repoRoot = $this->buildFixtureRepo();

        // Arm the projection stage + the objective producer (both flag-gated default paths).
        config()->set('atlas.loop.projection_stage_enabled', true);
        config()->set('atlas.loop.objective_producer_enabled', true);
        // The fixture is a small repo (a few callers) so its absolute leverage is modest; relax the
        // ambition floors so the rédea originates the (genuinely refactor-eligible) HeavyDemo leap. This
        // only affects WHICH leap is originated — the projection wiring under test is floor-independent.
        config()->set('atlas.loop.producer_leverage_floor', 0.05);
        config()->set('atlas.loop.producer_min_unblock', 0.15);
        // Producer-exclusive: when the rédea leads the cycle (here, by dispatching a projection), it is the
        // SOLE work source — the per-target lanes are skipped, so the ONLY path to a task is through a
        // CONVERGED projection. This isolates the projection wiring (otherwise a per-target refactor lane
        // would mint its own task and mask the severance under test).
        config()->set('atlas.loop.producer_exclusive', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repoRoot)) {
            exec('rm -rf '.escapeshellarg($this->repoRoot));
        }
        parent::tearDown();
    }

    public function test_flag_on_dispatches_a_projection_row_and_mints_zero_tasks(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        // The rédea produced its biggest leap and DISPATCHED it into the projection stage…
        $this->assertSame(
            1,
            DB::table('atlas_loop_pipeline_state')->where('campaign_id', $campaign->id)->where('stage', 'projection')->count(),
            'flag-ON ⇒ exactly one dispatched projection row',
        );
        // …and minted ZERO tasks (LOAD-BEARING: a direct enqueue would create a task immediately).
        $this->assertSame(
            0,
            DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(),
            'flag-ON ⇒ the projection stage is severed: no task until a projection converges',
        );
    }

    public function test_a_non_converged_projection_parks_and_mints_no_task(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        $pipeline = new AtlasLoopDeliveryPipeline;
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        // GENUINE, production-reachable park (NOT a stub): the worker is FAIL-CLOSED — if the projection
        // cannot be honestly run (here the binding-axis re-resolution layer is down and THROWS), the worker
        // PARKS the objective and mints NO task, rather than minting an unprojected one. This is exactly the
        // safety the severance buys: a projection that did not converge never becomes work.
        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->throwingAxis());
        $outcome = $worker->process($row);

        $this->assertSame('parked', $outcome['outcome']);
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'a parked projection never becomes work');
        $this->assertSame('parked', (string) DB::table('atlas_loop_pipeline_state')->where('objective_id', $row['objective_id'])->value('stage'));
    }

    public function test_the_real_engine_genuinely_parks_a_non_converging_projection(): void
    {
        // The engine's OWN non-converged park (NOT a fail-closed catch): a DEGENERATE objective whose
        // envelope carries no resolvable target ⇒ the worker's typed obligations are ungrounded ⇒ the set
        // never grows, the critic never engages ⇒ the FROZEN engine PARKS ⇒ NO task is minted. This proves
        // the park branch is reachable through the engine itself, so the gate is not a rubber-stamp.
        $campaign = $this->store()->openCampaign('S2 degenerate', $this->repoRoot, ['max_seconds' => 3600], [], '');
        $pipeline = new AtlasLoopDeliveryPipeline;
        $pipeline->dispatchProjection($campaign->id, 'obj-degenerate', 1.0, [
            'built' => ['objective' => 'x', 'payload' => [], 'acceptance_hash' => 'h', 'target_path' => '', 'leverage' => 1.0],
            'repoRoot' => $this->repoRoot,
            'priority' => 4100,
            'real_target_id' => 'tid-deg',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        // REAL engine + REAL axis service — nothing stubbed; only the envelope is degenerate.
        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, new AtlasLoopSystemAxisService);
        $outcome = $worker->process($row);

        $this->assertSame('parked', $outcome['outcome']);
        $this->assertSame('parked', (string) $outcome['status']);
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'the real engine parked ⇒ no task');
    }

    public function test_a_converged_projection_emits_exactly_one_task_with_typed_obligations(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        $pipeline = new AtlasLoopDeliveryPipeline;
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        // The REAL frozen engine + a fixed binding axis: the worker's deterministic designer/critic converge.
        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'));
        $outcome = $worker->process($row);

        $this->assertSame('enqueued', $outcome['outcome']);
        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'a converged projection mints exactly one task');

        $payload = json_decode((string) $tasks->first()->payload, true);
        $this->assertNotEmpty($payload['_obligations'] ?? [], 'the engine typed obligations ride on the task payload');
        $this->assertNotEmpty($payload['acceptance']['obligations'] ?? [], 'and on the acceptance contract');
        $this->assertArrayNotHasKey('is_self_improvement', $payload, 'a generic non-harness projection refactor must not be laundered as self-improvement');

        // The completed projection left the pipeline (no longer open work).
        $this->assertSame(0, DB::table('atlas_loop_pipeline_state')->where('objective_id', $row['objective_id'])->count());
    }

    public function test_a_converged_projection_reopens_retryable_terminal_duplicate_instead_of_starving(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        $pipeline = new AtlasLoopDeliveryPipeline;
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'));
        $this->assertSame('enqueued', $worker->process($row)['outcome']);

        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->firstOrFail();
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_DONE,
            'attempts' => 1,
            'max_attempts' => 2,
            'result' => ['has_winner' => false, 'reason' => 'quality_bar:below_min:7.33'],
        ])->save();

        $pipeline->dispatchProjection($campaign->id, (string) $row['objective_id'], 1.0, (array) $row['checkpoint']);
        $retryRow = $pipeline->claimNextProjection($campaign->id, 'worker-B', 300);
        $this->assertIsArray($retryRow);
        $this->assertSame('enqueued', $worker->process($retryRow)['outcome']);

        $tasks = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'dedupe keeps one task row');
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, (string) $tasks->first()->status, 'terminal no-winner was reopened as live supply');
        $this->assertNull($tasks->first()->result, 'stale terminal result is cleared before retry');
    }

    public function test_a_converged_projection_parks_exhausted_terminal_duplicate_instead_of_reporting_phantom_enqueue(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        $pipeline = new AtlasLoopDeliveryPipeline;
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'));
        $this->assertSame('enqueued', $worker->process($row)['outcome']);

        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->firstOrFail();
        $task->forceFill([
            'status' => AtlasLoopTask::STATUS_FAILED,
            'attempts' => 2,
            'max_attempts' => 2,
            'result' => ['status' => 'failed', 'reason' => 'parallel_worker_timeout'],
        ])->save();

        $pipeline->dispatchProjection($campaign->id, (string) $row['objective_id'], 1.0, (array) $row['checkpoint']);
        $retryRow = $pipeline->claimNextProjection($campaign->id, 'worker-B', 300);
        $this->assertIsArray($retryRow);

        $outcome = $worker->process($retryRow);

        $this->assertSame('enqueue_failed', $outcome['outcome']);
        $this->assertSame('projection_enqueue_no_live_task', $outcome['reason']);
        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_FAILED, (string) $task->status);
        $this->assertSame(2, (int) $task->attempts);
        $this->assertSame(0, $pipeline->countOpenProjections($campaign->id), 'parked duplicate is terminal, not phantom open work');
        $this->assertSame('parked', (string) DB::table('atlas_loop_pipeline_state')->where('objective_id', $row['objective_id'])->value('stage'));
    }

    public function test_projected_loop_harness_complexity_refactor_counts_as_governed_self_improvement(): void
    {
        $campaign = $this->store()->openCampaign('S2 loop self-improvement projection', $this->repoRoot, ['max_seconds' => 3600], [], '');
        $pipeline = new AtlasLoopDeliveryPipeline;
        $target = 'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php';

        $pipeline->dispatchProjection($campaign->id, 'obj-loop-self-improve', 0.9, [
            'built' => [
                'objective' => 'Reduce DemoLoopHarness complexity with a frozen behavior harness.',
                'payload' => [
                    'objective_kind' => 'refactor_reduce_complexity',
                    'target_repo_path' => $target,
                    'target_relative_path' => $target,
                    'acceptance' => [
                        'commands' => ['./vendor/bin/phpunit tests/Unit/DemoLoopHarnessTest.php'],
                        'complexity_proof' => true,
                        'metric_kind' => 'minimize',
                    ],
                ],
                'acceptance_hash' => 'hash-loop-self-improve',
                'target_path' => $target,
                'self_contained' => true,
                'leverage' => 0.9,
            ],
            'repoRoot' => $this->repoRoot,
            'priority' => 4100,
            'real_target_id' => 'target-loop-self-improve',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'));
        $outcome = $worker->process($row);

        $this->assertSame('enqueued', $outcome['outcome']);
        $payload = json_decode((string) DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->value('payload'), true);
        $this->assertTrue((bool) ($payload['is_self_improvement'] ?? false));
        $this->assertTrue((bool) ($payload['acceptance']['quality_bar_gate'] ?? false));
        $this->assertGreaterThanOrEqual(9.0, (float) ($payload['acceptance']['quality_bar'] ?? 0));

        $scorecard = app(AtlasLoopRealWorkScorecardService::class)->scorecard($campaign->id);
        $this->assertSame(1, $scorecard['real_work_tasks']);
        $this->assertSame(1, $scorecard['self_improvement_tasks']);
        $this->assertSame(0, $scorecard['proxy_refactor_tasks']);
        $this->assertTrue($scorecard['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_a_dispatched_projection_is_open_work_for_the_starvation_clause(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4);

        // Dispatched-but-not-drained: countOpenProjections === 1 ⇒ the supervisor's
        // (… && openProjections === 0) starvation clause is FALSE ⇒ it does NOT stop.
        $open = (new AtlasLoopDeliveryPipeline)->countOpenProjections($campaign->id);
        $this->assertSame(1, $open, 'a dispatched projection is OPEN work the starvation guard must respect');
    }

    public function test_supervisor_openProjections_helper_is_the_starvation_clause_signal(): void
    {
        $campaign = $this->campaign();
        $this->refiller()->refill($campaign, 4); // dispatches exactly one projection (proven above)

        // The production supervisor is resolved by the container, where nullable constructor args default to
        // null. It must still resolve the pipeline internally; otherwise live projections are invisible to
        // the starvation clause and never drain.
        $supervisor = app(\App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor::class);
        $open = (new \ReflectionClass($supervisor))->getMethod('openProjections');
        $open->setAccessible(true);

        // WIRED + flag-ON ⇒ the helper reports the in-flight projection as OPEN work (clause is FALSE).
        $this->assertSame(1, $open->invoke($supervisor, $campaign->id), 'wired+ON ⇒ the in-flight projection counts ⇒ no starvation');

        // Flag OFF ⇒ the helper returns 0 (… && 0 === 0 ⇒ … && true): the three clauses are byte-identical
        // to the pre-S2 loop, so the projection stage can never wedge the old starvation behaviour.
        config()->set('atlas.loop.projection_stage_enabled', false);
        $this->assertSame(0, $open->invoke($supervisor, $campaign->id), 'flag-OFF ⇒ helper is 0 ⇒ starvation clauses byte-identical');
    }

    public function test_armed_grounded_phase_attaches_a_consumer_intact_obligation_for_a_real_caller(): void
    {
        // §3 ARCHITECT PHASE armed: the worker runs the GROUNDED critic, which raises a consumer_intact
        // obligation for the target's REAL caller (from the injected comprehension model's edges). The
        // minted task's acceptance contract therefore PROVABLY protects that caller — proof the architect
        // phase emits a grounded contract, not the scripted v1 floor.
        config()->set('atlas.loop.grounded_projection_enabled', true);
        $campaign = $this->store()->openCampaign('S2 grounded projection', $this->repoRoot, ['max_seconds' => 3600], [], '');
        $pipeline = new AtlasLoopDeliveryPipeline;
        $target = 'app/Services/Ai/AutonomousEvolution/GroundedDemo.php';
        $caller = 'app/Services/Ai/AutonomousEvolution/GroundedCaller.php';

        $pipeline->dispatchProjection($campaign->id, 'obj-grounded', 0.9, [
            'built' => [
                'objective' => 'Refactor GroundedDemo, preserving its caller.',
                'payload' => ['objective_kind' => 'refactor_reduce_complexity', 'target_repo_path' => $target, 'target_relative_path' => $target, 'acceptance' => ['commands' => ['./vendor/bin/phpunit']]],
                'acceptance_hash' => 'hash-grounded',
                'target_path' => $target,
                'self_contained' => true,
                'leverage' => 0.9,
            ],
            'repoRoot' => $this->repoRoot,
            'priority' => 4100,
            'real_target_id' => 'target-grounded',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        // Inject a fixture model (skips the heavy build) whose edges give the target ONE real caller.
        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'), $this->fixtureModel($target, [$caller]));
        $outcome = $worker->process($row);

        $this->assertSame('enqueued', $outcome['outcome'], (string) ($outcome['reason'] ?? ''));
        $payload = json_decode((string) DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->value('payload'), true);
        $obligations = (array) ($payload['acceptance']['obligations'] ?? []);
        $consumerTargets = array_values(array_map(
            static fn (array $o): string => (string) ($o['target_symbol'] ?? ''),
            array_filter($obligations, static fn (array $o): bool => ($o['kind'] ?? '') === 'consumer_intact'),
        ));
        $this->assertContains(strtolower($caller), $consumerTargets, 'the grounded contract protects the real caller');
    }

    public function test_armed_grounded_phase_parks_a_petreo_cert_organ_target_and_mints_no_task(): void
    {
        // §3 constitution guard: the architect phase refuses to project a change to a forbidden cert organ —
        // it PARKS explicitly and mints no task, so the loop can never design its way into weakening its judge.
        config()->set('atlas.loop.grounded_projection_enabled', true);
        $campaign = $this->store()->openCampaign('S2 petreo park', $this->repoRoot, ['max_seconds' => 3600], [], '');
        $pipeline = new AtlasLoopDeliveryPipeline;
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';

        $pipeline->dispatchProjection($campaign->id, 'obj-petreo', 0.9, [
            'built' => ['objective' => 'x', 'payload' => ['objective_kind' => 'refactor_reduce_complexity', 'target_repo_path' => $target, 'acceptance' => ['commands' => ['./vendor/bin/phpunit']]], 'acceptance_hash' => 'hash-petreo', 'target_path' => $target, 'self_contained' => true, 'leverage' => 0.9],
            'repoRoot' => $this->repoRoot,
            'priority' => 4100,
            'real_target_id' => 'target-petreo',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        // A fixture model that marks the target forbidden (the real model gets it from the harness guard).
        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'), $this->forbiddenModel($target));
        $outcome = $worker->process($row);

        $this->assertSame('parked', $outcome['outcome']);
        $this->assertSame('forbidden_target_petreo', $outcome['reason']);
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'a pétreo cert-organ target never becomes work');
    }

    public function test_grounded_phase_off_is_byte_identical_to_the_scripted_floor(): void
    {
        // Flag OFF (default) ⇒ the worker uses the scripted roles even with a model injected: the minted
        // contract carries the scripted obligations, NOT a grounded consumer_intact for the caller.
        config()->set('atlas.loop.grounded_projection_enabled', false);
        $campaign = $this->store()->openCampaign('S2 grounded off', $this->repoRoot, ['max_seconds' => 3600], [], '');
        $pipeline = new AtlasLoopDeliveryPipeline;
        $target = 'app/Services/Ai/AutonomousEvolution/GroundedDemo.php';
        $caller = 'app/Services/Ai/AutonomousEvolution/GroundedCaller.php';

        $pipeline->dispatchProjection($campaign->id, 'obj-grounded-off', 0.9, [
            'built' => ['objective' => 'x', 'payload' => ['objective_kind' => 'refactor_reduce_complexity', 'target_repo_path' => $target, 'acceptance' => ['commands' => ['./vendor/bin/phpunit']]], 'acceptance_hash' => 'hash-off', 'target_path' => $target, 'self_contained' => true, 'leverage' => 0.9],
            'repoRoot' => $this->repoRoot,
            'priority' => 4100,
            'real_target_id' => 'target-grounded-off',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        $worker = new AtlasLoopProjectionWorker($this->store(), $pipeline, new AtlasLoopProjectionEngine, $this->fixedAxis('wired'), $this->fixtureModel($target, [$caller]));
        $this->assertSame('enqueued', $worker->process($row)['outcome']);

        $payload = json_decode((string) DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->value('payload'), true);
        $consumerTargets = array_filter((array) ($payload['acceptance']['obligations'] ?? []), static fn (array $o): bool => ($o['kind'] ?? '') === 'consumer_intact' && str_contains((string) ($o['target_symbol'] ?? ''), 'groundedcaller'));
        $this->assertSame([], $consumerTargets, 'flag-OFF ⇒ no grounded caller obligation (scripted floor, byte-identical)');
    }

    // --- fixtures / wiring ---

    /** A fixture comprehension model that marks $target pétreo/forbidden (a cert organ). */
    private function forbiddenModel(string $target): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [['rel_path' => $target, 'fqcn' => 'App\\Frozen', 'public_methods' => ['run'], 'is_orphan' => false, 'is_forbidden' => true, 'clone_cluster_id' => null]],
            edges: [$target => ['app/Services/Ai/AutonomousEvolution/OneCaller.php']],
            orphans: [], cloneClusters: [], forbidden: [$target], docPurposes: [], docStatedGaps: [],
            snapshotId: hash('sha256', $target),
        );
    }

    /** A fixture comprehension model whose edges give $target the given real callers. */
    private function fixtureModel(string $target, array $callers): AtlasLoopScopeComprehensionModel
    {
        $paths = array_values(array_unique(array_merge([$target], $callers)));
        $inventory = array_map(static fn (string $p): array => [
            'rel_path' => $p, 'fqcn' => 'App\\'.str_replace('/', '\\', substr($p, 0, -4)),
            'public_methods' => ['run'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null,
        ], $paths);

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [$target => $callers],
            orphans: [], cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [],
            snapshotId: hash('sha256', $target),
        );
    }

    private function campaign(): AtlasLoopCampaign
    {
        $campaign = $this->store()->openCampaign('S2 projection-live', $this->repoRoot, ['max_seconds' => 3600], [], '');
        // Seed the HeavyDemo candidate so the refiller's claimTop returns it WITHOUT depending on
        // discovery's root-config scan — the producer then originates its refactor leap on a real file.
        app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id,
            'app/Demo/HeavyDemo.php',
            hash('sha256', (string) file_get_contents($this->repoRoot.'/app/Demo/HeavyDemo.php')),
            ['score' => 0.9, 'self_contained' => 1.0, 'improvement' => 0.9, 'novelty' => 0.9, 'signals' => ['cyclomatic' => 12]],
        );

        return $campaign;
    }

    private function store(): AtlasLoopStore
    {
        return app(AtlasLoopStore::class);
    }

    /**
     * The REAL refiller, wired to the REAL objective producer (so produce() actually originates a refactor
     * leap on the fixture) and the REAL delivery pipeline (so the dispatch lands in pipeline_state).
     */
    private function refiller(): AtlasLoopQueueRefiller
    {
        $producer = new AtlasLoopObjectiveProducer(
            analyzer: new AtlasLoopSignalAnalyzer,
            stateReader: new AtlasLoopStateOfAtlasReader,
            critic: null,
            origination: new AtlasLoopOriginationBuilder, // real refactor synthesizer underneath
        );

        return new AtlasLoopQueueRefiller(
            discovery: app(AtlasLoopTargetDiscoveryService::class),
            repository: app(AtlasLoopTargetRepository::class),
            generator: app(\App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator::class),
            loopBack: app(AtlasLoopBackService::class),
            store: $this->store(),
            objectiveProducer: $producer,
            pipeline: new AtlasLoopDeliveryPipeline,
        );
    }

    /** An axis service that always names a fixed binding axis (deterministic convergence in the worker). */
    private function fixedAxis(string $axis): AtlasLoopSystemAxisService
    {
        return new AtlasLoopSystemAxisService(function (string $repoRoot, ?int $window) use ($axis): array {
            $axes = ['wired' => 0.95, 'real_target' => 0.95, 'non_trivial' => 0.95, 'compounding' => 0.95, 'safety' => 0.95];
            $axes[$axis] = 0.1; // this axis binds

            return ['graded_merges' => 9, 'axes' => $axes];
        });
    }

    /** An axis service whose grade re-resolution THROWS — drives the worker's fail-closed park branch. */
    private function throwingAxis(): AtlasLoopSystemAxisService
    {
        return new AtlasLoopSystemAxisService(function (string $repoRoot, ?int $window): array {
            throw new \RuntimeException('axis layer down');
        });
    }

    /**
     * A self-contained, genuinely-complex (cyclomatic ≥ 10) file with a PLAIN-`php` sibling test (no
     * TestCase/assert/$this) under tests/Unit — exactly what the objective producer + refactor synthesizer
     * require to originate a real refactor leap.
     */
    private function buildFixtureRepo(): string
    {
        $root = sys_get_temp_dir().'/atlas-s2-proj-'.bin2hex(random_bytes(5));
        @mkdir($root.'/app/Demo', 0o755, true);
        @mkdir($root.'/app/Use', 0o755, true);
        @mkdir($root.'/tests/Unit', 0o755, true);
        file_put_contents($root.'/composer.json', "{}\n");

        // A handful of real callers so the target is a WIRED hub (breadth > 0 ⇒ leverage clears the floor).
        for ($i = 0; $i < 6; $i++) {
            file_put_contents($root."/app/Use/Caller{$i}.php", "<?php\nclass Caller{$i} { public function go() { return (new HeavyDemo())->classify({$i}); } }\n");
        }

        // A deliberately branch-heavy method (cyclomatic well above the floor of 10).
        $heavy = <<<'PHP'
<?php

class HeavyDemo
{
    public function classify(int $n): string
    {
        if ($n < 0) { return 'neg'; }
        if ($n === 0) { return 'zero'; }
        if ($n === 1) { return 'one'; }
        if ($n === 2) { return 'two'; }
        if ($n < 10) { return 'small'; }
        if ($n < 20) { return 'teen'; }
        if ($n < 50) { return 'mid'; }
        if ($n < 100) { return 'big'; }
        if ($n % 2 === 0) { return 'even-huge'; }
        if ($n % 3 === 0) { return 'tri-huge'; }
        if ($n % 5 === 0) { return 'penta-huge'; }
        return 'huge';
    }
}
PHP;
        file_put_contents($root.'/app/Demo/HeavyDemo.php', $heavy);

        // Plain-php behavior anchor: requires the production file, asserts via plain exit codes (no PHPUnit,
        // no $this->, no extends TestCase) so the refactor synthesizer admits it as a frozen harness.
        $test = <<<'PHP'
<?php

require __DIR__ . '/../app/Demo/HeavyDemo.php';

$d = new HeavyDemo();
if ($d->classify(-1) !== 'neg') { exit(1); }
if ($d->classify(0) !== 'zero') { exit(1); }
if ($d->classify(5) !== 'small') { exit(1); }
if ($d->classify(1000) !== 'huge') { exit(1); }
echo "ok\n";
PHP;
        file_put_contents($root.'/tests/Unit/HeavyDemoTest.php', $test);

        return $root;
    }
}
