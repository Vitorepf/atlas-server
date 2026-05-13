<?php

namespace App\Services\Engineering;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
        'provider_timeout_seconds',
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
        'fair_mode',
        'claude_only',
        'single_provider',
        'no_decide',
        'fallback_disabled',
        'require_pass_without_human',
        'claude_code_baseline',
        'claude_code_baseline_mode',
        'claude_code_baseline_model',
        'claude_code_baseline_binary',
        'claude_code_baseline_timeout',
        'claude_code_baseline_workspace',
        'claude_code_baseline_validation_timeout',
        'baseline_runner',
        'baseline_model',
        'baseline_timeout_seconds',
        'baseline_validation_timeout_seconds',
        'rivals_battery_mode',
        'rivals_battery_plan_hash',
        'operator_plan_reviewed',
        'operator_cost_acknowledged',
        'rivals_battery_plan',
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
        private readonly EngineeringClaudeCodeBaselineRunnerService $claudeCodeBaseline,
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly EngineeringReleaseGateAlertService $releaseGateAlerts,
        private readonly ?EngineeringBenchmarkInput $input = null,
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

        $case = AtlasEngineeringBenchmarkCase::query()->firstOrNew([
            'suite_id' => $suite->id,
            'case_code' => $caseCode,
        ]);
        $case->forceFill([
            'suite_id' => $suite->id,
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
        ])->save();

        return $case->refresh();
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
            'source_blueprint_snapshot_id' => $run->blueprint_snapshot_id,
            'source_blueprint_id' => $run->blueprint_id,
            'source_blueprint' => $this->arrayValue(data_get($run->metadata, 'blueprint', [])),
            'source_evidence_refs' => $run->task->engineeringEvidence()
                ->latest('recorded_at')
                ->limit(20)
                ->get()
                ->map(fn ($evidence): array => [
                    'id' => $evidence->id,
                    'evidence_type' => $evidence->evidence_type,
                    'target_id' => $evidence->target_id,
                    'status' => $evidence->status,
                    'confidence' => $evidence->confidence === null ? null : (float) $evidence->confidence,
                    'recorded_at' => $evidence->recorded_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'memory_delta' => [
                'status' => 'candidate',
                'privacy_guard' => true,
                'summary' => 'Run real promovido para benchmark; preservar apenas refs e evidencias redigidas.',
            ],
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
        $limit = $this->benchmarkInput()->promoteRecentRunsLimit($options['limit'] ?? null);
        $minSourceScore = $this->benchmarkInput()->minSourceScore($options['min_source_score'] ?? null);
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
        $pairedBaselineWorktreePlan = null;
        $pairedWorkspaces = null;
        $claudeCodeBaseline = null;

        try {
            $task = $this->taskForCase($case);
            $runnerOptions = $this->runnerOptionsForCase($case, $overrides);
            if (! isset($runnerOptions['workspace']) || ! is_string($runnerOptions['workspace']) || trim($runnerOptions['workspace']) === '') {
                throw new InvalidArgumentException('Benchmark case precisa de workspace no run, na suite ou no case.');
            }

            $paired = $this->preparePairedBaselineWorkspace($case, $runnerOptions);
            $runnerOptions = $paired['runner_options'];
            $pairedBaselineWorktreePlan = $paired['baseline_plan'];
            $pairedWorkspaces = $paired['artifact'];

            $claudeCodeBaseline = $this->claudeCodeBaseline->capture($case, $task, $runnerOptions);
            if ($pairedBaselineWorktreePlan !== null) {
                $pairedWorkspaces['claude_code_baseline']['release'] = $this->releasePairedBaselineWorkspace(
                    $pairedBaselineWorktreePlan,
                    $runnerOptions,
                );
                $pairedBaselineWorktreePlan = null;
            }

            $atlasStartedAt = microtime(true);
            $payload = $this->runner->run($task, $runnerOptions);
            $engineeringRunId = $this->nonEmptyString(data_get($payload, 'run.id'));
            $decision = $this->nonEmptyString(data_get($payload, 'run.decision'));
            $score = data_get($payload, 'run.score');
            $score = is_numeric($score) ? (int) $score : null;
            $durationMs = $this->durationMs($atlasStartedAt);
            $fairScorecard = $this->fairScorecard($payload, $runnerOptions);
            $evaluation = $this->evaluate($case, $decision, $score, $fairScorecard);
            $pairedScorecard = $this->pairedScorecard($case, $evaluation, $fairScorecard, $claudeCodeBaseline, $decision, $score);
            if (is_array($pairedScorecard)) {
                $pairedScorecard['atlas']['duration_ms'] = $durationMs;
            }

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
                'duration_ms' => $durationMs,
                'expectation_json' => $expectation,
                'observed_json' => [
                    'decision' => $decision,
                    'score' => $score,
                    'run_id' => $engineeringRunId,
                    'status' => data_get($payload, 'run.status'),
                    'test_run_count' => data_get($payload, 'test_run_count'),
                    'blocking_reasons' => data_get($payload, 'score.blocking_reasons', []),
                    'fair_scorecard' => $fairScorecard,
                    'claude_code_baseline' => $claudeCodeBaseline,
                    'paired_scorecard' => $pairedScorecard,
                    'paired_workspaces' => $pairedWorkspaces,
                ],
                'failure_summary' => $evaluation['failure_summary'],
                'metadata' => [
                    'runner_options' => $this->redactWorkspace($runnerOptions, true),
                    'claude_code_baseline' => $claudeCodeBaseline ? [
                        'enabled' => true,
                        'mode' => $claudeCodeBaseline['mode'] ?? null,
                        'status' => $claudeCodeBaseline['status'] ?? null,
                        'provider' => $claudeCodeBaseline['provider'] ?? null,
                        'model' => $claudeCodeBaseline['model'] ?? null,
                        'prompt_hash' => $claudeCodeBaseline['prompt_hash'] ?? null,
                    ] : null,
                    'paired_workspaces' => $pairedWorkspaces,
                ],
            ]);
        } catch (Throwable $exception) {
            if ($pairedBaselineWorktreePlan !== null) {
                $pairedWorkspaces['claude_code_baseline']['release'] = $this->releasePairedBaselineWorkspace(
                    $pairedBaselineWorktreePlan,
                    is_array($runnerOptions ?? null) ? $runnerOptions : [],
                );
            }

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
                    'claude_code_baseline' => $claudeCodeBaseline,
                    'paired_workspaces' => $pairedWorkspaces,
                ],
                'failure_summary' => Str::limit($exception->getMessage(), 2000),
                'metadata' => [
                    'paired_workspaces' => $pairedWorkspaces,
                ],
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
        $limit = $this->benchmarkInput()->trendLimit($options['limit'] ?? null);
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
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function fairClaudeReportPayload(AtlasEngineeringBenchmarkSuite $suite, array $options = []): array
    {
        $limit = $this->benchmarkInput()->fairClaudeReportLimit($options['limit'] ?? null);
        $scanLimit = $this->benchmarkInput()->fairClaudeScanLimit($limit);
        $batchSize = $this->benchmarkInput()->fairClaudeBatchSize($scanLimit);
        $scannedRunCount = 0;
        $runs = collect();

        while ($runs->count() < $limit && $scannedRunCount < $scanLimit) {
            $batch = AtlasEngineeringBenchmarkRun::query()
                ->where('suite_id', $suite->id)
                ->whereNotIn('status', ['running'])
                ->with(['results.benchmarkCase'])
                ->orderByDesc('finished_at')
                ->orderByDesc('created_at')
                ->offset($scannedRunCount)
                ->limit(min($batchSize, $scanLimit - $scannedRunCount))
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $scannedRunCount += $batch->count();
            $runs = $runs
                ->concat($batch->filter(fn (AtlasEngineeringBenchmarkRun $run): bool => (bool) data_get($run->summary_json ?? [], 'paired_scorecard.enabled')))
                ->take($limit)
                ->values();
        }

        $results = $runs
            ->flatMap(fn (AtlasEngineeringBenchmarkRun $run): Collection => $run->results)
            ->values();
        $fairResults = $results
            ->filter(fn (AtlasEngineeringBenchmarkResult $result): bool => (bool) data_get($result->observed_json ?? [], 'paired_scorecard.fair_mode'))
            ->values();
        $fairRunIds = $fairResults
            ->pluck('benchmark_run_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $fairRuns = $runs
            ->filter(fn (AtlasEngineeringBenchmarkRun $run): bool => $fairRunIds->contains((int) $run->id))
            ->values();
        $paired = $this->pairedScorecardSummary($fairResults, $this->runsCostMicrousd($fairRuns));
        $allPaired = $this->pairedScorecardSummary($results, $this->runsCostMicrousd($runs));
        $baseline = $this->claudeCodeBaselineSummary($fairResults);
        $replay = $this->fairClaudeReplayReport($fairRuns);
        $caseComparisons = $this->fairClaudeCaseComparisons($fairResults, $limit);
        $corpusManifest = $this->arrayValue(data_get($suite->metadata ?? [], 'corpus_manifest', []));
        $readiness = $this->fairClaudeReportReadiness($fairRuns, $paired, $baseline, $replay, $corpusManifest);
        $invalidBatteryFingerprint = $this->fairClaudeInvalidBatteryFingerprint($fairRuns, $readiness, $paired, $replay, $caseComparisons);
        $invalidBatteryTriage = $this->fairClaudeInvalidBatteryTriageState($suite, $invalidBatteryFingerprint);
        $nextActions = $this->fairClaudeReportNextActions($readiness, $paired, $baseline, $replay, $caseComparisons, $invalidBatteryTriage);
        $batteryExecutionContract = $this->fairClaudeBatteryExecutionContract($suite, $readiness, $paired, $baseline, $replay);
        $experimentValidity = $this->fairClaudeExperimentValidity($readiness, $paired, $caseComparisons);
        $resultIntegrity = $this->fairClaudeResultIntegrity($readiness, $paired, $caseComparisons, $invalidBatteryTriage, $experimentValidity);
        $executiveSummary = $this->fairClaudeExecutiveSummary($readiness, $paired, $baseline, $replay, $caseComparisons);
        $executiveSummary['result_integrity'] = Arr::only($resultIntegrity, [
            'status',
            'score_admitted',
            'claim_winner_admitted',
            'winner_for_claim',
            'provisional_leader',
            'operator_headline',
            'experiment_validity',
        ]);
        $evidencePacket = $this->fairClaudeEvidencePacket(
            $suite,
            $fairRuns,
            $readiness,
            $paired,
            $baseline,
            $replay,
            $caseComparisons,
            $nextActions,
            $resultIntegrity,
        );
        $claimMarkdown = $this->fairClaudeClaimMarkdown(
            $executiveSummary,
            $evidencePacket,
            $nextActions,
            $caseComparisons,
        );
        $runHistory = $runs
            ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->fairClaudeRunHistoryPayload(
                $run,
                $fairRunIds->contains((int) $run->id),
            ))
            ->values();

        $payload = [
            'schema_version' => 1,
            'kind' => 'fair_claude_benchmark_report',
            'generated_at' => now()->toJSON(),
            'suite' => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
            ],
            'limit' => $limit,
            'scan_limit' => $scanLimit,
            'scanned_run_count' => $scannedRunCount,
            'run_count' => $runs->count(),
            'result_count' => $results->count(),
            'scope' => [
                'paired_run_count' => $runs->count(),
                'fair_run_count' => $fairRuns->count(),
                'paired_result_count' => $results->count(),
                'fair_result_count' => $fairResults->count(),
                'non_fair_paired_result_count' => max(0, $results->count() - $fairResults->count()),
                'case_comparison_count' => $caseComparisons->count(),
                'invalid_battery_fingerprint' => $invalidBatteryFingerprint,
            ],
            'readiness' => $readiness,
            'executive_summary' => $executiveSummary,
            'result_integrity' => $resultIntegrity,
            'battery_execution_contract' => $batteryExecutionContract,
            'next_actions' => $nextActions,
            'evidence_packet' => $evidencePacket,
            'claim_markdown' => $claimMarkdown,
            'corpus_manifest' => $corpusManifest,
            'paired_scorecard' => $paired,
            'all_paired_scorecard' => $allPaired,
            'claude_code_baseline' => $baseline,
            'replay_manifest' => $replay,
            'case_comparisons' => $caseComparisons->values()->all(),
            'history_timeline' => $this->fairClaudeHistoryTimeline($runHistory),
            'runs' => $runHistory->all(),
        ];

        $payload['export_bundle'] = $this->fairClaudeExportBundle($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $replay
     * @return array<string,mixed>
     */
    private function fairClaudeBatteryExecutionContract(
        AtlasEngineeringBenchmarkSuite $suite,
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
    ): array {
        $releaseCount = (int) ($readiness['release_corpus_case_count'] ?? 0);
        $minimumReleaseCount = (int) ($readiness['minimum_release_corpus_case_count'] ?? 6);
        $corpusPrepared = $releaseCount >= $minimumReleaseCount;
        $pairedRunCount = (int) ($paired['case_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $baselineExecuted = (bool) ($baseline['enabled'] ?? false);
        $replayVerified = (bool) ($replay['enabled'] ?? false)
            && (int) ($replay['packet_count'] ?? 0) > 0
            && (int) ($replay['artifact_integrity_failed_count'] ?? 0) === 0;

        return [
            'schema_version' => 'atlas.fair_claude.battery_execution_contract.v1',
            'status' => $corpusPrepared ? 'operator_execution_required' : 'prepare_corpus_required',
            'summary' => $corpusPrepared
                ? 'Corpus pronto; falta executar bateria pareada real com provider e baseline Claude Code.'
                : 'Corpus release ainda incompleto; prepare o corpus antes de qualquer bateria.',
            'operator_required' => true,
            'agent_auto_execution_allowed' => false,
            'provider_dispatch_required' => true,
            'external_cost_possible' => true,
            'synthetic_scores_allowed' => false,
            'current_state' => [
                'corpus_prepared' => $corpusPrepared,
                'release_corpus_case_count' => $releaseCount,
                'minimum_release_corpus_case_count' => $minimumReleaseCount,
                'paired_case_count' => $pairedRunCount,
                'comparable_case_count' => $comparableCount,
                'baseline_executed' => $baselineExecuted,
                'replay_verified' => $replayVerified,
                'ready_for_claim' => (bool) ($readiness['ready_for_claim'] ?? false),
            ],
            'start_requirements' => [
                'release_corpus_prepared',
                'separate_atlas_workspace',
                'separate_claude_code_baseline_workspace',
                'claude_code_binary_available',
                'operator_accepts_time_and_provider_cost',
                'runbook_ready_to_start_battery_true',
            ],
            'commands' => [
                'prepare_corpus' => 'atlas rivals prepare --json',
                'runbook' => 'atlas rivals runbook --json',
                'quick_battery' => 'atlas rivals run --json',
                'medium_battery' => 'atlas rivals run --medium --json',
                'full_battery' => 'atlas rivals run --full --json',
                'report' => 'atlas rivals report --json',
                'verify_export' => 'atlas rivals verify --output-dir=atlas-rivals-report --json',
                'artisan_report' => "php artisan atlas:engineering:benchmark:rivals report --suite={$suite->slug} --json",
            ],
            'recommended_presets' => [
                [
                    'id' => 'quick',
                    'case_count' => 1,
                    'estimated_time' => '10-25 min',
                    'purpose' => 'provar que a automacao e o baseline executam sem pagar a bateria inteira.',
                ],
                [
                    'id' => 'medium',
                    'case_count' => 3,
                    'estimated_time' => '30-75 min',
                    'purpose' => 'gerar sinal util sem custo de bateria completa.',
                ],
                [
                    'id' => 'full',
                    'case_count' => null,
                    'estimated_time' => '90-180+ min',
                    'purpose' => 'produzir evidencia forte para claim externo.',
                ],
            ],
            'safety' => [
                'read_model_only' => true,
                'report_does_not_execute_provider' => true,
                'operator_must_start_run' => true,
                'claim_blocked_until_real_comparable_cases' => $comparableCount === 0,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function writeFairClaudeExportBundle(array $payload, string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            throw new InvalidArgumentException('Export directory cannot be empty.');
        }

        File::ensureDirectoryExists($directory);

        $contents = [
            'report.json' => $this->prettyJson(Arr::except($payload, ['claim_markdown', 'export_bundle', 'written_export_bundle'])),
            'evidence.json' => $this->prettyJson($payload['evidence_packet'] ?? []),
            'claim.md' => (string) ($payload['claim_markdown'] ?? ''),
            'case-comparisons.json' => $this->prettyJson($payload['case_comparisons'] ?? []),
            'history-timeline.json' => $this->prettyJson($payload['history_timeline'] ?? []),
        ];
        $files = [];
        foreach ($contents as $filename => $content) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            File::put($path, $content);
            $files[$filename] = [
                'path' => $path,
                'bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
            ];
        }

        $manifest = [
            'schema_version' => 1,
            'kind' => 'fair_claude_written_export_bundle',
            'written_at' => now()->toJSON(),
            'directory' => $directory,
            'evidence_hash' => data_get($payload, 'evidence_packet.evidence_hash'),
            'bundle_hash' => data_get($payload, 'export_bundle.bundle_hash'),
            'files' => $files,
        ];
        $manifestJson = $this->prettyJson($manifest);
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        File::put($manifestPath, $manifestJson);
        $manifest['files']['manifest.json'] = [
            'path' => $manifestPath,
            'bytes' => strlen($manifestJson),
            'sha256' => hash('sha256', $manifestJson),
        ];

        return $manifest;
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyFairClaudeExportBundle(string $directory): array
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $checkedAt = now()->toJSON();

        $basePayload = [
            'schema_version' => 1,
            'kind' => 'fair_claude_export_bundle_verification',
            'checked_at' => $checkedAt,
            'directory' => $directory,
            'status' => 'failed',
            'verified' => false,
            'file_count' => 0,
            'passed_count' => 0,
            'failed_count' => 0,
            'missing_count' => 0,
            'blocking_reasons' => [],
            'files' => [],
        ];

        if ($directory === '') {
            return array_merge($basePayload, [
                'blocking_reasons' => ['export_directory_empty'],
            ]);
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_missing'],
                'manifest_path' => $manifestPath,
            ]);
        }

        $manifestJson = File::get($manifestPath);
        $manifest = json_decode($manifestJson, true);
        if (! is_array($manifest)) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_invalid_json'],
                'manifest_path' => $manifestPath,
                'manifest_sha256' => hash('sha256', $manifestJson),
            ]);
        }

        $manifestFiles = $manifest['files'] ?? [];
        if (! is_array($manifestFiles) || $manifestFiles === []) {
            return array_merge($basePayload, [
                'blocking_reasons' => ['manifest_files_missing'],
                'manifest_path' => $manifestPath,
                'manifest_sha256' => hash('sha256', $manifestJson),
                'manifest' => Arr::only($manifest, ['schema_version', 'kind', 'written_at', 'evidence_hash', 'bundle_hash']),
            ]);
        }

        $files = [];
        $passedCount = 0;
        $failedCount = 0;
        $missingCount = 0;
        $requiredFiles = [
            'report.json',
            'evidence.json',
            'claim.md',
            'case-comparisons.json',
            'history-timeline.json',
            'manifest.json',
        ];

        foreach ($manifestFiles as $filename => $fileMetadata) {
            if (! is_string($filename) || trim($filename) === '') {
                $failedCount++;
                $files[(string) $filename] = [
                    'status' => 'failed',
                    'exists' => false,
                    'hash_matches' => false,
                    'blocking_reason' => 'invalid_manifest_filename',
                ];

                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            $expectedHash = is_array($fileMetadata) && is_string($fileMetadata['sha256'] ?? null)
                ? (string) $fileMetadata['sha256']
                : null;
            $expectedBytes = is_array($fileMetadata) && is_numeric($fileMetadata['bytes'] ?? null)
                ? (int) $fileMetadata['bytes']
                : null;
            $exists = File::exists($path);
            $actualHash = $exists ? hash_file('sha256', $path) : null;
            $actualBytes = $exists ? File::size($path) : null;
            $hashMatches = $exists && $expectedHash !== null && hash_equals($expectedHash, (string) $actualHash);
            $bytesMatch = $exists && ($expectedBytes === null || $expectedBytes === $actualBytes);
            $status = $hashMatches && $bytesMatch ? 'passed' : ($exists ? 'failed' : 'missing');

            if ($status === 'passed') {
                $passedCount++;
            } elseif ($status === 'missing') {
                $missingCount++;
            } else {
                $failedCount++;
            }

            $files[$filename] = [
                'status' => $status,
                'path' => $path,
                'exists' => $exists,
                'expected_sha256' => $expectedHash,
                'actual_sha256' => $actualHash,
                'hash_matches' => $hashMatches,
                'expected_bytes' => $expectedBytes,
                'actual_bytes' => $actualBytes,
                'bytes_match' => $bytesMatch,
            ];
        }

        foreach ($requiredFiles as $requiredFile) {
            if (array_key_exists($requiredFile, $files)) {
                continue;
            }
            if ($requiredFile === 'manifest.json' && File::exists($manifestPath)) {
                $files['manifest.json'] = [
                    'status' => 'passed',
                    'path' => $manifestPath,
                    'exists' => true,
                    'expected_sha256' => hash('sha256', $manifestJson),
                    'actual_sha256' => hash('sha256', $manifestJson),
                    'hash_matches' => true,
                    'expected_bytes' => strlen($manifestJson),
                    'actual_bytes' => strlen($manifestJson),
                    'bytes_match' => true,
                    'required' => true,
                ];
                $passedCount++;

                continue;
            }

            $failedCount++;
            $files[$requiredFile] = [
                'status' => 'failed',
                'path' => $directory.DIRECTORY_SEPARATOR.$requiredFile,
                'exists' => File::exists($directory.DIRECTORY_SEPARATOR.$requiredFile),
                'hash_matches' => false,
                'bytes_match' => false,
                'required' => true,
                'blocking_reason' => 'manifest_required_file_missing',
            ];
        }

        if (! array_key_exists('manifest.json', $files)) {
            $files['manifest.json'] = [
                'status' => 'passed',
                'path' => $manifestPath,
                'exists' => true,
                'expected_sha256' => hash('sha256', $manifestJson),
                'actual_sha256' => hash('sha256', $manifestJson),
                'hash_matches' => true,
                'expected_bytes' => strlen($manifestJson),
                'actual_bytes' => strlen($manifestJson),
                'bytes_match' => true,
            ];
            $passedCount++;
        }

        $blockingReasons = [];
        if (collect($files)->contains(fn (array $file): bool => ($file['blocking_reason'] ?? null) === 'manifest_required_file_missing')) {
            $blockingReasons[] = 'manifest_required_file_missing';
        }
        if ($missingCount > 0) {
            $blockingReasons[] = 'export_file_missing';
        }
        if ($failedCount > 0) {
            $blockingReasons[] = 'export_file_hash_mismatch';
        }

        $evidencePath = $directory.DIRECTORY_SEPARATOR.'evidence.json';
        $evidenceHashMatches = null;
        $semanticChecks = [
            'status' => 'skipped',
            'blocking_reasons' => [],
        ];
        if (File::exists($evidencePath)) {
            $evidence = json_decode(File::get($evidencePath), true);
            if (is_array($evidence)) {
                $expectedEvidenceHash = is_string($manifest['evidence_hash'] ?? null)
                    ? (string) $manifest['evidence_hash']
                    : null;
                $actualEvidenceHash = is_string($evidence['evidence_hash'] ?? null)
                    ? (string) $evidence['evidence_hash']
                    : null;
                $computedEvidenceHash = hash('sha256', $this->canonicalJsonForHash(Arr::except($evidence, ['generated_at', 'evidence_hash'])));
                $evidenceHashMatches = $expectedEvidenceHash !== null
                    && $actualEvidenceHash !== null
                    && hash_equals($expectedEvidenceHash, $actualEvidenceHash)
                    && hash_equals($actualEvidenceHash, $computedEvidenceHash);
                if (! $evidenceHashMatches) {
                    $failedCount++;
                    $blockingReasons[] = 'evidence_hash_mismatch';
                    $files['evidence.json']['status'] = 'failed';
                    $files['evidence.json']['evidence_hash_matches'] = false;
                    $files['evidence.json']['expected_evidence_hash'] = $expectedEvidenceHash;
                    $files['evidence.json']['actual_evidence_hash'] = $actualEvidenceHash;
                    $files['evidence.json']['computed_evidence_hash'] = $computedEvidenceHash;
                } else {
                    $files['evidence.json']['evidence_hash_matches'] = true;
                    $files['evidence.json']['computed_evidence_hash'] = $computedEvidenceHash;
                }

                $semanticChecks = $this->fairClaudeExportSemanticChecks($evidence, $directory);
                if (($semanticChecks['status'] ?? null) !== 'passed') {
                    $failedCount++;
                    $blockingReasons = array_merge($blockingReasons, (array) ($semanticChecks['blocking_reasons'] ?? []));
                    $files['evidence.json']['status'] = 'failed';
                    $files['evidence.json']['semantic_checks'] = $semanticChecks;
                } else {
                    $files['evidence.json']['semantic_checks'] = $semanticChecks;
                }
            } else {
                $failedCount++;
                $blockingReasons[] = 'evidence_invalid_json';
                $files['evidence.json']['status'] = 'failed';
                $files['evidence.json']['semantic_checks'] = [
                    'status' => 'failed',
                    'blocking_reasons' => ['evidence_invalid_json'],
                ];
            }
        }

        $verified = $passedCount > 0 && $failedCount === 0 && $missingCount === 0;

        return array_merge($basePayload, [
            'status' => $verified ? 'passed' : 'failed',
            'verified' => $verified,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => hash('sha256', $manifestJson),
            'manifest' => Arr::only($manifest, ['schema_version', 'kind', 'written_at', 'evidence_hash', 'bundle_hash']),
            'required_files' => $requiredFiles,
            'evidence_hash_matches' => $evidenceHashMatches,
            'semantic_checks' => $semanticChecks,
            'file_count' => count($files),
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'missing_count' => $missingCount,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'files' => $files,
        ]);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function fairClaudeExportSemanticChecks(array $evidence, string $directory): array
    {
        $blocking = [];
        $integrity = $this->arrayValue($evidence['result_integrity'] ?? []);
        $claim = $this->arrayValue($evidence['claim'] ?? []);
        $scorecard = $this->arrayValue($evidence['scorecard'] ?? []);
        $uiContract = $this->arrayValue($integrity['ui_contract'] ?? []);
        $experimentValidity = $this->arrayValue($integrity['experiment_validity'] ?? []);

        if ($integrity === []) {
            $blocking[] = 'result_integrity_missing';
        }
        if (($integrity['schema_version'] ?? null) !== 'atlas.fair_claude.result_integrity.v1') {
            $blocking[] = 'result_integrity_schema_invalid';
        }
        if (($experimentValidity['schema_version'] ?? null) !== 'atlas.fair_claude.experiment_validity.v1') {
            $blocking[] = 'experiment_validity_schema_invalid';
        }
        if (data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_cannot_decide_winner') !== true) {
            $blocking[] = 'experiment_validity_external_variable_policy_missing';
        }
        if (data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_can_only_block_comparability') !== true) {
            $blocking[] = 'experiment_validity_confounder_policy_missing';
        }
        if (data_get($experimentValidity, 'ab_test_validity_model.no_provider_specific_case_filtering') !== true) {
            $blocking[] = 'experiment_validity_case_filtering_policy_missing';
        }

        $claimWinner = $claim['winner'] ?? null;
        $claimWinnerAdmitted = (bool) ($integrity['claim_winner_admitted'] ?? $claim['claim_winner_admitted'] ?? false);
        $mustNotRenderWinner = (bool) ($uiContract['must_not_render_winner'] ?? false);
        if (! $claimWinnerAdmitted && $claimWinner !== null) {
            $blocking[] = 'claim_winner_present_without_admission';
        }
        if ($mustNotRenderWinner && $claimWinner !== null) {
            $blocking[] = 'ui_contract_winner_violation';
        }
        if (($integrity['winner_for_claim'] ?? null) !== $claimWinner) {
            $blocking[] = 'claim_winner_mismatch_with_result_integrity';
        }
        if (($integrity['score_admitted'] ?? null) === false && (int) ($scorecard['comparable_count'] ?? 0) > 0 && $claimWinner !== null) {
            $blocking[] = 'score_not_admitted_but_winner_present';
        }

        $claimPath = $directory.DIRECTORY_SEPARATOR.'claim.md';
        $claimMarkdownContainsIntegrity = File::exists($claimPath)
            && str_contains(File::get($claimPath), '## Result Integrity');
        if (! $claimMarkdownContainsIntegrity) {
            $blocking[] = 'claim_markdown_result_integrity_missing';
        }
        $claimMarkdownContainsExperimentValidity = File::exists($claimPath)
            && str_contains(File::get($claimPath), '## Experiment Validity');
        if (! $claimMarkdownContainsExperimentValidity) {
            $blocking[] = 'claim_markdown_experiment_validity_missing';
        }

        return [
            'status' => $blocking === [] ? 'passed' : 'failed',
            'blocking_reasons' => array_values(array_unique($blocking)),
            'result_integrity_status' => $integrity['status'] ?? null,
            'experiment_validity_status' => $experimentValidity['status'] ?? null,
            'claim_winner' => $claimWinner,
            'claim_winner_admitted' => $claimWinnerAdmitted,
            'must_not_render_winner' => $mustNotRenderWinner,
            'claim_markdown_contains_result_integrity' => $claimMarkdownContainsIntegrity,
            'claim_markdown_contains_experiment_validity' => $claimMarkdownContainsExperimentValidity,
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
        $limit = $this->benchmarkInput()->calibrateSuiteLimit($options['limit'] ?? null);
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

        return $this->withReleaseQualityScanDefaults(
            $this->withCaseValidationDefaults(
                $case,
                array_replace_recursive($suiteOptions, $caseOptions, $runOverrides),
                $runOverrides,
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $runOverrides
     * @return array<string,mixed>
     */
    private function withCaseValidationDefaults(AtlasEngineeringBenchmarkCase $case, array $options, array $runOverrides): array
    {
        if ($this->nonEmptyString($options['test_command'] ?? null) !== null) {
            return $options;
        }

        if (array_key_exists('test_command', $runOverrides)) {
            return $options;
        }

        $testCommands = (array) data_get($case->task_contract_json ?? [], 'test_commands', []);
        foreach ($testCommands as $command) {
            $command = $this->nonEmptyString($command);
            if ($command !== null) {
                $options['test_command'] = $command;
                $options['test_command_source'] = 'case_task_contract';

                return $options;
            }
        }

        return $options;
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
        $options = $this->withFairClaudeDefaults($options);
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
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function withFairClaudeDefaults(array $options): array
    {
        $claudeOnly = (bool) ($options['claude_only'] ?? false);
        $fairMode = (bool) ($options['fair_mode'] ?? false)
            || $claudeOnly
            || (bool) ($options['single_provider'] ?? false)
            || (bool) ($options['no_decide'] ?? false)
            || (bool) ($options['fallback_disabled'] ?? false);
        if (! $fairMode) {
            return $options;
        }

        $explicitProvider = $this->nonEmptyString($options['provider'] ?? null);
        if ($explicitProvider !== null && $explicitProvider !== FairClaudePolicy::PROVIDER_LOCK) {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires provider '
                .FairClaudePolicy::PROVIDER_LOCK
                .', got '.$explicitProvider.'.'
            );
        }

        $explicitModel = $this->nonEmptyString($options['model'] ?? null);
        if ($explicitModel !== null && ! $this->fairClaudeModelLocked($explicitModel)) {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires Claude Opus model, got '.$explicitModel.'.'
            );
        }

        $explicitModelPolicy = $this->nonEmptyString($options['model_policy'] ?? null);
        if ($explicitModelPolicy !== null && strtolower($explicitModelPolicy) !== 'fixed') {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires model_policy=fixed, got '.$explicitModelPolicy.'.'
            );
        }

        $options['fair_mode'] = true;
        if ($claudeOnly) {
            $options['claude_only'] = true;
        }
        $options['single_provider'] = true;
        $options['no_decide'] = true;
        $options['fallback_disabled'] = true;
        $options['require_pass_without_human'] = true;
        $options['provider'] = FairClaudePolicy::PROVIDER_LOCK;
        $options['model'] = $explicitModel ?? FairClaudePolicy::MODEL_LOCK;
        $options['model_policy'] = 'fixed';

        return $options;
    }

    /**
     * @return array{passed:bool,failure_summary:?string}
     */
    private function evaluate(AtlasEngineeringBenchmarkCase $case, ?string $decision, ?int $score, ?array $fairScorecard = null): array
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

        if (is_array($fairScorecard) && (bool) ($fairScorecard['required'] ?? false) && ! (bool) ($fairScorecard['passed'] ?? false)) {
            $reasons = implode(', ', array_map('strval', (array) ($fairScorecard['blocking_reasons'] ?? [])));
            $failures[] = 'fair_scorecard_failed'.($reasons !== '' ? ': '.$reasons : '');
        }

        return [
            'passed' => $failures === [],
            'failure_summary' => $failures === [] ? null : implode('; ', $failures),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>|null
     */
    private function fairScorecard(array $payload, array $runnerOptions): ?array
    {
        $fairMode = (bool) ($runnerOptions['fair_mode'] ?? false)
            || (bool) ($runnerOptions['claude_only'] ?? false)
            || (bool) data_get($payload, 'run.fair_mode.fair_mode')
            || (bool) data_get($payload, 'run.fair_mode_result');

        if (! $fairMode) {
            return null;
        }

        $fairResult = is_array(data_get($payload, 'run.fair_mode_result'))
            ? data_get($payload, 'run.fair_mode_result')
            : [];
        $provider = data_get($payload, 'run.model_selection.selected_provider')
            ?: data_get($payload, 'run.attempts.0.provider');
        $model = data_get($payload, 'run.model_selection.selected_model')
            ?: data_get($payload, 'run.attempts.0.model');
        $required = (bool) ($runnerOptions['require_pass_without_human'] ?? true);
        $providerLocked = $provider === 'claude_cli';
        $modelLocked = $this->fairClaudeModelLocked($model);
        $reportedPassWithoutHuman = (bool) ($fairResult['pass_without_human'] ?? false);
        $humanInterventionCount = max(0, (int) ($fairResult['human_intervention_count'] ?? 0));
        $deterministicGatesPassed = (bool) ($fairResult['deterministic_gates_passed'] ?? false);
        $protocolValid = (string) ($fairResult['status'] ?? 'unverified') === 'valid';
        $harnessStatus = (string) data_get($payload, 'run.status', 'unknown');
        $harnessDecision = (string) data_get($payload, 'run.decision', 'unknown');
        $harnessGatesPassed = $harnessStatus === 'passed' && $harnessDecision === 'resolved';
        $attemptCount = (int) (data_get($payload, 'run.attempt_count') ?: count((array) data_get($payload, 'run.attempts', [])));
        $attemptCount = max(0, $attemptCount);
        $repairAttemptCount = max(0, $attemptCount - 1);
        $scopeSafety = $this->fairScopeSafety($payload);
        $passWithoutHuman = $reportedPassWithoutHuman && $deterministicGatesPassed && $harnessGatesPassed && $scopeSafety['verified'];
        $blockingReasons = [];

        if (! $providerLocked) {
            $blockingReasons[] = 'provider_not_locked_to_claude_cli';
        }
        if (! $modelLocked) {
            $blockingReasons[] = 'model_not_locked_to_opus';
        }
        if (! $protocolValid) {
            $blockingReasons[] = 'fair_protocol_not_valid';
        }
        if (! $deterministicGatesPassed) {
            $blockingReasons[] = 'deterministic_gates_not_passed';
        }
        if (! $harnessGatesPassed) {
            $blockingReasons[] = 'harness_gates_not_passed';
        }
        if (! $passWithoutHuman) {
            $blockingReasons[] = 'pass_without_human_false';
        }
        foreach ($scopeSafety['blocking_reasons'] as $reason) {
            $blockingReasons[] = $reason;
        }

        return [
            'required' => $required,
            'passed' => $providerLocked && $modelLocked && $protocolValid && $deterministicGatesPassed && $harnessGatesPassed && $passWithoutHuman,
            'fair_mode' => true,
            'provider_lock' => $provider,
            'model_lock' => $model,
            'protocol_status' => $fairResult['status'] ?? 'unverified',
            'protocol_valid' => $protocolValid,
            'deterministic_gates_passed' => $deterministicGatesPassed,
            'harness_gates_passed' => $harnessGatesPassed,
            'harness_status' => $harnessStatus,
            'harness_decision' => $harnessDecision,
            'final_gate_passed' => $deterministicGatesPassed && $harnessGatesPassed,
            'human_intervention_count' => $humanInterventionCount,
            'pass_without_human' => $passWithoutHuman,
            'pass_without_human_reported' => $reportedPassWithoutHuman,
            'provider_violation_count' => $providerLocked ? 0 : 1,
            'fallback_violation_count' => 0,
            'attempt_count' => $attemptCount,
            'repair_attempt_count' => $repairAttemptCount,
            'repair_used' => $repairAttemptCount > 0,
            'converted_to_green' => $repairAttemptCount > 0 && $protocolValid && $deterministicGatesPassed && $passWithoutHuman,
            'scope_safety' => $scopeSafety['summary'],
            'blocking_reasons' => array_values(array_unique(array_merge(
                $blockingReasons,
                array_map('strval', (array) ($fairResult['blocking_reasons'] ?? [])),
            ))),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{verified:bool,summary:array<string,mixed>,blocking_reasons:array<int,string>}
     */
    private function fairScopeSafety(array $payload): array
    {
        $isolatedPatchApply = data_get($payload, 'run.scope_safety');
        if (! is_array($isolatedPatchApply) || $isolatedPatchApply === []) {
            return [
                'verified' => false,
                'summary' => [
                    'status' => 'missing',
                    'reason' => 'isolated_patch_apply_missing',
                    'dirty_overlap' => [],
                    'untracked_overlap' => [],
                    'modified_overlap' => [],
                    'untracked_files' => [],
                    'profile_status' => null,
                ],
                'blocking_reasons' => ['scope_safety_unverified'],
            ];
        }

        $status = (string) ($isolatedPatchApply['status'] ?? '');
        $reason = $this->nonEmptyString($isolatedPatchApply['reason'] ?? null);
        $profile = is_array($isolatedPatchApply['scope_safety'] ?? null) ? $isolatedPatchApply['scope_safety'] : [];
        $profileSafe = (bool) ($profile['safe'] ?? true);
        $dirtyOverlap = array_values((array) ($isolatedPatchApply['dirty_overlap'] ?? $profile['dirty_overlap'] ?? []));
        $untrackedOverlap = array_values((array) ($isolatedPatchApply['untracked_overlap'] ?? $profile['untracked_overlap'] ?? []));
        $modifiedOverlap = array_values((array) ($profile['modified_overlap'] ?? array_values(array_diff($dirtyOverlap, $untrackedOverlap))));
        $untrackedFiles = array_values((array) ($profile['untracked_files'] ?? []));

        $summary = [
            'status' => $status,
            'reason' => $reason,
            'dirty_overlap' => $dirtyOverlap,
            'untracked_overlap' => $untrackedOverlap,
            'modified_overlap' => $modifiedOverlap,
            'untracked_files' => $untrackedFiles,
            'profile_status' => $profile['status'] ?? null,
        ];

        $verified = match ($status) {
            'applied' => true,
            'no_patch' => true,
            'not_applicable' => $profileSafe,
            default => false,
        };

        $blocking = [];
        if ($dirtyOverlap !== []) {
            $blocking[] = 'dirty_state_overlap';
        }
        if ($status === 'blocked' && $reason === 'possible_secret_in_diff') {
            $blocking[] = 'possible_secret_in_diff';
        }
        if ($status === 'failed') {
            $blocking[] = 'isolated_patch_apply_failed';
        }
        if (! $verified) {
            $blocking[] = 'scope_safety_unverified';
        }

        return [
            'verified' => $verified,
            'summary' => $summary,
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
    }

    private function fairClaudeModelLocked(mixed $model): bool
    {
        if (! is_string($model) || trim($model) === '') {
            return false;
        }

        $model = strtolower(trim($model));
        $configured = strtolower(trim((string) config('atlas.ai.providers.claude_cli.premium_model', '')));

        return $model === FairClaudePolicy::MODEL_LOCK
            || ($configured !== '' && $model === $configured);
    }

    /**
     * @param  array{passed:bool,failure_summary:?string}  $atlasEvaluation
     * @param  array<string,mixed>|null  $fairScorecard
     * @param  array<string,mixed>|null  $claudeCodeBaseline
     * @return array<string,mixed>|null
     */
    private function pairedScorecard(
        AtlasEngineeringBenchmarkCase $case,
        array $atlasEvaluation,
        ?array $fairScorecard,
        ?array $claudeCodeBaseline,
        ?string $atlasDecision,
        ?int $atlasScore,
    ): ?array {
        if (! is_array($claudeCodeBaseline) || ! (bool) ($claudeCodeBaseline['enabled'] ?? false)) {
            return null;
        }

        $expectedDecision = $case->expected_decision ?: 'resolved';
        $minScore = (int) ($case->min_score ?? 85);
        $baselineStatus = (string) ($claudeCodeBaseline['status'] ?? 'unknown');
        $baselineExecuted = (bool) ($claudeCodeBaseline['executed'] ?? false);
        $baselineDecision = $this->nonEmptyString($claudeCodeBaseline['decision'] ?? null);
        $baselineScore = is_numeric($claudeCodeBaseline['score'] ?? null) ? (int) $claudeCodeBaseline['score'] : null;
        $baselineDeterministic = (bool) ($claudeCodeBaseline['deterministic_gates_passed'] ?? false);
        $baselinePassWithoutHuman = (bool) ($claudeCodeBaseline['pass_without_human'] ?? false);
        $baselineVerified = $baselineExecuted
            && $baselineStatus === 'completed'
            && $baselineDeterministic
            && $baselinePassWithoutHuman;
        $atlasProtocolInvalidReasons = $this->atlasFairProtocolInvalidReasons($fairScorecard);
        $atlasProtocolValid = $atlasProtocolInvalidReasons === [];

        $atlasPassed = (bool) ($atlasEvaluation['passed'] ?? false);
        $atlasFairPassed = $fairScorecard === null || ! (bool) ($fairScorecard['required'] ?? false) || (bool) ($fairScorecard['passed'] ?? false);
        $atlasVerified = $atlasProtocolValid && $atlasPassed && $atlasFairPassed;
        $baselinePassed = $baselineVerified
            && $baselineDecision === $expectedDecision
            && $baselineScore !== null
            && $baselineScore >= $minScore;

        $comparisonStatus = match (true) {
            ! $atlasProtocolValid => 'atlas_protocol_invalid',
            $baselineStatus === 'planned' => 'baseline_planned',
            ! $baselineExecuted => 'baseline_not_executed',
            $baselineStatus !== 'completed' => 'baseline_failed',
            ! $baselineDeterministic => 'baseline_unverified',
            ! $baselinePassWithoutHuman => 'baseline_human_intervention',
            $baselineDecision !== $expectedDecision => 'comparable',
            $baselineScore === null || $baselineScore < $minScore => 'comparable',
            default => 'comparable',
        };
        $comparable = $comparisonStatus === 'comparable';
        $winner = null;

        if ($comparable) {
            $winner = match (true) {
                $atlasVerified && ! $baselinePassed => 'atlas',
                ! $atlasVerified && $baselinePassed => 'claude_code_baseline',
                $atlasVerified && $baselinePassed && $atlasScore !== null && $baselineScore !== null && $atlasScore > $baselineScore => 'atlas',
                $atlasVerified && $baselinePassed && $atlasScore !== null && $baselineScore !== null && $atlasScore < $baselineScore => 'claude_code_baseline',
                $atlasVerified === $baselinePassed => 'tie',
                default => null,
            };
        }

        return [
            'schema_version' => 1,
            'fair_mode' => $fairScorecard !== null,
            'case' => [
                'id' => $case->id,
                'case_code' => $case->case_code,
                'corpus_tier' => $case->corpus_tier,
                'domain_slug' => $case->domain_slug,
                'risk_profile' => $case->risk_profile,
            ],
            'comparison_status' => $comparisonStatus,
            'comparable' => $comparable,
            'winner' => $winner,
            'atlas' => [
                'provider' => 'atlas',
                'decision' => $atlasDecision,
                'score' => $atlasScore,
                'passed' => $atlasPassed,
                'verified' => $atlasVerified,
                'fair_scorecard_passed' => $fairScorecard === null ? null : (bool) ($fairScorecard['passed'] ?? false),
                'protocol_valid' => $fairScorecard === null ? null : (bool) ($fairScorecard['protocol_valid'] ?? false),
                'final_gate_passed' => $fairScorecard === null ? null : (bool) ($fairScorecard['final_gate_passed'] ?? false),
                'pass_without_human' => $fairScorecard === null ? null : (bool) ($fairScorecard['pass_without_human'] ?? $fairScorecard['passed'] ?? false),
                'human_intervention_count' => $fairScorecard === null ? null : (int) ($fairScorecard['human_intervention_count'] ?? 0),
                'provider_violation_count' => $fairScorecard === null ? 0 : (int) ($fairScorecard['provider_violation_count'] ?? 0),
                'fallback_violation_count' => $fairScorecard === null ? 0 : (int) ($fairScorecard['fallback_violation_count'] ?? 0),
                'attempt_count' => $fairScorecard === null ? null : (int) ($fairScorecard['attempt_count'] ?? 0),
                'repair_attempt_count' => $fairScorecard === null ? null : (int) ($fairScorecard['repair_attempt_count'] ?? 0),
                'repair_used' => $fairScorecard === null ? null : (bool) ($fairScorecard['repair_used'] ?? false),
                'converted_to_green' => $fairScorecard === null ? null : (bool) ($fairScorecard['converted_to_green'] ?? false),
            ],
            'claude_code_baseline' => [
                'provider' => $claudeCodeBaseline['provider'] ?? null,
                'model' => $claudeCodeBaseline['model'] ?? null,
                'status' => $baselineStatus,
                'executed' => $baselineExecuted,
                'decision' => $baselineDecision,
                'score' => $baselineScore,
                'deterministic_gates_passed' => $baselineDeterministic,
                'pass_without_human' => $baselinePassWithoutHuman,
                'verified' => $baselineVerified,
                'passed' => $baselinePassed,
                'duration_ms' => is_numeric($claudeCodeBaseline['duration_ms'] ?? null) ? (int) $claudeCodeBaseline['duration_ms'] : null,
            ],
            'deltas' => [
                'score' => $atlasScore !== null && $baselineScore !== null ? $atlasScore - $baselineScore : null,
            ],
            'blocking_reasons' => $this->pairedScorecardBlockingReasons(
                $comparisonStatus,
                $atlasVerified,
                $baselineVerified,
                $claudeCodeBaseline,
                $atlasProtocolInvalidReasons,
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $claudeCodeBaseline
     * @param  array<int,string>  $atlasProtocolInvalidReasons
     * @return array<int,string>
     */
    private function pairedScorecardBlockingReasons(
        string $comparisonStatus,
        bool $atlasVerified,
        bool $baselineVerified,
        array $claudeCodeBaseline,
        array $atlasProtocolInvalidReasons = [],
    ): array {
        $reasons = [];
        if ($comparisonStatus !== 'comparable') {
            $reasons[] = $comparisonStatus;
        }
        if (! $atlasVerified) {
            $reasons[] = 'atlas_not_verified_pass';
        }
        if (! $baselineVerified) {
            $reasons[] = 'baseline_not_verified_pass';
        }

        return array_values(array_unique(array_merge(
            $reasons,
            $atlasProtocolInvalidReasons,
            array_map('strval', (array) ($claudeCodeBaseline['blocking_reasons'] ?? [])),
        )));
    }

    /**
     * Invalid fair-protocol cases are excluded from win/loss math. A benchmark
     * may only compare outputs after proving both arms were measured on a clean,
     * equivalent protocol.
     *
     * @param  array<string,mixed>|null  $fairScorecard
     * @return array<int,string>
     */
    private function atlasFairProtocolInvalidReasons(?array $fairScorecard): array
    {
        if ($fairScorecard === null || ! (bool) ($fairScorecard['required'] ?? false)) {
            return [];
        }

        $reasons = [];
        $protocolValid = (bool) data_get($fairScorecard, 'protocol_valid', data_get($fairScorecard, 'passed', false));
        if (! $protocolValid) {
            $reasons[] = 'fair_protocol_not_valid';
        }
        if ((int) data_get($fairScorecard, 'provider_violation_count', 0) > 0) {
            $reasons[] = 'provider_lock_violation';
        }
        if ((int) data_get($fairScorecard, 'fallback_violation_count', 0) > 0) {
            $reasons[] = 'fallback_violation';
        }

        $contaminationReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
        ];
        foreach ((array) data_get($fairScorecard, 'blocking_reasons', []) as $reason) {
            if (is_string($reason) && in_array($reason, $contaminationReasons, true)) {
                $reasons[] = $reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     * @return array{runner_options:array<string,mixed>,baseline_plan:array<string,mixed>|null,artifact:array<string,mixed>|null}
     */
    private function preparePairedBaselineWorkspace(AtlasEngineeringBenchmarkCase $case, array $runnerOptions): array
    {
        if (! $this->baselineRunRequested($runnerOptions) || $this->hasExplicitBaselineWorkspace($runnerOptions)) {
            return [
                'runner_options' => $runnerOptions,
                'baseline_plan' => null,
                'artifact' => null,
            ];
        }

        $workspace = $this->workspaceFrom($runnerOptions['workspace'] ?? null);
        if ($workspace === null) {
            throw new InvalidArgumentException('Claude Code baseline auto worktree requires workspace.');
        }

        $plan = $this->workspaces->preparePairedWorktree(
            $workspace,
            'claude-code-baseline-'.$case->case_code,
        );

        $artifact = [
            'schema_version' => 1,
            'mode' => 'paired_git_worktree',
            'auto_prepared' => true,
            'claude_code_baseline' => $this->compactWorkspacePlan($plan),
        ];

        if ((string) ($plan['status'] ?? '') !== 'ready' || ! (bool) ($plan['isolated'] ?? false)) {
            throw new InvalidArgumentException('Claude Code baseline auto worktree failed: '.(string) ($plan['failure_reason'] ?? $plan['fallback_reason'] ?? 'unknown'));
        }

        $runnerOptions['claude_code_baseline_workspace'] = (string) $plan['execution_workspace'];
        $runnerOptions['claude_code_baseline_workspace_auto_prepared'] = true;
        $runnerOptions['claude_code_baseline_workspace_isolation_type'] = 'git_worktree';

        return [
            'runner_options' => $runnerOptions,
            'baseline_plan' => $plan,
            'artifact' => $artifact,
        ];
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function baselineRunRequested(array $runnerOptions): bool
    {
        $raw = $runnerOptions['claude_code_baseline'] ?? $runnerOptions['baseline_runner'] ?? $runnerOptions['claude_code_baseline_mode'] ?? 'off';
        if ($raw === true) {
            return false;
        }

        return strtolower(trim((string) $raw)) === 'run';
    }

    /**
     * @param  array<string,mixed>  $runnerOptions
     */
    private function hasExplicitBaselineWorkspace(array $runnerOptions): bool
    {
        return isset($runnerOptions['claude_code_baseline_workspace'])
            && is_string($runnerOptions['claude_code_baseline_workspace'])
            && trim($runnerOptions['claude_code_baseline_workspace']) !== '';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>
     */
    private function releasePairedBaselineWorkspace(array $plan, array $runnerOptions): array
    {
        return $this->workspaces->release($plan, (bool) ($runnerOptions['keep_workspace'] ?? false));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function compactWorkspacePlan(array $plan): array
    {
        $original = $this->nonEmptyString($plan['original_workspace'] ?? null);
        $execution = $this->nonEmptyString($plan['execution_workspace'] ?? null);
        $repoRoot = $this->nonEmptyString($plan['repo_root'] ?? null);
        $dirtyFiles = (array) ($plan['dirty_files'] ?? []);

        return [
            'status' => $plan['status'] ?? null,
            'mode' => $plan['mode'] ?? null,
            'pair_label' => $plan['pair_label'] ?? null,
            'original_workspace_hash' => $original ? hash('sha256', $original) : null,
            'execution_workspace_hash' => $execution ? hash('sha256', $execution) : null,
            'repo_root_hash' => $repoRoot ? hash('sha256', $repoRoot) : null,
            'branch' => $plan['branch'] ?? null,
            'head' => $plan['head'] ?? null,
            'isolated' => (bool) ($plan['isolated'] ?? false),
            'isolation_type' => $plan['isolation_type'] ?? null,
            'worktree_path_hash' => $plan['worktree_path_hash'] ?? null,
            'dirty_files_count' => count($dirtyFiles),
            'dirty_files_hash' => hash('sha256', json_encode(array_values($dirtyFiles), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'dirty_files_included' => (bool) ($plan['dirty_files_included'] ?? false),
            'failure_reason' => $plan['failure_reason'] ?? $plan['fallback_reason'] ?? null,
            'created_at' => $plan['created_at'] ?? null,
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
        $claudeCodeBaselineSummary = $this->claudeCodeBaselineSummary($results);
        $pairedScorecardSummary = $this->pairedScorecardSummary($results, (int) ($quality['cost_microusd'] ?? 0));
        $replayManifest = $this->persistReplayManifestArtifact($run, $this->replayManifestSummary(
            run: $run,
            results: $results,
            status: $status,
            quality: $quality,
            releaseGate: $releaseGate,
        ));

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
                'claude_code_baseline' => $claudeCodeBaselineSummary,
                'paired_scorecard' => $pairedScorecardSummary,
                'replay_manifest' => $replayManifest,
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
        $summary = $this->safeReplayManifestSummaryForApi(
            $this->withReplayManifestArtifactVerification($this->arrayValue($run->summary_json ?? [])),
        );

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
            'summary' => $summary,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function replayManifestPayload(AtlasEngineeringBenchmarkRun $run): array
    {
        $summary = $this->withReplayManifestArtifactVerification($this->arrayValue($run->summary_json ?? []));
        $manifest = $this->arrayValue(data_get($summary, 'replay_manifest', []));
        $artifact = $this->arrayValue(data_get($manifest, 'artifact', []));
        $safeArtifact = $this->safeReplayManifestArtifact($artifact);

        if (! (bool) ($manifest['enabled'] ?? false)) {
            return [
                'status' => 'unavailable',
                'reason' => 'replay_manifest_disabled',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $integrity = $this->arrayValue(data_get($artifact, 'integrity', []));
        if (($artifact['status'] ?? null) !== 'persisted' || ! (bool) ($integrity['hash_matches'] ?? false)) {
            return [
                'status' => 'unavailable',
                'reason' => (string) ($integrity['reason'] ?? 'artifact_integrity_failed'),
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $path = $this->resolveReplayManifestArtifactPath($this->nonEmptyString($artifact['path'] ?? null));
        if ($path === null || ! File::isFile($path)) {
            return [
                'status' => 'unavailable',
                'reason' => 'artifact_not_readable',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            return [
                'status' => 'unavailable',
                'reason' => 'artifact_json_decode_failed',
                'artifact' => $safeArtifact,
                'summary' => Arr::except($manifest, ['artifact', 'packets']),
                'final_packet' => data_get($manifest, 'final_packet'),
                'replay_manifest' => null,
            ];
        }

        return [
            'status' => 'available',
            'artifact' => $safeArtifact,
            'summary' => Arr::except($manifest, ['artifact', 'packets']),
            'final_packet' => data_get($decoded, 'final_packet', data_get($manifest, 'final_packet')),
            'replay_manifest' => $decoded,
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
            'task_contract' => $case->task_contract_json,
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
     * @param  Collection<int,AtlasEngineeringBenchmarkResult>  $results
     * @return array<string,mixed>
     */
    private function claudeCodeBaselineSummary(Collection $results): array
    {
        $baselines = $results
            ->map(fn (AtlasEngineeringBenchmarkResult $result): mixed => data_get($result->observed_json ?? [], 'claude_code_baseline'))
            ->filter(fn (mixed $baseline): bool => is_array($baseline) && (bool) ($baseline['enabled'] ?? false))
            ->values();

        if ($baselines->isEmpty()) {
            return [
                'enabled' => false,
                'case_count' => 0,
            ];
        }

        return [
            'enabled' => true,
            'case_count' => $baselines->count(),
            'planned_count' => $baselines->where('status', 'planned')->count(),
            'executed_count' => $baselines->where('executed', true)->count(),
            'completed_count' => $baselines->where('status', 'completed')->count(),
            'failed_count' => $baselines->where('status', 'failed')->count(),
            'providers' => $baselines->pluck('provider')->filter()->unique()->values()->all(),
            'models' => $baselines->pluck('model')->filter()->unique()->values()->all(),
            'prompt_hashes' => $baselines->pluck('prompt_hash')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkResult>  $results
     * @return array<string,mixed>
     */
    private function pairedScorecardSummary(Collection $results, ?int $costMicrousd = null): array
    {
        $scorecards = $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): mixed {
                $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
                if (! is_array($scorecard)) {
                    return $scorecard;
                }
                if (! is_numeric(data_get($scorecard, 'atlas.duration_ms')) && is_numeric($result->duration_ms)) {
                    data_set($scorecard, 'atlas.duration_ms', (int) $result->duration_ms);
                }

                return $this->normalizePairedScorecardForReport($scorecard);
            })
            ->filter(fn (mixed $scorecard): bool => is_array($scorecard))
            ->values();

        if ($scorecards->isEmpty()) {
            return [
                'enabled' => false,
                'case_count' => 0,
                'protocol_validity_rate' => null,
                'pass_without_human_rate' => null,
                'pass_without_human_rate_medium_hard' => null,
                'repair_conversion_rate' => null,
                'final_gate_pass_rate' => null,
                'intervention_reduction' => null,
                'autonomous_success_lift' => null,
                'time_to_green' => null,
                'cost_per_green_case' => null,
                'invalid_case_count' => 0,
                'provider_violation_count' => 0,
                'fallback_violation_count' => 0,
            ];
        }

        $comparable = $scorecards->where('comparable', true);
        $caseCount = $scorecards->count();
        $atlasPassWithoutHumanCount = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human'))
            ->count();
        $baselinePassWithoutHumanCount = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'claude_code_baseline.verified')
                && (bool) data_get($scorecard, 'claude_code_baseline.pass_without_human'))
            ->count();
        $atlasHumanInterventions = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.human_intervention_count', 0)));
        $baselineHumanInterventions = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'claude_code_baseline.human_intervention_count', 0)));
        $repairUsed = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.repair_used', ((int) data_get($scorecard, 'atlas.repair_attempt_count', 0)) > 0))
            ->values();
        $repairConvertedCount = $repairUsed
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.converted_to_green'))
            ->count();
        $atlasGreenDurations = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human')
                && is_numeric(data_get($scorecard, 'atlas.duration_ms')))
            ->map(fn (array $scorecard): int => (int) data_get($scorecard, 'atlas.duration_ms'))
            ->values();
        $baselineGreenDurations = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'claude_code_baseline.verified')
                && (bool) data_get($scorecard, 'claude_code_baseline.pass_without_human')
                && is_numeric(data_get($scorecard, 'claude_code_baseline.duration_ms')))
            ->map(fn (array $scorecard): int => (int) data_get($scorecard, 'claude_code_baseline.duration_ms'))
            ->values();
        $winners = $scorecards
            ->pluck('winner')
            ->filter(fn (mixed $winner): bool => is_string($winner) && $winner !== '')
            ->countBy()
            ->all();

        return [
            'enabled' => true,
            'case_count' => $scorecards->count(),
            'fair_mode_count' => $scorecards
                ->filter(fn (array $scorecard): bool => (bool) ($scorecard['fair_mode'] ?? false))
                ->count(),
            'comparable_count' => $comparable->count(),
            'inconclusive_count' => max(0, $scorecards->count() - $comparable->count()),
            'comparison_statuses' => $scorecards
                ->pluck('comparison_status')
                ->filter()
                ->countBy()
                ->all(),
            'winners' => $winners,
            'atlas_win_count' => (int) ($winners['atlas'] ?? 0),
            'claude_code_baseline_win_count' => (int) ($winners['claude_code_baseline'] ?? 0),
            'tie_count' => (int) ($winners['tie'] ?? 0),
            'protocol_validity_rate' => $this->rate($scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
                ->count(), $caseCount),
            'pass_without_human_rate' => $this->rate($atlasPassWithoutHumanCount, $caseCount),
            'pass_without_human_rate_medium_hard' => $this->rate($scorecards
                ->filter(fn (array $scorecard): bool => in_array(data_get($scorecard, 'case.risk_profile'), ['medium', 'high', 'critical'], true)
                    && (bool) data_get($scorecard, 'atlas.verified')
                    && (bool) data_get($scorecard, 'atlas.pass_without_human'))
                ->count(), max(0, $scorecards
                ->filter(fn (array $scorecard): bool => in_array(data_get($scorecard, 'case.risk_profile'), ['medium', 'high', 'critical'], true))
                ->count())),
            'repair_conversion_rate' => $this->rate($repairConvertedCount, $repairUsed->count()),
            'final_gate_pass_rate' => $this->rate($scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                    && (bool) data_get($scorecard, 'atlas.final_gate_passed'))
                ->count(), $caseCount),
            'intervention_reduction' => $atlasHumanInterventions > 0
                ? round($baselineHumanInterventions / $atlasHumanInterventions, 2)
                : ($baselineHumanInterventions > 0 ? null : 1.0),
            'autonomous_success_lift' => round(
                ($this->rate($atlasPassWithoutHumanCount, $caseCount) ?? 0.0)
                - ($this->rate($baselinePassWithoutHumanCount, $caseCount) ?? 0.0),
                2,
            ),
            'time_to_green' => [
                'atlas_avg_ms' => $atlasGreenDurations->isNotEmpty() ? (int) round($atlasGreenDurations->avg()) : null,
                'claude_code_baseline_avg_ms' => $baselineGreenDurations->isNotEmpty() ? (int) round($baselineGreenDurations->avg()) : null,
                'atlas_green_case_count' => $atlasGreenDurations->count(),
                'claude_code_baseline_green_case_count' => $baselineGreenDurations->count(),
            ],
            'cost_per_green_case' => $costMicrousd !== null && $costMicrousd > 0 && $atlasPassWithoutHumanCount > 0
                ? [
                    'microusd' => (int) round($costMicrousd / $atlasPassWithoutHumanCount),
                    'usd' => round(($costMicrousd / $atlasPassWithoutHumanCount) / 1_000_000, 6),
                    'green_case_count' => $atlasPassWithoutHumanCount,
                    'source_cost_microusd' => $costMicrousd,
                ]
                : null,
            'invalid_case_count' => $scorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'fair_mode')
                    && ! (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
                ->count(),
            'provider_violation_count' => $scorecards
                ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.provider_violation_count', 0))),
            'fallback_violation_count' => $scorecards
                ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.fallback_violation_count', 0))),
            'atlas_pass_without_human_rate' => $this->rate($atlasPassWithoutHumanCount, $caseCount),
            'baseline_pass_without_human_rate' => $this->rate($baselinePassWithoutHumanCount, $caseCount),
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkResult>  $results
     * @return Collection<int,array<string,mixed>>
     */
    private function fairClaudeCaseComparisons(Collection $results, int $limit): Collection
    {
        return $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): ?array {
                $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
                if (! is_array($scorecard)) {
                    return null;
                }
                $scorecard = $this->normalizePairedScorecardForReport($scorecard);

                $atlas = $this->arrayValue(data_get($scorecard, 'atlas', []));
                $baseline = $this->arrayValue(data_get($scorecard, 'claude_code_baseline', []));
                $case = $this->arrayValue(data_get($scorecard, 'case', []));
                $benchmarkCase = $result->benchmarkCase;
                $winner = data_get($scorecard, 'winner');
                $comparisonStatus = (string) data_get($scorecard, 'comparison_status', 'unknown');
                $blockingReasons = collect((array) data_get($scorecard, 'blocking_reasons', []))
                    ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                    ->values()
                    ->all();

                return [
                    'schema_version' => 1,
                    'result_id' => $result->id,
                    'benchmark_run_id' => $result->benchmark_run_id,
                    'case_id' => $result->case_id,
                    'case_code' => $benchmarkCase?->case_code ?? data_get($case, 'case_code'),
                    'title' => $benchmarkCase?->title ?? data_get($case, 'title'),
                    'domain_slug' => $benchmarkCase?->domain_slug ?? data_get($case, 'domain_slug'),
                    'risk_profile' => $benchmarkCase?->risk_profile ?? data_get($case, 'risk_profile'),
                    'corpus_tier' => $benchmarkCase?->corpus_tier ?? data_get($case, 'corpus_tier'),
                    'status' => $result->status,
                    'passed' => (bool) $result->passed,
                    'decision' => $result->decision,
                    'score' => $result->score,
                    'comparison_status' => $comparisonStatus,
                    'comparable' => (bool) data_get($scorecard, 'comparable', false),
                    'winner' => is_string($winner) && $winner !== '' ? $winner : null,
                    'winner_reason' => $this->fairClaudeCaseWinnerReason($scorecard, $result),
                    'blocking_reasons' => $blockingReasons,
                    'atlas' => [
                        'verified' => (bool) data_get($atlas, 'verified', data_get($atlas, 'protocol_valid', false)),
                        'passed' => (bool) data_get($atlas, 'passed', $result->passed),
                        'pass_without_human' => (bool) data_get($atlas, 'pass_without_human', data_get($atlas, 'verified', false)),
                        'score' => $this->nullableInt(data_get($atlas, 'score', $result->score)),
                        'duration_ms' => $this->nullableInt(data_get($atlas, 'duration_ms', $result->duration_ms)),
                        'attempt_count' => $this->nullableInt(data_get($atlas, 'attempt_count')),
                        'repair_attempt_count' => $this->nullableInt(data_get($atlas, 'repair_attempt_count')),
                        'repair_used' => (bool) data_get($atlas, 'repair_used', ((int) data_get($atlas, 'repair_attempt_count', 0)) > 0),
                        'converted_to_green' => (bool) data_get($atlas, 'converted_to_green', false),
                        'provider_violation_count' => max(0, (int) data_get($atlas, 'provider_violation_count', 0)),
                        'fallback_violation_count' => max(0, (int) data_get($atlas, 'fallback_violation_count', 0)),
                        'human_intervention_count' => max(0, (int) data_get($atlas, 'human_intervention_count', 0)),
                    ],
                    'claude_code_baseline' => [
                        'verified' => (bool) data_get($baseline, 'verified', false),
                        'passed' => (bool) data_get($baseline, 'passed', data_get($baseline, 'verified', false)),
                        'pass_without_human' => (bool) data_get($baseline, 'pass_without_human', data_get($baseline, 'verified', false)),
                        'score' => $this->nullableInt(data_get($baseline, 'score')),
                        'duration_ms' => $this->nullableInt(data_get($baseline, 'duration_ms')),
                        'human_intervention_count' => max(0, (int) data_get($baseline, 'human_intervention_count', 0)),
                        'executed' => (bool) data_get($result->observed_json ?? [], 'claude_code_baseline.executed', false),
                        'status' => $this->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.status')),
                        'error_code' => $this->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.error_code')),
                        'gate_status' => $this->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.status')),
                        'gate_reason' => $this->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.reason')),
                        'gate_stderr_excerpt' => $this->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.stderr_excerpt')),
                    ],
                    'deltas' => [
                        'score' => $this->nullableInt(data_get($scorecard, 'deltas.score')),
                        'duration_ms' => $this->nullableInt(data_get($scorecard, 'deltas.duration_ms')),
                        'human_intervention_count' => $this->nullableInt(data_get($scorecard, 'deltas.human_intervention_count')),
                    ],
                    'failure_summary' => $result->failure_summary,
                    'created_at' => $result->created_at?->toJSON(),
                ];
            })
            ->filter()
            ->sortBy([
                fn (array $comparison): int => $this->fairClaudeCaseComparisonRank($comparison),
                fn (array $comparison): string => (string) ($comparison['case_code'] ?? $comparison['case_id'] ?? ''),
            ])
            ->take($this->benchmarkInput()->fairClaudeComparisonTakeLimit($limit))
            ->values();
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @return array<string,mixed>
     */
    private function normalizePairedScorecardForReport(array $scorecard): array
    {
        $invalidReasons = $this->pairedScorecardProtocolInvalidReasons($scorecard);
        if ($invalidReasons === []) {
            return $scorecard;
        }

        $scorecard['comparison_status'] = 'atlas_protocol_invalid';
        $scorecard['comparable'] = false;
        $scorecard['winner'] = null;
        data_set($scorecard, 'atlas.verified', false);
        $scorecard['blocking_reasons'] = array_values(array_unique(array_merge(
            ['atlas_protocol_invalid'],
            $invalidReasons,
            array_map('strval', (array) ($scorecard['blocking_reasons'] ?? [])),
        )));

        return $scorecard;
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @return array<int,string>
     */
    private function pairedScorecardProtocolInvalidReasons(array $scorecard): array
    {
        if (! (bool) ($scorecard['fair_mode'] ?? false)) {
            return [];
        }

        $reasons = [];
        if (! (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified', false))) {
            $reasons[] = 'fair_protocol_not_valid';
        }
        if ((int) data_get($scorecard, 'atlas.provider_violation_count', 0) > 0) {
            $reasons[] = 'provider_lock_violation';
        }
        if ((int) data_get($scorecard, 'atlas.fallback_violation_count', 0) > 0) {
            $reasons[] = 'fallback_violation';
        }

        $contaminationReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
        ];
        foreach ((array) ($scorecard['blocking_reasons'] ?? []) as $reason) {
            if (is_string($reason) && in_array($reason, $contaminationReasons, true)) {
                $reasons[] = $reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $scorecard
     */
    private function fairClaudeCaseWinnerReason(array $scorecard, AtlasEngineeringBenchmarkResult $result): string
    {
        $winner = (string) data_get($scorecard, 'winner', '');
        $status = (string) data_get($scorecard, 'comparison_status', 'unknown');

        if ($status !== 'comparable') {
            $reasons = collect((array) data_get($scorecard, 'blocking_reasons', []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->values()
                ->all();

            return $reasons === []
                ? 'not_comparable'
                : implode(',', $reasons);
        }

        return match ($winner) {
            'atlas' => 'atlas_verified_better_or_baseline_failed',
            'claude_code_baseline' => 'baseline_verified_better_or_atlas_failed',
            'tie' => 'both_verified_equivalent',
            default => $result->failure_summary ?: 'winner_not_declared',
        };
    }

    /**
     * @param  array<string,mixed>  $comparison
     */
    private function fairClaudeCaseComparisonRank(array $comparison): int
    {
        if (! (bool) ($comparison['comparable'] ?? false)) {
            return 0;
        }

        return match ((string) ($comparison['winner'] ?? '')) {
            'claude_code_baseline' => 1,
            'atlas' => 2,
            'tie' => 3,
            default => 4,
        };
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $runs
     * @return array<string,mixed>
     */
    private function fairClaudeReplayReport(Collection $runs): array
    {
        $manifests = $runs
            ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->arrayValue(data_get(
                $this->withReplayManifestArtifactVerification($this->arrayValue($run->summary_json ?? [])),
                'replay_manifest',
                [],
            )))
            ->filter(fn (array $manifest): bool => (bool) ($manifest['enabled'] ?? false))
            ->values();

        if ($manifests->isEmpty()) {
            return [
                'enabled' => false,
                'run_count' => 0,
                'packet_count' => 0,
                'artifact_integrity_passed_count' => 0,
                'artifact_integrity_failed_count' => 0,
            ];
        }

        $integrityPassed = $manifests
            ->filter(fn (array $manifest): bool => (bool) data_get($manifest, 'artifact.integrity.hash_matches'))
            ->count();

        return [
            'enabled' => true,
            'run_count' => $manifests->count(),
            'packet_count' => $manifests->sum(fn (array $manifest): int => (int) ($manifest['packet_count'] ?? 0)),
            'providers' => $manifests
                ->flatMap(fn (array $manifest): array => (array) ($manifest['providers'] ?? []))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'model_locks' => $manifests
                ->flatMap(fn (array $manifest): array => (array) ($manifest['model_locks'] ?? []))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'artifact_integrity_passed_count' => $integrityPassed,
            'artifact_integrity_failed_count' => max(0, $manifests->count() - $integrityPassed),
            'manifest_hashes' => $manifests
                ->pluck('manifest_hash')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $runs
     */
    private function runsCostMicrousd(Collection $runs): ?int
    {
        $cost = $runs
            ->sum(fn (AtlasEngineeringBenchmarkRun $run): int => max(0, (int) ($run->cost_microusd ?? data_get($run->summary_json ?? [], 'quality_metrics.cost_microusd', 0))));

        return $cost > 0 ? $cost : null;
    }

    private function rate(int|float $numerator, int|float $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 2);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function safeReplayManifestSummaryForReport(array $manifest): array
    {
        $artifact = $this->arrayValue($manifest['artifact'] ?? []);
        $safe = Arr::except($manifest, ['packets', 'artifact']);
        if ($artifact !== []) {
            $safe['artifact'] = $this->safeReplayManifestArtifact($artifact);
        }

        return $safe;
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $runs
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $replay
     * @return array<string,mixed>
     */
    private function fairClaudeReportReadiness(Collection $runs, array $paired, array $baseline, array $replay, array $corpusManifest): array
    {
        $blocking = [];
        $minimumReleaseCount = 6;
        $releaseCorpusCount = (int) data_get($corpusManifest, 'official_subsets.release', 0);
        $activeCorpusCount = (int) ($corpusManifest['active_cases'] ?? 0);
        $comparableCount = (int) ($paired['comparable_count'] ?? 0);
        $pairedEnabled = (bool) ($paired['enabled'] ?? false);

        if ($releaseCorpusCount < $minimumReleaseCount) {
            $blocking[] = 'fair_release_corpus_below_minimum';
        }
        if ($runs->isEmpty()) {
            $blocking[] = 'no_fair_claude_runs';
        }
        if (! $pairedEnabled) {
            $blocking[] = 'paired_scorecard_missing';
        }
        if ((int) ($paired['fair_mode_count'] ?? 0) === 0) {
            $blocking[] = 'fair_atlas_arm_missing';
        }
        if ($comparableCount === 0) {
            $blocking[] = 'no_comparable_cases';
        }
        if ($comparableCount > 0 && $comparableCount < $minimumReleaseCount) {
            $blocking[] = 'fair_comparable_cases_below_release_minimum';
        }
        if (! (bool) ($baseline['enabled'] ?? false) || (int) ($baseline['executed_count'] ?? 0) === 0) {
            $blocking[] = 'claude_code_baseline_not_executed';
        }
        if (! (bool) ($replay['enabled'] ?? false) || (int) ($replay['artifact_integrity_failed_count'] ?? 0) > 0) {
            $blocking[] = 'replay_manifest_not_fully_verified';
        }
        if ($pairedEnabled) {
            if ((float) ($paired['protocol_validity_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_protocol_validity_below_100';
            }
            if ((float) ($paired['final_gate_pass_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_final_gate_pass_rate_below_100';
            }
            if ((float) ($paired['pass_without_human_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_pass_without_human_rate_below_100';
            }
            if ((int) ($paired['provider_violation_count'] ?? 0) > 0) {
                $blocking[] = 'provider_lock_violation';
            }
            if ((int) ($paired['fallback_violation_count'] ?? 0) > 0) {
                $blocking[] = 'fallback_violation';
            }
        }

        $atlasWins = (int) ($paired['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? 0);
        $ties = (int) ($paired['tie_count'] ?? 0);
        $status = match (true) {
            $blocking !== [] => 'not_ready',
            $atlasWins > $baselineWins => 'atlas_leading',
            $baselineWins > $atlasWins => 'baseline_leading',
            $ties > 0 => 'tied',
            default => 'inconclusive',
        };

        return [
            'status' => $status,
            'ready_for_claim' => $blocking === [] && $atlasWins > $baselineWins,
            'blocking_reasons' => $blocking,
            'atlas_win_count' => $atlasWins,
            'claude_code_baseline_win_count' => $baselineWins,
            'tie_count' => $ties,
            'comparable_count' => $comparableCount,
            'fair_mode_count' => (int) ($paired['fair_mode_count'] ?? 0),
            'active_corpus_case_count' => $activeCorpusCount,
            'release_corpus_case_count' => $releaseCorpusCount,
            'minimum_release_corpus_case_count' => $minimumReleaseCount,
            'minimum_comparable_case_count' => $minimumReleaseCount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fairClaudeRunHistoryPayload(AtlasEngineeringBenchmarkRun $run, bool $officialFairScope): array
    {
        $summary = $this->arrayValue($run->summary_json ?? []);
        $runResults = $run->results instanceof Collection
            ? $run->results
            : collect($run->results ?? []);
        $fairResults = $runResults
            ->filter(fn (AtlasEngineeringBenchmarkResult $result): bool => (bool) data_get($result->observed_json ?? [], 'paired_scorecard.fair_mode'))
            ->values();
        $paired = $this->pairedScorecardSummary($fairResults, $this->runsCostMicrousd(collect([$run])));
        if (! (bool) ($paired['enabled'] ?? false)) {
            $paired = $this->arrayValue(data_get($summary, 'paired_scorecard', []));
        }
        $baseline = $this->claudeCodeBaselineSummary($fairResults);
        if (! (bool) ($baseline['enabled'] ?? false)) {
            $baseline = $this->arrayValue(data_get($summary, 'claude_code_baseline', []));
        }
        $replay = $this->safeReplayManifestSummaryForReport($this->arrayValue(data_get(
            $this->withReplayManifestArtifactVerification($summary),
            'replay_manifest',
            [],
        )));

        $atlasWins = (int) ($paired['atlas_win_count'] ?? data_get($paired, 'winners.atlas', 0));
        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? data_get($paired, 'winners.claude_code_baseline', 0));
        $tieCount = (int) ($paired['tie_count'] ?? data_get($paired, 'winners.tie', 0));
        $comparableCount = (int) ($paired['comparable_count'] ?? 0);
        $invalidCaseCount = (int) ($paired['invalid_case_count'] ?? 0);
        $blocking = [];

        if (! $officialFairScope) {
            $blocking[] = 'not_official_fair_claude_scope';
        }
        if (! (bool) ($paired['enabled'] ?? false)) {
            $blocking[] = 'paired_scorecard_missing';
        }
        if ((int) ($paired['fair_mode_count'] ?? 0) === 0) {
            $blocking[] = 'fair_atlas_arm_missing';
        }
        if ($comparableCount === 0) {
            $blocking[] = 'no_comparable_cases';
        }
        if (! (bool) ($baseline['enabled'] ?? false) || (int) ($baseline['executed_count'] ?? $baseline['case_count'] ?? 0) === 0) {
            $blocking[] = 'claude_code_baseline_not_executed';
        }
        if (! (bool) ($replay['enabled'] ?? false) || (int) ($replay['artifact_integrity_failed_count'] ?? 0) > 0) {
            $blocking[] = 'replay_manifest_not_fully_verified';
        }
        if ((int) ($paired['provider_violation_count'] ?? 0) > 0) {
            $blocking[] = 'provider_lock_violation';
        }
        if ((int) ($paired['fallback_violation_count'] ?? 0) > 0) {
            $blocking[] = 'fallback_violation';
        }

        $winner = match (true) {
            $atlasWins > $baselineWins => 'atlas',
            $baselineWins > $atlasWins => 'claude_code_baseline',
            $tieCount > 0 => 'tie',
            default => null,
        };
        $healthStatus = match (true) {
            $blocking !== [] => 'blocked',
            $winner === 'atlas' => 'atlas_leading',
            $winner === 'claude_code_baseline' => 'baseline_leading',
            $winner === 'tie' => 'tied',
            default => 'inconclusive',
        };
        $resultIntegrityStatus = match (true) {
            $comparableCount === 0 && $invalidCaseCount > 0 => 'invalid_battery_no_comparable_score',
            $comparableCount === 0 => 'no_comparable_score',
            $blocking !== [] => 'comparable_score_blocked',
            default => 'claim_ready',
        };
        $claimWinnerAdmitted = $resultIntegrityStatus === 'claim_ready' && $winner !== null;

        return [
            'id' => $run->id,
            'status' => $run->status,
            'benchmark_key' => $run->benchmark_key,
            'provider' => $run->provider,
            'model' => $run->model,
            'total_cases' => $run->total_cases,
            'passed_cases' => $run->passed_cases,
            'failed_cases' => $run->failed_cases,
            'duration_ms' => $run->duration_ms,
            'cost_microusd' => $run->cost_microusd,
            'fair_report_scope' => $officialFairScope ? 'official_fair_claude' : 'paired_non_fair',
            'history_summary' => [
                'schema_version' => 1,
                'health_status' => $healthStatus,
                'winner' => $winner,
                'blocking_reasons' => $blocking,
                'atlas_win_count' => $atlasWins,
                'claude_code_baseline_win_count' => $baselineWins,
                'tie_count' => $tieCount,
                'comparable_count' => $comparableCount,
                'invalid_case_count' => $invalidCaseCount,
                'result_integrity_status' => $resultIntegrityStatus,
                'score_admitted' => $comparableCount > 0,
                'claim_winner_admitted' => $claimWinnerAdmitted,
                'protocol_validity_rate' => $this->nullableFloat($paired['protocol_validity_rate'] ?? null),
                'pass_without_human_rate' => $this->nullableFloat($paired['pass_without_human_rate'] ?? null),
                'pass_without_human_rate_medium_hard' => $this->nullableFloat($paired['pass_without_human_rate_medium_hard'] ?? null),
                'final_gate_pass_rate' => $this->nullableFloat($paired['final_gate_pass_rate'] ?? null),
                'repair_conversion_rate' => $this->nullableFloat($paired['repair_conversion_rate'] ?? null),
                'baseline_executed_count' => (int) ($baseline['executed_count'] ?? $baseline['case_count'] ?? 0),
                'replay_packet_count' => (int) ($replay['packet_count'] ?? 0),
                'replay_integrity_failed_count' => (int) ($replay['artifact_integrity_failed_count'] ?? 0),
            ],
            'paired_scorecard' => $paired,
            'claude_code_baseline' => $baseline,
            'replay_manifest' => $replay,
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $runHistory
     * @return array<string,mixed>
     */
    private function fairClaudeHistoryTimeline(Collection $runHistory): array
    {
        return [
            'schema_version' => 'atlas.fair_claude.history_timeline.v1',
            'summary' => [
                'run_count' => $runHistory->count(),
                'blocked_count' => $runHistory
                    ->filter(fn (array $run): bool => data_get($run, 'history_summary.health_status') === 'blocked')
                    ->count(),
                'claim_ready_count' => $runHistory
                    ->filter(fn (array $run): bool => data_get($run, 'history_summary.claim_winner_admitted') === true)
                    ->count(),
                'comparable_case_count' => $runHistory
                    ->sum(fn (array $run): int => (int) data_get($run, 'history_summary.comparable_count', 0)),
                'invalid_case_count' => $runHistory
                    ->sum(fn (array $run): int => (int) data_get($run, 'history_summary.invalid_case_count', 0)),
            ],
            'entries' => $runHistory
                ->map(fn (array $run): array => [
                    'run_id' => $run['id'] ?? null,
                    'status' => $run['status'] ?? null,
                    'finished_at' => $run['finished_at'] ?? null,
                    'created_at' => $run['created_at'] ?? null,
                    'provider' => $run['provider'] ?? null,
                    'model' => $run['model'] ?? null,
                    'health_status' => data_get($run, 'history_summary.health_status'),
                    'winner_for_history' => data_get($run, 'history_summary.winner'),
                    'score_admitted' => (bool) data_get($run, 'history_summary.score_admitted', false),
                    'claim_winner_admitted' => (bool) data_get($run, 'history_summary.claim_winner_admitted', false),
                    'result_integrity_status' => data_get($run, 'history_summary.result_integrity_status'),
                    'comparable_count' => (int) data_get($run, 'history_summary.comparable_count', 0),
                    'invalid_case_count' => (int) data_get($run, 'history_summary.invalid_case_count', 0),
                    'atlas_win_count' => (int) data_get($run, 'history_summary.atlas_win_count', 0),
                    'claude_code_baseline_win_count' => (int) data_get($run, 'history_summary.claude_code_baseline_win_count', 0),
                    'tie_count' => (int) data_get($run, 'history_summary.tie_count', 0),
                    'blocking_reasons' => (array) data_get($run, 'history_summary.blocking_reasons', []),
                    'baseline_executed_count' => (int) data_get($run, 'history_summary.baseline_executed_count', 0),
                    'replay_packet_count' => (int) data_get($run, 'history_summary.replay_packet_count', 0),
                    'replay_integrity_failed_count' => (int) data_get($run, 'history_summary.replay_integrity_failed_count', 0),
                    'duration_ms' => $run['duration_ms'] ?? null,
                    'cost_microusd' => $run['cost_microusd'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $replay
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     * @return array<int,array<string,mixed>>
     */
    private function fairClaudeReportNextActions(
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
        array $invalidBatteryTriage = [],
    ): array {
        $actions = [];
        $blocking = collect((array) ($readiness['blocking_reasons'] ?? []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->values();
        $invalidBatteryNeedsTriage = (int) ($paired['invalid_case_count'] ?? 0) > 0
            && (int) ($readiness['comparable_count'] ?? 0) === 0
            && $blocking->contains('fair_protocol_validity_below_100')
            && (bool) ($invalidBatteryTriage['required_before_rerun'] ?? true);

        $add = function (
            string $id,
            string $severity,
            string $title,
            string $detail,
            ?string $command = null,
            ?string $owner = null,
        ) use (&$actions): void {
            $actions[$id] = [
                'id' => $id,
                'severity' => $severity,
                'title' => $title,
                'detail' => $detail,
                'command' => $command,
                'owner' => $owner,
            ];
        };

        if ($blocking->contains('fair_release_corpus_below_minimum')) {
            $add(
                'prepare_release_corpus',
                'critical',
                'Preparar corpus release',
                'O relatório não pode sustentar claim antes de ter o mínimo de casos release ativos.',
                'atlas rivals prepare --json',
                'benchmark_operator',
            );
        }

        if (! $blocking->contains('fair_release_corpus_below_minimum') && $blocking->contains('no_fair_claude_runs')) {
            $add(
                'run_battery_runbook',
                'critical',
                'Validar runbook da bateria',
                'Antes de executar provider, confirme workspace Atlas, workspace Claude Code separado e binário Claude Code disponível.',
                'atlas rivals runbook --json',
                'benchmark_operator',
            );
        }

        if ($invalidBatteryNeedsTriage) {
            $add(
                'triage_invalid_battery_before_provider_rerun',
                'critical',
                'Triar bateria inválida antes de novo custo',
                'A última bateria real tem protocolo Atlas inválido e zero casos comparáveis; corrija gates, escopo e replay antes de gastar provider novamente.',
                null,
                'engineering_operator',
            );
        }

        if (! $invalidBatteryNeedsTriage && ($blocking->contains('no_fair_claude_runs')
            || $blocking->contains('fair_atlas_arm_missing')
            || $blocking->contains('no_comparable_cases')
            || $blocking->contains('fair_comparable_cases_below_release_minimum'))
        ) {
            $add(
                'run_paired_battery',
                'critical',
                'Executar bateria pareada completa',
                'Ainda não existe amostra release comparável suficiente para sustentar um resultado justo.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('claude_code_baseline_not_executed') || ! (bool) ($baseline['enabled'] ?? false)) {
            $add(
                'run_claude_code_baseline',
                'critical',
                'Executar baseline Claude Code',
                'A comparação justa exige o braço Claude Code CLI com o mesmo Opus e workspace isolado.',
                'atlas rivals run-claude-code --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('replay_manifest_not_fully_verified') || ! (bool) ($replay['enabled'] ?? false)) {
            $add(
                'verify_replay_manifest',
                'critical',
                'Verificar replay e integridade',
                'O claim só é auditável quando os packets e artifacts do replay estão íntegros.',
                'atlas rivals report --json',
                'benchmark_operator',
            );
        }

        if ((int) ($paired['provider_violation_count'] ?? 0) > 0 || (int) ($paired['fallback_violation_count'] ?? 0) > 0) {
            $add(
                'rerun_after_protocol_violation',
                'critical',
                'Descartar runs com violação de fair mode',
                'Provider drift ou fallback invalida o protocolo; rode novamente mantendo Claude Opus travado.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('fair_protocol_validity_below_100')
            || $blocking->contains('fair_final_gate_pass_rate_below_100')
            || $blocking->contains('fair_pass_without_human_rate_below_100')
            || ((float) ($paired['protocol_validity_rate'] ?? 0.0) < 100.0 && (bool) ($paired['enabled'] ?? false))
        ) {
            $add(
                'inspect_protocol_invalid_cases',
                'critical',
                'Corrigir casos sem validade experimental',
                'Protocol validity, final gate e pass_without_human precisam estar em 100% para claim.',
                null,
                'engineering_operator',
            );
        }

        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? 0);
        if ($baselineWins > 0) {
            $add(
                'review_baseline_wins',
                'warning',
                'Revisar vitórias do Claude Code',
                "{$baselineWins} caso(s) favorecem o baseline; priorize esses casos no próximo ciclo de harness.",
                null,
                'engineering_operator',
            );
        }

        $blockedCaseCount = $caseComparisons
            ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
            ->count();
        if ($blockedCaseCount > 0) {
            $add(
                'resolve_incomparable_cases',
                'warning',
                'Resolver casos inconclusivos',
                "{$blockedCaseCount} caso(s) ainda não são comparáveis entre Atlas e Claude Code.",
                null,
                'engineering_operator',
            );
        }

        if ((bool) ($readiness['ready_for_claim'] ?? false)) {
            $add(
                'publish_claim_packet',
                'info',
                'Publicar pacote de evidência',
                'O relatório está pronto para claim; exporte scorecard, replay manifest e case comparisons para revisão externa.',
                'atlas rivals report --json',
                'benchmark_operator',
            );
        }

        if ($actions === []) {
            $add(
                'collect_more_runs',
                'info',
                'Coletar mais amostras',
                'Não há bloqueio crítico novo; aumente a amostra para estabilizar a comparação.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

        return collect($actions)
            ->sortBy(fn (array $action): int => $rank[(string) ($action['severity'] ?? 'info')] ?? 3)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $replay
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     * @return array<string,mixed>
     */
    private function fairClaudeExecutiveSummary(
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
    ): array {
        $atlasWins = (int) ($readiness['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($readiness['claude_code_baseline_win_count'] ?? 0);
        $tieCount = (int) ($readiness['tie_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $ready = (bool) ($readiness['ready_for_claim'] ?? false);
        $claim = match (true) {
            $ready => 'ready_to_claim_atlas_lead',
            $comparableCount === 0 => 'insufficient_comparable_data',
            $baselineWins > $atlasWins => 'baseline_leading',
            $atlasWins > $baselineWins => 'atlas_leading_but_blocked',
            $tieCount > 0 => 'tied_but_blocked',
            default => 'not_ready',
        };
        $headline = match ($claim) {
            'ready_to_claim_atlas_lead' => 'Atlas está pronto para claim no protocolo Fair Claude.',
            'atlas_leading_but_blocked' => 'Atlas lidera, mas ainda há bloqueios de evidência.',
            'baseline_leading' => 'Claude Code lidera a amostra atual.',
            'tied_but_blocked' => 'A amostra está empatada e ainda bloqueada.',
            'insufficient_comparable_data' => 'Ainda não há amostra comparável suficiente.',
            default => 'Relatório Fair Claude ainda não está pronto para claim.',
        };
        $topRisks = collect((array) ($readiness['blocking_reasons'] ?? []))
            ->take(5)
            ->values()
            ->all();
        $baselineWinCases = $caseComparisons
            ->filter(fn (array $comparison): bool => ($comparison['winner'] ?? null) === 'claude_code_baseline')
            ->pluck('case_code')
            ->filter()
            ->values()
            ->all();
        $blockedCases = $caseComparisons
            ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
            ->pluck('case_code')
            ->filter()
            ->values()
            ->all();

        return [
            'claim_status' => $claim,
            'headline' => $headline,
            'ready_for_claim' => $ready,
            'winner' => $atlasWins > $baselineWins
                ? 'atlas'
                : ($baselineWins > $atlasWins ? 'claude_code_baseline' : ($tieCount > 0 ? 'tie' : null)),
            'confidence' => $ready ? 'high' : ($comparableCount > 0 ? 'limited' : 'none'),
            'sample' => [
                'comparable_cases' => $comparableCount,
                'fair_mode_cases' => (int) ($readiness['fair_mode_count'] ?? 0),
                'atlas_wins' => $atlasWins,
                'claude_code_baseline_wins' => $baselineWins,
                'ties' => $tieCount,
            ],
            'quality_bar' => [
                'protocol_validity_rate' => $paired['protocol_validity_rate'] ?? null,
                'pass_without_human_rate' => $paired['pass_without_human_rate'] ?? null,
                'medium_hard_pass_without_human_rate' => $paired['pass_without_human_rate_medium_hard'] ?? null,
                'final_gate_pass_rate' => $paired['final_gate_pass_rate'] ?? null,
            ],
            'auditability' => [
                'baseline_executed' => (bool) ($baseline['enabled'] ?? false) && (int) ($baseline['executed_count'] ?? 0) > 0,
                'replay_verified' => (bool) ($replay['enabled'] ?? false) && (int) ($replay['artifact_integrity_failed_count'] ?? 0) === 0,
                'manifest_hash_count' => count((array) ($replay['manifest_hashes'] ?? [])),
            ],
            'top_risks' => $topRisks,
            'baseline_win_cases' => $baselineWinCases,
            'blocked_cases' => $blockedCases,
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     * @return array<string,mixed>
     */
    private function fairClaudeResultIntegrity(
        array $readiness,
        array $paired,
        Collection $caseComparisons,
        array $invalidBatteryTriage = [],
        array $experimentValidity = [],
    ): array {
        $atlasWins = (int) ($readiness['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($readiness['claude_code_baseline_win_count'] ?? 0);
        $tieCount = (int) ($readiness['tie_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $minimumComparableCount = (int) ($readiness['minimum_comparable_case_count'] ?? 6);
        $invalidCaseCount = (int) ($paired['invalid_case_count'] ?? 0);
        $inconclusiveCount = (int) ($paired['inconclusive_count'] ?? max(0, $caseComparisons->count() - $comparableCount));
        $readyForClaim = (bool) ($readiness['ready_for_claim'] ?? false);
        $provisionalLeader = match (true) {
            $comparableCount === 0 => null,
            $atlasWins > $baselineWins => 'atlas',
            $baselineWins > $atlasWins => 'claude_code_baseline',
            $tieCount > 0 => 'tie',
            default => null,
        };
        $winnerForClaim = $readyForClaim ? $provisionalLeader : null;
        $status = match (true) {
            $readyForClaim && $winnerForClaim !== null => 'claim_ready',
            $comparableCount === 0 && $invalidCaseCount > 0 => 'invalid_battery_no_comparable_score',
            $comparableCount === 0 => 'no_comparable_score',
            $comparableCount < $minimumComparableCount => 'limited_sample_not_claimable',
            default => 'comparable_score_blocked',
        };
        $operatorHeadline = match ($status) {
            'claim_ready' => 'Resultado comparável pronto para claim.',
            'invalid_battery_no_comparable_score' => 'Bateria real inválida: nenhum vencedor pode ser declarado.',
            'no_comparable_score' => 'Sem score comparável: rode uma bateria válida antes de declarar resultado.',
            'limited_sample_not_claimable' => 'Existe líder provisório, mas a amostra ainda é pequena para claim.',
            default => 'Existe score comparável, mas os gates ainda bloqueiam claim.',
        };

        return [
            'schema_version' => 'atlas.fair_claude.result_integrity.v1',
            'status' => $status,
            'operator_headline' => $operatorHeadline,
            'score_admitted' => $comparableCount > 0,
            'claim_winner_admitted' => $winnerForClaim !== null,
            'winner_for_claim' => $winnerForClaim,
            'provisional_leader' => $provisionalLeader,
            'policy' => [
                'invalid_cases_count_as_losses' => false,
                'inconclusive_cases_count_as_losses' => false,
                'only_comparable_cases_enter_win_loss_math' => true,
                'external_variables_can_block_comparability' => true,
                'external_variables_cannot_decide_winner' => true,
                'synthetic_scores_allowed' => false,
                'triaged_invalid_batteries_remain_excluded_from_score' => true,
            ],
            'experiment_validity' => $experimentValidity,
            'invalid_battery_triage' => $invalidBatteryTriage,
            'triage_required_before_rerun' => (bool) ($invalidBatteryTriage['required_before_rerun'] ?? ($status === 'invalid_battery_no_comparable_score')),
            'counts' => [
                'case_comparison_count' => $caseComparisons->count(),
                'comparable_count' => $comparableCount,
                'minimum_comparable_case_count' => $minimumComparableCount,
                'invalid_case_count' => $invalidCaseCount,
                'inconclusive_count' => $inconclusiveCount,
                'atlas_win_count' => $atlasWins,
                'claude_code_baseline_win_count' => $baselineWins,
                'tie_count' => $tieCount,
            ],
            'status_counts' => $caseComparisons
                ->pluck('comparison_status')
                ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
                ->countBy()
                ->all(),
            'blocking_reason_counts' => $caseComparisons
                ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->countBy()
                ->all(),
            'ui_contract' => [
                'primary_state' => $status,
                'primary_metric' => 'comparable_count',
                'must_not_render_winner' => $winnerForClaim === null,
                'must_not_render_invalid_cases_as_losses' => true,
                'must_label_provisional_leader_when_not_claim_ready' => $provisionalLeader !== null && $winnerForClaim === null,
                'must_show_blocking_reasons' => true,
            ],
            'first_invalid_cases' => $caseComparisons
                ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
                ->take(5)
                ->map(fn (array $comparison): array => [
                    'case_code' => $comparison['case_code'] ?? null,
                    'title' => $comparison['title'] ?? null,
                    'comparison_status' => $comparison['comparison_status'] ?? null,
                    'winner' => null,
                    'blocking_reasons' => (array) ($comparison['blocking_reasons'] ?? []),
                    'plain_explanation' => 'Caso excluído do placar porque não passou no protocolo de comparabilidade.',
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     * @return array<string,mixed>
     */
    private function fairClaudeExperimentValidity(array $readiness, array $paired, Collection $caseComparisons): array
    {
        $blockingReasonCounts = $caseComparisons
            ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->countBy()
            ->all();
        $confounderReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
            'provider_lock_violation',
            'fallback_violation',
            'fair_protocol_not_valid',
            'atlas_protocol_invalid',
        ];
        $observedConfounders = collect($confounderReasons)
            ->filter(fn (string $reason): bool => ((int) ($blockingReasonCounts[$reason] ?? 0)) > 0)
            ->values()
            ->all();
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $readyForClaim = (bool) ($readiness['ready_for_claim'] ?? false);
        $status = match (true) {
            $readyForClaim && $observedConfounders === [] => 'valid_for_claim',
            $comparableCount > 0 && $observedConfounders === [] => 'limited_valid_comparable_sample',
            $observedConfounders !== [] => 'blocked_by_confounders',
            default => 'not_enough_comparable_data',
        };

        return [
            'schema_version' => 'atlas.fair_claude.experiment_validity.v1',
            'status' => $status,
            'ab_test_validity_model' => [
                'same_case_snapshot_required' => true,
                'equivalent_initial_state_required' => true,
                'same_acceptance_gates_required' => true,
                'same_prompt_and_task_contract_required' => true,
                'provider_model_locks_required' => true,
                'clean_isolated_workspaces_required' => true,
                'deterministic_replay_required' => true,
                'no_provider_specific_case_filtering' => true,
            ],
            'external_variable_policy' => [
                'non_evaluated_variables_cannot_decide_winner' => true,
                'non_evaluated_variables_can_only_block_comparability' => true,
                'dirty_workspace_blocks_provider_battery' => true,
                'provider_or_model_drift_blocks_comparability' => true,
                'fallback_blocks_comparability' => true,
                'invalid_protocol_blocks_score_admission' => true,
            ],
            'score_policy' => [
                'winner_requires_valid_comparable_case' => true,
                'invalid_cases_excluded_from_win_loss_math' => true,
                'inconclusive_cases_excluded_from_win_loss_math' => true,
                'claim_requires_ready_for_claim_gate' => true,
                'synthetic_scores_allowed' => false,
            ],
            'observed' => [
                'comparable_count' => $comparableCount,
                'invalid_case_count' => (int) ($paired['invalid_case_count'] ?? 0),
                'inconclusive_count' => (int) ($paired['inconclusive_count'] ?? max(0, $caseComparisons->count() - $comparableCount)),
                'provider_violation_count' => (int) ($paired['provider_violation_count'] ?? 0),
                'fallback_violation_count' => (int) ($paired['fallback_violation_count'] ?? 0),
                'protocol_validity_rate' => $this->nullableFloat($paired['protocol_validity_rate'] ?? null),
                'final_gate_pass_rate' => $this->nullableFloat($paired['final_gate_pass_rate'] ?? null),
                'blocking_reason_counts' => $blockingReasonCounts,
                'observed_confounders' => $observedConfounders,
            ],
            'operator_summary' => match ($status) {
                'valid_for_claim' => 'O desenho experimental esta valido para claim.',
                'limited_valid_comparable_sample' => 'A comparacao tem casos validos, mas ainda nao atingiu todos os gates de claim.',
                'blocked_by_confounders' => 'A comparacao esta bloqueada por variaveis externas ou protocolo invalido; nao declare vencedor.',
                default => 'Ainda nao ha dados comparaveis suficientes para avaliar vencedor.',
            },
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $fairRuns
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $replay
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     */
    private function fairClaudeInvalidBatteryFingerprint(
        Collection $fairRuns,
        array $readiness,
        array $paired,
        array $replay,
        Collection $caseComparisons,
    ): string {
        return hash('sha256', $this->canonicalJsonForHash([
            'run_ids' => $fairRuns
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->sort()
                ->values()
                ->all(),
            'manifest_hashes' => collect((array) ($replay['manifest_hashes'] ?? []))
                ->map(fn (mixed $hash): string => (string) $hash)
                ->sort()
                ->values()
                ->all(),
            'case_statuses' => $caseComparisons
                ->pluck('comparison_status')
                ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
                ->countBy()
                ->sortKeys()
                ->all(),
            'blocking_reasons' => $caseComparisons
                ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->countBy()
                ->sortKeys()
                ->all(),
            'comparable_count' => (int) ($readiness['comparable_count'] ?? 0),
            'invalid_case_count' => (int) ($paired['invalid_case_count'] ?? 0),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function fairClaudeInvalidBatteryTriageState(AtlasEngineeringBenchmarkSuite $suite, string $fingerprint): array
    {
        $record = $this->arrayValue(data_get($suite->metadata ?? [], 'rivals_invalid_battery_triage', []));
        $accepted = ($record['status'] ?? null) === 'triaged_quarantined'
            && is_string($record['invalid_battery_fingerprint'] ?? null)
            && hash_equals((string) $record['invalid_battery_fingerprint'], $fingerprint);

        return [
            'schema_version' => 'atlas.fair_claude.invalid_battery_triage.v1',
            'status' => $accepted ? 'triaged_quarantined' : 'triage_required',
            'required_before_rerun' => ! $accepted,
            'invalid_battery_fingerprint' => $fingerprint,
            'accepted_record' => $accepted ? Arr::only($record, [
                'status',
                'invalid_battery_fingerprint',
                'reason',
                'triaged_at',
                'triaged_by',
                'policy',
            ]) : null,
            'policy' => [
                'quarantine_does_not_delete_history' => true,
                'quarantined_cases_stay_out_of_win_loss_math' => true,
                'new_provider_run_still_requires_clean_workspaces_and_operator_cost_confirmation' => true,
                'claim_still_requires_new_comparable_cases' => true,
            ],
        ];
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkRun>  $fairRuns
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $paired
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $replay
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     * @param  array<int,array<string,mixed>>  $nextActions
     * @param  array<string,mixed>  $resultIntegrity
     * @return array<string,mixed>
     */
    private function fairClaudeEvidencePacket(
        AtlasEngineeringBenchmarkSuite $suite,
        Collection $fairRuns,
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
        array $nextActions,
        array $resultIntegrity,
    ): array {
        $packet = [
            'schema_version' => 1,
            'kind' => 'fair_claude_claim_evidence_packet',
            'generated_at' => now()->toJSON(),
            'suite' => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
            ],
            'protocol' => [
                'atlas_provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
                'atlas_model_lock' => FairClaudePolicy::MODEL_LOCK,
                'baseline_provider_lock' => 'claude_code_cli',
                'baseline_model_lock' => FairClaudePolicy::MODEL_LOCK,
                'fallback_disabled' => true,
                'atlas_decide_disabled' => true,
            ],
            'claim' => [
                'ready_for_claim' => (bool) ($readiness['ready_for_claim'] ?? false),
                'readiness_status' => $readiness['status'] ?? null,
                'blocking_reasons' => $readiness['blocking_reasons'] ?? [],
                'winner' => $resultIntegrity['winner_for_claim'] ?? null,
                'provisional_leader' => $resultIntegrity['provisional_leader'] ?? null,
                'claim_winner_admitted' => (bool) ($resultIntegrity['claim_winner_admitted'] ?? false),
            ],
            'result_integrity' => Arr::only($resultIntegrity, [
                'schema_version',
                'status',
                'operator_headline',
                'score_admitted',
                'claim_winner_admitted',
                'winner_for_claim',
                'provisional_leader',
                'policy',
                'experiment_validity',
                'counts',
                'ui_contract',
            ]),
            'scorecard' => Arr::only($paired, [
                'case_count',
                'fair_mode_count',
                'comparable_count',
                'atlas_win_count',
                'claude_code_baseline_win_count',
                'tie_count',
                'protocol_validity_rate',
                'pass_without_human_rate',
                'pass_without_human_rate_medium_hard',
                'repair_conversion_rate',
                'final_gate_pass_rate',
                'provider_violation_count',
                'fallback_violation_count',
            ]),
            'audit' => [
                'run_ids' => $fairRuns->pluck('id')->values()->all(),
                'run_count' => $fairRuns->count(),
                'case_comparison_count' => $caseComparisons->count(),
                'replay_enabled' => (bool) ($replay['enabled'] ?? false),
                'replay_manifest_hashes' => (array) ($replay['manifest_hashes'] ?? []),
                'replay_packet_count' => (int) ($replay['packet_count'] ?? 0),
                'artifact_integrity_passed_count' => (int) ($replay['artifact_integrity_passed_count'] ?? 0),
                'artifact_integrity_failed_count' => (int) ($replay['artifact_integrity_failed_count'] ?? 0),
                'baseline_enabled' => (bool) ($baseline['enabled'] ?? false),
                'baseline_executed_count' => (int) ($baseline['executed_count'] ?? 0),
            ],
            'case_outcomes' => $caseComparisons
                ->map(fn (array $comparison): array => Arr::only($comparison, [
                    'case_code',
                    'comparison_status',
                    'comparable',
                    'winner',
                    'winner_reason',
                    'blocking_reasons',
                    'deltas',
                ]))
                ->values()
                ->all(),
            'next_action_ids' => collect($nextActions)
                ->pluck('id')
                ->filter()
                ->values()
                ->all(),
        ];

        return array_merge($packet, [
            'evidence_hash' => hash('sha256', $this->canonicalJsonForHash(Arr::except($packet, ['generated_at']))),
        ]);
    }

    /**
     * @param  array<string,mixed>  $executiveSummary
     * @param  array<string,mixed>  $evidencePacket
     * @param  array<int,array<string,mixed>>  $nextActions
     * @param  Collection<int,array<string,mixed>>  $caseComparisons
     */
    private function fairClaudeClaimMarkdown(
        array $executiveSummary,
        array $evidencePacket,
        array $nextActions,
        Collection $caseComparisons,
    ): string {
        $scorecard = $this->arrayValue($evidencePacket['scorecard'] ?? []);
        $audit = $this->arrayValue($evidencePacket['audit'] ?? []);
        $protocol = $this->arrayValue($evidencePacket['protocol'] ?? []);
        $claim = $this->arrayValue($evidencePacket['claim'] ?? []);
        $sample = $this->arrayValue($executiveSummary['sample'] ?? []);
        $quality = $this->arrayValue($executiveSummary['quality_bar'] ?? []);
        $auditability = $this->arrayValue($executiveSummary['auditability'] ?? []);
        $integrity = $this->arrayValue($executiveSummary['result_integrity'] ?? []);
        $experimentValidity = $this->arrayValue($integrity['experiment_validity'] ?? []);
        $hash = (string) ($evidencePacket['evidence_hash'] ?? '');
        $lines = [
            '# Atlas Rivals Fair Claude Report',
            '',
            '## Executive Summary',
            '',
            '- Claim status: `'.$this->markdownInline((string) ($executiveSummary['claim_status'] ?? 'unknown')).'`',
            '- Headline: '.$this->markdownText((string) ($executiveSummary['headline'] ?? '')),
            '- Winner: `'.$this->markdownInline((string) ($executiveSummary['winner'] ?? 'none')).'`',
            '- Confidence: `'.$this->markdownInline((string) ($executiveSummary['confidence'] ?? 'none')).'`',
            '- Evidence hash: `'.$this->markdownInline($hash).'`',
            '',
            '## Result Integrity',
            '',
            '- Status: `'.$this->markdownInline((string) ($integrity['status'] ?? 'unknown')).'`',
            '- Score admitted: `'.($integrity['score_admitted'] ?? false ? 'true' : 'false').'`',
            '- Claim winner admitted: `'.($integrity['claim_winner_admitted'] ?? false ? 'true' : 'false').'`',
            '- Winner for claim: `'.$this->markdownInline((string) ($integrity['winner_for_claim'] ?? 'none')).'`',
            '- Provisional leader: `'.$this->markdownInline((string) ($integrity['provisional_leader'] ?? 'none')).'`',
            '- Operator note: '.$this->markdownText((string) ($integrity['operator_headline'] ?? '')),
            '',
            '## Experiment Validity',
            '',
            '- Status: `'.$this->markdownInline((string) ($experimentValidity['status'] ?? 'unknown')).'`',
            '- Non-evaluated variables cannot decide winner: `'.(data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_cannot_decide_winner') ? 'true' : 'false').'`',
            '- Non-evaluated variables can only block comparability: `'.(data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_can_only_block_comparability') ? 'true' : 'false').'`',
            '- No provider-specific case filtering: `'.(data_get($experimentValidity, 'ab_test_validity_model.no_provider_specific_case_filtering') ? 'true' : 'false').'`',
            '- Observed confounders: `'.$this->markdownInline(implode(', ', (array) data_get($experimentValidity, 'observed.observed_confounders', [])) ?: 'none').'`',
            '- Operator summary: '.$this->markdownText((string) ($experimentValidity['operator_summary'] ?? '')),
            '',
            '## Sample',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| Comparable cases | '.$this->markdownNumber($sample['comparable_cases'] ?? null).' |',
            '| Fair mode cases | '.$this->markdownNumber($sample['fair_mode_cases'] ?? null).' |',
            '| Atlas wins | '.$this->markdownNumber($sample['atlas_wins'] ?? null).' |',
            '| Claude Code wins | '.$this->markdownNumber($sample['claude_code_baseline_wins'] ?? null).' |',
            '| Ties | '.$this->markdownNumber($sample['ties'] ?? null).' |',
            '',
            '## Quality Bar',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| Protocol validity | '.$this->markdownPercent($quality['protocol_validity_rate'] ?? null).' |',
            '| Pass without human | '.$this->markdownPercent($quality['pass_without_human_rate'] ?? null).' |',
            '| Medium/hard pass without human | '.$this->markdownPercent($quality['medium_hard_pass_without_human_rate'] ?? null).' |',
            '| Final gate pass | '.$this->markdownPercent($quality['final_gate_pass_rate'] ?? null).' |',
            '| Repair conversion | '.$this->markdownPercent($scorecard['repair_conversion_rate'] ?? null).' |',
            '',
            '## Protocol Lock',
            '',
            '| Lock | Value |',
            '| --- | --- |',
            '| Atlas provider | `'.$this->markdownInline((string) ($protocol['atlas_provider_lock'] ?? '-')).'` |',
            '| Atlas model | `'.$this->markdownInline((string) ($protocol['atlas_model_lock'] ?? '-')).'` |',
            '| Baseline provider | `'.$this->markdownInline((string) ($protocol['baseline_provider_lock'] ?? '-')).'` |',
            '| Baseline model | `'.$this->markdownInline((string) ($protocol['baseline_model_lock'] ?? '-')).'` |',
            '| Fallback disabled | `'.($protocol['fallback_disabled'] ?? false ? 'true' : 'false').'` |',
            '| Atlas Decide disabled | `'.($protocol['atlas_decide_disabled'] ?? false ? 'true' : 'false').'` |',
            '',
            '## Auditability',
            '',
            '| Evidence | Value |',
            '| --- | ---: |',
            '| Ready for claim | `'.($claim['ready_for_claim'] ?? false ? 'true' : 'false').'` |',
            '| Baseline executed | `'.($auditability['baseline_executed'] ?? false ? 'true' : 'false').'` |',
            '| Replay verified | `'.($auditability['replay_verified'] ?? false ? 'true' : 'false').'` |',
            '| Replay packets | '.$this->markdownNumber($audit['replay_packet_count'] ?? null).' |',
            '| Artifact integrity failures | '.$this->markdownNumber($audit['artifact_integrity_failed_count'] ?? null).' |',
            '| Manifest hashes | '.$this->markdownNumber($auditability['manifest_hash_count'] ?? null).' |',
            '',
            '## Next Actions',
            '',
        ];

        if ($nextActions === []) {
            $lines[] = '- No next actions generated.';
        } else {
            foreach ($nextActions as $action) {
                $line = '- `'.$this->markdownInline((string) ($action['severity'] ?? 'info')).'` '.$this->markdownText((string) ($action['title'] ?? 'Action'));
                if (($action['command'] ?? null) !== null) {
                    $line .= ' - `'.$this->markdownInline((string) $action['command']).'`';
                }
                $lines[] = $line;
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Case Outcomes',
            '',
            '| Case | Status | Winner | Reason | Delta Score | Delta Time |',
            '| --- | --- | --- | --- | ---: | ---: |',
        ]);

        $caseRows = $caseComparisons->take(30);
        if ($caseRows->isEmpty()) {
            $lines[] = '| - | - | - | No case comparisons available. | - | - |';
        } else {
            foreach ($caseRows as $comparison) {
                $deltas = $this->arrayValue($comparison['deltas'] ?? []);
                $lines[] = '| '.$this->markdownCell((string) ($comparison['case_code'] ?? $comparison['case_id'] ?? '-'))
                    .' | '.$this->markdownCell((string) ($comparison['comparison_status'] ?? '-'))
                    .' | '.$this->markdownCell((string) ($comparison['winner'] ?? '-'))
                    .' | '.$this->markdownCell((string) ($comparison['winner_reason'] ?? '-'))
                    .' | '.$this->markdownNumber($deltas['score'] ?? null)
                    .' | '.$this->markdownNumber($deltas['duration_ms'] ?? null).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function fairClaudeExportBundle(array $payload): array
    {
        $reportJson = $this->prettyJson(Arr::except($payload, ['claim_markdown', 'export_bundle']));
        $evidenceJson = $this->prettyJson($payload['evidence_packet'] ?? []);
        $caseComparisonsJson = $this->prettyJson($payload['case_comparisons'] ?? []);
        $historyTimelineJson = $this->prettyJson($payload['history_timeline'] ?? []);
        $claimMarkdown = (string) ($payload['claim_markdown'] ?? '');
        $files = [
            'report.json' => [
                'kind' => 'fair_claude_report_json',
                'content_type' => 'application/json',
                'bytes' => strlen($reportJson),
                'sha256' => hash('sha256', $reportJson),
            ],
            'evidence.json' => [
                'kind' => 'fair_claude_evidence_packet_json',
                'content_type' => 'application/json',
                'bytes' => strlen($evidenceJson),
                'sha256' => hash('sha256', $evidenceJson),
            ],
            'claim.md' => [
                'kind' => 'fair_claude_claim_markdown',
                'content_type' => 'text/markdown',
                'bytes' => strlen($claimMarkdown),
                'sha256' => hash('sha256', $claimMarkdown),
            ],
            'case-comparisons.json' => [
                'kind' => 'fair_claude_case_comparisons_json',
                'content_type' => 'application/json',
                'bytes' => strlen($caseComparisonsJson),
                'sha256' => hash('sha256', $caseComparisonsJson),
            ],
            'history-timeline.json' => [
                'kind' => 'fair_claude_history_timeline_json',
                'content_type' => 'application/json',
                'bytes' => strlen($historyTimelineJson),
                'sha256' => hash('sha256', $historyTimelineJson),
            ],
        ];

        return [
            'schema_version' => 1,
            'kind' => 'fair_claude_export_bundle',
            'recommended_directory' => 'atlas-rivals-'.((string) data_get($payload, 'suite.slug', 'suite')).'-'.now()->format('Ymd-His'),
            'verification_command' => 'atlas rivals verify --output-dir=<export-directory> --json',
            'evidence_hash' => data_get($payload, 'evidence_packet.evidence_hash'),
            'files' => $files,
            'bundle_hash' => hash('sha256', $this->canonicalJsonForHash($files)),
        ];
    }

    private function prettyJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return (is_string($json) ? $json : 'null')."\n";
    }

    private function markdownText(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', trim($value));
    }

    private function markdownInline(string $value): string
    {
        return str_replace('`', "'", $this->markdownText($value));
    }

    private function markdownCell(string $value): string
    {
        return str_replace('|', '\\|', $this->markdownText($value));
    }

    private function markdownNumber(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '-';
    }

    private function markdownPercent(mixed $value): string
    {
        return is_numeric($value) ? ((string) $value).'%' : '-';
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkResult>  $results
     * @return array<string,mixed>
     */
    private function replayManifestSummary(
        AtlasEngineeringBenchmarkRun $run,
        Collection $results,
        string $status,
        array $quality,
        array $releaseGate,
    ): array {
        $packets = $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): ?array {
                $packet = data_get($result->observed_json ?? [], 'claude_code_baseline.replay_packet');
                if (! is_array($packet)) {
                    return null;
                }

                return [
                    'case_id' => $result->case_id,
                    'case_code' => data_get($packet, 'case_code'),
                    'provider_lock' => data_get($packet, 'provider_lock'),
                    'model_lock' => data_get($packet, 'model_lock'),
                    'mode' => data_get($packet, 'mode'),
                    'workspace_hash' => data_get($packet, 'workspace_hash'),
                    'prompt_hash' => data_get($packet, 'input.prompt_hash'),
                    'task_contract_hash' => data_get($packet, 'input.task_contract_hash'),
                    'command_hash' => data_get($packet, 'invocation.command_hash'),
                    'deterministic_gate_command_hash' => data_get($packet, 'deterministic_gate.command_hash'),
                    'deterministic_gate_command_present' => (bool) data_get($packet, 'deterministic_gate.command_present'),
                    'packet_hash' => hash('sha256', $this->canonicalJsonForHash($this->hashableReplayPacket($packet))),
                ];
            })
            ->filter()
            ->values();

        if ($packets->isEmpty()) {
            $manifest = [
                'enabled' => false,
                'packet_count' => 0,
            ];

            return array_merge($manifest, [
                'final_packet' => $this->benchmarkFinalPacket($run, $results, $status, $quality, $releaseGate, $manifest),
            ]);
        }

        $packetHashes = $packets
            ->pluck('packet_hash')
            ->filter()
            ->values()
            ->all();
        $sortedPacketHashes = $packetHashes;
        sort($sortedPacketHashes);

        $manifest = [
            'enabled' => true,
            'schema_version' => 1,
            'kind' => 'engineering_benchmark_replay_manifest',
            'packet_count' => $packets->count(),
            'providers' => $packets->pluck('provider_lock')->filter()->unique()->values()->all(),
            'model_locks' => $packets->pluck('model_lock')->filter()->unique()->values()->all(),
            'modes' => $packets->pluck('mode')->filter()->countBy()->all(),
            'deterministic_gate_packet_count' => $packets
                ->filter(fn (array $packet): bool => (bool) ($packet['deterministic_gate_command_present'] ?? false))
                ->count(),
            'packet_hashes' => $packetHashes,
            'manifest_hash' => hash('sha256', $this->canonicalJsonForHash($sortedPacketHashes)),
            'packets' => $packets->all(),
        ];

        $finalPacket = $this->benchmarkFinalPacket($run, $results, $status, $quality, $releaseGate, $manifest);

        return array_merge($manifest, [
            'final_packet' => array_merge($finalPacket, [
                'final_packet_hash' => hash('sha256', $this->canonicalJsonForHash(
                    Arr::except($finalPacket, ['generated_at']),
                )),
            ]),
        ]);
    }

    /**
     * @param  Collection<int,AtlasEngineeringBenchmarkResult>  $results
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $releaseGate
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function benchmarkFinalPacket(
        AtlasEngineeringBenchmarkRun $run,
        Collection $results,
        string $status,
        array $quality,
        array $releaseGate,
        array $manifest,
    ): array {
        $scorecards = $results
            ->map(fn (AtlasEngineeringBenchmarkResult $result): mixed => data_get($result->observed_json ?? [], 'paired_scorecard'))
            ->filter(fn (mixed $scorecard): bool => is_array($scorecard))
            ->values();
        $fairScorecards = $scorecards
            ->filter(fn (array $scorecard): bool => (bool) ($scorecard['fair_mode'] ?? false))
            ->values();
        $fairMode = $fairScorecards->isNotEmpty();
        $protocolValid = $fairMode
            ? $fairScorecards->every(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.protocol_valid', data_get($scorecard, 'atlas.verified')))
            : null;
        $humanInterventionCount = $scorecards
            ->sum(fn (array $scorecard): int => max(0, (int) data_get($scorecard, 'atlas.human_intervention_count', 0))
                + max(0, (int) data_get($scorecard, 'claude_code_baseline.human_intervention_count', 0)));
        $pairedSummary = $this->pairedScorecardSummary($results, (int) ($quality['cost_microusd'] ?? 0));
        $packetCount = (int) ($manifest['packet_count'] ?? 0);
        $deterministicGatePacketCount = (int) ($manifest['deterministic_gate_packet_count'] ?? 0);
        $providerViolationCount = (int) ($pairedSummary['provider_violation_count'] ?? 0);
        $fallbackViolationCount = (int) ($pairedSummary['fallback_violation_count'] ?? 0);
        $invalidCaseCount = (int) ($pairedSummary['invalid_case_count'] ?? 0);
        $atlasVerifiedCount = $fairScorecards
            ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.verified')
                && (bool) data_get($scorecard, 'atlas.pass_without_human'))
            ->count();
        $failedTestCount = (int) ($quality['failed_test_count'] ?? 0);
        $failedControlCount = (int) ($quality['failed_control_count'] ?? 0);
        $releaseGateStatus = $this->nonEmptyString($releaseGate['status'] ?? null);

        $invalidReasons = [];
        if ($protocolValid === false) {
            $invalidReasons[] = 'fair_protocol_invalid';
        }
        if ($providerViolationCount > 0) {
            $invalidReasons[] = 'provider_lock_violation';
        }
        if ($fallbackViolationCount > 0) {
            $invalidReasons[] = 'fallback_violation';
        }

        $unverifiedReasons = [];
        if ($packetCount > 0 && $deterministicGatePacketCount < $packetCount) {
            $unverifiedReasons[] = 'deterministic_gate_packet_missing';
        }
        if ($fairMode && $atlasVerifiedCount < $fairScorecards->count()) {
            $unverifiedReasons[] = 'pass_without_human_unverified';
        }

        $failedReasons = [];
        if ($failedTestCount > 0) {
            $failedReasons[] = 'failed_tests_present';
        }
        if ($failedControlCount > 0) {
            $failedReasons[] = 'failed_controls_present';
        }
        if ($releaseGateStatus === 'failed') {
            $failedReasons[] = 'release_gate_failed';
        }

        $finalStatus = match (true) {
            $invalidReasons !== [] => 'invalid',
            $status === 'failed' || $failedReasons !== [] => 'failed',
            $unverifiedReasons !== [] => 'unverified',
            $status === 'passed' => 'passed',
            default => $status,
        };
        $evaluatorVerified = $finalStatus === 'passed';
        $decisionReasons = array_values(array_unique(array_merge(
            $invalidReasons,
            $failedReasons,
            $unverifiedReasons,
        )));

        $skips = [];
        if ($packetCount > 0 && $deterministicGatePacketCount < $packetCount) {
            $skips[] = [
                'reason' => 'deterministic_gate_packet_missing',
                'packet_count' => $packetCount,
                'deterministic_gate_packet_count' => $deterministicGatePacketCount,
            ];
        }

        $selfAssessment = [
            'authoritative' => false,
            'note' => 'Self-reported flags from provider stdout never promote status to passed; Atlas-deterministic gates are authoritative.',
            'protocol_valid' => $protocolValid,
            'fair_scorecard_count' => $fairScorecards->count(),
            'invalid_case_count' => $invalidCaseCount,
            'pass_without_human_reported_count' => $fairScorecards
                ->filter(fn (array $scorecard): bool => (bool) data_get($scorecard, 'atlas.pass_without_human_reported', data_get($scorecard, 'atlas.pass_without_human')))
                ->count(),
        ];

        return [
            'schema_version' => 1,
            'kind' => 'engineering_benchmark_final_packet',
            'evaluator' => 'atlas_deterministic',
            'evaluator_verified' => $evaluatorVerified,
            'evaluation_inputs' => [
                'paired_scorecard',
                'fair_scorecard',
                'release_gate',
                'quality_metrics',
            ],
            'status' => $finalStatus,
            'benchmark_status' => $status,
            'protocol_valid' => $protocolValid,
            'fair_mode' => $fairMode,
            'provider_lock' => $fairMode ? FairClaudePolicy::PROVIDER_LOCK : null,
            'model_lock' => $fairMode ? FairClaudePolicy::MODEL_LOCK : null,
            'attempts' => (int) ($quality['total_attempts'] ?? 0),
            'repair_conversion_rate' => $pairedSummary['repair_conversion_rate'] ?? null,
            'time_to_green' => $pairedSummary['time_to_green'] ?? null,
            'human_intervention_count' => $humanInterventionCount,
            'files_changed_count' => (int) ($quality['changed_files_count'] ?? 0),
            'diff_hash' => null,
            'replay_manifest_hash' => $manifest['manifest_hash'] ?? null,
            'tests' => [
                'failed_test_count' => $failedTestCount,
                'failed_tests' => $quality['failed_tests'] ?? [],
            ],
            'gates' => [
                'release_gate_status' => $releaseGate['status'] ?? null,
                'release_gate_profile' => $releaseGate['profile'] ?? null,
                'deterministic_gate_packet_count' => $deterministicGatePacketCount,
                'deterministic_gate_packet_total' => $packetCount,
                'failed_control_count' => $failedControlCount,
                'blocked_control_count' => (int) ($quality['blocked_control_count'] ?? 0),
                'release_gate_failures' => $releaseGate['failures'] ?? [],
                'fair_scorecard_count' => $fairScorecards->count(),
                'fair_scorecard_atlas_verified_count' => $atlasVerifiedCount,
                'provider_violation_count' => $providerViolationCount,
                'fallback_violation_count' => $fallbackViolationCount,
            ],
            'skips' => $skips,
            'risks' => [
                'risk_flag_count' => (int) ($quality['risk_flag_count'] ?? 0),
                'risk_flags' => $quality['risk_flags'] ?? [],
                'release_gate_warnings' => $releaseGate['warnings'] ?? [],
            ],
            'decision_reasons' => $decisionReasons,
            'self_assessment' => $selfAssessment,
            'replay_command' => 'atlas benchmark claude-fair replay '.$run->id.' --json',
            'rollback_command' => null,
            'trace_id' => data_get($quality, 'telemetry_trace_ids.0'),
            'trace_ids' => $quality['telemetry_trace_ids'] ?? [],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function persistReplayManifestArtifact(AtlasEngineeringBenchmarkRun $run, array $manifest): array
    {
        if (! (bool) ($manifest['enabled'] ?? false)) {
            return $manifest;
        }

        $directory = storage_path('app/engineering-benchmark-runs/'.$run->id);
        $path = $directory.'/replay-manifest.json';
        $payload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($payload) || $payload === '') {
            return array_merge($manifest, [
                'artifact' => [
                    'status' => 'failed',
                    'reason' => 'manifest_json_encode_failed',
                ],
            ]);
        }

        File::ensureDirectoryExists($directory);
        File::put($path, $payload."\n");

        return array_merge($manifest, [
            'artifact' => [
                'status' => File::isFile($path) ? 'persisted' : 'failed',
                'path' => $path,
                'path_hash' => hash('sha256', $path),
                'bytes' => File::isFile($path) ? File::size($path) : null,
                'sha256' => File::isFile($path) ? hash('sha256', File::get($path)) : null,
                'written_at' => now()->toJSON(),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function withReplayManifestArtifactVerification(array $summary): array
    {
        $artifact = data_get($summary, 'replay_manifest.artifact');
        if (! is_array($artifact)) {
            return $summary;
        }

        $path = $this->nonEmptyString($artifact['path'] ?? null);
        $expectedSha = $this->nonEmptyString($artifact['sha256'] ?? null);
        if ($path === null) {
            data_set($summary, 'replay_manifest.artifact.integrity', [
                'checked' => true,
                'exists' => false,
                'hash_matches' => false,
                'reason' => 'artifact_path_missing',
                'checked_at' => now()->toJSON(),
            ]);

            return $summary;
        }

        $resolvedPath = $this->resolveReplayManifestArtifactPath($path);

        if ($resolvedPath === null) {
            data_set($summary, 'replay_manifest.artifact.integrity', [
                'checked' => true,
                'exists' => realpath($path) !== false,
                'hash_matches' => false,
                'reason' => $this->replayManifestArtifactPathFailureReason($path),
                'checked_at' => now()->toJSON(),
            ]);

            return $summary;
        }

        $actualSha = File::isFile($resolvedPath) ? hash('sha256', File::get($resolvedPath)) : null;
        data_set($summary, 'replay_manifest.artifact.integrity', [
            'checked' => true,
            'exists' => $actualSha !== null,
            'hash_matches' => $expectedSha !== null && hash_equals($expectedSha, (string) $actualSha),
            'expected_sha256' => $expectedSha,
            'actual_sha256' => $actualSha,
            'checked_at' => now()->toJSON(),
        ]);

        return $summary;
    }

    private function resolveReplayManifestArtifactPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $allowedRoot = realpath(storage_path('app/engineering-benchmark-runs'));
        $resolvedPath = realpath($path);
        if ($allowedRoot === false || $resolvedPath === false) {
            return null;
        }

        $allowedPrefix = rtrim($allowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($resolvedPath, $allowedPrefix) ? $resolvedPath : null;
    }

    private function replayManifestArtifactPathFailureReason(string $path): string
    {
        $allowedRoot = realpath(storage_path('app/engineering-benchmark-runs'));
        if ($allowedRoot === false) {
            return 'artifact_allowed_root_missing';
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            return 'artifact_missing';
        }

        return 'artifact_path_outside_allowed_root';
    }

    /**
     * @param  array<string,mixed>  $artifact
     * @return array<string,mixed>
     */
    private function safeReplayManifestArtifact(array $artifact): array
    {
        return Arr::except($artifact, ['path']);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function safeReplayManifestSummaryForApi(array $summary): array
    {
        $manifest = $this->arrayValue(data_get($summary, 'replay_manifest'));
        if ($manifest === []) {
            return $summary;
        }

        $artifact = $this->arrayValue($manifest['artifact'] ?? []);
        if ($artifact !== []) {
            $manifest['artifact'] = $this->safeReplayManifestArtifact($artifact);
        }

        $summary['replay_manifest'] = $manifest;

        return $summary;
    }

    private function canonicalJsonForHash(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalSortForHash($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : '';
    }

    private function canonicalSortForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalSortForHash($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $sub) {
            $value[$key] = $this->canonicalSortForHash($sub);
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>|mixed  $packet
     * @return array<string,mixed>
     */
    private function hashableReplayPacket(mixed $packet): array
    {
        $packet = is_array($packet) ? $packet : [];

        return Arr::except($packet, ['created_at']);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function redactWorkspace(array $options, bool $redact = true): array
    {
        foreach (['workspace', 'claude_code_baseline_workspace'] as $key) {
            if (! isset($options[$key]) || ! is_string($options[$key]) || trim($options[$key]) === '') {
                continue;
            }

            $workspace = $this->workspaceFrom($options[$key]) ?: trim($options[$key]);
            if ($redact) {
                $options[$key.'_hash'] = hash('sha256', $workspace);
                unset($options[$key]);

                continue;
            }

            $options[$key] = $workspace;
        }

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

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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

    private function benchmarkInput(): EngineeringBenchmarkInput
    {
        return $this->input ?? app(EngineeringBenchmarkInput::class);
    }
}
