<?php

namespace App\Http\Controllers;

use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\Process;

class EngineeringBenchmarkController extends Controller
{
    public function indexSuites(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $suites = AtlasEngineeringBenchmarkSuite::query()
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->with('latestBenchmarkRun')
            ->withCount(['cases', 'benchmarkRuns'])
            ->orderBy('slug')
            ->get()
            ->map(fn (AtlasEngineeringBenchmarkSuite $suite): array => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
                'description' => $suite->description,
                'status' => $suite->status,
                'cases_count' => $suite->cases_count,
                'benchmark_runs_count' => $suite->benchmark_runs_count,
                'corpus_manifest' => data_get($suite->metadata, 'corpus_manifest'),
                'corpus_health' => data_get($suite->metadata, 'corpus_health'),
                'rollout_policy' => data_get($suite->metadata, 'rollout_policy'),
                'rollout_calibration' => data_get($suite->metadata, 'rollout_calibration'),
                'latest_run' => $suite->latestBenchmarkRun ? [
                    'id' => $suite->latestBenchmarkRun->id,
                    'suite_id' => $suite->latestBenchmarkRun->suite_id,
                    'benchmark_key' => $suite->latestBenchmarkRun->benchmark_key,
                    'provider' => $suite->latestBenchmarkRun->provider,
                    'model' => $suite->latestBenchmarkRun->model,
                    'mode' => $suite->latestBenchmarkRun->mode,
                    'case_set_hash' => $suite->latestBenchmarkRun->case_set_hash,
                    'status' => $suite->latestBenchmarkRun->status,
                    'total_cases' => $suite->latestBenchmarkRun->total_cases,
                    'passed_cases' => $suite->latestBenchmarkRun->passed_cases,
                    'failed_cases' => $suite->latestBenchmarkRun->failed_cases,
                    'baseline_run_id' => $suite->latestBenchmarkRun->baseline_run_id,
                    'pass_rate' => $suite->latestBenchmarkRun->pass_rate,
                    'pass_rate_delta' => $suite->latestBenchmarkRun->pass_rate_delta,
                    'average_score' => $suite->latestBenchmarkRun->average_score,
                    'average_score_delta' => $suite->latestBenchmarkRun->average_score_delta,
                    'duration_ms' => $suite->latestBenchmarkRun->duration_ms,
                    'trend_status' => $suite->latestBenchmarkRun->trend_status,
                    'harness_version' => $suite->latestBenchmarkRun->harness_version,
                    'total_attempts' => $suite->latestBenchmarkRun->total_attempts,
                    'failed_control_count' => $suite->latestBenchmarkRun->failed_control_count,
                    'blocked_control_count' => $suite->latestBenchmarkRun->blocked_control_count,
                    'skipped_required_control_count' => $suite->latestBenchmarkRun->skipped_required_control_count,
                    'failed_test_count' => $suite->latestBenchmarkRun->failed_test_count,
                    'open_review_finding_count' => $suite->latestBenchmarkRun->open_review_finding_count,
                    'blocking_review_finding_count' => $suite->latestBenchmarkRun->blocking_review_finding_count,
                    'changed_files_count' => $suite->latestBenchmarkRun->changed_files_count,
                    'risk_flag_count' => $suite->latestBenchmarkRun->risk_flag_count,
                    'total_tokens' => $suite->latestBenchmarkRun->total_tokens,
                    'cost_microusd' => $suite->latestBenchmarkRun->cost_microusd,
                    'telemetry_coverage_count' => $suite->latestBenchmarkRun->telemetry_coverage_count,
                    'quality_metrics' => $suite->latestBenchmarkRun->quality_metrics_json,
                    'release_gate_status' => $suite->latestBenchmarkRun->release_gate_status,
                    'release_gate_profile' => $suite->latestBenchmarkRun->release_gate_profile,
                    'release_gate_policy' => $suite->latestBenchmarkRun->release_gate_policy_json,
                    'release_gate_failures' => $suite->latestBenchmarkRun->release_gate_failures_json,
                    'release_gate_warnings' => $suite->latestBenchmarkRun->release_gate_warnings_json,
                    'rollout_status' => $suite->latestBenchmarkRun->rollout_status,
                    'rollout_policy' => $suite->latestBenchmarkRun->rollout_policy_json,
                    'rollout_decision_at' => $suite->latestBenchmarkRun->rollout_decision_at?->toJSON(),
                    'outcome_status' => $suite->latestBenchmarkRun->outcome_status,
                    'outcome_score' => $suite->latestBenchmarkRun->outcome_score,
                    'outcome' => $suite->latestBenchmarkRun->outcome_json,
                    'outcome_recorded_at' => $suite->latestBenchmarkRun->outcome_recorded_at?->toJSON(),
                    'outcome_recorded_by' => $suite->latestBenchmarkRun->outcome_recorded_by,
                    'started_at' => $suite->latestBenchmarkRun->started_at?->toJSON(),
                    'finished_at' => $suite->latestBenchmarkRun->finished_at?->toJSON(),
                    'created_at' => $suite->latestBenchmarkRun->created_at?->toJSON(),
                ] : null,
                'created_at' => $suite->created_at?->toJSON(),
                'updated_at' => $suite->updated_at?->toJSON(),
            ])
            ->values()
            ->all();

