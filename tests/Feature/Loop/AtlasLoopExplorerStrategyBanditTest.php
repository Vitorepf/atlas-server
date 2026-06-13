<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopExplorerStrategyBanditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
        AtlasLoopExploration::query()->delete();
        AtlasLoopTask::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    public function test_strategy_bandit_changes_distribution_when_certification_per_token_lifts(): void
    {
        $this->seedServiceStrategyHistory();
        $receipt = storage_path('framework/testing/strategy-bandit/receipt.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:loop:strategy-bandit', [
            '--write-receipt' => true,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('active_distribution_changed_with_token_lift', $payload['status']);
        $serviceRecommendation = collect($payload['recommendations'])->firstWhere('target_type', 'service');
        $this->assertIsArray($serviceRecommendation);
        $this->assertSame('root_cause', $serviceRecommendation['top_strategy_key']);
        $this->assertTrue((bool) $serviceRecommendation['distribution_changed']);
        $this->assertGreaterThan(0.01, (float) $serviceRecommendation['token_efficiency_delta_per_1k']);
        $this->assertTrue((bool) $payload['completion_claim_allowed']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
    }

    public function test_grinder_applies_proven_bandit_order_unless_task_overrides_strategy(): void
    {
        $this->seedServiceStrategyHistory();

        $driver = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $intents = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->intents[] = $intent;
                file_put_contents(
                    $workspace.'/app/Services/Foo/SmokeSubject.php',
                    "<?php\nfunction atlas_l63_smoke(): string { return 'hello'; }\n",
                );

                return ['status' => 'completed', 'tokens_used' => 100, 'cost_estimate_usd' => 0.01];
            }
        };
        $this->app->bind(LoopExecutionDriver::class, fn () => $driver);

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l6-3-strategy-bandit-grind',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => 'app/Services/Foo/SmokeSubject.php',
            'objective' => 'Fix the smoke function.',
            'payload' => [
                'target_relative_path' => 'app/Services/Foo/SmokeSubject.php',
                'target_content' => "<?php\nfunction atlas_l63_smoke(): string { return 'helo'; }\n",
                'frozen_tests' => [[
                    'path' => 'tests/L63SmokeTest.php',
                    'content' => "<?php\nrequire __DIR__.'/../app/Services/Foo/SmokeSubject.php';\nif (atlas_l63_smoke() !== 'hello') { fwrite(STDERR, 'bad'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => [
                    'commands' => ['php tests/L63SmokeTest.php'],
                    'allowed_globs' => ['app/Services/Foo/**'],
                    'frozen_globs' => ['tests/**'],
                    'metric_kind' => 'gate',
                ],
                'allowed_files' => ['app/Services/Foo/SmokeSubject.php'],
                'validation_commands' => ['php tests/L63SmokeTest.php'],
            ],
            'dedupe_key' => 'l6-3-grind',
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertStringContainsString('Re-read the failing acceptance carefully', $driver->intents[0] ?? '');
        $this->assertSame('applied', data_get($task->result, 'explorer_strategy_bandit.status'));
        $this->assertTrue((bool) data_get($task->result, 'explorer_strategy_bandit.applied'));
        $this->assertSame('root_cause', data_get($task->result, 'explorer_strategy_bandit.selected_strategy_keys.0'));
        $this->assertTrue((bool) data_get($task->result, 'explorer_strategy_bandit.distribution_changed'));
    }

    public function test_grinder_applies_full_proven_order_across_multiple_scenarios(): void
    {
        // The apply-path must hand the explorer the EXACT top-N UCB-proven order
        // when N>1 scenarios run — not just scenario[0]. This freezes the contract
        // that "the grinder applies the proven bandit order" for the live multi-
        // scenario path that actually feeds the 24/7 soak.
        $this->seedRankedServiceStrategyHistory();

        $driver = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $intents = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->intents[] = $intent;
                // Only the LAST scenario produces the passing fix, so the explorer
                // is forced to run every scenario (no early convergence) — proving
                // the full proven order is tried in order.
                if (count($this->intents) >= 3) {
                    file_put_contents(
                        $workspace.'/app/Services/Foo/SmokeSubject.php',
                        "<?php\nfunction atlas_l63_smoke(): string { return 'hello'; }\n",
                    );
                }

                return ['status' => 'completed', 'tokens_used' => 100, 'cost_estimate_usd' => 0.01];
            }
        };
        $this->app->bind(LoopExecutionDriver::class, fn () => $driver);

        $task = $this->makeGrindTask('l6-3-multi-grind', 'l6-3-multi');

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 3])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertCount(3, $driver->intents);
        // Proven order for the seeded `service` history is root_cause > simplify > surgical.
        $this->assertStringContainsString('Re-read the failing acceptance carefully', $driver->intents[0]);
        $this->assertStringContainsString('Favor deleting/simplifying over adding', $driver->intents[1]);
        $this->assertStringContainsString('Prefer the smallest, most surgical change', $driver->intents[2]);

        $this->assertTrue((bool) data_get($task->result, 'explorer_strategy_bandit.applied'));
        $this->assertSame(
            ['root_cause', 'simplify', 'surgical'],
            data_get($task->result, 'explorer_strategy_bandit.selected_strategy_keys'),
        );
        $this->assertSame(3, (int) data_get($task->result, 'explorer_strategy_bandit.applied_scenario_count'));
        $this->assertTrue((bool) data_get($task->result, 'explorer_strategy_bandit.distribution_changed'));
    }

    public function test_grind_attempt_metrics_carry_applied_strategy_key_so_live_evidence_auto_fills(): void
    {
        // The live-evidence auto-fill closure: a grind that applied the bandit order
        // must persist attempt_metrics whose strategy_key/tokens_used are EXACTLY
        // what the next measurement reads. This proves the loop closes on real runs
        // (no fabricated outcome — just the mechanism that lets evidence accumulate).
        $this->seedRankedServiceStrategyHistory();

        $driver = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents(
                    $workspace.'/app/Services/Foo/SmokeSubject.php',
                    "<?php\nfunction atlas_l63_smoke(): string { return 'hello'; }\n",
                );

                return ['status' => 'completed', 'tokens_used' => 250, 'cost_estimate_usd' => 0.02];
            }
        };
        $this->app->bind(LoopExecutionDriver::class, fn () => $driver);

        $task = $this->makeGrindTask('l6-3-evidence-grind', 'l6-3-evidence');

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $exploration = AtlasLoopExploration::query()
            ->where('task_id', $task->id)
            ->latest('id')
            ->firstOrFail();

        $metrics = is_array($exploration->attempt_metrics) ? $exploration->attempt_metrics : [];
        $this->assertNotEmpty($metrics);
        $first = $metrics[0];
        // The winning scenario carries the bandit's proven top key + real tokens.
        $this->assertSame('root_cause', $first['strategy_key']);
        $this->assertSame(250, $first['tokens_used']);
        $this->assertTrue((bool) $first['passed']);

        // And that persisted metric is exactly what the bandit reads next cycle:
        // re-measuring now sees a fresh `root_cause` attempt for type `service`.
        $service = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService::class);
        $measurement = $service->measure(['write_receipt' => false]);
        $rootCause = data_get($measurement, 'target_type_stats.service.root_cause');
        $this->assertIsArray($rootCause);
        $this->assertGreaterThanOrEqual(1, (int) $rootCause['token_samples']);
    }

    public function test_operator_scenario_strategies_override_beats_the_bandit(): void
    {
        // Operator override wins, petreo: a task that declares its own scenario
        // strategies must NOT be touched by the bandit, even when the bandit has a
        // proven distribution. This freezes the "operator_override_wins" invariant
        // on the live apply-path.
        $this->seedRankedServiceStrategyHistory();

        $driver = new class implements LoopExecutionDriver
        {
            /** @var list<string> */
            public array $intents = [];

            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                $this->intents[] = $intent;
                file_put_contents(
                    $workspace.'/app/Services/Foo/SmokeSubject.php',
                    "<?php\nfunction atlas_l63_smoke(): string { return 'hello'; }\n",
                );

                return ['status' => 'completed', 'tokens_used' => 100, 'cost_estimate_usd' => 0.01];
            }
        };
        $this->app->bind(LoopExecutionDriver::class, fn () => $driver);

        $task = $this->makeGrindTask('l6-3-override-grind', 'l6-3-override', [
            'scenario_strategies' => ['Operator-pinned approach hint: do the operator thing.'],
            'scenario_strategy_keys' => ['operator_pinned'],
        ]);

        $this->artisan('atlas:loop:grind-task', ['--task-id' => $task->id, '--scenarios' => 1])
            ->assertExitCode(0);

        $task->refresh();
        $this->assertStringContainsString('Operator-pinned approach hint', $driver->intents[0] ?? '');
        $this->assertStringNotContainsString('Re-read the failing acceptance carefully', $driver->intents[0] ?? '');
        $this->assertSame('operator_override', data_get($task->result, 'explorer_strategy_bandit.status'));
        $this->assertFalse((bool) data_get($task->result, 'explorer_strategy_bandit.applied'));
    }

    public function test_decision_does_not_collapse_to_single_strategy_when_scenario_count_unset(): void
    {
        // Apply-path regression guard: the grinder passes `scenario_count => null`
        // whenever no explicit --scenarios is given. That MUST fall through to the
        // configured default (3), not collapse the explored portfolio to one
        // strategy (which would silently kill diversification on the soak).
        $this->seedRankedServiceStrategyHistory();
        config()->set('atlas.loop.scenarios_per_task', 3);

        $service = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService::class);
        $decision = $service->decideForTask('app/Services/Foo/SmokeSubject.php', ['scenario_count' => null]);

        $this->assertTrue((bool) $decision['applied']);
        $this->assertSame(3, (int) $decision['scenario_count']);
        $this->assertSame(3, (int) $decision['applied_scenario_count']);
        $this->assertSame(['root_cause', 'simplify', 'surgical'], $decision['selected_strategy_keys']);
    }

    public function test_schedule_lists_strategy_bandit_measurement(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:strategy-bandit --write-receipt --json', $output);
    }

    /**
     * @param  array<string,mixed>  $extraPayload
     */
    private function makeGrindTask(string $goal, string $dedupe, array $extraPayload = []): AtlasLoopTask
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => $goal,
            'config' => [],
            'max_seconds' => 60,
        ]);

        return AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => 'app/Services/Foo/SmokeSubject.php',
            'objective' => 'Fix the smoke function.',
            'payload' => array_merge([
                'target_relative_path' => 'app/Services/Foo/SmokeSubject.php',
                'target_content' => "<?php\nfunction atlas_l63_smoke(): string { return 'helo'; }\n",
                'frozen_tests' => [[
                    'path' => 'tests/L63SmokeTest.php',
                    'content' => "<?php\nrequire __DIR__.'/../app/Services/Foo/SmokeSubject.php';\nif (atlas_l63_smoke() !== 'hello') { fwrite(STDERR, 'bad'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => [
                    'commands' => ['php tests/L63SmokeTest.php'],
                    'allowed_globs' => ['app/Services/Foo/**'],
                    'frozen_globs' => ['tests/**'],
                    'metric_kind' => 'gate',
                ],
                'allowed_files' => ['app/Services/Foo/SmokeSubject.php'],
                'validation_commands' => ['php tests/L63SmokeTest.php'],
            ], $extraPayload),
            'dedupe_key' => $dedupe,
        ]);
    }

    /**
     * Seed a `service` history with a clean total UCB order:
     * root_cause > simplify > surgical > clean_alternative > baseline.
     * Equal attempt counts per strategy => identical UCB exploration bonus =>
     * certification rate is the sole, deterministic tiebreaker.
     */
    private function seedRankedServiceStrategyHistory(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l6-3-ranked-history',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_DONE,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => 'app/Services/Foo/SmokeSubject.php',
            'objective' => 'history',
            'payload' => [],
            'dedupe_key' => (string) Str::uuid(),
            'result' => [],
        ]);

        // 4 attempts each; certified count sets the rate. Tokens present on every
        // attempt so token_samples >= 1 for baseline and the top key (completion floor).
        $rank = [
            'root_cause' => 4,        // 1.00
            'simplify' => 3,          // 0.75
            'surgical' => 2,          // 0.50
            'clean_alternative' => 1, // 0.25
            'baseline' => 0,          // 0.00
        ];
        $portfolio = (new \App\Services\Ai\AutonomousEvolution\AtlasLoopExplorerStrategyBanditService)->portfolio();
        $metrics = [];
        $scn = 0;
        foreach ($rank as $key => $certified) {
            for ($i = 0; $i < 4; $i++) {
                $scn++;
                $metrics[] = [
                    'scenario' => 'scn-'.$scn,
                    'strategy_key' => $key,
                    'strategy' => (string) ($portfolio[$key] ?? ''),
                    'passed' => $i < $certified,
                    'metric' => $i < $certified ? 1.0 : 0.0,
                    'metric_finite' => true,
                    'tokens_used' => 300,
                    'cost_estimate_usd' => 0.01,
                    'diff_files' => 1,
                    'diff_lines' => 1,
                ];
            }
        }

        AtlasLoopExploration::create([
            'campaign_id' => $campaign->id,
            'task_id' => $task->id,
            'schema_version' => 'atlas.loop.exploration.v1',
            'objective' => 'history',
            'provider' => 'fixture',
            'scenarios_explored' => $scn,
            'scenarios_accepted' => 10,
            'has_winner' => true,
            'rejected_reasons' => [],
            'attempt_metrics' => $metrics,
        ]);
    }

    private function seedServiceStrategyHistory(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'l6-3-history',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $task = AtlasLoopTask::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_DONE,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => 'app/Services/Foo/SmokeSubject.php',
            'objective' => 'history',
            'payload' => [],
            'dedupe_key' => (string) Str::uuid(),
            'result' => [],
        ]);

        AtlasLoopExploration::create([
            'campaign_id' => $campaign->id,
            'task_id' => $task->id,
            'schema_version' => 'atlas.loop.exploration.v1',
            'objective' => 'history',
            'provider' => 'fixture',
            'scenarios_explored' => 6,
            'scenarios_accepted' => 4,
            'has_winner' => true,
            'rejected_reasons' => [],
            'attempt_metrics' => [
                ['scenario' => 'scn-1', 'strategy_key' => 'baseline', 'strategy' => '', 'passed' => false, 'metric' => 0.0, 'metric_finite' => true, 'tokens_used' => 1000, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
                ['scenario' => 'scn-2', 'strategy_key' => 'baseline', 'strategy' => '', 'passed' => true, 'metric' => 1.0, 'metric_finite' => true, 'tokens_used' => 1000, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
                ['scenario' => 'scn-3', 'strategy_key' => 'baseline', 'strategy' => '', 'passed' => false, 'metric' => 0.0, 'metric_finite' => true, 'tokens_used' => 1000, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
                ['scenario' => 'scn-4', 'strategy_key' => 'root_cause', 'strategy' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.', 'passed' => true, 'metric' => 1.0, 'metric_finite' => true, 'tokens_used' => 300, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
                ['scenario' => 'scn-5', 'strategy_key' => 'root_cause', 'strategy' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.', 'passed' => true, 'metric' => 1.0, 'metric_finite' => true, 'tokens_used' => 300, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
                ['scenario' => 'scn-6', 'strategy_key' => 'root_cause', 'strategy' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.', 'passed' => true, 'metric' => 1.0, 'metric_finite' => true, 'tokens_used' => 300, 'cost_estimate_usd' => 0.01, 'diff_files' => 1, 'diff_lines' => 1],
            ],
        ]);
    }
}
