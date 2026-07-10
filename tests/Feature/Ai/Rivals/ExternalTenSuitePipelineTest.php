<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\NativeExecutionBundleImporter;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\Preregistration;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Deterministic production-shaped unit bundle through all ten adapters. */
class ExternalTenSuitePipelineTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_ten_pipeline_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        config()->set('atlas_rivals.claim.block_dirty_workspace', false);
        config()->set('atlas_rivals.claim.min_distinct_cases_internal', 1);
        config()->set('atlas_rivals.claim.max_ci_width_internal', 1.0);
        foreach (File::directories(base_path('tests/Fixtures/Rivals/cases')) as $suiteDir) {
            File::copyDirectory(
                $suiteDir,
                RunPaths::root().'/external/'.basename($suiteDir).'/cases',
            );
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_all_ten_suites_complete_manifest_bound_three_rep_pipeline(): void
    {
        $registry = new SuiteRegistry;
        $completed = [];
        foreach ($registry->externalSuiteIds() as $suiteId) {
            $adapter = $registry->adapterFor($suiteId, allowLegacyAlias: false);
            $case = $adapter->listCases()[0];
            $caseId = (string) $case['case_id'];
            $judge = $suiteId === 'senior_swe_bench'
                ? ['va_model' => 'gpt-5.5', 'judge_model' => 'claude-opus-4-8']
                : null;
            $plan = RunPlan::make(
                $suiteId,
                [$caseId],
                [(new ArmRegistry)->parse('claude_sonnet_5@bare', $suiteId)],
                3,
                ['max_usd' => 3.0, 'max_minutes' => 30],
                42,
                $judge,
            );
            $data = $plan->data;
            $data['environment']['approve_provider_spend'] = true;
            $data['environment']['repo_commit'] = str_repeat('a', 40);
            $data['environment']['adapter_hash'] = str_repeat('b', 64);
            $plan = RunPlan::fromArray($data);
            $preregistration = Preregistration::fromPlan($plan);
            $data = $plan->data;
            $data['preregistration_hash'] = $preregistration->hash();
            $plan = RunPlan::fromArray($data);
            $plan->persist();
            $preregistration->persist();
            $smokeDir = RunPaths::root().'/benchmarks/'.$suiteId;
            File::ensureDirectoryExists($smokeDir);
            file_put_contents($smokeDir.'/latest.json', json_encode([
                'status' => 'running',
                'commit' => str_repeat('a', 40),
                'finished_at' => now()->toIso8601String(),
            ]));
            $states = new RunStateMachine;
            $states->mark($plan->runId(), RunStateMachine::PLANNED);
            $states->mark($plan->runId(), RunStateMachine::NATIVE_RUNNING);
            $manifest = NativeExecutionManifest::fromPlan(
                $plan,
                $adapter,
                $adapter->planCommands($plan),
            );
            $manifest->persist();

            $bundle = sys_get_temp_dir().'/rivals_bundle_'.$suiteId.'_'.uniqid();
            File::ensureDirectoryExists($bundle.'/native_execution_receipts');
            foreach ($manifest->entries() as $entry) {
                $payload = $this->unitPayload(
                    $suiteId,
                    $caseId,
                    (int) $entry['repetition'],
                    (string) $entry['cli_model'],
                    (string) $entry['native_agent'],
                    $judge,
                );
                $resultPath = $bundle.'/'.$entry['expected_result_path'];
                File::ensureDirectoryExists(dirname($resultPath));
                file_put_contents($resultPath, json_encode($payload, JSON_PRETTY_PRINT));
                $nativeReceipt = NativeExecutionReceipt::fromArray([
                    'schema_version' => NativeExecutionReceipt::SCHEMA,
                    'run_id' => $plan->runId(),
                    'execution_id' => $entry['execution_id'],
                    'manifest_hash' => $manifest->hash(),
                    'command_hash' => $entry['command_hash'],
                    'expected_result_path' => $entry['expected_result_path'],
                    'result_sha256' => hash_file('sha256', $resultPath),
                    'status' => 'success',
                    'exit_code' => 0,
                    'started_at' => now()->toIso8601String(),
                    'finished_at' => now()->toIso8601String(),
                    'wall_ms' => 1,
                    'cost_usd' => 0.01,
                    'stdout' => ['present' => false, 'sha256' => null],
                    'stderr' => ['present' => false, 'sha256' => null],
                    'runner' => ['version' => 'fixture-contract'],
                ]);
                file_put_contents(
                    $bundle.'/native_execution_receipts/'.$entry['execution_id'].'.json',
                    json_encode($nativeReceipt->data),
                );
            }

            (new NativeExecutionBundleImporter)->import($plan->runId(), $suiteId, $bundle);
            $receipts = $adapter->ingestResults(RunPaths::runDir($plan->runId()));
            $this->assertCount(3, $receipts, "{$suiteId}: receipt cardinality");
            foreach ($receipts as $receipt) {
                $receipt->append();
            }
            $states->mark($plan->runId(), RunStateMachine::RESULTS_IMPORTED);
            (new EvidencePackBuilder)->build($plan->runId());
            $states->mark($plan->runId(), RunStateMachine::EVIDENCE_BUILT);
            $this->assertTrue((new ReplayVerifier)->verify($plan->runId())['verified']);
            $states->mark($plan->runId(), RunStateMachine::VERIFIED);
            $decision = (new Adjudicator)->adjudicate($plan->runId());
            $this->assertTrue($decision['pipeline_valid'], implode(',', $decision['pipeline_blockers']));
            $this->assertFalse(
                $decision['internal_claim_allowed'],
                "{$suiteId}: fixture runner must never authorize a production claim",
            );
            $this->assertStringContainsString(
                'native_runner_mode_not_execute:',
                implode(',', $decision['internal_claim_blockers']),
            );
            $report = (new ReportBuilder)->build($plan->runId());
            $this->assertTrue($report['pipeline_valid']);
            $this->assertNotEmpty($report['rows']);
            $completed[] = $suiteId;
            File::deleteDirectory($bundle);
        }

        $this->assertSame($registry->externalSuiteIds(), $completed);
    }

    /** @return array<string, mixed> */
    private function unitPayload(
        string $suite,
        string $caseId,
        int $rep,
        string $model,
        string $agent,
        ?array $judge,
    ): array {
        $times = [
            'started_at' => '2026-07-09T00:00:00Z',
            'finished_at' => '2026-07-09T00:00:01Z',
        ];

        return match ($suite) {
            'tau2_bench' => [
                'agent_llm' => $model,
                'simulations' => [[
                    'task_id' => $caseId, 'trial' => $rep, 'reward' => 1.0,
                    'duration_sec' => 1, 'usage' => [
                        'input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01,
                    ],
                ] + $times],
            ],
            'bfcl' => [
                'model' => $model,
                'results' => [[
                    'test_category' => $caseId, 'run' => $rep, 'accuracy' => 1.0,
                    'status' => 'success', 'duration_sec' => 1,
                    'tokens_in' => 10, 'tokens_out' => 2, 'cost_usd' => 0.01,
                ]],
            ],
            'terminal_bench' => [
                'episodes' => [[
                    'episode_id' => $caseId, 'agent' => $agent, 'model' => $model,
                    'trial' => $rep, 'exit_status' => 'completed', 'duration_sec' => 1,
                    'input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01,
                    'started_at' => $times['started_at'], 'ended_at' => $times['finished_at'],
                ]],
            ],
            'senior_swe_bench' => [
                'coverage' => 'public_50_only', 'judge_config' => $judge,
                'tasks' => [[
                    'task_id' => $caseId, 'task' => 'feature', 'model' => $model,
                    'agent' => $agent, 'attempt' => $rep, 'resolved' => true,
                    'duration_seconds' => 1, 'usage' => [
                        'input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01,
                    ],
                    'verdicts' => ['correctness' => 1, 'validation' => 1, 'rubric' => 1, 'taste' => 1, 'bloat_practice' => 1],
                ] + $times],
            ],
            'swe_bench_live' => [
                'instances' => [[
                    'instance_id' => $caseId, 'resolved' => true,
                    'model_name_or_path' => $model, 'repetition' => $rep,
                    'duration_sec' => 1, 'usage' => [
                        'input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01,
                    ],
                ] + $times],
            ],
            'live_code_bench' => [
                'model' => $model,
                'results' => [[
                    'question_id' => $caseId, 'repetition' => $rep,
                    'graded_list' => [true], 'pass@1' => 1.0, 'duration_sec' => 1,
                    'tokens_in' => 10, 'tokens_out' => 2, 'cost_usd' => 0.01,
                ]],
            ],
            'inspect_evals' => [
                'eval' => ['model' => $model, 'task' => 'inspect_evals/gaia_level1'],
                'samples' => [[
                    'id' => $caseId, 'epoch' => $rep, 'score' => ['value' => 'C'],
                    'total_time' => 1, 'model_usage' => [
                        'input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01,
                    ],
                ] + $times],
            ],
            'hal_harness' => [
                'runs' => [[
                    'task_id' => $caseId, 'trial' => $rep, 'success' => true,
                    'total_cost_usd' => 0.01, 'latency_sec' => 1,
                    'input_tokens' => 10, 'output_tokens' => 2,
                    'model' => $model, 'agent' => $agent,
                ] + $times],
            ],
            'aider_polyglot' => [
                'model' => $model,
                'results' => [[
                    'testcase' => $caseId, 'repetition' => $rep,
                    'tests_outcomes' => [true], 'duration' => 1, 'cost' => 0.01,
                    'sent_tokens' => 10, 'received_tokens' => 2,
                    'start_time' => $times['started_at'], 'end_time' => $times['finished_at'],
                ]],
            ],
            'swe_marathon' => [
                'tasks' => [[
                    'task_id' => $caseId, 'attempt' => $rep, 'resolved' => true,
                    'model' => $model, 'agent' => $agent, 'duration_seconds' => 1,
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'cost_usd' => 0.01],
                ] + $times],
            ],
        };
    }
}