        return response()->json(['suites' => $suites]);
    }

    public function storeSuite(Request $request, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'default_runner_options' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $suite = $benchmarks->createSuite($data);

        return response()->json($benchmarks->suitePayload($suite), 201);
    }

    public function ensureDefaultSuite(Request $request, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'default_runner_options' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $suite = $benchmarks->ensureDefaultSuite($data);

        return response()->json($benchmarks->suitePayload($suite), $suite->wasRecentlyCreated ? 201 : 200);
    }

    public function showSuite(string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->suitePayload($this->resolveSuite($suite)));
    }

    public function showTrends(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'benchmark_key' => ['nullable', 'string', 'max:180'],
            'provider' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json($benchmarks->trendPayload($this->resolveSuite($suite), $data));
    }

    public function showFairClaudeReport(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($benchmarks->fairClaudeReportPayload($this->resolveSuite($suite), $data));
    }

    public function rivalsBatteryPlan(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['official_fair', 'same_model', 'max'])],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'baseline_workspace' => ['nullable', 'string', 'max:1000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $resolved = $this->resolveSuite($suite)->load('cases');
        $plan = $this->rivalsBatteryPlanPayload(
            $resolved,
            $data,
            $benchmarks->fairClaudeReportPayload($resolved, ['limit' => 20]),
        );

        return response()->json(['battery_plan' => $plan]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function rivalsBatteryPlanPayload(AtlasEngineeringBenchmarkSuite $resolved, array $data, ?array $fairClaudeReport = null): array
    {
        $mode = (string) $data['mode'];
        $workspace = trim((string) ($data['workspace'] ?? ''));
        $baselineWorkspace = trim((string) ($data['baseline_workspace'] ?? ''));
        $provider = trim((string) ($data['provider'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));
        $limit = (int) ($data['limit'] ?? 6);
        $releaseCorpus = (int) (data_get($resolved->metadata, 'corpus_manifest.official_subsets.release')
            ?: $resolved->cases->where('status', 'active')->where('corpus_tier', 'release')->count());
        $activeCorpus = (int) (data_get($resolved->metadata, 'corpus_manifest.active_cases')
            ?: $resolved->cases->where('status', 'active')->count());
        $minimumRelease = 6;
        $workspaceExists = $workspace !== '' && is_dir($workspace);
        $baselineWorkspaceExists = $baselineWorkspace !== '' && is_dir($baselineWorkspace);
        $workspaceGit = $workspaceExists ? $this->gitWorkspaceState($workspace) : ['is_git' => false, 'clean' => null, 'dirty_files' => [], 'status' => $workspace === '' ? 'missing' : 'missing_or_unreadable'];
        $baselineGit = $baselineWorkspaceExists ? $this->gitWorkspaceState($baselineWorkspace) : ['is_git' => false, 'clean' => null, 'dirty_files' => [], 'status' => $baselineWorkspace === '' ? 'missing' : 'missing_or_unreadable'];
        $baselineSeparate = $workspaceExists
            && $baselineWorkspaceExists
            && realpath($workspace) !== realpath($baselineWorkspace);
        $blockers = [];
        if ($workspace === '') {
            $blockers[] = 'workspace_required';
        }
        if ($workspace !== '' && ! $workspaceExists) {
            $blockers[] = 'workspace_missing_or_unreadable';
        }
        if ($workspaceExists && ($workspaceGit['is_git'] ?? false) !== true) {
            $blockers[] = 'atlas_workspace_not_git_worktree';
        }
        if (($workspaceGit['is_git'] ?? false) && ($workspaceGit['clean'] ?? null) !== true) {
            $blockers[] = 'atlas_workspace_dirty';
        }
        if ($releaseCorpus < $minimumRelease) {
            $blockers[] = 'release_corpus_below_minimum';
        }
        $triageRequiredBeforeRerun = $this->fairClaudeReportRequiresTriageBeforeRerun($fairClaudeReport)
            || $this->suiteHasHistoricalInvalidFairBattery($resolved);
        if ($triageRequiredBeforeRerun) {
            $blockers[] = 'historical_invalid_battery_requires_triage';
        }
        if ($mode === 'official_fair' && $baselineWorkspace === '') {
            $blockers[] = 'separate_baseline_workspace_required';
        }
        if ($mode === 'official_fair' && $baselineWorkspace !== '' && ! $baselineWorkspaceExists) {
            $blockers[] = 'claude_code_baseline_workspace_missing_or_unreadable';
        }
        if ($mode === 'official_fair' && $workspaceExists && $baselineWorkspaceExists && ! $baselineSeparate) {
            $blockers[] = 'baseline_workspace_must_be_separate';
        }
        if ($mode === 'official_fair' && $baselineWorkspaceExists && ($baselineGit['is_git'] ?? false) !== true) {
            $blockers[] = 'claude_code_baseline_workspace_not_git_worktree';
        }
        if ($mode === 'official_fair' && ($baselineGit['is_git'] ?? false) && ($baselineGit['clean'] ?? null) !== true) {
            $blockers[] = 'claude_code_baseline_workspace_dirty';
        }
        if ($mode !== 'max' && $provider === '') {
            $blockers[] = 'provider_required';
        }
        if ($mode !== 'max' && $model === '') {
            $blockers[] = 'model_required';
        }

        $effectiveProvider = match ($mode) {
            'official_fair' => 'claude_cli',
            'max' => $provider !== '' ? $provider : 'policy',
            default => $provider,
        };
        $effectiveModel = match ($mode) {
            'official_fair' => 'opus',
            'max' => $model !== '' ? $model : 'best-quality',
            default => $model,
        };
        $baseline = match ($mode) {
            'official_fair' => 'paired',
            'same_model' => 'atlas_fixed_provider_model',
            default => 'not_paired',
        };
        $primaryBlocker = $blockers[0] ?? null;
        $operatorHeadline = $blockers === []
            ? 'Battery plan ready for operator review.'
            : $this->rivalsBatteryPlanBlockerHeadline($primaryBlocker);
        $workspaceDirtyFiles = array_values((array) ($workspaceGit['dirty_files'] ?? []));
        $baselineDirtyFiles = array_values((array) ($baselineGit['dirty_files'] ?? []));
        $plan = [
            'schema_version' => 'atlas.rivals.battery_plan.v1',
            'suite' => [
                'id' => $resolved->id,
                'slug' => $resolved->slug,
                'name' => $resolved->name,
            ],
            'mode' => $mode,
            'status' => $blockers === [] ? 'ready_for_operator_confirmation' : 'blocked',
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'operator_report' => [
                'schema_version' => 'atlas.rivals.battery_plan_operator_report.v1',
                'status_label' => $blockers === [] ? 'Ready for review' : 'Blocked',
                'headline' => $operatorHeadline,
                'primary_blocker' => $primaryBlocker,
                'blocker_count' => count($blockers),
                'workspace_status' => (string) ($workspaceGit['status'] ?? 'unknown'),
                'workspace_dirty_count' => count($workspaceDirtyFiles),
                'workspace_dirty_files_sample' => array_slice($workspaceDirtyFiles, 0, 20),
                'baseline_workspace_status' => (string) ($baselineGit['status'] ?? 'unknown'),
                'baseline_workspace_dirty_count' => count($baselineDirtyFiles),
                'baseline_workspace_dirty_files_sample' => array_slice($baselineDirtyFiles, 0, 20),
                'result_integrity_status' => data_get($fairClaudeReport, 'result_integrity.status'),
                'invalid_battery_triage_status' => data_get($fairClaudeReport, 'result_integrity.invalid_battery_triage.status'),
                'score_admitted' => (bool) data_get($fairClaudeReport, 'result_integrity.score_admitted', false),
                'claim_winner_admitted' => (bool) data_get($fairClaudeReport, 'result_integrity.claim_winner_admitted', false),
                'next_action' => $blockers === []
                    ? 'Review the plan hash and explicitly acknowledge provider cost before running the battery.'
                    : 'Resolve the primary blocker before provider execution.',
            ],
            'operator_required' => true,
            'cost_acknowledgement_required' => true,
            'agent_auto_execution_allowed' => false,
            'provider_dispatch_required' => true,
            'external_cost_possible' => true,
            'synthetic_scores_allowed' => false,
            'plan_review_required' => true,
            'selection_contract' => [
                'schema_version' => 'atlas.rivals.battery_selection_contract.v1',
                'allowed_modes' => $this->rivalsBatteryModeCatalog(),
                'provider_model_options' => $this->rivalsProviderModelOptions(),
                'ui_must_send_mode' => true,
                'ui_must_send_provider_and_model_for_modes' => ['official_fair', 'same_model'],
                'ui_may_leave_provider_or_model_empty_for_modes' => ['max'],
            ],
            'execution_intent' => [
                'baseline' => $baseline,
                'provider' => $effectiveProvider,
                'model' => $effectiveModel,
                'case_limit' => $limit,
                'workspace_required' => true,
                'baseline_workspace_required' => $mode === 'official_fair',
                'fair_claim_eligible' => $mode === 'official_fair',
                'max_capability_run' => $mode === 'max',
            ],
            'preflight' => [
                'workspace_exists' => $workspaceExists,
                'workspace_status' => (string) ($workspaceGit['status'] ?? 'unknown'),
                'workspace_dirty_count' => count($workspaceDirtyFiles),
                'workspace_dirty_files_sample' => array_slice($workspaceDirtyFiles, 0, 20),
                'workspace_git' => $workspaceGit,
                'baseline_workspace_exists' => $baselineWorkspaceExists,
                'baseline_workspace_separate' => $baselineSeparate,
                'baseline_workspace_status' => (string) ($baselineGit['status'] ?? 'unknown'),
                'baseline_workspace_dirty_count' => count($baselineDirtyFiles),
                'baseline_workspace_dirty_files_sample' => array_slice($baselineDirtyFiles, 0, 20),
                'baseline_workspace_git' => $baselineGit,
            ],
            'corpus' => [
                'active_case_count' => $activeCorpus,
                'release_case_count' => $releaseCorpus,
                'minimum_release_case_count' => $minimumRelease,
            ],
            'result_integrity' => [
                'status' => data_get($fairClaudeReport, 'result_integrity.status'),
                'score_admitted' => (bool) data_get($fairClaudeReport, 'result_integrity.score_admitted', false),
                'claim_winner_admitted' => (bool) data_get($fairClaudeReport, 'result_integrity.claim_winner_admitted', false),
                'winner_for_claim' => data_get($fairClaudeReport, 'result_integrity.winner_for_claim'),
                'triage_required_before_rerun' => $triageRequiredBeforeRerun,
                'invalid_battery_triage' => data_get($fairClaudeReport, 'result_integrity.invalid_battery_triage'),
            ],
            'safety' => [
                'read_only_plan' => true,
                'no_provider_call' => true,
                'no_benchmark_run_created' => true,
                'no_score_recorded' => true,
                'no_completion_gate_change' => true,
                'clean_git_workspaces_required' => true,
            ],
        ];
        $plan['plan_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $plan['generated_at'] = now()->toJSON();

        return $plan;
    }

    /**
     * @param  array<string,mixed>|null  $report
     */
    private function fairClaudeReportRequiresTriageBeforeRerun(?array $report): bool
    {
        if ($report === null) {
            return false;
        }

        if ((bool) data_get($report, 'result_integrity.triage_required_before_rerun', false) === false
            && (string) data_get($report, 'result_integrity.invalid_battery_triage.status') === 'triaged_quarantined') {
            return false;
        }

        if ((string) data_get($report, 'result_integrity.status') === 'invalid_battery_no_comparable_score') {
            return true;
        }

        $nextActionIds = collect((array) data_get($report, 'next_actions', []))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        return in_array('triage_invalid_battery_before_provider_rerun', $nextActionIds, true);
    }

    private function rivalsBatteryPlanBlockerHeadline(?string $blocker): string
    {
        return match ($blocker) {
            'workspace_required' => 'Choose an Atlas workspace before planning the battery.',
            'workspace_missing_or_unreadable' => 'Atlas workspace is missing or unreadable.',
            'atlas_workspace_not_git_worktree' => 'Atlas workspace must be a Git worktree.',
            'atlas_workspace_dirty' => 'Atlas workspace has uncommitted changes and would contaminate the comparison.',
            'release_corpus_below_minimum' => 'Release corpus is below the minimum required case count.',
            'historical_invalid_battery_requires_triage' => 'Historical invalid battery must be triaged before another paid run.',
            'separate_baseline_workspace_required' => 'A separate clean baseline workspace is required for official fair mode.',
            'claude_code_baseline_workspace_missing_or_unreadable' => 'Claude Code baseline workspace is missing or unreadable.',
            'baseline_workspace_must_be_separate' => 'Atlas and baseline workspaces must be separate.',
            'claude_code_baseline_workspace_not_git_worktree' => 'Claude Code baseline workspace must be a Git worktree.',
            'claude_code_baseline_workspace_dirty' => 'Claude Code baseline workspace has uncommitted changes.',
            'provider_required' => 'Choose a provider for this battery mode.',
            'model_required' => 'Choose a model for this battery mode.',
            default => 'Battery plan is blocked by unresolved preflight requirements.',
        };
    }

    /**
     * @return array{is_git:bool,clean:?bool,dirty_files:array<int,string>,status:string}
     */
    private function gitWorkspaceState(string $workspace): array
    {
        $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $inside->setTimeout(5);
        $inside->run();

        if (! $inside->isSuccessful() || trim($inside->getOutput()) !== 'true') {
            return [
                'is_git' => false,
                'clean' => null,
                'dirty_files' => [],
                'status' => 'not_git_workspace',
            ];
        }

        $status = new Process(['git', 'status', '--porcelain'], $workspace);
        $status->setTimeout(10);
        $status->run();

        if (! $status->isSuccessful()) {
            return [
                'is_git' => true,
                'clean' => null,
                'dirty_files' => [],
                'status' => 'git_status_unavailable',
            ];
        }

        $dirtyFiles = collect(explode("\n", trim($status->getOutput())))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values()
            ->all();

        return [
            'is_git' => true,
            'clean' => $dirtyFiles === [],
            'dirty_files' => $dirtyFiles,
            'status' => $dirtyFiles === [] ? 'clean' : 'dirty',
        ];
    }

    private function suiteHasHistoricalInvalidFairBattery(AtlasEngineeringBenchmarkSuite $suite): bool
    {
        $results = AtlasEngineeringBenchmarkResult::query()
            ->where('suite_id', $suite->id)
            ->latest()
            ->limit(50)
            ->get();
        $hasInvalidFairCase = false;
        $hasComparableFairCase = false;

        foreach ($results as $result) {
            $scorecard = data_get($result->observed_json ?? [], 'paired_scorecard');
            if (! is_array($scorecard) || ! (bool) ($scorecard['fair_mode'] ?? false)) {
                continue;
            }

            if (
                (string) ($scorecard['comparison_status'] ?? '') === 'atlas_protocol_invalid'
                || (bool) data_get($scorecard, 'atlas.protocol_valid', true) === false
            ) {
                $hasInvalidFairCase = true;

                continue;
            }

            if ((bool) ($scorecard['comparable'] ?? false) && (string) ($scorecard['comparison_status'] ?? '') === 'comparable') {
                $hasComparableFairCase = true;
            }
        }

        $triage = (array) data_get($suite->metadata ?? [], 'rivals_invalid_battery_triage', []);

        return $hasInvalidFairCase
            && ! $hasComparableFairCase
            && (string) ($triage['status'] ?? '') !== 'triaged_quarantined';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rivalsBatteryModeCatalog(): array
    {
        return [
            [
                'id' => 'official_fair',
                'label' => 'Justa oficial',
                'description' => 'Bateria pareada com baseline externo em workspace separado, provider/modelo fixos e claim comparavel.',
                'requires_provider' => true,
                'requires_model' => true,
                'requires_baseline_workspace' => true,
                'fair_claim_eligible' => true,
                'max_capability_run' => false,
                'recommended_provider' => 'claude_cli',
                'recommended_model' => 'opus',
            ],
            [
                'id' => 'same_model',
                'label' => 'Mesmo modelo',
                'description' => 'Atlas roda com o mesmo provider/modelo escolhido para isolar arquitetura, contexto e orquestracao.',
                'requires_provider' => true,
                'requires_model' => true,
                'requires_baseline_workspace' => false,
                'fair_claim_eligible' => false,
                'max_capability_run' => false,
                'recommended_provider' => 'codex_cli',
                'recommended_model' => $this->providerModel('codex_cli', 'model_identity'),
            ],
            [
                'id' => 'max',
                'label' => 'Maximo Atlas',
                'description' => 'Atlas usa politica best-quality e gates estritos para medir capacidade maxima, nao uma comparacao isolada de modelo.',
                'requires_provider' => false,
                'requires_model' => false,
                'requires_baseline_workspace' => false,
                'fair_claim_eligible' => false,
                'max_capability_run' => true,
                'recommended_provider' => 'policy',
                'recommended_model' => 'best-quality',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rivalsProviderModelOptions(): array
    {
        return collect((array) config('atlas.ai.providers', []))
            ->only(['claude_cli', 'codex_cli', 'gemini_cli'])
            ->map(function (mixed $config, string $provider): array {
                $providerConfig = is_array($config) ? $config : [];

                return [
                    'provider' => $provider,
                    'label' => str_replace('_', ' ', $provider),
                    'allow_auto' => (bool) ($providerConfig['allow_auto'] ?? false),
                    'allow_manual' => (bool) ($providerConfig['allow_manual'] ?? false),
                    'models' => $this->providerModelOptions($providerConfig),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     * @return array<int,array<string,mixed>>
     */
    private function providerModelOptions(array $providerConfig): array
    {
        $models = [];

        foreach ([
            ['key' => 'model_identity', 'label_key' => 'model_label', 'tier_key' => 'model_tier', 'role' => 'default'],
            ['key' => 'premium_model', 'label_key' => 'premium_model_label', 'tier_key' => null, 'role' => 'premium'],
            ['key' => 'fallback_model', 'label_key' => 'fallback_model_label', 'tier_key' => null, 'role' => 'fallback'],
        ] as $definition) {
            $model = trim((string) ($providerConfig[$definition['key']] ?? ''));
            if ($model === '' || $model === 'codex_cli_default' || $model === 'claude_cli_default') {
                continue;
            }

            $models[$model] = [
                'model' => $model,
                'label' => trim((string) ($providerConfig[$definition['label_key']] ?? '')) ?: $model,
                'role' => $definition['role'],
                'tier' => $definition['tier_key'] ? ($providerConfig[$definition['tier_key']] ?? null) : null,
            ];
        }

        return array_values($models);
    }

    private function providerModel(string $provider, string $key): string
    {
        $value = trim((string) config("atlas.ai.providers.{$provider}.{$key}", ''));

        return $value !== '' ? $value : $provider;
    }

    public function prepareFairClaudeSuite(Request $request, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'suite' => ['nullable', 'string', 'max:120'],
            'workspace' => ['nullable', 'string', 'max:1000'],
        ]);

        $suite = is_string($data['suite'] ?? null) && trim((string) $data['suite']) !== ''
            ? trim((string) $data['suite'])
            : 'atlas-fair-claude-v1';
        $args = [
            'action' => 'prepare',
            '--suite' => $suite,
            '--json' => false,
        ];
        if (is_string($data['workspace'] ?? null) && trim((string) $data['workspace']) !== '') {
            $args['--workspace'] = trim((string) $data['workspace']);
        }

        $exitCode = Artisan::call('atlas:engineering:benchmark:claude-fair', $args);
        if ($exitCode !== 0) {
            return response()->json([
                'error' => 'fair_claude_prepare_failed',
                'message' => trim(Artisan::output()) ?: 'Fair Claude prepare failed.',
                'suite' => $suite,
            ], 422);
        }

        $prepared = $this->resolveSuite($suite)->load('cases');
        $manifest = data_get($prepared->metadata, 'corpus_manifest', []);
        $seededCases = $prepared->cases
            ->filter(fn ($case): bool => data_get($case->metadata, 'source') === 'fair_claude_seed_v1')
            ->sortBy('case_code')
            ->values();
        $payload = [
            'suite' => $benchmarks->suitePayload($prepared)['suite'] ?? null,
            'corpus_manifest' => $manifest,
            'promoted_count' => $seededCases->count(),
            'promoted_cases' => $seededCases
                ->map(fn ($case): array => $benchmarks->casePayload($case))
                ->values()
                ->all(),
        ];

        return response()->json($payload);
    }

    public function storeCase(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'task_id' => ['nullable', 'uuid'],
            'case_code' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'persist_workspace_path' => ['nullable', 'boolean'],
            'task_contract' => ['nullable', 'array'],
            'runner_options' => ['nullable', 'array'],
            'expected_decision' => ['nullable', Rule::in(['resolved', 'partial', 'blocked', 'unsafe', 'unresolved'])],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'curation_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $case = $benchmarks->registerCase($this->resolveSuite($suite), $data);

        return response()->json([
            'case' => $benchmarks->casePayload($case),
        ], 201);
    }

    public function promoteRunCase(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'run_id' => ['required', 'uuid'],
            'case_code' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'persist_workspace_path' => ['nullable', 'boolean'],
            'task_contract' => ['nullable', 'array'],
            'runner_options' => ['nullable', 'array'],
            'expected_decision' => ['nullable', Rule::in(['resolved', 'partial', 'blocked', 'unsafe', 'unresolved'])],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'curation_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $run = AtlasEngineeringRun::query()->findOrFail($data['run_id']);
        $case = $benchmarks->promoteRunToCase($run, $this->resolveSuite($suite), $data);

        return response()->json([
            'case' => $benchmarks->casePayload($case),
        ], $case->wasRecentlyCreated ? 201 : 200);
    }

    public function runSuite(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_policy' => ['nullable', Rule::in(['fixed', 'off', 'auto', 'balanced', 'best_quality', 'best-quality', 'fastest', 'cheapest'])],
            'fair_mode' => ['nullable', 'boolean'],
            'claude_only' => ['nullable', 'boolean'],
            'single_provider' => ['nullable', 'boolean'],
            'no_decide' => ['nullable', 'boolean'],
            'fallback_disabled' => ['nullable', 'boolean'],
            'require_pass_without_human' => ['nullable', 'boolean'],
            'claude_code_baseline' => ['nullable', Rule::in(['off', 'plan', 'run', true, false, 1, 0, '1', '0', 'true', 'false'])],
            'claude_code_baseline_mode' => ['nullable', Rule::in(['off', 'plan', 'run'])],
            'claude_code_baseline_model' => ['nullable', 'string', 'max:120'],
            'claude_code_baseline_binary' => ['nullable', 'string', 'max:200'],
            'claude_code_baseline_workspace' => ['nullable', 'string', 'max:1000'],
            'claude_code_baseline_timeout' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'claude_code_baseline_validation_timeout' => ['nullable', 'integer', 'min:1', 'max:1800'],
            'baseline_runner' => ['nullable', Rule::in(['off', 'plan', 'run'])],
            'baseline_model' => ['nullable', 'string', 'max:120'],
            'baseline_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'baseline_validation_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:1800'],
            'rivals_battery_mode' => ['nullable', Rule::in(['official_fair', 'same_model', 'max'])],
            'rivals_battery_plan_hash' => ['nullable', 'string', 'size:64'],
            'operator_plan_reviewed' => ['nullable', 'boolean'],
            'operator_cost_acknowledged' => ['nullable', 'boolean'],
            'permission' => ['nullable', Rule::in(['auto', 'read', 'write', 'danger'])],
            'sandbox' => ['nullable', Rule::in(['workspace', 'worktree', 'docker'])],
            'docker_service' => ['nullable', 'string', 'max:120'],
            'docker_image' => ['nullable', 'string', 'max:180'],
            'docker_workdir' => ['nullable', 'string', 'max:200'],
            'docker_cache' => ['nullable', Rule::in(['auto', 'off'])],
            'docker_network' => ['nullable', Rule::in(['profile', 'none', 'bridge'])],
            'docker_healthcheck_services' => ['nullable', 'array'],
            'docker_healthcheck_services.*' => ['string', 'max:120'],
            'docker_healthcheck_timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'docker_artifact_paths' => ['nullable', 'array'],
            'docker_artifact_paths.*' => ['string', 'max:300'],
            'docker_artifact_max_files' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'docker_artifact_max_bytes' => ['nullable', 'integer', 'min:1', 'max:104857600'],
            'provider_runtime' => ['nullable', Rule::in(['host', 'docker', 'auto'])],
            'provider_docker_compose_file' => ['nullable', 'string', 'max:1000'],
            'provider_docker_service' => ['nullable', 'string', 'max:120'],
            'provider_docker_app_dir' => ['nullable', 'string', 'max:200'],
            'provider_docker_workspace_dir' => ['nullable', 'string', 'max:200'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'test_command' => ['nullable', 'string', 'max:500'],
            'visual_e2e' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_scan' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_profile' => ['nullable', Rule::in(['auto', 'fast', 'standard', 'release', 'deep'])],
            'quality_changed_only' => ['nullable', 'boolean'],
            'harness_policy' => ['nullable', Rule::in(['auto', 'off', 'strict'])],
            'control_profile' => ['nullable', 'string', 'max:120'],
            'complete' => ['nullable', 'boolean'],
            'auto_test' => ['nullable', 'boolean'],
            'critical' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'no_provider' => ['nullable', 'boolean'],
            'keep_workspace' => ['nullable', 'boolean'],
            'apply_isolated_patch' => ['nullable', 'boolean'],
            'release_gate_profile' => ['nullable', Rule::in(['off', 'advisory', 'smoke', 'release', 'strict'])],
            'release_gate_policy' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'case_codes' => ['nullable', 'array'],
            'case_codes.*' => ['string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'runner_options' => ['nullable', 'array'],
        ]);

        $resolved = $this->resolveSuite($suite)->load('cases');
        if (isset($data['rivals_battery_mode'])) {
            $plan = $this->rivalsBatteryPlanPayload($resolved, [
                'mode' => $data['rivals_battery_mode'],
                'workspace' => $data['workspace'] ?? null,
                'baseline_workspace' => $data['claude_code_baseline_workspace'] ?? null,
                'provider' => $data['provider'] ?? null,
                'model' => $data['model'] ?? null,
                'limit' => $data['limit'] ?? null,
            ], $benchmarks->fairClaudeReportPayload($resolved, ['limit' => 20]));

            if (($data['operator_plan_reviewed'] ?? false) !== true) {
                return response()->json([
                    'error' => 'rivals_battery_plan_review_required',
                    'message' => 'Revise e confirme o plano Rivals antes de executar a bateria real.',
                    'battery_plan' => $plan,
                ], 422);
            }

            if (($data['operator_cost_acknowledged'] ?? false) !== true) {
                return response()->json([
                    'error' => 'rivals_battery_cost_acknowledgement_required',
                    'message' => 'Confirme explicitamente o custo/provider externo antes de executar a bateria real.',
                    'battery_plan' => $plan,
                ], 422);
            }

            if (($plan['ready'] ?? false) !== true) {
                return response()->json([
                    'error' => 'rivals_battery_plan_blocked',
                    'message' => 'O plano Rivals ainda possui bloqueios operacionais.',
                    'battery_plan' => $plan,
                ], 422);
            }

            if (($data['rivals_battery_plan_hash'] ?? null) !== ($plan['plan_hash'] ?? null)) {
                return response()->json([
                    'error' => 'rivals_battery_plan_hash_mismatch',
                    'message' => 'O hash aprovado no app não corresponde ao plano atual. Recalcule e revise o plano.',
                    'battery_plan' => $plan,
                ], 422);
            }

            $data['runner_options'] = array_replace_recursive($data['runner_options'] ?? [], [
                'rivals_battery_plan' => [
                    'schema_version' => $plan['schema_version'],
                    'mode' => $plan['mode'],
                    'plan_hash' => $plan['plan_hash'],
                    'status' => $plan['status'],
                    'execution_intent' => $plan['execution_intent'],
                    'operator_plan_reviewed' => true,
                    'operator_cost_acknowledged' => true,
                ],
            ]);
        }

        $run = $benchmarks->runSuite($resolved, $data);

        return response()->json($benchmarks->runPayload($run), 201);
    }

    public function showRun(AtlasEngineeringBenchmarkRun $benchmarkRun, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->runPayload($benchmarkRun));
    }

    public function replayManifest(AtlasEngineeringBenchmarkRun $benchmarkRun, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->replayManifestPayload($benchmarkRun));
    }

    public function refreshCorpus(string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $resolved = $this->resolveSuite($suite);
        $manifest = $benchmarks->refreshCorpusManifest($resolved);

        return response()->json([
            ...$benchmarks->suitePayload($resolved->refresh()),
            'corpus_manifest' => $manifest,
        ]);
    }

    public function calibrateSuite(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $resolved = $this->resolveSuite($suite);
        $calibration = $benchmarks->calibrateSuite($resolved, $data);

        return response()->json([
            ...$benchmarks->suitePayload($resolved->refresh()),
            'rollout_calibration' => $calibration,
        ]);
    }

    public function recordOutcome(
        Request $request,
        AtlasEngineeringBenchmarkRun $benchmarkRun,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'])],
            'outcome_status' => ['nullable', Rule::in(['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'])],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'outcome_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'summary' => ['nullable', 'string', 'max:12000'],
            'notes' => ['nullable', 'string', 'max:12000'],
            'signals' => ['nullable', 'array'],
            'rollback_reason' => ['nullable', 'string', 'max:12000'],
            'incident_ref' => ['nullable', 'string', 'max:500'],
            'recorded_by' => ['nullable', 'string', 'max:120'],
        ]);

        $run = $benchmarks->recordOutcome($benchmarkRun, $data);

        return response()->json($benchmarks->runPayload($run));
    }

    private function resolveSuite(string $suite): AtlasEngineeringBenchmarkSuite
    {
        $query = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $suite);

        if (Str::isUuid($suite)) {
            $query->orWhere('id', $suite);
        }

        return $query->firstOrFail();
    }
}
