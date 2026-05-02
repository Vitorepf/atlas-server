<?php

namespace App\Services\Engineering;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class EngineeringBenchmarkService
{
    /**
     * @var array<int,string>
     */
    private const RUNNER_OPTION_KEYS = [
        'workspace',
        'provider',
        'model',
        'model_policy',
        'permission',
        'sandbox',
        'docker_service',
        'docker_image',
        'docker_workdir',
        'docker_cache',
        'docker_network',
        'docker_healthcheck_services',
        'docker_healthcheck_timeout',
        'docker_artifact_paths',
        'docker_artifact_max_files',
        'docker_artifact_max_bytes',
        'provider_runtime',
        'provider_docker_compose_file',
        'provider_docker_service',
        'provider_docker_app_dir',
        'provider_docker_workspace_dir',
        'max_attempts',
        'test_command',
        'visual_e2e',
        'quality_scan',
        'quality_profile',
        'quality_changed_only',
        'harness_policy',
        'control_profile',
        'complete',
        'auto_test',
        'critical',
        'dry_run',
        'no_provider',
        'keep_workspace',
        'apply_isolated_patch',
        'release_gate_profile',
        'release_gate_policy',
    ];

    private const DEFAULT_SUITE_SLUG = 'atlas-core-smoke';
    private const DEFAULT_RELEASE_GATE_PROFILE = 'release';
    private const BAD_OUTCOME_STATUSES = ['degraded', 'incident', 'rolled_back'];
    private const GOOD_OUTCOME_STATUSES = ['healthy', 'accepted'];

    /**
     * @var array<string,mixed>
     */
    private const DEFAULT_RUNNER_OPTIONS = [
        'permission' => 'auto',
        'sandbox' => 'worktree',
        'max_attempts' => 1,
        'auto_test' => true,
        'keep_workspace' => false,
        'apply_isolated_patch' => false,
        'release_gate_profile' => self::DEFAULT_RELEASE_GATE_PROFILE,
    ];

