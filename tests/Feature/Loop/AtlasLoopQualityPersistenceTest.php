<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionLoopRunner;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AP-820 S3 — per-attempt metric persistence + graded proposal quality.
 *
 * Proves (a) the runner summarises each exploration with LEAN per-attempt records
 * (never stdout/stderr/diff_text — provider-shaped bulk stays out of the app-read
 * table), (b/c) the store round-trips attempt_metrics and the proposal quality
 * verdict through the new nullable columns, and (d) the absence of both keys keeps
 * working exactly as before (backwards compatibility with pre-migration callers).
 */
final class AtlasLoopQualityPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        if (! Schema::hasColumn('atlas_loop_explorations', 'attempt_metrics')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
    }

    public function test_summarise_exploration_emits_lean_attempt_metrics_without_stdout(): void
    {
        $summary = $this->summarise([
            'objective' => 'obj',
            'provider' => 'p',
            'scenarios_explored' => 3,
            'scenarios_accepted' => 1,
            'winner' => null,
            'attempts' => [
                [
                    'scenario_id' => 'scn-1',
                    'verdict' => [
                        'passed' => true,
                        'metric' => 0.75,
                        'metric_finite' => true,
                        'details' => [
                            'reason' => 'accepted',
                            'command_results' => [[
                                'command' => 'php artisan test',
                                'stdout' => 'SECRET-PROVIDER-STDOUT',
                                'stderr' => 'SECRET-PROVIDER-STDERR',
                            ]],
                        ],
                    ],
                    'diff_size' => ['files' => 2, 'lines' => 14],
                    'diff_text' => 'SECRET-DIFF-TEXT',
                    'strategy_key' => 'surgical',
                    'strategy' => 'Prefer the smallest, most surgical change that satisfies the objective.',
                    'tokens_used' => 300,
                    'cost_estimate_usd' => 0.01,
                ],
                [
                    'scenario_id' => 'scn-2',
                    'verdict' => ['passed' => false, 'metric' => 0.0, 'metric_finite' => false, 'details' => ['reason' => 'acceptance_command_failed']],
                    'diff_size' => ['files' => 0, 'lines' => 0],
                    'diff_text' => 'SECRET-DIFF-TEXT-2',
                    'strategy_key' => 'root_cause',
                    'strategy' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.',
                    'tokens_used' => 900,
                    'cost_estimate_usd' => 0.02,
                ],
                [
                    // degraded attempt: no scenario_id, no metric, no diff_size — every
                    // optional slot must degrade to null/index, never crash.
                    'verdict' => ['passed' => false],
                ],
            ],
        ]);

        $this->assertSame([
            ['scenario' => 'scn-1', 'strategy_key' => 'surgical', 'strategy' => 'Prefer the smallest, most surgical change that satisfies the objective.', 'passed' => true, 'metric' => 0.75, 'metric_finite' => true, 'tokens_used' => 300, 'cost_estimate_usd' => 0.01, 'diff_files' => 2, 'diff_lines' => 14],
            ['scenario' => 'scn-2', 'strategy_key' => 'root_cause', 'strategy' => 'Re-read the failing acceptance carefully; fix the true root cause, not the symptom.', 'passed' => false, 'metric' => 0.0, 'metric_finite' => false, 'tokens_used' => 900, 'cost_estimate_usd' => 0.02, 'diff_files' => 0, 'diff_lines' => 0],
            ['scenario' => 3, 'strategy_key' => null, 'strategy' => null, 'passed' => false, 'metric' => null, 'metric_finite' => true, 'tokens_used' => null, 'cost_estimate_usd' => null, 'diff_files' => null, 'diff_lines' => null],
        ], $summary['attempt_metrics']);

        // The summary as a whole must be lean: no stdout/stderr/diff_text ANYWHERE.
        $json = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stdout', $json);
        $this->assertStringNotContainsString('stderr', $json);
        $this->assertStringNotContainsString('diff_text', $json);
        $this->assertStringNotContainsString('SECRET-', $json);
    }

    public function test_attempt_metrics_are_capped_at_24_entries(): void
    {
        $attempts = [];
        for ($i = 0; $i < 30; $i++) {
            $attempts[] = [
                'scenario_id' => 'scn-'.($i + 1),
                'verdict' => ['passed' => false, 'metric' => 0.0],
                'diff_size' => ['files' => 0, 'lines' => 0],
            ];
        }

        $summary = $this->summarise(['objective' => 'o', 'attempts' => $attempts]);

        $this->assertCount(AtlasEvolutionLoopRunner::ATTEMPT_METRICS_CAP, $summary['attempt_metrics']);
        $this->assertSame('scn-24', $summary['attempt_metrics'][23]['scenario']);
    }

    public function test_record_exploration_round_trips_attempt_metrics(): void
    {
        [$store, $task] = $this->makeClaimableTask('round-trip exploration');

        $metrics = [
            ['scenario' => 'scn-1', 'passed' => true, 'metric' => 1.0, 'metric_finite' => true, 'diff_files' => 1, 'diff_lines' => 3],
            ['scenario' => 'scn-2', 'passed' => false, 'metric' => null, 'metric_finite' => false, 'diff_files' => null, 'diff_lines' => null],
        ];

        $exploration = $store->recordExploration($task, [
            'objective' => 'obj',
            'provider' => 'p',
            'scenarios_explored' => 2,
            'scenarios_accepted' => 1,
            'has_winner' => true,
            'rejected_reasons' => ['acceptance_command_failed'],
            'attempt_metrics' => $metrics,
        ]);

        $this->assertEquals($metrics, $exploration->fresh()->attempt_metrics);
    }

    public function test_certify_proposal_round_trips_quality(): void
    {
        [$store, $task] = $this->makeClaimableTask('round-trip quality');

        $quality = [
            'schema_version' => 'atlas.loop.proposal_quality.v1',
            'grade' => 'strong',
            'score' => 0.92,
            'checks' => ['scope' => true, 'simplicity' => true],
        ];

        $proposal = $store->certifyProposal($task, [
            'objective' => 'obj',
            'diff_text' => 'diff --git a/x b/x',
            'proposal_hash' => 'h-quality',
            'quality' => $quality,
        ]);

        $this->assertEquals($quality, $proposal->fresh()->quality);
    }

    public function test_absence_of_attempt_metrics_and_quality_keys_still_works(): void
    {
        [$store, $task] = $this->makeClaimableTask('backwards compat');

        // Pre-S3 callers send neither key — both rows must persist with null columns.
        $exploration = $store->recordExploration($task, [
            'objective' => 'obj',
            'scenarios_explored' => 1,
            'scenarios_accepted' => 0,
            'has_winner' => false,
            'rejected_reasons' => [],
        ]);
        $this->assertNull($exploration->fresh()->attempt_metrics);

        $proposal = $store->certifyProposal($task, [
            'objective' => 'obj',
            'diff_text' => 'd',
            'proposal_hash' => 'h-compat',
        ]);
        $this->assertNull($proposal->fresh()->quality);
    }

    /**
     * Invoke the runner's private summariseExploration on a synthetic exploration
     * result (the explorer/judge are real instances; only the summary seam is probed).
     *
     * @param  array<string,mixed>  $exploration
     * @return array<string,mixed>
     */
    private function summarise(array $exploration): array
    {
        $driver = new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'completed'];
            }
        };
        $runner = new AtlasEvolutionLoopRunner(new AtlasEvolutionScenarioExplorer($driver, new AtlasEvolutionFrozenJudge));

        return (new ReflectionMethod($runner, 'summariseExploration'))->invoke($runner, $exploration);
    }

    /**
     * @return array{0: AtlasLoopStore, 1: AtlasLoopTask}
     */
    private function makeClaimableTask(string $goal): array
    {
        $store = $this->app->make(AtlasLoopStore::class);
        $campaign = $store->openCampaign($goal, sys_get_temp_dir(), ['max_seconds' => 0]);
        $task = $store->enqueueTask(
            $campaign->id,
            'obj',
            ['target_relative_path' => 'src/A.php', 'target_content' => 'x', 'acceptance' => ['commands' => ['true']]],
            AtlasLoopTask::SOURCE_SEED,
            'src/A.php',
        );
        $this->assertNotNull($task);

        return [$store, $task];
    }
}
