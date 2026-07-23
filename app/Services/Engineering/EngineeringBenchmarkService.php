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
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use App\Services\Engineering\Benchmark\BenchmarkPrimitives;
use App\Services\Engineering\Benchmark\BenchmarkRunResultsSection;
use App\Services\Engineering\Benchmark\BenchmarkFairClaudeReportSection;
use App\Services\Engineering\Benchmark\BenchmarkFairClaudeExportSection;
use App\Services\Engineering\Benchmark\BenchmarkRunExecutionSection;
use App\Services\Engineering\Benchmark\BenchmarkSuiteDataSection;

class EngineeringBenchmarkService
{
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
        private readonly BenchmarkPrimitives $primitives,
        private readonly BenchmarkRunResultsSection $runResults,
        private readonly BenchmarkFairClaudeReportSection $report,
        private readonly BenchmarkFairClaudeExportSection $export,
        private readonly BenchmarkRunExecutionSection $runExec,
        private readonly BenchmarkSuiteDataSection $suiteData,
        private readonly ?EngineeringBenchmarkInput $input = null,
    ) {}

    public function createSuite(array $data): AtlasEngineeringBenchmarkSuite
    {
        $name = $this->primitives->nonEmptyString($data['name'] ?? null) ?: 'Atlas Engineering Benchmark';
        $slug = $this->primitives->slug($data['slug'] ?? $name);

        return AtlasEngineeringBenchmarkSuite::query()->create([
            'slug' => $slug,
            'name' => $name,
            'description' => $this->primitives->nonEmptyString($data['description'] ?? null),
            'status' => $this->primitives->nonEmptyString($data['status'] ?? null) ?: 'active',
            'default_runner_options_json' => $this->primitives->redactWorkspace(
                $this->primitives->arrayValue($data['default_runner_options'] ?? $data['default_runner_options_json'] ?? []),
                true,
            ),
            'metadata' => $this->primitives->arrayValue($data['metadata'] ?? []),
        ]);
    }

    public function ensureDefaultSuite(array $data = []): AtlasEngineeringBenchmarkSuite
    {
        $slug = $this->primitives->slug($data['slug'] ?? self::DEFAULT_SUITE_SLUG);
        $name = $this->primitives->nonEmptyString($data['name'] ?? null) ?: 'Atlas Core Smoke Benchmark';
        $description = $this->primitives->nonEmptyString($data['description'] ?? null)
            ?: 'Suite operacional padrao para medir regressao do Atlas Engineering Harness Runner.';
        $runnerOptions = $this->primitives->arrayValue($data['default_runner_options'] ?? $data['default_runner_options_json'] ?? []);
        if ($runnerOptions === []) {
            $runnerOptions = self::DEFAULT_RUNNER_OPTIONS;
        }

        $suite = AtlasEngineeringBenchmarkSuite::query()->firstOrNew(['slug' => $slug]);
        $suite->forceFill([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'status' => $this->primitives->nonEmptyString($data['status'] ?? null) ?: ($suite->status ?: 'active'),
            'default_runner_options_json' => $this->primitives->redactWorkspace($runnerOptions, true),
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
            ], $suite->metadata ?? [], $this->primitives->arrayValue($data['metadata'] ?? [])),
        ])->save();

        return $suite->refresh();
    }

    public function registerCase(AtlasEngineeringBenchmarkSuite $suite, array $data): AtlasEngineeringBenchmarkCase
    {
        $runnerOptions = $this->primitives->arrayValue($data['runner_options'] ?? $data['runner_options_json'] ?? []);
        $metadata = $this->primitives->arrayValue($data['metadata'] ?? []);
        $workspace = $this->primitives->workspaceFrom($data['workspace'] ?? ($runnerOptions['workspace'] ?? null));
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

        $caseCode = $this->primitives->caseCode($data['case_code'] ?? $data['title'] ?? Str::uuid()->toString());
        $contract = $this->primitives->arrayValue($data['task_contract'] ?? $data['task_contract_json'] ?? []);
        $tags = $this->suiteData->normalizedTags($data['tags'] ?? $data['tags_json'] ?? []);
        $corpus = $this->suiteData->caseCorpusFields($data, $metadata, $tags, $contract);

        $case = AtlasEngineeringBenchmarkCase::query()->firstOrNew([
            'suite_id' => $suite->id,
            'case_code' => $caseCode,
        ]);
        $case->forceFill([
            'suite_id' => $suite->id,
            'task_id' => $this->primitives->nonEmptyString($data['task_id'] ?? null),
            'case_code' => $caseCode,
            'title' => $this->primitives->nonEmptyString($data['title'] ?? null) ?: Str::headline(str_replace('_', ' ', $caseCode)),
            'description' => $this->primitives->nonEmptyString($data['description'] ?? null),
            'workspace_path_hash' => $workspace ? hash('sha256', $workspace) : null,
            'task_contract_json' => $contract,
            'runner_options_json' => $this->primitives->redactWorkspace($runnerOptions, ! $persistWorkspace),
            'expected_decision' => $this->primitives->nonEmptyString($data['expected_decision'] ?? null),
            'min_score' => max(0, min(100, (int) ($data['min_score'] ?? 85))),
            'corpus_tier' => $corpus['corpus_tier'],
            'domain_slug' => $corpus['domain_slug'],
            'risk_profile' => $corpus['risk_profile'],
            'curation_status' => $corpus['curation_status'],
            'curation_score' => $corpus['curation_score'],
            'corpus_fingerprint' => $this->suiteData->corpusFingerprint($caseCode, $contract, [
                'expected_decision' => $this->primitives->nonEmptyString($data['expected_decision'] ?? null) ?: 'resolved',
                'min_score' => max(0, min(100, (int) ($data['min_score'] ?? 85))),
                'tags' => $tags,
                'corpus' => $corpus,
            ]),
            'curated_at' => $corpus['curated_at'],
            'tags_json' => $tags,
            'status' => $this->primitives->nonEmptyString($data['status'] ?? null) ?: 'active',
            'metadata' => $metadata,
        ])->save();

        return $case->refresh();
    }

    public function promoteRunToCase(
        AtlasEngineeringRun $run,
        AtlasEngineeringBenchmarkSuite $suite,
        array $data = [],
    ): AtlasEngineeringBenchmarkCase {
        $run->loadMissing(['task', 'patchArtifacts', 'testRuns', 'controlResults', 'reviewFindings']);
        if (! $run->task) {
            throw new InvalidArgumentException('Engineering run sem task nao pode virar benchmark case.');
        }

        $workspace = $this->primitives->workspaceFrom($data['workspace'] ?? null);
        $persistWorkspace = (bool) ($data['persist_workspace_path'] ?? false);
        $workspaceHash = $workspace ? hash('sha256', $workspace) : $run->workspace_path_hash;
        $runnerOptions = array_replace_recursive(
            $this->suiteData->runnerOptionsFromRun($run),
            $this->primitives->arrayValue($data['runner_options'] ?? $data['runner_options_json'] ?? []),
        );

        if ($workspace !== null && $persistWorkspace) {
            $runnerOptions['workspace'] = $workspace;
        } else {
            unset($runnerOptions['workspace']);
        }

        $contract = $this->primitives->arrayValue($data['task_contract'] ?? $data['task_contract_json'] ?? []);
        if ($contract === []) {
            $contract = $this->primitives->arrayValue(data_get($run->task->metadata, 'engineering_contract', []));
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
        $caseCode = $this->primitives->caseCode($data['case_code'] ?? 'run_'.$shortRunId);
        $tags = $this->suiteData->tagsForPromotedRun($run, $data['tags'] ?? $data['tags_json'] ?? []);
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
            'source_blueprint' => $this->primitives->arrayValue(data_get($run->metadata, 'blueprint', [])),
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
        ], $this->primitives->arrayValue($data['metadata'] ?? []));
        $corpus = $this->suiteData->caseCorpusFields($data, $metadata, $tags, $contract, $run->task?->domain);

        $case = AtlasEngineeringBenchmarkCase::query()->firstOrNew([
            'suite_id' => $suite->id,
            'case_code' => $caseCode,
        ]);
        $case->forceFill([
            'suite_id' => $suite->id,
            'task_id' => $run->task_id,
            'case_code' => $caseCode,
            'title' => $this->primitives->nonEmptyString($data['title'] ?? null) ?: $run->task->title,
            'description' => $this->primitives->nonEmptyString($data['description'] ?? null) ?: $run->task->description,
            'workspace_path_hash' => $workspaceHash,
            'task_contract_json' => $contract,
            'runner_options_json' => $this->primitives->redactWorkspace($runnerOptions, ! $persistWorkspace),
            'expected_decision' => $this->primitives->nonEmptyString($data['expected_decision'] ?? null) ?: ($run->decision ?: 'resolved'),
            'min_score' => max(0, min(100, (int) ($data['min_score'] ?? $this->suiteData->defaultMinScoreFor($run)))),
            'corpus_tier' => $corpus['corpus_tier'],
            'domain_slug' => $corpus['domain_slug'],
            'risk_profile' => $corpus['risk_profile'],
            'curation_status' => $corpus['curation_status'],
            'curation_score' => $corpus['curation_score'],
            'corpus_fingerprint' => $this->suiteData->corpusFingerprint($caseCode, $contract, [
                'expected_decision' => $this->primitives->nonEmptyString($data['expected_decision'] ?? null) ?: ($run->decision ?: 'resolved'),
                'min_score' => max(0, min(100, (int) ($data['min_score'] ?? $this->suiteData->defaultMinScoreFor($run)))),
                'tags' => $tags,
                'corpus' => $corpus,
                'source_run_id' => $run->id,
            ]),
            'curated_at' => $corpus['curated_at'],
            'tags_json' => $tags,
            'status' => $this->primitives->nonEmptyString($data['status'] ?? null) ?: 'active',
            'metadata' => $metadata,
        ])->save();

        return $case->refresh();
    }

    public function promoteRecentRuns(AtlasEngineeringBenchmarkSuite $suite, array $options = []): Collection
    {
        $limit = $this->benchmarkInput()->promoteRecentRunsLimit($options['limit'] ?? null);
        $minSourceScore = $this->benchmarkInput()->minSourceScore($options['min_source_score'] ?? null);
        $decision = $this->primitives->nonEmptyString($options['decision'] ?? null) ?: 'resolved';
        $workspace = $this->primitives->workspaceFrom($options['workspace'] ?? null);

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

    public function runSuite(AtlasEngineeringBenchmarkSuite $suite, array $options = []): AtlasEngineeringBenchmarkRun
    {
        $suite->loadMissing('cases');
        $cases = $this->runExec->selectedCases($suite, $options);
        $runnerOptions = $this->withReleaseQualityScanDefaults($this->runExec->runnerOverrides($options));
        $caseSetHash = $this->suiteData->caseSetHash($cases);
        $identity = $this->suiteData->benchmarkIdentity($suite, $runnerOptions, $options, $caseSetHash);

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
            'runner_options_json' => $this->primitives->redactWorkspace($runnerOptions, true),
            'started_at' => now(),
            'metadata' => [
                'case_codes' => $cases->pluck('case_code')->values()->all(),
                'workspace_path_hash' => $this->primitives->workspaceFrom($options['workspace'] ?? null)
                    ? hash('sha256', (string) $this->primitives->workspaceFrom($options['workspace'] ?? null))
                    : null,
                'benchmark_identity' => $identity,
            ],
        ]);

        if ($cases->isEmpty()) {
            $quality = $this->suiteData->emptyQualityMetrics();
            $releaseGate = $this->suiteData->releaseGateFor($benchmarkRun, 'empty', null, null, $quality, [
                'trend_status' => 'empty',
                'pass_rate_delta' => null,
                'average_score_delta' => null,
                'baseline' => null,
            ]);
            $rollout = $this->suiteData->rolloutFor($benchmarkRun, 'empty', $releaseGate);
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

        return $this->runExec->finalizeRun($benchmarkRun->refresh());
    }

    public function runCase(
        AtlasEngineeringBenchmarkRun $benchmarkRun,
        AtlasEngineeringBenchmarkCase $case,
        array $overrides = [],
    ): AtlasEngineeringBenchmarkResult {
        $startedAt = microtime(true);
        $expectation = $this->runExec->expectationFor($case);
        $pairedBaselineWorktreePlan = null;
        $pairedWorkspaces = null;
        $claudeCodeBaseline = null;

        try {
            $task = $this->runExec->taskForCase($case);
            $runnerOptions = $this->runExec->runnerOptionsForCase($case, $overrides);
            if (! isset($runnerOptions['workspace']) || ! is_string($runnerOptions['workspace']) || trim($runnerOptions['workspace']) === '') {
                throw new InvalidArgumentException('Benchmark case precisa de workspace no run, na suite ou no case.');
            }

            $caseDeadline = $this->runExec->caseDeadline($startedAt, $runnerOptions);
            $runnerOptions = $this->runExec->capCaseTimeouts($runnerOptions, $caseDeadline);
            $paired = $this->runExec->preparePairedBaselineWorkspace($case, $runnerOptions);
            $runnerOptions = $paired['runner_options'];
            $pairedBaselineWorktreePlan = $paired['baseline_plan'];
            $pairedWorkspaces = $paired['artifact'];

            $this->runExec->assertCaseDeadline($caseDeadline, 'before_claude_code_baseline');
            $runnerOptions = $this->runExec->capCaseTimeouts($runnerOptions, $caseDeadline);
            $claudeCodeBaseline = $this->claudeCodeBaseline->capture($case, $task, $runnerOptions);
            if ($pairedBaselineWorktreePlan !== null) {
                $pairedWorkspaces['claude_code_baseline']['release'] = $this->runExec->releasePairedBaselineWorkspace(
                    $pairedBaselineWorktreePlan,
                    $runnerOptions,
                );
                $pairedBaselineWorktreePlan = null;
            }

            $this->runExec->assertCaseDeadline($caseDeadline, 'before_atlas_provider');
            $runnerOptions = $this->runExec->capCaseTimeouts($runnerOptions, $caseDeadline);
            $atlasStartedAt = microtime(true);
            $payload = $this->runner->run($task, $runnerOptions);
            $engineeringRunId = $this->primitives->nonEmptyString(data_get($payload, 'run.id'));
            $decision = $this->primitives->nonEmptyString(data_get($payload, 'run.decision'));
            $score = data_get($payload, 'run.score');
            $score = is_numeric($score) ? (int) $score : null;
            $durationMs = $this->primitives->durationMs($atlasStartedAt);
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
                    'runner_options' => $this->primitives->redactWorkspace($runnerOptions, true),
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
                $pairedWorkspaces['claude_code_baseline']['release'] = $this->runExec->releasePairedBaselineWorkspace(
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
                'duration_ms' => $this->primitives->durationMs($startedAt),
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
                'corpus_manifest' => data_get($suite->metadata, 'corpus_manifest') ?: $this->suiteData->corpusManifestFor($suite->cases),
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
                ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->runExec->benchmarkRunSummary($run))
                ->values()
                ->all(),
        ];
    }

    public function runPayload(AtlasEngineeringBenchmarkRun $run): array
    {
        $run->loadMissing(['suite', 'results.benchmarkCase', 'results.engineeringRun']);

        return [
            'benchmark_run' => $this->runExec->benchmarkRunSummary($run),
            'suite' => $run->suite ? [
                'id' => $run->suite->id,
                'slug' => $run->suite->slug,
                'name' => $run->suite->name,
            ] : null,
            'results' => $run->results
                ->sortBy(fn (AtlasEngineeringBenchmarkResult $result): string => (string) ($result->benchmarkCase?->case_code ?? $result->case_id))
                ->map(fn (AtlasEngineeringBenchmarkResult $result): array => $this->runResults->resultPayload($result))
                ->values()
                ->all(),
        ];
    }

    public function trendPayload(AtlasEngineeringBenchmarkSuite $suite, array $options = []): array
    {
        $limit = $this->benchmarkInput()->trendLimit($options['limit'] ?? null);
        $benchmarkKey = $this->primitives->nonEmptyString($options['benchmark_key'] ?? null);
        $provider = $this->primitives->nonEmptyString($options['provider'] ?? null);

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
                        ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->runExec->benchmarkRunSummary($run))
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
                ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->runExec->benchmarkRunSummary($run))
                ->values()
                ->all(),
            'series' => $series,
        ];
    }

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
        $paired = $this->runResults->pairedScorecardSummary($fairResults, $this->primitives->runsCostMicrousd($fairRuns));
        $allPaired = $this->runResults->pairedScorecardSummary($results, $this->primitives->runsCostMicrousd($runs));
        $baseline = $this->runResults->claudeCodeBaselineSummary($fairResults);
        $replay = $this->report->fairClaudeReplayReport($fairRuns);
        $caseComparisons = $this->fairClaudeCaseComparisons($fairResults, $limit);
        $corpusManifest = $this->primitives->arrayValue(data_get($suite->metadata ?? [], 'corpus_manifest', []));
        $readiness = $this->fairClaudeReportReadiness($fairRuns, $paired, $baseline, $replay, $corpusManifest);
        $invalidBatteryFingerprint = $this->report->fairClaudeInvalidBatteryFingerprint($fairRuns, $readiness, $paired, $replay, $caseComparisons);
        $invalidBatteryTriage = $this->report->fairClaudeInvalidBatteryTriageState($suite, $invalidBatteryFingerprint);
        $nextActions = $this->report->fairClaudeReportNextActions($readiness, $paired, $baseline, $replay, $caseComparisons, $invalidBatteryTriage);
        $batteryExecutionContract = $this->report->fairClaudeBatteryExecutionContract($suite, $readiness, $paired, $baseline, $replay);
        $experimentValidity = $this->report->fairClaudeExperimentValidity($readiness, $paired, $caseComparisons);
        $resultIntegrity = $this->report->fairClaudeResultIntegrity($readiness, $paired, $caseComparisons, $invalidBatteryTriage, $experimentValidity);
        $executiveSummary = $this->report->fairClaudeExecutiveSummary($readiness, $paired, $baseline, $replay, $caseComparisons);
        $executiveSummary['result_integrity'] = Arr::only($resultIntegrity, [
            'status',
            'score_admitted',
            'claim_winner_admitted',
            'winner_for_claim',
            'provisional_leader',
            'operator_headline',
            'experiment_validity',
        ]);
        $evidencePacket = $this->report->fairClaudeEvidencePacket(
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
        $claimMarkdown = $this->report->fairClaudeClaimMarkdown(
            $executiveSummary,
            $evidencePacket,
            $nextActions,
            $caseComparisons,
        );
        $runHistory = $runs
            ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->report->fairClaudeRunHistoryPayload(
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
            'history_timeline' => $this->report->fairClaudeHistoryTimeline($runHistory),
            'runs' => $runHistory->all(),
        ];

        $payload['export_bundle'] = $this->export->fairClaudeExportBundle($payload);

        return $payload;
    }

    public function refreshCorpusManifest(AtlasEngineeringBenchmarkSuite $suite): array
    {
        $suite->loadMissing('cases');
        $cases = $suite->cases;
        foreach ($cases as $case) {
            $updates = [];
            $tags = $this->suiteData->normalizedTags($case->tags_json ?? []);
            $contract = $this->primitives->arrayValue($case->task_contract_json ?? []);
            $metadata = $this->primitives->arrayValue($case->metadata ?? []);
            $corpus = $this->suiteData->caseCorpusFields([], $metadata, $tags, $contract, $case->task?->domain);

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
                $updates['corpus_fingerprint'] = $this->suiteData->corpusFingerprint($case->case_code, $contract, [
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
        $manifest = $this->suiteData->corpusManifestFor($suite->cases);
        $suite->forceFill([
            'metadata' => array_replace_recursive($this->primitives->arrayValue($suite->metadata ?? []), [
                'corpus_manifest' => $manifest,
            ]),
        ])->save();

        return $manifest;
    }

    public function recordOutcome(AtlasEngineeringBenchmarkRun $run, array $data): AtlasEngineeringBenchmarkRun
    {
        $status = $this->suiteData->outcomeStatus($data['outcome_status'] ?? $data['status'] ?? null);
        $score = isset($data['outcome_score']) || isset($data['score'])
            ? max(0, min(100, (int) ($data['outcome_score'] ?? $data['score'])))
            : null;
        $recordedAt = now();
        $outcome = [
            'status' => $status,
            'score' => $score,
            'summary' => $this->primitives->nonEmptyString($data['summary'] ?? null),
            'notes' => $this->primitives->nonEmptyString($data['notes'] ?? null),
            'signals' => $this->primitives->arrayValue($data['signals'] ?? []),
            'rollback_reason' => $this->primitives->nonEmptyString($data['rollback_reason'] ?? null),
            'incident_ref' => $this->primitives->nonEmptyString($data['incident_ref'] ?? null),
            'recorded_at' => $recordedAt->toJSON(),
            'recorded_by' => $this->primitives->nonEmptyString($data['recorded_by'] ?? null) ?: 'operator',
        ];
        $summary = $this->primitives->arrayValue($run->summary_json ?? []);
        $summary['outcome'] = $outcome;

        $run->forceFill([
            'rollout_status' => $this->suiteData->rolloutStatusAfterOutcome($status),
            'outcome_status' => $status,
            'outcome_score' => $score,
            'outcome_json' => $outcome,
            'outcome_recorded_at' => $recordedAt,
            'outcome_recorded_by' => $outcome['recorded_by'],
            'summary_json' => $summary,
        ])->save();

        return $run->refresh();
    }

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

        $signals = $this->suiteData->calibrationCaseSignals($runs);
        $caseHealth = [
            'by_tier' => $this->suiteData->calibrationDistribution($signals, 'corpus_tier'),
            'by_domain' => $this->suiteData->calibrationDistribution($signals, 'domain_slug'),
            'by_risk' => $this->suiteData->calibrationDistribution($signals, 'risk_profile'),
            'by_curation_status' => $this->suiteData->calibrationDistribution($signals, 'curation_status'),
        ];
        $quarantineCandidates = $this->suiteData->quarantineCandidates($signals);
        $riskOverrides = $this->suiteData->rolloutCalibrationOverrides($caseHealth);
        $recommendedPolicy = $this->suiteData->recommendedRolloutPolicy($suite, $totalOutcomes, $badOutcomeRate, $statusCounts, $riskOverrides);

        $calibration = [
            'generated_at' => now()->toJSON(),
            'sample_limit' => $limit,
            'sample_window' => [
                'latest_outcome_at' => $runs->first()?->outcome_recorded_at?->toJSON(),
                'oldest_outcome_at' => $runs->last()?->outcome_recorded_at?->toJSON(),
            ],
            'status' => $this->suiteData->calibrationStatus($totalOutcomes, $badOutcomeRate, $statusCounts),
            'confidence' => $this->suiteData->calibrationConfidence($totalOutcomes),
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
        $metadata = $this->primitives->arrayValue($suite->metadata ?? []);
        $metadata['rollout_policy'] = $recommendedPolicy;
        $metadata['rollout_calibration'] = $calibration;
        $metadata['corpus_health'] = $corpusHealth;

        $suite->forceFill(['metadata' => $metadata])->save();

        return $calibration;
    }

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

    private function fairClaudeCaseComparisons(Collection $results, int $limit): Collection
    {
        return $results
            ->map(function (AtlasEngineeringBenchmarkResult $result): ?array {
                $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
                if (! is_array($scorecard)) {
                    return null;
                }
                $scorecard = $this->normalizePairedScorecardForReport($scorecard);

                $atlas = $this->primitives->arrayValue(data_get($scorecard, 'atlas', []));
                $baseline = $this->primitives->arrayValue(data_get($scorecard, 'claude_code_baseline', []));
                $case = $this->primitives->arrayValue(data_get($scorecard, 'case', []));
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
                        'score' => $this->primitives->nullableInt(data_get($atlas, 'score', $result->score)),
                        'duration_ms' => $this->primitives->nullableInt(data_get($atlas, 'duration_ms', $result->duration_ms)),
                        'attempt_count' => $this->primitives->nullableInt(data_get($atlas, 'attempt_count')),
                        'repair_attempt_count' => $this->primitives->nullableInt(data_get($atlas, 'repair_attempt_count')),
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
                        'score' => $this->primitives->nullableInt(data_get($baseline, 'score')),
                        'duration_ms' => $this->primitives->nullableInt(data_get($baseline, 'duration_ms')),
                        'human_intervention_count' => max(0, (int) data_get($baseline, 'human_intervention_count', 0)),
                        'executed' => (bool) data_get($result->observed_json ?? [], 'claude_code_baseline.executed', false),
                        'status' => $this->primitives->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.status')),
                        'error_code' => $this->primitives->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.error_code')),
                        'gate_status' => $this->primitives->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.status')),
                        'gate_reason' => $this->primitives->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.reason')),
                        'gate_stderr_excerpt' => $this->primitives->nonEmptyString(data_get($result->observed_json ?? [], 'claude_code_baseline.deterministic_gate.stderr_excerpt')),
                    ],
                    'deltas' => [
                        'score' => $this->primitives->nullableInt(data_get($scorecard, 'deltas.score')),
                        'duration_ms' => $this->primitives->nullableInt(data_get($scorecard, 'deltas.duration_ms')),
                        'human_intervention_count' => $this->primitives->nullableInt(data_get($scorecard, 'deltas.human_intervention_count')),
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

    private function benchmarkInput(): EngineeringBenchmarkInput
    {
        return $this->input ?? app(EngineeringBenchmarkInput::class);
    }

    public function writeFairClaudeExportBundle(array $payload, string $directory): array
    {
        return $this->export->writeFairClaudeExportBundle($payload, $directory);
    }

    public function verifyFairClaudeExportBundle(string $directory): array
    {
        return $this->export->verifyFairClaudeExportBundle($directory);
    }

    public function replayManifestPayload(AtlasEngineeringBenchmarkRun $run): array
    {
        return $this->runResults->replayManifestPayload($run);
    }

    private function evaluate(AtlasEngineeringBenchmarkCase $case, ?string $decision, ?int $score, ?array $fairScorecard = null): array
    {
        return $this->runResults->evaluate($case, $decision, $score, $fairScorecard);
    }

    private function fairScorecard(array $payload, array $runnerOptions): ?array
    {
        return $this->runResults->fairScorecard($payload, $runnerOptions);
    }

    private function pairedScorecard(
        AtlasEngineeringBenchmarkCase $case,
        array $atlasEvaluation,
        ?array $fairScorecard,
        ?array $claudeCodeBaseline,
        ?string $atlasDecision,
        ?int $atlasScore,
    ): ?array
    {
        return $this->runResults->pairedScorecard($case, $atlasEvaluation, $fairScorecard, $claudeCodeBaseline, $atlasDecision, $atlasScore);
    }

    private function fairClaudeReportReadiness(Collection $runs, array $paired, array $baseline, array $replay, array $corpusManifest): array
    {
        return $this->report->fairClaudeReportReadiness($runs, $paired, $baseline, $replay, $corpusManifest);
    }

    private function normalizePairedScorecardForReport(array $scorecard): array
    {
        return $this->runResults->normalizePairedScorecardForReport($scorecard);
    }

    private function withReplayManifestArtifactVerification(array $summary): array
    {
        return $this->runResults->withReplayManifestArtifactVerification($summary);
    }

    private function withFairClaudeDefaults(array $options): array
    {
        return $this->runExec->withFairClaudeDefaults($options);
    }

    private function benchmarkFinalPacket(
        AtlasEngineeringBenchmarkRun $run,
        Collection $results,
        string $status,
        array $quality,
        array $releaseGate,
        array $manifest,
    ): array
    {
        return $this->runResults->benchmarkFinalPacket($run, $results, $status, $quality, $releaseGate, $manifest);
    }

    private function withReleaseQualityScanDefaults(array $options): array
    {
        return $this->runExec->withReleaseQualityScanDefaults($options);
    }
}