    public function __construct(
        private readonly EngineeringHarnessRunnerService $runner,
        private readonly EngineeringReleaseGateAlertService $releaseGateAlerts,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function createSuite(array $data): AtlasEngineeringBenchmarkSuite
    {
        $name = $this->nonEmptyString($data['name'] ?? null) ?: 'Atlas Engineering Benchmark';
        $slug = $this->slug($data['slug'] ?? $name);

        return AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $this->nonEmptyString($data['description'] ?? null),
            'status' => $this->nonEmptyString($data['status'] ?? null) ?: 'active',
            'default_runner_options_json' => $this->redactWorkspace(
                $this->arrayValue($data['default_runner_options'] ?? $data['default_runner_options_json'] ?? []),
                true,
            ),
            'metadata' => $this->arrayValue($data['metadata'] ?? []),
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function ensureDefaultSuite(array $data = []): AtlasEngineeringBenchmarkSuite
    {
        $slug = $this->slug($data['slug'] ?? self::DEFAULT_SUITE_SLUG);
        $name = $this->nonEmptyString($data['name'] ?? null) ?: 'Atlas Core Smoke Benchmark';
        $description = $this->nonEmptyString($data['description'] ?? null)
            ?: 'Suite operacional padrao para medir regressao do Atlas Engineering Harness Runner.';
        $runnerOptions = $this->arrayValue($data['default_runner_options'] ?? $data['default_runner_options_json'] ?? []);
        if ($runnerOptions === []) {
            $runnerOptions = self::DEFAULT_RUNNER_OPTIONS;
        }

        $suite = AtlasEngineeringBenchmarkSuite::query()->firstOrNew(['slug' => $slug]);
        $suite->forceFill([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'status' => $this->nonEmptyString($data['status'] ?? null) ?: ($suite->status ?: 'active'),
            'default_runner_options_json' => $this->redactWorkspace($runnerOptions, true),
            'metadata' => array_replace_recursive([
                'managed_by' => 'atlas:engineering:benchmark:seed',
                'benchmark_tier' => 'core_smoke',
                'corpus_policy' => 'promote_resolved_engineering_runs',
                'corpus_schema' => [
                    'tiers' => ['smoke', 'release', 'full_regression', 'quarantine'],
                    'required_release_tags' => ['promoted', 'real_run'],
                    'curation_statuses' => ['candidate', 'curated', 'quarantined', 'retired'],
                ],
                'rollout_policy' => [
                    'default_status_after_passed_gate' => 'release_ready',
                    'default_status_after_warning_gate' => 'needs_review',
                    'outcome_required_within_hours' => 72,
                    'healthy_outcome_min_score' => 80,
                ],
            ], $suite->metadata ?? [], $this->arrayValue($data['metadata'] ?? [])),
        ])->save();

        return $suite->refresh();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function registerCase(AtlasEngineeringBenchmarkSuite $suite, array $data): AtlasEngineeringBenchmarkCase
    {
        $runnerOptions = $this->arrayValue($data['runner_options'] ?? $data['runner_options_json'] ?? []);
        $metadata = $this->arrayValue($data['metadata'] ?? []);
        $workspace = $this->workspaceFrom($data['workspace'] ?? ($runnerOptions['workspace'] ?? null));
        $persistWorkspace = (bool) ($data['persist_workspace_path'] ?? false);

        if ($workspace !== null) {
            $metadata = array_merge($metadata, [
                'workspace_label' => basename($workspace) ?: hash('sha256', $workspace),
                'workspace_path_hash' => hash('sha256', $workspace),
                'workspace_path_persisted' => $persistWorkspace,
            ]);

            if ($persistWorkspace) {
                $runnerOptions['workspace'] = $workspace;
            } else {
                unset($runnerOptions['workspace']);
            }
        }

        $caseCode = $this->caseCode($data['case_code'] ?? $data['title'] ?? Str::uuid()->toString());
        $contract = $this->arrayValue($data['task_contract'] ?? $data['task_contract_json'] ?? []);
        $tags = $this->normalizedTags($data['tags'] ?? $data['tags_json'] ?? []);
        $corpus = $this->caseCorpusFields($data, $metadata, $tags, $contract);

        return $suite->cases()->create([
            'task_id' => $this->nonEmptyString($data['task_id'] ?? null),
            'case_code' => $caseCode,
            'title' => $this->nonEmptyString($data['title'] ?? null) ?: Str::headline(str_replace('_', ' ', $caseCode)),
            'description' => $this->nonEmptyString($data['description'] ?? null),
            'workspace_path_hash' => $workspace ? hash('sha256', $workspace) : null,
            'task_contract_json' => $contract,
            'runner_options_json' => $this->redactWorkspace($runnerOptions, ! $persistWorkspace),
            'expected_decision' => $this->nonEmptyString($data['expected_decision'] ?? null),
            'min_score' => max(0, min(100, (int) ($data['min_score'] ?? 85))),
            'corpus_tier' => $corpus['corpus_tier'],
            'domain_slug' => $corpus['domain_slug'],
            'risk_profile' => $corpus['risk_profile'],
            'curation_status' => $corpus['curation_status'],
            'curation_score' => $corpus['curation_score'],
            'corpus_fingerprint' => $this->corpusFingerprint($caseCode, $contract, [
                'expected_decision' => $this->nonEmptyString($data['expected_decision'] ?? null) ?: 'resolved',
                'min_score' => max(0, min(100, (int) ($data['min_score'] ?? 85))),
                'tags' => $tags,
                'corpus' => $corpus,
            ]),
            'curated_at' => $corpus['curated_at'],
            'tags_json' => $tags,
            'status' => $this->nonEmptyString($data['status'] ?? null) ?: 'active',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function promoteRunToCase(
        AtlasEngineeringRun $run,
        AtlasEngineeringBenchmarkSuite $suite,
        array $data = [],
    ): AtlasEngineeringBenchmarkCase {
        $run->loadMissing(['task', 'patchArtifacts', 'testRuns', 'controlResults', 'reviewFindings']);
        if (! $run->task) {
            throw new InvalidArgumentException('Engineering run sem task nao pode virar benchmark case.');
        }

        $workspace = $this->workspaceFrom($data['workspace'] ?? null);
        $persistWorkspace = (bool) ($data['persist_workspace_path'] ?? false);
        $workspaceHash = $workspace ? hash('sha256', $workspace) : $run->workspace_path_hash;
        $runnerOptions = array_replace_recursive(
            $this->runnerOptionsFromRun($run),
            $this->arrayValue($data['runner_options'] ?? $data['runner_options_json'] ?? []),
        );

        if ($workspace !== null && $persistWorkspace) {
            $runnerOptions['workspace'] = $workspace;
        } else {
            unset($runnerOptions['workspace']);
        }

        $contract = $this->arrayValue($data['task_contract'] ?? $data['task_contract_json'] ?? []);
        if ($contract === []) {
            $contract = $this->arrayValue(data_get($run->task->metadata, 'engineering_contract', []));
        }
        if ($contract === []) {
            $contract = [
                'goal' => $run->task->title,
                'acceptance_criteria' => array_values(array_filter([
                    $run->task->minimum_viable_action,
                    $run->task->starter_step,
                    'Benchmark case promovido a partir de run real do Harness.',
                ])),
            ];
        }

        $patch = $run->patchArtifacts->first();
        $shortRunId = substr(str_replace('-', '', (string) $run->id), 0, 12);
        $caseCode = $this->caseCode($data['case_code'] ?? 'run_'.$shortRunId);
        $tags = $this->tagsForPromotedRun($run, $data['tags'] ?? $data['tags_json'] ?? []);
        $metadata = array_replace_recursive([
            'source' => 'engineering_run_promotion',
            'source_engineering_run_id' => $run->id,
            'source_task_id' => $run->task_id,
            'source_decision' => $run->decision,
            'source_status' => $run->status,
            'source_score' => $run->score,
            'source_attempt_count' => $run->attempt_count,
            'source_harnessability_score' => $run->harnessability_score,
            'source_workspace_label' => $run->workspace_label,
            'source_workspace_path_hash' => $run->workspace_path_hash,
            'source_changed_files_count' => count((array) ($patch?->changed_files_json ?? [])),
            'source_risk_flags' => array_values((array) ($patch?->risk_flags_json ?? [])),
            'source_finished_at' => $run->finished_at?->toJSON(),
            'promoted_at' => now()->toJSON(),
            'workspace_path_persisted' => $workspace !== null && $persistWorkspace,
        ], $this->arrayValue($data['metadata'] ?? []));
        $corpus = $this->caseCorpusFields($data, $metadata, $tags, $contract, $run->task?->domain);

        $case = AtlasEngineeringBenchmarkCase::query()->firstOrNew([
            'suite_id' => $suite->id,
            'case_code' => $caseCode,
        ]);
        $case->forceFill([
            'suite_id' => $suite->id,
            'task_id' => $run->task_id,
            'case_code' => $caseCode,
            'title' => $this->nonEmptyString($data['title'] ?? null) ?: $run->task->title,
            'description' => $this->nonEmptyString($data['description'] ?? null) ?: $run->task->description,
            'workspace_path_hash' => $workspaceHash,
            'task_contract_json' => $contract,
            'runner_options_json' => $this->redactWorkspace($runnerOptions, ! $persistWorkspace),
            'expected_decision' => $this->nonEmptyString($data['expected_decision'] ?? null) ?: ($run->decision ?: 'resolved'),
            'min_score' => max(0, min(100, (int) ($data['min_score'] ?? $this->defaultMinScoreFor($run)))),
            'corpus_tier' => $corpus['corpus_tier'],
            'domain_slug' => $corpus['domain_slug'],
            'risk_profile' => $corpus['risk_profile'],
            'curation_status' => $corpus['curation_status'],
            'curation_score' => $corpus['curation_score'],
            'corpus_fingerprint' => $this->corpusFingerprint($caseCode, $contract, [
                'expected_decision' => $this->nonEmptyString($data['expected_decision'] ?? null) ?: ($run->decision ?: 'resolved'),
                'min_score' => max(0, min(100, (int) ($data['min_score'] ?? $this->defaultMinScoreFor($run)))),
                'tags' => $tags,
                'corpus' => $corpus,
                'source_run_id' => $run->id,
            ]),
            'curated_at' => $corpus['curated_at'],
            'tags_json' => $tags,
            'status' => $this->nonEmptyString($data['status'] ?? null) ?: 'active',
            'metadata' => $metadata,
        ])->save();

        return $case->refresh();
    }

    /**
     * @param  array<string,mixed>  $options
     * @return Collection<int,AtlasEngineeringBenchmarkCase>
     */
    public function promoteRecentRuns(AtlasEngineeringBenchmarkSuite $suite, array $options = []): Collection
    {
        $limit = max(1, min(100, (int) ($options['limit'] ?? 10)));
        $minSourceScore = max(0, min(100, (int) ($options['min_source_score'] ?? 85)));
        $decision = $this->nonEmptyString($options['decision'] ?? null) ?: 'resolved';
        $workspace = $this->workspaceFrom($options['workspace'] ?? null);

        $query = AtlasEngineeringRun::query()
            ->with(['task', 'patchArtifacts', 'testRuns', 'controlResults', 'reviewFindings'])
            ->whereNotNull('task_id')
            ->whereHas('task')
            ->whereNotNull('score')
            ->where('score', '>=', $minSourceScore)
            ->where('decision', $decision)
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($workspace !== null) {
            $query->where('workspace_path_hash', hash('sha256', $workspace));
        }

        return $query
            ->get()
            ->map(fn (AtlasEngineeringRun $run): AtlasEngineeringBenchmarkCase => $this->promoteRunToCase($run, $suite, [
                'workspace' => $workspace,
                'tags' => $options['tags'] ?? [],
                'status' => $options['status'] ?? 'active',
                'expected_decision' => $options['expected_decision'] ?? $run->decision,
                'min_score' => $options['min_score'] ?? null,
                'corpus_tier' => $options['corpus_tier'] ?? $options['tier'] ?? null,
                'domain_slug' => $options['domain_slug'] ?? $options['domain'] ?? null,
                'risk_profile' => $options['risk_profile'] ?? $options['risk'] ?? null,
                'curation_status' => $options['curation_status'] ?? null,
            ]))
            ->values();
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function runSuite(AtlasEngineeringBenchmarkSuite $suite, array $options = []): AtlasEngineeringBenchmarkRun
    {
        $suite->loadMissing('cases');
        $cases = $this->selectedCases($suite, $options);
        $runnerOptions = $this->withReleaseQualityScanDefaults($this->runnerOverrides($options));
        $caseSetHash = $this->caseSetHash($cases);
        $identity = $this->benchmarkIdentity($suite, $runnerOptions, $options, $caseSetHash);

        $benchmarkRun = AtlasEngineeringBenchmarkRun::query()->create([
            'suite_id' => $suite->id,
            'benchmark_key' => $identity['benchmark_key'],
            'provider' => $identity['provider'],
            'model' => $identity['model'],
            'mode' => $identity['mode'],
            'case_set_hash' => $caseSetHash,
            'status' => 'running',
            'total_cases' => $cases->count(),
            'passed_cases' => 0,
            'failed_cases' => 0,
            'runner_options_json' => $this->redactWorkspace($runnerOptions, true),
            'started_at' => now(),
            'metadata' => [
                'case_codes' => $cases->pluck('case_code')->values()->all(),
                'workspace_path_hash' => $this->workspaceFrom($options['workspace'] ?? null)
                    ? hash('sha256', (string) $this->workspaceFrom($options['workspace'] ?? null))
                    : null,
                'benchmark_identity' => $identity,
            ],
        ]);

        if ($cases->isEmpty()) {
            $quality = $this->emptyQualityMetrics();
            $releaseGate = $this->releaseGateFor($benchmarkRun, 'empty', null, null, $quality, [
                'trend_status' => 'empty',
                'pass_rate_delta' => null,
                'average_score_delta' => null,
                'baseline' => null,
            ]);
            $rollout = $this->rolloutFor($benchmarkRun, 'empty', $releaseGate);
            $benchmarkRun->forceFill([
                'status' => 'empty',
                'finished_at' => now(),
                'harness_version' => $quality['harness_version'],
                'quality_metrics_json' => $quality,
                'release_gate_status' => $releaseGate['status'],
                'release_gate_profile' => $releaseGate['profile'],
                'release_gate_policy_json' => $releaseGate['policy'],
                'release_gate_failures_json' => $releaseGate['failures'],
                'release_gate_warnings_json' => $releaseGate['warnings'],
                'rollout_status' => $rollout['status'],
                'rollout_policy_json' => $rollout['policy'],
                'rollout_decision_at' => now(),
                'summary_json' => [
                    'message' => 'Nenhum case ativo selecionado para esta suite.',
                    'quality_metrics' => $quality,
                    'release_gate' => $releaseGate,
                    'rollout' => $rollout,
                ],
                'trend_status' => 'empty',
            ])->save();

            $benchmarkRun = $benchmarkRun->refresh();
            $this->releaseGateAlerts->emitIfNeeded($benchmarkRun);

            return $benchmarkRun;
        }

        foreach ($cases as $case) {
            $this->runCase($benchmarkRun->refresh(), $case, $options);
        }

        return $this->finalizeRun($benchmarkRun->refresh());
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function runCase(
        AtlasEngineeringBenchmarkRun $benchmarkRun,
        AtlasEngineeringBenchmarkCase $case,
        array $overrides = [],
    ): AtlasEngineeringBenchmarkResult {
        $startedAt = microtime(true);
        $expectation = $this->expectationFor($case);

        try {
            $task = $this->taskForCase($case);
            $runnerOptions = $this->runnerOptionsForCase($case, $overrides);
            if (! isset($runnerOptions['workspace']) || ! is_string($runnerOptions['workspace']) || trim($runnerOptions['workspace']) === '') {
                throw new InvalidArgumentException('Benchmark case precisa de workspace no run, na suite ou no case.');
            }

            $payload = $this->runner->run($task, $runnerOptions);
            $engineeringRunId = $this->nonEmptyString(data_get($payload, 'run.id'));
            $decision = $this->nonEmptyString(data_get($payload, 'run.decision'));
            $score = data_get($payload, 'run.score');
            $score = is_numeric($score) ? (int) $score : null;
            $evaluation = $this->evaluate($case, $decision, $score);

            return AtlasEngineeringBenchmarkResult::query()->create([
                'benchmark_run_id' => $benchmarkRun->id,
                'suite_id' => $benchmarkRun->suite_id,
                'case_id' => $case->id,
                'engineering_run_id' => $engineeringRunId,
                'task_id' => $task->id,
                'status' => $evaluation['passed'] ? 'passed' : 'failed',
                'decision' => $decision,
                'score' => $score,
                'passed' => $evaluation['passed'],
                'duration_ms' => $this->durationMs($startedAt),
                'expectation_json' => $expectation,
                'observed_json' => [
                    'decision' => $decision,
                    'score' => $score,
                    'run_id' => $engineeringRunId,
                    'status' => data_get($payload, 'run.status'),
                    'test_run_count' => data_get($payload, 'test_run_count'),
                    'blocking_reasons' => data_get($payload, 'score.blocking_reasons', []),
                ],
                'failure_summary' => $evaluation['failure_summary'],
                'metadata' => [
                    'runner_options' => $this->redactWorkspace($runnerOptions, true),
                ],
            ]);
        } catch (Throwable $exception) {
            return AtlasEngineeringBenchmarkResult::query()->create([
                'benchmark_run_id' => $benchmarkRun->id,
                'suite_id' => $benchmarkRun->suite_id,
                'case_id' => $case->id,
                'engineering_run_id' => null,
                'task_id' => $case->task_id,
                'status' => 'failed',
                'decision' => null,
                'score' => null,
                'passed' => false,
                'duration_ms' => $this->durationMs($startedAt),
                'expectation_json' => $expectation,
                'observed_json' => [
                    'exception' => get_class($exception),
                ],
                'failure_summary' => Str::limit($exception->getMessage(), 2000),
                'metadata' => [],
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function suitePayload(AtlasEngineeringBenchmarkSuite $suite): array
    {
        $suite->loadMissing(['cases']);
        $latestRuns = $suite->benchmarkRuns()->limit(10)->get();

        return [
            'suite' => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
                'description' => $suite->description,
                'status' => $suite->status,
                'default_runner_options' => $suite->default_runner_options_json,
                'metadata' => $suite->metadata,
                'corpus_manifest' => data_get($suite->metadata, 'corpus_manifest') ?: $this->corpusManifestFor($suite->cases),
                'corpus_health' => data_get($suite->metadata, 'corpus_health'),
                'rollout_policy' => data_get($suite->metadata, 'rollout_policy'),
                'rollout_calibration' => data_get($suite->metadata, 'rollout_calibration'),
                'created_at' => $suite->created_at?->toJSON(),
                'updated_at' => $suite->updated_at?->toJSON(),
            ],
            'cases' => $suite->cases
                ->sortBy('case_code')
                ->map(fn (AtlasEngineeringBenchmarkCase $case): array => $this->casePayload($case))
                ->values()
                ->all(),
            'latest_runs' => $latestRuns
                ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->benchmarkRunSummary($run))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runPayload(AtlasEngineeringBenchmarkRun $run): array
    {
        $run->loadMissing(['suite', 'results.benchmarkCase', 'results.engineeringRun']);

        return [
            'benchmark_run' => $this->benchmarkRunSummary($run),
            'suite' => $run->suite ? [
                'id' => $run->suite->id,
                'slug' => $run->suite->slug,
                'name' => $run->suite->name,
            ] : null,
            'results' => $run->results
                ->sortBy(fn (AtlasEngineeringBenchmarkResult $result): string => (string) ($result->benchmarkCase?->case_code ?? $result->case_id))
                ->map(fn (AtlasEngineeringBenchmarkResult $result): array => $this->resultPayload($result))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function trendPayload(AtlasEngineeringBenchmarkSuite $suite, array $options = []): array
    {
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $benchmarkKey = $this->nonEmptyString($options['benchmark_key'] ?? null);
        $provider = $this->nonEmptyString($options['provider'] ?? null);

        $runs = AtlasEngineeringBenchmarkRun::query()
            ->where('suite_id', $suite->id)
            ->whereNotIn('status', ['running'])
            ->when($benchmarkKey, fn ($query) => $query->where('benchmark_key', $benchmarkKey))
            ->when($provider, fn ($query) => $query->where('provider', $provider))
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $series = $runs
            ->groupBy(fn (AtlasEngineeringBenchmarkRun $run): string => $run->benchmark_key ?: 'unknown')
            ->map(function (Collection $seriesRuns, string $key): array {
                /** @var AtlasEngineeringBenchmarkRun|null $latest */
                $latest = $seriesRuns->first();

                return [
                    'benchmark_key' => $key,
                    'provider' => $latest?->provider,
                    'model' => $latest?->model,
                    'mode' => $latest?->mode,
                    'case_set_hash' => $latest?->case_set_hash,
                    'latest_status' => $latest?->status,
                    'latest_trend_status' => $latest?->trend_status,
                    'run_count' => $seriesRuns->count(),
                    'runs' => $seriesRuns
                        ->sortBy('created_at')
                        ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->benchmarkRunSummary($run))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'suite' => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
            ],
            'runs' => $runs
                ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->benchmarkRunSummary($run))
                ->values()
                ->all(),
            'series' => $series,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshCorpusManifest(AtlasEngineeringBenchmarkSuite $suite): array
    {
        $suite->loadMissing('cases');
        $cases = $suite->cases;
        foreach ($cases as $case) {
            $updates = [];
            $tags = $this->normalizedTags($case->tags_json ?? []);
            $contract = $this->arrayValue($case->task_contract_json ?? []);
            $metadata = $this->arrayValue($case->metadata ?? []);
            $corpus = $this->caseCorpusFields([], $metadata, $tags, $contract, $case->task?->domain);

            if (! $case->corpus_tier) {
                $updates['corpus_tier'] = $corpus['corpus_tier'];
            }
            if (! $case->domain_slug) {
                $updates['domain_slug'] = $corpus['domain_slug'];
            }
            if (! $case->risk_profile) {
                $updates['risk_profile'] = $corpus['risk_profile'];
            }
            if (! $case->curation_status) {
                $updates['curation_status'] = $corpus['curation_status'];
            }
            if (! $case->corpus_fingerprint) {
                $updates['corpus_fingerprint'] = $this->corpusFingerprint($case->case_code, $contract, [
                    'expected_decision' => $case->expected_decision ?: 'resolved',
                    'min_score' => (int) ($case->min_score ?? 85),
                    'tags' => $tags,
                    'corpus_tier' => $updates['corpus_tier'] ?? $case->corpus_tier,
                    'domain_slug' => $updates['domain_slug'] ?? $case->domain_slug,
                    'risk_profile' => $updates['risk_profile'] ?? $case->risk_profile,
                ]);
            }

            if ($updates !== []) {
                $case->forceFill($updates)->save();
            }
        }

        $suite = $suite->refresh()->load('cases');
        $manifest = $this->corpusManifestFor($suite->cases);
        $suite->forceFill([
            'metadata' => array_replace_recursive($this->arrayValue($suite->metadata ?? []), [
                'corpus_manifest' => $manifest,
            ]),
        ])->save();

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function recordOutcome(AtlasEngineeringBenchmarkRun $run, array $data): AtlasEngineeringBenchmarkRun
    {
        $status = $this->outcomeStatus($data['outcome_status'] ?? $data['status'] ?? null);
        $score = isset($data['outcome_score']) || isset($data['score'])
            ? max(0, min(100, (int) ($data['outcome_score'] ?? $data['score'])))
            : null;
        $recordedAt = now();
        $outcome = [
            'status' => $status,
            'score' => $score,
            'summary' => $this->nonEmptyString($data['summary'] ?? null),
            'notes' => $this->nonEmptyString($data['notes'] ?? null),
            'signals' => $this->arrayValue($data['signals'] ?? []),
            'rollback_reason' => $this->nonEmptyString($data['rollback_reason'] ?? null),
            'incident_ref' => $this->nonEmptyString($data['incident_ref'] ?? null),
            'recorded_at' => $recordedAt->toJSON(),
            'recorded_by' => $this->nonEmptyString($data['recorded_by'] ?? null) ?: 'operator',
        ];
        $summary = $this->arrayValue($run->summary_json ?? []);
        $summary['outcome'] = $outcome;

        $run->forceFill([
            'rollout_status' => $this->rolloutStatusAfterOutcome($status),
            'outcome_status' => $status,
            'outcome_score' => $score,
            'outcome_json' => $outcome,
            'outcome_recorded_at' => $recordedAt,
            'outcome_recorded_by' => $outcome['recorded_by'],
            'summary_json' => $summary,
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function calibrateSuite(AtlasEngineeringBenchmarkSuite $suite, array $options = []): array
    {
        $limit = max(1, min(500, (int) ($options['limit'] ?? 200)));
        $runs = AtlasEngineeringBenchmarkRun::query()
            ->with(['results.benchmarkCase'])
            ->where('suite_id', $suite->id)
            ->whereNotNull('outcome_status')
            ->orderByDesc('outcome_recorded_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $totalOutcomes = $runs->count();
        $badOutcomeCount = $runs
            ->filter(fn (AtlasEngineeringBenchmarkRun $run): bool => in_array((string) $run->outcome_status, self::BAD_OUTCOME_STATUSES, true))
            ->count();
        $goodOutcomeCount = $runs
            ->filter(fn (AtlasEngineeringBenchmarkRun $run): bool => in_array((string) $run->outcome_status, self::GOOD_OUTCOME_STATUSES, true))
            ->count();
        $badOutcomeRate = $totalOutcomes > 0 ? round(($badOutcomeCount / $totalOutcomes) * 100, 2) : null;
        $statusCounts = $runs
            ->groupBy(fn (AtlasEngineeringBenchmarkRun $run): string => (string) ($run->outcome_status ?: 'unknown'))
            ->map->count()
            ->sortKeys()
            ->all();

        $signals = $this->calibrationCaseSignals($runs);
        $caseHealth = [
            'by_tier' => $this->calibrationDistribution($signals, 'corpus_tier'),
            'by_domain' => $this->calibrationDistribution($signals, 'domain_slug'),
            'by_risk' => $this->calibrationDistribution($signals, 'risk_profile'),
            'by_curation_status' => $this->calibrationDistribution($signals, 'curation_status'),
        ];
        $quarantineCandidates = $this->quarantineCandidates($signals);
        $riskOverrides = $this->rolloutCalibrationOverrides($caseHealth);
        $recommendedPolicy = $this->recommendedRolloutPolicy($suite, $totalOutcomes, $badOutcomeRate, $statusCounts, $riskOverrides);

        $calibration = [
            'generated_at' => now()->toJSON(),
            'sample_limit' => $limit,
            'sample_window' => [
                'latest_outcome_at' => $runs->first()?->outcome_recorded_at?->toJSON(),
                'oldest_outcome_at' => $runs->last()?->outcome_recorded_at?->toJSON(),
            ],
            'status' => $this->calibrationStatus($totalOutcomes, $badOutcomeRate, $statusCounts),
            'confidence' => $this->calibrationConfidence($totalOutcomes),
            'total_outcomes' => $totalOutcomes,
            'good_outcome_count' => $goodOutcomeCount,
            'bad_outcome_count' => $badOutcomeCount,
            'bad_outcome_rate' => $badOutcomeRate,
            'outcomes_by_status' => $statusCounts,
            'case_health' => $caseHealth,
            'recommended_policy' => $recommendedPolicy,
            'risk_overrides' => $riskOverrides,
            'quarantine_candidates' => $quarantineCandidates,
        ];
        $corpusHealth = [
            'generated_at' => $calibration['generated_at'],
            'total_outcomes' => $totalOutcomes,
            'bad_outcome_rate' => $badOutcomeRate,
            'case_health' => $caseHealth,
            'quarantine_candidate_count' => count($quarantineCandidates),
            'quarantine_candidates' => $quarantineCandidates,
        ];
        $metadata = $this->arrayValue($suite->metadata ?? []);
        $metadata['rollout_policy'] = $recommendedPolicy;
        $metadata['rollout_calibration'] = $calibration;
        $metadata['corpus_health'] = $corpusHealth;

        $suite->forceFill(['metadata' => $metadata])->save();

        return $calibration;
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $runs
     * @return Collection<int,array<string,mixed>>
     */
    private function calibrationCaseSignals(Collection $runs): Collection
    {
        $signals = collect();
        foreach ($runs as $run) {
            $outcomeStatus = (string) ($run->outcome_status ?: 'unknown');
            foreach ($run->results as $result) {
                $case = $result->benchmarkCase;
                $signals->push([
                    'run_id' => $run->id,
                    'outcome_status' => $outcomeStatus,
                    'outcome_score' => $run->outcome_score,
                    'outcome_recorded_at' => $run->outcome_recorded_at?->toJSON()
                        ?: $run->finished_at?->toJSON()
                        ?: $run->created_at?->toJSON(),
                    'case_id' => $result->case_id,
                    'case_code' => $case?->case_code ?? $result->case_id,
                    'corpus_tier' => $case?->corpus_tier ?: 'unknown',
                    'domain_slug' => $case?->domain_slug ?: 'unknown',
                    'risk_profile' => $case?->risk_profile ?: 'unknown',
                    'curation_status' => $case?->curation_status ?: 'unknown',
                    'result_status' => $result->status,
                    'passed' => (bool) $result->passed,
                    'score' => $result->score,
                ]);
            }
        }

        return $signals->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $signals
     * @return array<string,array<string,mixed>>
     */
    private function calibrationDistribution(Collection $signals, string $field): array
    {
        return $signals
            ->groupBy(fn (array $signal): string => (string) ($signal[$field] ?? 'unknown'))
            ->map(function (Collection $items): array {
                $total = $items->count();
                $bad = $items
                    ->filter(fn (array $signal): bool => in_array((string) ($signal['outcome_status'] ?? ''), self::BAD_OUTCOME_STATUSES, true))
                    ->count();
                $failed = $items
                    ->filter(fn (array $signal): bool => ! (bool) ($signal['passed'] ?? false))
                    ->count();
                $scores = $items
                    ->pluck('score')
                    ->filter(fn (mixed $score): bool => is_numeric($score))
                    ->map(fn (mixed $score): int => (int) $score);

                return [
                    'total_case_outcome_exposures' => $total,
                    'bad_outcome_exposures' => $bad,
                    'bad_outcome_rate' => $total > 0 ? round(($bad / $total) * 100, 2) : null,
                    'failed_result_count' => $failed,
                    'average_score' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
                ];
            })
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $signals
     * @return array<int,array<string,mixed>>
     */
    private function quarantineCandidates(Collection $signals): array
    {
        return $signals
            ->filter(fn (array $signal): bool => $this->nonEmptyString($signal['case_id'] ?? null) !== null
                && in_array((string) ($signal['outcome_status'] ?? ''), self::BAD_OUTCOME_STATUSES, true))
            ->groupBy(fn (array $signal): string => (string) $signal['case_id'])
            ->map(function (Collection $items): array {
                $first = $items->sortByDesc('outcome_recorded_at')->first();
                $scores = $items
                    ->pluck('score')
                    ->filter(fn (mixed $score): bool => is_numeric($score))
                    ->map(fn (mixed $score): int => (int) $score);
                $badCount = $items->count();
                $risk = (string) ($first['risk_profile'] ?? 'unknown');

                return [
                    'case_id' => (string) ($first['case_id'] ?? ''),
                    'case_code' => (string) ($first['case_code'] ?? ''),
                    'corpus_tier' => (string) ($first['corpus_tier'] ?? 'unknown'),
                    'domain_slug' => (string) ($first['domain_slug'] ?? 'unknown'),
                    'risk_profile' => $risk,
                    'bad_outcome_count' => $badCount,
                    'failed_result_count' => $items
                        ->filter(fn (array $signal): bool => ! (bool) ($signal['passed'] ?? false))
                        ->count(),
                    'average_score' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
                    'last_outcome_status' => (string) ($first['outcome_status'] ?? 'unknown'),
                    'last_seen_at' => $first['outcome_recorded_at'] ?? null,
                    'suggested_curation_status' => $badCount >= 2 || in_array($risk, ['high', 'critical'], true)
                        ? 'quarantined'
                        : 'candidate_review',
                ];
            })
            ->sortByDesc('bad_outcome_count')
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $caseHealth
     * @return array<int,array<string,mixed>>
     */
    private function rolloutCalibrationOverrides(array $caseHealth): array
    {
        $overrides = [];
        foreach ([
            'by_risk' => 'risk_profile',
            'by_domain' => 'domain_slug',
        ] as $bucket => $dimension) {
            foreach ($this->arrayValue($caseHealth[$bucket] ?? []) as $value => $metrics) {
                $metrics = $this->arrayValue($metrics);
                $sampleSize = (int) ($metrics['total_case_outcome_exposures'] ?? 0);
                $badCount = (int) ($metrics['bad_outcome_exposures'] ?? 0);
                $badRate = is_numeric($metrics['bad_outcome_rate'] ?? null) ? (float) $metrics['bad_outcome_rate'] : 0.0;
                $highRiskDimension = $dimension === 'risk_profile' && in_array((string) $value, ['high', 'critical'], true);

                if ($badCount === 0 || ! ($badRate >= 20.0 || ($highRiskDimension && $badRate > 0.0) || ($sampleSize >= 5 && $badRate >= 10.0))) {
                    continue;
                }

                $overrides[] = [
                    'dimension' => $dimension,
                    'value' => (string) $value,
                    'sample_size' => $sampleSize,
                    'bad_outcome_count' => $badCount,
                    'bad_outcome_rate' => $badRate,
                    'policy' => [
                        'default_status_after_passed_gate' => 'needs_review',
                        'default_status_after_warning_gate' => 'blocked',
                        'outcome_required_within_hours' => 24,
                        'healthy_outcome_min_score' => 90,
                        'required_release_gate_profile' => 'strict',
                    ],
                ];
            }
        }

        return collect($overrides)
            ->sortByDesc('bad_outcome_rate')
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @param  array<string,int>  $statusCounts
     * @param  array<int,array<string,mixed>>  $riskOverrides
     * @return array<string,mixed>
     */
    private function recommendedRolloutPolicy(
        AtlasEngineeringBenchmarkSuite $suite,
        int $totalOutcomes,
        ?float $badOutcomeRate,
        array $statusCounts,
        array $riskOverrides,
    ): array {
        $current = array_replace_recursive([
            'default_status_after_passed_gate' => 'release_ready',
            'default_status_after_warning_gate' => 'needs_review',
            'default_status_after_failed_gate' => 'blocked',
            'outcome_required_within_hours' => 72,
            'healthy_outcome_min_score' => 80,
        ], $this->arrayValue(data_get($suite->metadata, 'rollout_policy', [])));

        if ($totalOutcomes === 0 || $badOutcomeRate === null) {
            return array_replace_recursive($current, [
                'calibration_mode' => 'insufficient_data',
                'strict_release_gate_recommended' => false,
                'requires_calibration_review' => true,
                'risk_overrides_enabled' => false,
                'calibrated_at' => now()->toJSON(),
            ]);
        }

        $incidentLikeCount = (int) ($statusCounts['incident'] ?? 0) + (int) ($statusCounts['rolled_back'] ?? 0);
        $strictRecommended = $badOutcomeRate >= 10.0 || $incidentLikeCount > 0 || $riskOverrides !== [];
        $passedStatus = match (true) {
            $badOutcomeRate >= 35.0 && $incidentLikeCount >= 2 => 'blocked',
            $badOutcomeRate >= 10.0 || $strictRecommended => 'needs_review',
            default => 'release_ready',
        };

        return array_replace_recursive($current, [
            'default_status_after_passed_gate' => $passedStatus,
            'default_status_after_warning_gate' => $badOutcomeRate >= 10.0 || $incidentLikeCount > 0 ? 'blocked' : 'needs_review',
            'default_status_after_failed_gate' => 'blocked',
            'outcome_required_within_hours' => $badOutcomeRate >= 10.0 || $incidentLikeCount > 0 ? 24 : ($badOutcomeRate > 0.0 ? 48 : 72),
            'healthy_outcome_min_score' => $badOutcomeRate >= 10.0 || $incidentLikeCount > 0 ? 90 : ($badOutcomeRate > 0.0 ? 85 : 80),
            'calibration_mode' => $strictRecommended ? 'conservative' : 'healthy',
            'strict_release_gate_recommended' => $strictRecommended,
            'requires_calibration_review' => $badOutcomeRate >= 10.0 || $incidentLikeCount > 0,
            'risk_overrides_enabled' => $riskOverrides !== [],
            'calibrated_at' => now()->toJSON(),
        ]);
    }

    /**
     * @param  array<string,int>  $statusCounts
     */
    private function calibrationStatus(int $totalOutcomes, ?float $badOutcomeRate, array $statusCounts): string
    {
        if ($totalOutcomes === 0 || $badOutcomeRate === null) {
            return 'insufficient_data';
        }

        $incidentLikeCount = (int) ($statusCounts['incident'] ?? 0) + (int) ($statusCounts['rolled_back'] ?? 0);

        return match (true) {
            $badOutcomeRate >= 25.0 || $incidentLikeCount >= 2 => 'conservative',
            $badOutcomeRate > 0.0 || $incidentLikeCount > 0 => 'watch',
            default => 'healthy',
        };
    }

    private function calibrationConfidence(int $totalOutcomes): string
    {
        return match (true) {
            $totalOutcomes >= 30 => 'high',
            $totalOutcomes >= 10 => 'medium',
            $totalOutcomes > 0 => 'low',
            default => 'none',
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @return Collection<int,AtlasEngineeringBenchmarkCase>
     */
    private function selectedCases(AtlasEngineeringBenchmarkSuite $suite, array $options): Collection
    {
        $cases = $suite->cases
            ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $case->status === 'active')
            ->sortBy('case_code')
            ->values();

        $caseCodes = collect((array) ($options['case_codes'] ?? $options['cases'] ?? []))
            ->filter(fn (mixed $code): bool => is_scalar($code) && trim((string) $code) !== '')
            ->map(fn (mixed $code): string => $this->caseCode($code))
            ->values();
        if ($caseCodes->isNotEmpty()) {
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $caseCodes->contains($case->case_code))
                ->values();
        }

        $tags = collect((array) ($options['tags'] ?? []))
            ->filter(fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->values();
        if ($tags->isNotEmpty()) {
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $tags->intersect($case->tags_json ?? [])->isNotEmpty())
                ->values();
        }

        foreach ([
            'corpus_tier' => $options['corpus_tier'] ?? $options['tier'] ?? null,
            'domain_slug' => $options['domain_slug'] ?? $options['domain'] ?? null,
            'risk_profile' => $options['risk_profile'] ?? $options['risk'] ?? null,
            'curation_status' => $options['curation_status'] ?? null,
        ] as $field => $value) {
            if (! $this->nonEmptyString($value)) {
                continue;
            }

            $normalized = $this->caseCode($value);
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => (string) $case->{$field} === $normalized)
                ->values();
        }

        $limit = (int) ($options['limit'] ?? 0);
        if ($limit > 0) {
            $cases = $cases->take($limit)->values();
        }

        return $cases;
    }

    private function taskForCase(AtlasEngineeringBenchmarkCase $case): AtlasTask
    {
        if ($case->task_id) {
            return AtlasTask::query()->findOrFail($case->task_id);
        }

        $contract = $this->arrayValue($case->task_contract_json ?? []);
        if ($contract === []) {
            throw new InvalidArgumentException('Benchmark case sem task_id precisa de task_contract.');
        }

        return AtlasTask::query()->create([
            'title' => $this->nonEmptyString($contract['goal'] ?? null) ?: $case->title,
            'description' => $case->description ?: $this->nonEmptyString($contract['context'][0] ?? null),
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'estimated_minutes' => 60,
            'metadata' => [
                'engineering_contract' => $contract,
                'benchmark_generated' => true,
                'benchmark_case_id' => $case->id,
                'benchmark_suite_id' => $case->suite_id,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function runnerOptionsForCase(AtlasEngineeringBenchmarkCase $case, array $overrides): array
    {
        $case->loadMissing('suite');

        $suiteOptions = $this->arrayValue($case->suite?->default_runner_options_json ?? []);
        $caseOptions = $this->arrayValue($case->runner_options_json ?? []);
        $runOverrides = $this->runnerOverrides($overrides);

        return $this->withReleaseQualityScanDefaults(array_replace_recursive($suiteOptions, $caseOptions, $runOverrides));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runnerOverrides(array $options): array
    {
        $nested = array_filter(
            $this->arrayValue($options['runner_options'] ?? []),
            fn (mixed $value): bool => $value !== null,
        );
        $flat = array_filter(
            Arr::only($options, self::RUNNER_OPTION_KEYS),
            fn (mixed $value): bool => $value !== null,
        );

        return array_replace_recursive($nested, $flat);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function withReleaseQualityScanDefaults(array $options): array
    {
        $profile = $this->nonEmptyString($options['release_gate_profile'] ?? null) ?: self::DEFAULT_RELEASE_GATE_PROFILE;
        if (! in_array($profile, ['release', 'strict'], true)) {
            return $options;
        }

        if ($this->nonEmptyString($options['quality_scan'] ?? null) === null) {
            $options['quality_scan'] = 'auto';
        }

        if ($this->nonEmptyString($options['quality_profile'] ?? null) === null) {
            $options['quality_profile'] = $profile === 'strict' ? 'release' : 'standard';
        }

        if (! array_key_exists('quality_changed_only', $options)) {
            $options['quality_changed_only'] = true;
        }

        return $options;
    }

    /**
     * @return array{passed:bool,failure_summary:?string}
     */
    private function evaluate(AtlasEngineeringBenchmarkCase $case, ?string $decision, ?int $score): array
    {
        $expectedDecision = $case->expected_decision ?: 'resolved';
        $minScore = (int) ($case->min_score ?? 85);
        $failures = [];

        if ($decision !== $expectedDecision) {
            $failures[] = "decision esperado={$expectedDecision}, observado=".($decision ?: 'null');
        }

        if ($score === null || $score < $minScore) {
            $failures[] = "score minimo={$minScore}, observado=".($score === null ? 'null' : (string) $score);
        }

        return [
            'passed' => $failures === [],
            'failure_summary' => $failures === [] ? null : implode('; ', $failures),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function expectationFor(AtlasEngineeringBenchmarkCase $case): array
    {
        return [
            'expected_decision' => $case->expected_decision ?: 'resolved',
            'min_score' => (int) ($case->min_score ?? 85),
            'task_id' => $case->task_id,
            'case_code' => $case->case_code,
            'tags' => $case->tags_json ?? [],
        ];
    }

    private function finalizeRun(AtlasEngineeringBenchmarkRun $run): AtlasEngineeringBenchmarkRun
    {
        $results = $run->results()->get();
        $total = $results->count();
        $passed = $results->where('passed', true)->count();
        $failed = max(0, $total - $passed);
        $scores = $results
            ->pluck('score')
            ->filter(fn (mixed $score): bool => is_numeric($score))
            ->map(fn (mixed $score): int => (int) $score);
        $quality = $this->qualityMetricsFor($run);
        $caseStatus = $total === 0 ? 'empty' : ($failed === 0 ? 'passed' : 'failed');
        $passRate = $total > 0 ? round(($passed / $total) * 100, 2) : null;
        $averageScore = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
        $finishedAt = now();
        $durationMs = $run->started_at
            ? max(0, ($finishedAt->getTimestamp() - $run->started_at->getTimestamp()) * 1000)
            : 0;
        $baseline = $this->baselineFor($run);
        $trend = $this->trendFor(
            status: $caseStatus,
            passRate: $passRate,
            averageScore: $averageScore,
            quality: $quality,
            baseline: $baseline,
        );
        $releaseGate = $this->releaseGateFor($run, $caseStatus, $passRate, $averageScore, $quality, $trend);
        $status = $this->statusAfterReleaseGate($caseStatus, $releaseGate);
        $rollout = $this->rolloutFor($run, $status, $releaseGate);

        $run->forceFill([
            'status' => $status,
            'total_cases' => $total,
            'passed_cases' => $passed,
            'failed_cases' => $failed,
            'baseline_run_id' => $baseline?->id,
            'pass_rate' => $passRate,
            'pass_rate_delta' => $trend['pass_rate_delta'],
            'average_score' => $averageScore,
            'average_score_delta' => $trend['average_score_delta'],
            'duration_ms' => $durationMs,
            'trend_status' => $trend['trend_status'],
            'harness_version' => $quality['harness_version'],
            'total_attempts' => $quality['total_attempts'],
            'failed_control_count' => $quality['failed_control_count'],
            'blocked_control_count' => $quality['blocked_control_count'],
            'skipped_required_control_count' => $quality['skipped_required_control_count'],
            'failed_test_count' => $quality['failed_test_count'],
            'open_review_finding_count' => $quality['open_review_finding_count'],
            'blocking_review_finding_count' => $quality['blocking_review_finding_count'],
            'changed_files_count' => $quality['changed_files_count'],
            'risk_flag_count' => $quality['risk_flag_count'],
            'total_tokens' => $quality['total_tokens'],
            'cost_microusd' => $quality['cost_microusd'],
            'telemetry_coverage_count' => $quality['telemetry_coverage_count'],
            'quality_metrics_json' => $quality,
            'release_gate_status' => $releaseGate['status'],
            'release_gate_profile' => $releaseGate['profile'],
            'release_gate_policy_json' => $releaseGate['policy'],
            'release_gate_failures_json' => $releaseGate['failures'],
            'release_gate_warnings_json' => $releaseGate['warnings'],
            'rollout_status' => $rollout['status'],
            'rollout_policy_json' => $rollout['policy'],
            'rollout_decision_at' => $finishedAt,
            'summary_json' => [
                'case_status' => $caseStatus,
                'decisions' => $results->groupBy('decision')->map->count()->all(),
                'failures' => $results
                    ->where('passed', false)
                    ->pluck('failure_summary')
                    ->filter()
                    ->values()
                    ->take(10)
                    ->all(),
                'baseline' => $trend['baseline'],
                'quality_metrics' => $quality,
                'release_gate' => $releaseGate,
                'rollout' => $rollout,
            ],
            'finished_at' => $finishedAt,
        ])->save();

        $run = $run->refresh();
        $this->releaseGateAlerts->emitIfNeeded($run);

        return $run;
    }

    /**
     * @return array<string,mixed>
     */
    private function benchmarkRunSummary(AtlasEngineeringBenchmarkRun $run): array
    {
        return [
            'id' => $run->id,
            'suite_id' => $run->suite_id,
            'benchmark_key' => $run->benchmark_key,
            'provider' => $run->provider,
            'model' => $run->model,
            'mode' => $run->mode,
            'case_set_hash' => $run->case_set_hash,
            'status' => $run->status,
            'total_cases' => $run->total_cases,
            'passed_cases' => $run->passed_cases,
            'failed_cases' => $run->failed_cases,
            'baseline_run_id' => $run->baseline_run_id,
            'pass_rate' => $run->pass_rate,
            'pass_rate_delta' => $run->pass_rate_delta,
            'average_score' => $run->average_score,
            'average_score_delta' => $run->average_score_delta,
            'duration_ms' => $run->duration_ms,
            'trend_status' => $run->trend_status,
            'harness_version' => $run->harness_version,
            'total_attempts' => $run->total_attempts,
            'failed_control_count' => $run->failed_control_count,
            'blocked_control_count' => $run->blocked_control_count,
            'skipped_required_control_count' => $run->skipped_required_control_count,
            'failed_test_count' => $run->failed_test_count,
            'open_review_finding_count' => $run->open_review_finding_count,
            'blocking_review_finding_count' => $run->blocking_review_finding_count,
            'changed_files_count' => $run->changed_files_count,
            'risk_flag_count' => $run->risk_flag_count,
            'total_tokens' => $run->total_tokens,
            'cost_microusd' => $run->cost_microusd,
            'telemetry_coverage_count' => $run->telemetry_coverage_count,
            'quality_metrics' => $run->quality_metrics_json,
            'release_gate_status' => $run->release_gate_status,
            'release_gate_profile' => $run->release_gate_profile,
            'release_gate_policy' => $run->release_gate_policy_json,
            'release_gate_failures' => $run->release_gate_failures_json,
            'release_gate_warnings' => $run->release_gate_warnings_json,
            'rollout_status' => $run->rollout_status,
            'rollout_policy' => $run->rollout_policy_json,
            'rollout_decision_at' => $run->rollout_decision_at?->toJSON(),
            'outcome_status' => $run->outcome_status,
            'outcome_score' => $run->outcome_score,
            'outcome' => $run->outcome_json,
            'outcome_recorded_at' => $run->outcome_recorded_at?->toJSON(),
            'outcome_recorded_by' => $run->outcome_recorded_by,
            'summary' => $run->summary_json,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function casePayload(AtlasEngineeringBenchmarkCase $case): array
    {
        return [
            'id' => $case->id,
            'suite_id' => $case->suite_id,
            'task_id' => $case->task_id,
            'case_code' => $case->case_code,
            'title' => $case->title,
            'description' => $case->description,
            'expected_decision' => $case->expected_decision,
            'min_score' => $case->min_score,
            'corpus_tier' => $case->corpus_tier,
            'domain_slug' => $case->domain_slug,
            'risk_profile' => $case->risk_profile,
            'curation_status' => $case->curation_status,
            'curation_score' => $case->curation_score,
            'corpus_fingerprint' => $case->corpus_fingerprint,
            'curated_at' => $case->curated_at?->toJSON(),
            'tags' => $case->tags_json,
            'status' => $case->status,
            'workspace_path_hash' => $case->workspace_path_hash,
            'metadata' => $case->metadata,
            'created_at' => $case->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resultPayload(AtlasEngineeringBenchmarkResult $result): array
    {
        return [
            'id' => $result->id,
            'benchmark_run_id' => $result->benchmark_run_id,
            'case_id' => $result->case_id,
            'case_code' => $result->benchmarkCase?->case_code,
            'engineering_run_id' => $result->engineering_run_id,
            'task_id' => $result->task_id,
            'status' => $result->status,
            'passed' => $result->passed,
            'decision' => $result->decision,
            'score' => $result->score,
            'duration_ms' => $result->duration_ms,
            'expectation' => $result->expectation_json,
            'observed' => $result->observed_json,
            'failure_summary' => $result->failure_summary,
            'engineering_run' => $result->engineeringRun
                ? $this->runner->runSummary($result->engineeringRun)
                : null,
            'created_at' => $result->created_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function redactWorkspace(array $options, bool $redact = true): array
    {
        if (! isset($options['workspace']) || ! is_string($options['workspace']) || trim($options['workspace']) === '') {
            return $options;
        }

        $workspace = $this->workspaceFrom($options['workspace']) ?: trim($options['workspace']);
        if ($redact) {
            $options['workspace_hash'] = hash('sha256', $workspace);
            unset($options['workspace']);

            return $options;
        }

        $options['workspace'] = $workspace;

        return $options;
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function workspaceFrom(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $workspace = trim($value);
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function slug(mixed $value): string
    {
        $slug = Str::slug((string) ($this->nonEmptyString($value) ?: 'engineering-benchmark'));

        return $slug !== '' ? $slug : 'engineering-benchmark';
    }

    private function caseCode(mixed $value): string
    {
        $slug = Str::slug((string) ($this->nonEmptyString($value) ?: Str::uuid()->toString()), '_');

        return $slug !== '' ? $slug : 'benchmark_case';
    }

    /**
     * @return array<string,mixed>
     */
    private function runnerOptionsFromRun(AtlasEngineeringRun $run): array
    {
        $strategy = $this->arrayValue($run->provider_strategy_json ?? []);
        $metadata = $this->arrayValue($run->metadata ?? []);
        $docker = $this->arrayValue($strategy['docker'] ?? []);
        $providerRuntime = $this->arrayValue($strategy['provider_runtime'] ?? []);
        $hasAutomatedTests = $run->testRuns->isNotEmpty();

        return array_filter([
            'permission' => $strategy['permission'] ?? 'auto',
            'sandbox' => $strategy['sandbox'] ?? 'worktree',
            'docker_service' => $docker['docker_service'] ?? null,
            'docker_image' => $docker['docker_image'] ?? null,
            'docker_workdir' => $docker['docker_workdir'] ?? null,
            'docker_cache' => $docker['docker_cache'] ?? null,
            'docker_network' => $docker['docker_network'] ?? null,
            'docker_healthcheck_services' => $docker['docker_healthcheck_services'] ?? null,
            'docker_healthcheck_timeout' => $docker['docker_healthcheck_timeout'] ?? null,
            'docker_artifact_paths' => $docker['docker_artifact_paths'] ?? null,
            'docker_artifact_max_files' => $docker['docker_artifact_max_files'] ?? null,
            'docker_artifact_max_bytes' => $docker['docker_artifact_max_bytes'] ?? null,
            'provider_runtime' => $providerRuntime['provider_runtime'] ?? null,
            'provider_docker_compose_file' => $providerRuntime['provider_docker_compose_file'] ?? null,
            'provider_docker_service' => $providerRuntime['provider_docker_service'] ?? null,
            'provider_docker_app_dir' => $providerRuntime['provider_docker_app_dir'] ?? null,
            'provider_docker_workspace_dir' => $providerRuntime['provider_docker_workspace_dir'] ?? null,
            'max_attempts' => $run->max_attempts ?: 1,
            'auto_test' => $hasAutomatedTests,
            'visual_e2e' => $strategy['visual_e2e'] ?? null,
            'control_profile' => $metadata['control_profile'] ?? null,
            'keep_workspace' => false,
            'apply_isolated_patch' => false,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  mixed  $extraTags
     * @return array<int,string>
     */
    private function tagsForPromotedRun(AtlasEngineeringRun $run, mixed $extraTags = []): array
    {
        $strategy = $this->arrayValue($run->provider_strategy_json ?? []);
        $patch = $run->patchArtifacts->first();
        $tags = [
            'promoted',
            'real_run',
            'decision_'.$this->caseCode($run->decision ?: 'unknown'),
            'status_'.$this->caseCode($run->status ?: 'unknown'),
            'domain_'.$this->caseCode((string) ($run->task?->domain ?: 'atlas')),
            'sandbox_'.$this->caseCode((string) ($strategy['sandbox'] ?? 'workspace')),
        ];

        foreach ((array) ($patch?->risk_flags_json ?? []) as $flag) {
            if (is_scalar($flag) && trim((string) $flag) !== '') {
                $tags[] = 'risk_'.$this->caseCode($flag);
            }
        }

        foreach ((array) $extraTags as $tag) {
            if (is_scalar($tag) && trim((string) $tag) !== '') {
                $tags[] = $this->caseCode($tag);
            }
        }

        return collect($tags)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function defaultMinScoreFor(AtlasEngineeringRun $run): int
    {
        $score = is_numeric($run->score) ? (int) $run->score : 85;

        return max(85, min(95, $score));
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkCase>  $cases
     */
    private function caseSetHash(Collection $cases): string
    {
        $fingerprint = $cases
            ->sortBy('case_code')
            ->map(fn (AtlasEngineeringBenchmarkCase $case): array => [
                'case_code' => $case->case_code,
                'expected_decision' => $case->expected_decision ?: 'resolved',
                'min_score' => (int) ($case->min_score ?? 85),
                'corpus_tier' => $case->corpus_tier,
                'curation_status' => $case->curation_status,
                'corpus_fingerprint' => $case->corpus_fingerprint,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function benchmarkIdentity(
        AtlasEngineeringBenchmarkSuite $suite,
        array $runnerOptions,
        array $options,
        string $caseSetHash,
    ): array {
        $provider = $this->nonEmptyString($options['benchmark_provider'] ?? null)
            ?: $this->nonEmptyString($runnerOptions['provider'] ?? null)
            ?: ((bool) ($runnerOptions['no_provider'] ?? false) ? 'no_provider' : 'default_provider');
        $model = $this->nonEmptyString($options['benchmark_model'] ?? $options['model'] ?? null)
            ?: $this->nonEmptyString($runnerOptions['model'] ?? null)
            ?: $this->policyModelIdentity($runnerOptions)
            ?: 'default_model';
        $mode = match (true) {
            (bool) ($runnerOptions['dry_run'] ?? false) => 'dry_run',
            (bool) ($runnerOptions['no_provider'] ?? false) => 'no_provider',
            (bool) ($runnerOptions['complete'] ?? false) => 'complete',
            default => 'single_shot',
        };
        $sandbox = $this->nonEmptyString($runnerOptions['sandbox'] ?? null) ?: 'workspace';
        $keyParts = [
            $suite->slug,
            $this->caseCode($provider),
            $this->caseCode($model),
            $this->caseCode($mode),
            $this->caseCode($sandbox),
            substr($caseSetHash, 0, 16),
        ];

        return [
            'benchmark_key' => implode(':', $keyParts),
            'provider' => $provider,
            'model' => $model,
            'model_policy' => $this->nonEmptyString($runnerOptions['model_policy'] ?? null),
            'mode' => $mode,
            'sandbox' => $sandbox,
            'case_set_hash' => $caseSetHash,
        ];
    }

    private function baselineFor(AtlasEngineeringBenchmarkRun $run): ?AtlasEngineeringBenchmarkRun
    {
        if (! $run->benchmark_key) {
            return null;
        }

        return AtlasEngineeringBenchmarkRun::query()
            ->where('suite_id', $run->suite_id)
            ->where('benchmark_key', $run->benchmark_key)
            ->where('id', '!=', $run->id)
            ->whereNotIn('status', ['running', 'empty'])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function policyModelIdentity(array $runnerOptions): ?string
    {
        $policy = $this->nonEmptyString($runnerOptions['model_policy'] ?? null);
        if ($policy === null) {
            return null;
        }

        $normalized = str_replace('_', '-', strtolower($policy));

        return in_array($normalized, ['auto', 'balanced', 'best-quality', 'fastest', 'cheapest'], true)
            ? 'policy:'.$normalized
            : null;
    }

    /**
     * @return array{trend_status:string,pass_rate_delta:?float,average_score_delta:?float,baseline:?array<string,mixed>}
     */
    private function trendFor(
        string $status,
        ?float $passRate,
        ?float $averageScore,
        array $quality,
        ?AtlasEngineeringBenchmarkRun $baseline,
    ): array {
        if ($status === 'empty') {
            return [
                'trend_status' => 'empty',
                'pass_rate_delta' => null,
                'average_score_delta' => null,
                'baseline' => null,
            ];
        }

        if (! $baseline) {
            return [
                'trend_status' => 'first_baseline',
                'pass_rate_delta' => null,
                'average_score_delta' => null,
                'baseline' => null,
            ];
        }

        $passRateDelta = is_numeric($passRate) && is_numeric($baseline->pass_rate)
            ? round((float) $passRate - (float) $baseline->pass_rate, 2)
            : null;
        $averageScoreDelta = is_numeric($averageScore) && is_numeric($baseline->average_score)
            ? round((float) $averageScore - (float) $baseline->average_score, 2)
            : null;

        $currentQualityDebt = $this->qualityDebt($quality);
        $baselineQualityDebt = $this->qualityDebt([
            'failed_control_count' => $baseline->failed_control_count,
            'blocked_control_count' => $baseline->blocked_control_count,
            'skipped_required_control_count' => $baseline->skipped_required_control_count,
            'failed_test_count' => $baseline->failed_test_count,
            'blocking_review_finding_count' => $baseline->blocking_review_finding_count,
        ]);

        $trendStatus = match (true) {
            $baseline->status === 'passed' && $status === 'failed' => 'regressed',
            $baseline->status === 'failed' && $status === 'passed' => 'improved',
            $baselineQualityDebt === 0 && $currentQualityDebt > 0 => 'regressed',
            $baselineQualityDebt > 0 && $currentQualityDebt === 0 => 'improved',
            $passRateDelta !== null && $passRateDelta <= -5.0 => 'regressed',
            $averageScoreDelta !== null && $averageScoreDelta <= -5.0 => 'regressed',
            $passRateDelta !== null && $passRateDelta >= 5.0 => 'improved',
            $averageScoreDelta !== null && $averageScoreDelta >= 5.0 => 'improved',
            default => 'stable',
        };

        return [
            'trend_status' => $trendStatus,
            'pass_rate_delta' => $passRateDelta,
            'average_score_delta' => $averageScoreDelta,
            'baseline' => [
                'run_id' => $baseline->id,
                'status' => $baseline->status,
                'pass_rate' => $baseline->pass_rate,
                'average_score' => $baseline->average_score,
                'quality_debt' => $baselineQualityDebt,
                'finished_at' => $baseline->finished_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function qualityMetricsFor(AtlasEngineeringBenchmarkRun $run): array
    {
        $results = $run->results()
            ->with(['engineeringRun.attempts', 'engineeringRun.controlResults', 'engineeringRun.testRuns', 'engineeringRun.reviewFindings', 'engineeringRun.patchArtifacts'])
            ->get();
        $engineeringRuns = $results
            ->map(fn (AtlasEngineeringBenchmarkResult $result) => $result->engineeringRun)
            ->filter()
            ->unique('id')
            ->values();

        $controlResults = $engineeringRuns->flatMap(fn (AtlasEngineeringRun $engineeringRun) => $engineeringRun->controlResults);
        $testRuns = $engineeringRuns->flatMap(fn (AtlasEngineeringRun $engineeringRun) => $engineeringRun->testRuns);
        $artifactExports = $testRuns
            ->filter(fn ($testRun): bool => data_get($testRun->metadata, 'artifact_export.status') === 'captured');
        $visualArtifactExports = $testRuns
            ->filter(fn ($testRun): bool => data_get($testRun->metadata, 'visual_artifact_export.status') === 'captured');
        $visualSmokeRuns = $testRuns
            ->filter(fn ($testRun): bool => is_array(data_get($testRun->metadata, 'visual_smoke')));
        $reviewFindings = $engineeringRuns->flatMap(fn (AtlasEngineeringRun $engineeringRun) => $engineeringRun->reviewFindings);
        $patchArtifacts = $engineeringRuns->flatMap(fn (AtlasEngineeringRun $engineeringRun) => $engineeringRun->patchArtifacts);
        $attempts = $engineeringRuns->flatMap(fn (AtlasEngineeringRun $engineeringRun) => $engineeringRun->attempts);
        $traceIds = $attempts
            ->pluck('trace_id')
            ->merge($engineeringRuns->pluck('trace_id'))
            ->filter(fn (mixed $traceId): bool => is_string($traceId) && trim($traceId) !== '')
            ->unique()
            ->values();
        $telemetry = $this->telemetryMetricsFor($traceIds);
        $riskFlags = $patchArtifacts
            ->flatMap(fn ($patch): array => array_values((array) ($patch->risk_flags_json ?? [])))
            ->filter(fn (mixed $flag): bool => is_scalar($flag) && trim((string) $flag) !== '')
            ->map(fn (mixed $flag): string => trim((string) $flag))
            ->values();
        $changedFiles = $patchArtifacts
            ->flatMap(fn ($patch): array => array_values((array) ($patch->changed_files_json ?? [])))
            ->filter(fn (mixed $file): bool => is_scalar($file) && trim((string) $file) !== '')
            ->map(fn (mixed $file): string => trim((string) $file))
            ->unique()
            ->values();
        $failedControls = $controlResults
            ->filter(fn ($result): bool => in_array((string) $result->status, ['failed', 'blocked'], true))
            ->groupBy(fn ($result): string => (string) $result->control_slug)
            ->map->count()
            ->sortDesc()
            ->all();
        $openFindings = $reviewFindings->filter(fn ($finding): bool => (string) $finding->status === 'open');
        $blockingFindings = $openFindings->filter(fn ($finding): bool => in_array((string) $finding->severity, ['p0', 'p1'], true));

        return [
            'harness_version' => $this->harnessVersion(),
            'engineering_run_count' => $engineeringRuns->count(),
            'total_attempts' => $attempts->count(),
            'failed_control_count' => $controlResults->where('status', 'failed')->count(),
            'blocked_control_count' => $controlResults->where('status', 'blocked')->count(),
            'skipped_required_control_count' => $controlResults
                ->filter(fn ($result): bool => (string) $result->status === 'skipped' && (bool) data_get($result->metadata, 'required', false))
                ->count(),
            'failed_test_count' => $testRuns->where('status', 'failed')->count(),
            'artifact_export_count' => $artifactExports->count() + $visualArtifactExports->count(),
            'artifact_export_bytes' => $artifactExports
                ->map(fn ($testRun): int => (int) data_get($testRun->metadata, 'artifact_export.total_bytes', 0))
                ->merge($visualArtifactExports->map(fn ($testRun): int => (int) data_get($testRun->metadata, 'visual_artifact_export.total_bytes', 0)))
                ->sum(),
            'visual_artifact_export_count' => $visualArtifactExports->count(),
            'visual_smoke_run_count' => $visualSmokeRuns->count(),
            'visual_baseline_changed_count' => $visualSmokeRuns
                ->map(fn ($testRun): int => (int) data_get($testRun->metadata, 'visual_smoke.baseline_changed_count', 0))
                ->sum(),
            'visual_baseline_first_count' => $visualSmokeRuns
                ->map(fn ($testRun): int => (int) data_get($testRun->metadata, 'visual_smoke.baseline_first_count', 0))
                ->sum(),
            'open_review_finding_count' => $openFindings->count(),
            'blocking_review_finding_count' => $blockingFindings->count(),
            'changed_files_count' => $changedFiles->count(),
            'risk_flag_count' => $riskFlags->count(),
            'risk_flags' => $riskFlags->unique()->values()->all(),
            'failed_controls' => $failedControls,
            'failed_tests' => $testRuns
                ->where('status', 'failed')
                ->map(fn ($testRun): array => [
                    'command' => $testRun->command,
                    'exit_code' => $testRun->exit_code,
                ])
                ->values()
                ->take(20)
                ->all(),
            'blocking_findings' => $blockingFindings
                ->map(fn ($finding): array => [
                    'severity' => $finding->severity,
                    'title' => $finding->title,
                    'source' => $finding->source,
                ])
                ->values()
                ->take(20)
                ->all(),
            'total_tokens' => $telemetry['total_tokens'],
            'cost_microusd' => $telemetry['cost_microusd'],
            'telemetry_coverage_count' => $telemetry['coverage_count'],
            'telemetry_trace_ids' => $telemetry['trace_ids'],
        ];
    }

    /**
     * @param  Collection<int,string>  $traceIds
     * @return array{total_tokens:?int,cost_microusd:?int,coverage_count:int,trace_ids:array<int,string>}
     */
    private function telemetryMetricsFor(Collection $traceIds): array
    {
        if ($traceIds->isEmpty() || ! Schema::hasTable('ai_trace_metric_summaries')) {
            return [
                'total_tokens' => null,
                'cost_microusd' => null,
                'coverage_count' => 0,
                'trace_ids' => $traceIds->all(),
            ];
        }

        $summaries = AiTraceMetricSummary::query()
            ->whereIn('trace_id', $traceIds->all())
            ->get();
        $tokens = $summaries
            ->pluck('total_tokens')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->sum();
        $cost = $summaries
            ->pluck('cost_microusd')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->sum();

        return [
            'total_tokens' => $summaries->isEmpty() ? null : (int) $tokens,
            'cost_microusd' => $summaries->isEmpty() ? null : (int) $cost,
            'coverage_count' => $summaries->count(),
            'trace_ids' => $traceIds->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function qualityDebt(array $quality): int
    {
        return (int) ($quality['failed_control_count'] ?? 0)
            + (int) ($quality['blocked_control_count'] ?? 0)
            + (int) ($quality['skipped_required_control_count'] ?? 0)
            + (int) ($quality['failed_test_count'] ?? 0)
            + (int) ($quality['blocking_review_finding_count'] ?? 0);
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $trend
     * @return array{status:string,profile:string,policy:array<string,mixed>,failures:array<int,string>,warnings:array<int,string>,quality_debt:int}
     */
    private function releaseGateFor(
        AtlasEngineeringBenchmarkRun $run,
        string $caseStatus,
        ?float $passRate,
        ?float $averageScore,
        array $quality,
        array $trend,
    ): array {
        $run->loadMissing('suite');

        $suiteOptions = $this->arrayValue($run->suite?->default_runner_options_json ?? []);
        $runnerOptions = $this->arrayValue($run->runner_options_json ?? []);
        $profile = $this->releaseGateProfile($runnerOptions, $suiteOptions, $run);
        $policy = array_replace_recursive(
            $this->releaseGateProfilePolicy($profile),
            $this->arrayValue(data_get($run->suite?->metadata, 'release_gate.policy', [])),
            $this->arrayValue($runnerOptions['release_gate_policy'] ?? []),
        );
        $qualityDebt = $this->qualityDebt($quality);

        if (! (bool) ($policy['enabled'] ?? true)) {
            return [
                'status' => 'skipped',
                'profile' => $profile,
                'policy' => $policy,
                'failures' => [],
                'warnings' => [],
                'quality_debt' => $qualityDebt,
            ];
        }

        $failures = [];
        $warnings = [];

        if ($caseStatus === 'empty' && (bool) ($policy['fail_on_empty'] ?? true)) {
            $failures[] = 'Suite sem cases ativos.';
        }

        $this->failWhenAbove($failures, 'cases falhos', (int) $run->failed_cases, $policy['max_failed_cases'] ?? null);
        $this->failWhenBelow($failures, 'pass rate', $passRate, $policy['min_pass_rate'] ?? null, '%');
        $this->failWhenBelow($failures, 'score medio', $averageScore, $policy['min_average_score'] ?? null);
        $this->failWhenAbove($failures, 'divida de qualidade', $qualityDebt, $policy['max_quality_debt'] ?? null);
        $this->failWhenAbove($failures, 'controles falhos ou bloqueados', (int) ($quality['failed_control_count'] ?? 0) + (int) ($quality['blocked_control_count'] ?? 0), $policy['max_failed_or_blocked_controls'] ?? null);
        $this->failWhenAbove($failures, 'controles obrigatorios pulados', (int) ($quality['skipped_required_control_count'] ?? 0), $policy['max_skipped_required_controls'] ?? null);
        $this->failWhenAbove($failures, 'testes falhos', (int) ($quality['failed_test_count'] ?? 0), $policy['max_failed_tests'] ?? null);
        $this->failWhenAbove($failures, 'review findings bloqueantes', (int) ($quality['blocking_review_finding_count'] ?? 0), $policy['max_blocking_review_findings'] ?? null);
        $this->failWhenAbove($failures, 'tentativas totais', (int) ($quality['total_attempts'] ?? 0), $policy['max_total_attempts'] ?? null);

        if ((bool) ($policy['fail_on_regressed_trend'] ?? true) && ($trend['trend_status'] ?? null) === 'regressed') {
            $failures[] = 'Trend regrediu contra o baseline equivalente.';
        }

        $this->warnWhenAbove($warnings, 'review findings abertos', (int) ($quality['open_review_finding_count'] ?? 0), $policy['max_open_review_findings_warning'] ?? null);
        $this->warnWhenAbove($warnings, 'risk flags', (int) ($quality['risk_flag_count'] ?? 0), $policy['max_risk_flags_warning'] ?? null);
        $this->warnWhenAbove($warnings, 'arquivos alterados', (int) ($quality['changed_files_count'] ?? 0), $policy['max_changed_files_warning'] ?? null);
        $this->warnWhenAbove($warnings, 'tokens totais', $quality['total_tokens'] ?? null, $policy['max_total_tokens_warning'] ?? null);
        $this->warnWhenAbove($warnings, 'custo microusd', $quality['cost_microusd'] ?? null, $policy['max_cost_microusd_warning'] ?? null);

        if ((bool) ($policy['warn_on_missing_telemetry'] ?? false)
            && (int) ($quality['total_attempts'] ?? 0) > 0
            && (int) ($quality['telemetry_coverage_count'] ?? 0) === 0
        ) {
            $warnings[] = 'Sem cobertura de telemetria para attempts do benchmark.';
        }

        if (! (bool) ($policy['blocking'] ?? true) && $failures !== []) {
            $warnings = array_values(array_merge($warnings, $failures));
            $failures = [];
        }

        return [
            'status' => $failures !== [] ? 'failed' : ($warnings !== [] ? 'warning' : 'passed'),
            'profile' => $profile,
            'policy' => $policy,
            'failures' => array_values($failures),
            'warnings' => array_values($warnings),
            'quality_debt' => $qualityDebt,
        ];
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @param  array<string,mixed>  $suiteOptions
     */
    private function releaseGateProfile(
        array $runnerOptions,
        array $suiteOptions,
        AtlasEngineeringBenchmarkRun $run,
    ): string {
        $profile = $this->caseCode(
            $this->nonEmptyString($runnerOptions['release_gate_profile'] ?? null)
                ?: $this->nonEmptyString($suiteOptions['release_gate_profile'] ?? null)
                ?: $this->nonEmptyString(data_get($run->suite?->metadata, 'release_gate.profile'))
                ?: self::DEFAULT_RELEASE_GATE_PROFILE,
        );

        return in_array($profile, ['off', 'advisory', 'smoke', 'release', 'strict'], true)
            ? $profile
            : self::DEFAULT_RELEASE_GATE_PROFILE;
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseGateProfilePolicy(string $profile): array
    {
        $release = [
            'enabled' => true,
            'blocking' => true,
            'fail_on_empty' => true,
            'fail_on_regressed_trend' => true,
            'min_pass_rate' => 100,
            'min_average_score' => 85,
            'max_failed_cases' => 0,
            'max_quality_debt' => 0,
            'max_failed_or_blocked_controls' => 0,
            'max_skipped_required_controls' => 0,
            'max_failed_tests' => 0,
            'max_blocking_review_findings' => 0,
            'max_open_review_findings_warning' => 0,
            'max_risk_flags_warning' => 0,
            'max_changed_files_warning' => 40,
            'max_total_attempts' => null,
            'max_total_tokens_warning' => null,
            'max_cost_microusd_warning' => null,
            'warn_on_missing_telemetry' => false,
        ];

        return match ($profile) {
            'off' => array_merge($release, [
                'enabled' => false,
                'blocking' => false,
            ]),
            'advisory' => array_merge($release, [
                'blocking' => false,
            ]),
            'smoke' => array_merge($release, [
                'min_average_score' => null,
                'fail_on_regressed_trend' => false,
                'max_open_review_findings_warning' => null,
                'max_risk_flags_warning' => null,
                'max_changed_files_warning' => null,
            ]),
            'strict' => array_merge($release, [
                'min_average_score' => 90,
                'max_total_attempts' => 1,
                'max_changed_files_warning' => 20,
                'max_total_tokens_warning' => 200000,
                'max_cost_microusd_warning' => 5000000,
                'warn_on_missing_telemetry' => true,
            ]),
            default => $release,
        };
    }

    /**
     * @param  array<int,string>  $failures
     */
    private function failWhenAbove(array &$failures, string $label, mixed $value, mixed $limit): void
    {
        if (! is_numeric($limit) || ! is_numeric($value)) {
            return;
        }

        if ((float) $value > (float) $limit) {
            $failures[] = "{$label} acima do limite: observado {$value}, limite {$limit}.";
        }
    }

    /**
     * @param  array<int,string>  $failures
     */
    private function failWhenBelow(array &$failures, string $label, mixed $value, mixed $limit, string $suffix = ''): void
    {
        if (! is_numeric($limit)) {
            return;
        }

        if (! is_numeric($value) || (float) $value < (float) $limit) {
            $observed = is_numeric($value) ? (string) $value.$suffix : 'indisponivel';
            $failures[] = "{$label} abaixo do minimo: observado {$observed}, minimo {$limit}{$suffix}.";
        }
    }

    /**
     * @param  array<int,string>  $warnings
     */
    private function warnWhenAbove(array &$warnings, string $label, mixed $value, mixed $limit): void
    {
        if (! is_numeric($limit) || ! is_numeric($value)) {
            return;
        }

        if ((float) $value > (float) $limit) {
            $warnings[] = "{$label} acima do limite de alerta: observado {$value}, limite {$limit}.";
        }
    }

    /**
     * @param  array<string,mixed>  $releaseGate
     */
    private function statusAfterReleaseGate(string $caseStatus, array $releaseGate): string
    {
        if ($caseStatus === 'empty') {
            return 'empty';
        }

        return ($releaseGate['status'] ?? null) === 'failed' ? 'failed' : $caseStatus;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyQualityMetrics(): array
    {
        return [
            'harness_version' => $this->harnessVersion(),
            'engineering_run_count' => 0,
            'total_attempts' => 0,
            'failed_control_count' => 0,
            'blocked_control_count' => 0,
            'skipped_required_control_count' => 0,
            'failed_test_count' => 0,
            'open_review_finding_count' => 0,
            'blocking_review_finding_count' => 0,
            'changed_files_count' => 0,
            'risk_flag_count' => 0,
            'risk_flags' => [],
            'failed_controls' => [],
            'failed_tests' => [],
            'blocking_findings' => [],
            'total_tokens' => null,
            'cost_microusd' => null,
            'telemetry_coverage_count' => 0,
            'telemetry_trace_ids' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $tags
     * @param  array<string,mixed>  $contract
     * @return array{corpus_tier:string,domain_slug:string,risk_profile:string,curation_status:string,curation_score:?int,curated_at:mixed}
     */
    private function caseCorpusFields(
        array $data,
        array $metadata,
        array $tags,
        array $contract,
        ?string $fallbackDomain = null,
    ): array {
        $tier = $this->normalizedChoice(
            $data['corpus_tier'] ?? $data['tier'] ?? $metadata['corpus_tier'] ?? null,
            ['smoke', 'release', 'full_regression', 'quarantine'],
            $this->tierFromTags($tags),
        );
        $domain = $this->caseCode(
            $this->nonEmptyString($data['domain_slug'] ?? $data['domain'] ?? $metadata['domain_slug'] ?? null)
                ?: $this->nonEmptyString($fallbackDomain)
                ?: $this->nonEmptyString(data_get($contract, 'domain'))
                ?: 'atlas',
        );
        $risk = $this->normalizedChoice(
            $data['risk_profile'] ?? $data['risk'] ?? $metadata['risk_profile'] ?? null,
            ['low', 'medium', 'high', 'critical'],
            $this->riskFromTags($tags),
        );
        $status = $this->normalizedChoice(
            $data['curation_status'] ?? $metadata['curation_status'] ?? null,
            ['candidate', 'curated', 'quarantined', 'retired'],
            $tier === 'quarantine' ? 'quarantined' : 'curated',
        );
        $score = isset($data['curation_score']) || isset($metadata['curation_score'])
            ? max(0, min(100, (int) ($data['curation_score'] ?? $metadata['curation_score'])))
            : null;

        return [
            'corpus_tier' => $tier,
            'domain_slug' => $domain,
            'risk_profile' => $risk,
            'curation_status' => $status,
            'curation_score' => $score,
            'curated_at' => $status === 'curated' ? now() : null,
        ];
    }

    /**
     * @param  mixed  $tags
     * @return array<int,string>
     */
    private function normalizedTags(mixed $tags): array
    {
        return collect((array) $tags)
            ->filter(fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')
            ->map(fn (mixed $tag): string => $this->caseCode($tag))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $tags
     */
    private function tierFromTags(array $tags): string
    {
        if (in_array('quarantine', $tags, true) || in_array('flaky', $tags, true)) {
            return 'quarantine';
        }
        if (in_array('full_regression', $tags, true) || in_array('regression', $tags, true)) {
            return 'full_regression';
        }
        if (in_array('release', $tags, true)) {
            return 'release';
        }

        return 'smoke';
    }

    /**
     * @param  array<int,string>  $tags
     */
    private function riskFromTags(array $tags): string
    {
        foreach (['critical', 'high', 'medium', 'low'] as $risk) {
            if (in_array('risk_'.$risk, $tags, true) || in_array($risk.'_risk', $tags, true)) {
                return $risk;
            }
        }

        return in_array('migration', $tags, true) || in_array('security', $tags, true) ? 'high' : 'medium';
    }

    /**
     * @param  array<int,string>  $allowed
     */
    private function normalizedChoice(mixed $value, array $allowed, string $default): string
    {
        $normalized = $this->nonEmptyString($value) ? $this->caseCode($value) : $default;

        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $expectation
     */
    private function corpusFingerprint(string $caseCode, array $contract, array $expectation): string
    {
        return hash('sha256', json_encode([
            'case_code' => $caseCode,
            'contract' => $contract,
            'expectation' => $expectation,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkCase>  $cases
     * @return array<string,mixed>
     */
    private function corpusManifestFor(Collection $cases): array
    {
        $active = $cases->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $case->status === 'active');

        return [
            'generated_at' => now()->toJSON(),
            'total_cases' => $cases->count(),
            'active_cases' => $active->count(),
            'by_tier' => $this->distribution($cases, 'corpus_tier'),
            'by_domain' => $this->distribution($cases, 'domain_slug'),
            'by_risk' => $this->distribution($cases, 'risk_profile'),
            'by_curation_status' => $this->distribution($cases, 'curation_status'),
            'official_subsets' => [
                'smoke' => $cases->where('corpus_tier', 'smoke')->where('status', 'active')->count(),
                'release' => $cases->whereIn('corpus_tier', ['smoke', 'release'])->where('curation_status', 'curated')->where('status', 'active')->count(),
                'full_regression' => $cases->where('curation_status', 'curated')->where('status', 'active')->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkCase>  $cases
     * @return array<string,int>
     */
    private function distribution(Collection $cases, string $field): array
    {
        return $cases
            ->groupBy(fn (AtlasEngineeringBenchmarkCase $case): string => (string) ($case->{$field} ?: 'unknown'))
            ->map->count()
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $releaseGate
     * @return array{status:string,policy:array<string,mixed>}
     */
    private function rolloutFor(AtlasEngineeringBenchmarkRun $run, string $status, array $releaseGate): array
    {
        $run->loadMissing('suite');
        $policy = array_replace_recursive([
            'default_status_after_passed_gate' => 'release_ready',
            'default_status_after_warning_gate' => 'needs_review',
            'default_status_after_failed_gate' => 'blocked',
            'outcome_required_within_hours' => 72,
            'healthy_outcome_min_score' => 80,
        ], $this->arrayValue(data_get($run->suite?->metadata, 'rollout_policy', [])));
        $policy = $this->applyRolloutCalibrationOverrides($run, $policy);

        $rolloutStatus = match (true) {
            $status === 'empty' => 'not_applicable',
            ($releaseGate['status'] ?? null) === 'failed' => (string) $policy['default_status_after_failed_gate'],
            ($releaseGate['status'] ?? null) === 'warning' => (string) $policy['default_status_after_warning_gate'],
            $status === 'passed' => (string) $policy['default_status_after_passed_gate'],
            default => 'blocked',
        };

        return [
            'status' => $rolloutStatus,
            'policy' => $policy,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function applyRolloutCalibrationOverrides(AtlasEngineeringBenchmarkRun $run, array $policy): array
    {
        $run->loadMissing(['suite', 'results.benchmarkCase']);
        $overrides = collect($this->arrayValue(data_get($run->suite?->metadata, 'rollout_calibration.risk_overrides', [])));
        if ($overrides->isEmpty()) {
            return $policy;
        }

        unset($policy['calibration_overrides_applied']);

        $dimensions = [
            'risk_profile' => $run->results
                ->map(fn (AtlasEngineeringBenchmarkResult $result): ?string => $result->benchmarkCase?->risk_profile)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'domain_slug' => $run->results
                ->map(fn (AtlasEngineeringBenchmarkResult $result): ?string => $result->benchmarkCase?->domain_slug)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'corpus_tier' => $run->results
                ->map(fn (AtlasEngineeringBenchmarkResult $result): ?string => $result->benchmarkCase?->corpus_tier)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
        $applied = [];

        foreach ($overrides as $override) {
            $override = $this->arrayValue($override);
            $dimension = $this->nonEmptyString($override['dimension'] ?? null);
            $value = $this->nonEmptyString($override['value'] ?? null);
            if (! $dimension || ! $value || ! in_array($value, (array) ($dimensions[$dimension] ?? []), true)) {
                continue;
            }

            $policy = array_replace_recursive($policy, $this->arrayValue($override['policy'] ?? []));
            $applied[] = [
                'dimension' => $dimension,
                'value' => $value,
                'bad_outcome_rate' => $override['bad_outcome_rate'] ?? null,
            ];
        }

        if ($applied !== []) {
            $policy['calibration_overrides_applied'] = $applied;
        }

        return $policy;
    }

    private function outcomeStatus(mixed $value): string
    {
        $status = $this->nonEmptyString($value) ? $this->caseCode($value) : 'healthy';

        return in_array($status, ['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'], true)
            ? $status
            : 'healthy';
    }

    private function rolloutStatusAfterOutcome(string $outcomeStatus): string
    {
        return match ($outcomeStatus) {
            'healthy' => 'healthy',
            'accepted' => 'accepted',
            'degraded', 'incident' => 'degraded',
            'rolled_back' => 'rolled_back',
            default => 'monitoring',
        };
    }

    private function harnessVersion(): string
    {
        return 'runner-v1.visual-smoke';
    }
}
