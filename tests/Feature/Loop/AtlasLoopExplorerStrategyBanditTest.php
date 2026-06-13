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

    public function test_schedule_lists_strategy_bandit_measurement(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('atlas:loop:strategy-bandit --write-receipt --json', $output);
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
