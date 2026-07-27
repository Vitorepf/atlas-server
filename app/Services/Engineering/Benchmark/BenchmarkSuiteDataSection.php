<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

class BenchmarkSuiteDataSection
{
    private const BAD_OUTCOME_STATUSES = ['degraded', 'incident', 'rolled_back'];

    private const DEFAULT_RELEASE_GATE_PROFILE = 'release';

    public function __construct(
        private readonly BenchmarkPrimitives $primitives,
    ) {}

    public function calibrationCaseSignals(Collection $runs): Collection
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

    public function calibrationDistribution(Collection $signals, string $field): array
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

    public function quarantineCandidates(Collection $signals): array
    {
        return $signals
            ->filter(fn (array $signal): bool => $this->primitives->nonEmptyString($signal['case_id'] ?? null) !== null
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

    public function rolloutCalibrationOverrides(array $caseHealth): array
    {
        $overrides = [];
        foreach ([
            'by_risk' => 'risk_profile',
            'by_domain' => 'domain_slug',
        ] as $bucket => $dimension) {
            foreach ($this->primitives->arrayValue($caseHealth[$bucket] ?? []) as $value => $metrics) {
                $metrics = $this->primitives->arrayValue($metrics);
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

    public function recommendedRolloutPolicy(
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
        ], $this->primitives->arrayValue(data_get($suite->metadata, 'rollout_policy', [])));

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

    public function calibrationStatus(int $totalOutcomes, ?float $badOutcomeRate, array $statusCounts): string
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

    public function calibrationConfidence(int $totalOutcomes): string
    {
        return match (true) {
            $totalOutcomes >= 30 => 'high',
            $totalOutcomes >= 10 => 'medium',
            $totalOutcomes > 0 => 'low',
            default => 'none',
        };
    }

    public function runnerOptionsFromRun(AtlasEngineeringRun $run): array
    {
        $strategy = $this->primitives->arrayValue($run->provider_strategy_json ?? []);
        $metadata = $this->primitives->arrayValue($run->metadata ?? []);
        $docker = $this->primitives->arrayValue($strategy['docker'] ?? []);
        $providerRuntime = $this->primitives->arrayValue($strategy['provider_runtime'] ?? []);
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

    public function tagsForPromotedRun(AtlasEngineeringRun $run, mixed $extraTags = []): array
    {
        $strategy = $this->primitives->arrayValue($run->provider_strategy_json ?? []);
        $patch = $run->patchArtifacts->first();
        $tags = [
            'promoted',
            'real_run',
            'decision_'.$this->primitives->caseCode($run->decision ?: 'unknown'),
            'status_'.$this->primitives->caseCode($run->status ?: 'unknown'),
            'domain_'.$this->primitives->caseCode((string) ($run->task?->domain ?: 'atlas')),
            'sandbox_'.$this->primitives->caseCode((string) ($strategy['sandbox'] ?? 'workspace')),
        ];

        foreach ((array) ($patch?->risk_flags_json ?? []) as $flag) {
            if (is_scalar($flag) && trim((string) $flag) !== '') {
                $tags[] = 'risk_'.$this->primitives->caseCode($flag);
            }
        }

        foreach ((array) $extraTags as $tag) {
            if (is_scalar($tag) && trim((string) $tag) !== '') {
                $tags[] = $this->primitives->caseCode($tag);
            }
        }

        return collect($tags)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function defaultMinScoreFor(AtlasEngineeringRun $run): int
    {
        $score = is_numeric($run->score) ? (int) $run->score : 85;

        return max(85, min(95, $score));
    }

    public function caseSetHash(Collection $cases): string
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

    public function benchmarkIdentity(
        AtlasEngineeringBenchmarkSuite $suite,
        array $runnerOptions,
        array $options,
        string $caseSetHash,
    ): array {
        $provider = $this->primitives->nonEmptyString($options['benchmark_provider'] ?? null)
            ?: $this->primitives->nonEmptyString($runnerOptions['provider'] ?? null)
            ?: ((bool) ($runnerOptions['no_provider'] ?? false) ? 'no_provider' : 'default_provider');
        $model = $this->primitives->nonEmptyString($options['benchmark_model'] ?? $options['model'] ?? null)
            ?: $this->primitives->nonEmptyString($runnerOptions['model'] ?? null)
            ?: $this->policyModelIdentity($runnerOptions)
            ?: 'default_model';
        $mode = match (true) {
            (bool) ($runnerOptions['dry_run'] ?? false) => 'dry_run',
            (bool) ($runnerOptions['no_provider'] ?? false) => 'no_provider',
            (bool) ($runnerOptions['complete'] ?? false) => 'complete',
            default => 'single_shot',
        };
        $sandbox = $this->primitives->nonEmptyString($runnerOptions['sandbox'] ?? null) ?: 'workspace';
        $keyParts = [
            $suite->slug,
            $this->primitives->caseCode($provider),
            $this->primitives->caseCode($model),
            $this->primitives->caseCode($mode),
            $this->primitives->caseCode($sandbox),
            substr($caseSetHash, 0, 16),
        ];

        return [
            'benchmark_key' => implode(':', $keyParts),
            'provider' => $provider,
            'model' => $model,
            'model_policy' => $this->primitives->nonEmptyString($runnerOptions['model_policy'] ?? null),
            'mode' => $mode,
            'sandbox' => $sandbox,
            'case_set_hash' => $caseSetHash,
        ];
    }

    public function baselineFor(AtlasEngineeringBenchmarkRun $run): ?AtlasEngineeringBenchmarkRun
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

    public function policyModelIdentity(array $runnerOptions): ?string
    {
        $policy = $this->primitives->nonEmptyString($runnerOptions['model_policy'] ?? null);
        if ($policy === null) {
            return null;
        }

        $normalized = str_replace('_', '-', strtolower($policy));

        return in_array($normalized, ['auto', 'balanced', 'best-quality', 'fastest', 'cheapest'], true)
            ? 'policy:'.$normalized
            : null;
    }

    public function trendFor(
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

    public function qualityMetricsFor(AtlasEngineeringBenchmarkRun $run): array
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
            'harness_version' => $this->primitives->harnessVersion(),
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

    public function telemetryMetricsFor(Collection $traceIds): array
    {
        if ($traceIds->isEmpty() || ! DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
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

    public function qualityDebt(array $quality): int
    {
        return (int) ($quality['failed_control_count'] ?? 0)
            + (int) ($quality['blocked_control_count'] ?? 0)
            + (int) ($quality['skipped_required_control_count'] ?? 0)
            + (int) ($quality['failed_test_count'] ?? 0)
            + (int) ($quality['blocking_review_finding_count'] ?? 0);
    }

    public function releaseGateFor(
        AtlasEngineeringBenchmarkRun $run,
        string $caseStatus,
        ?float $passRate,
        ?float $averageScore,
        array $quality,
        array $trend,
    ): array {
        $run->loadMissing('suite');

        $suiteOptions = $this->primitives->arrayValue($run->suite?->default_runner_options_json ?? []);
        $runnerOptions = $this->primitives->arrayValue($run->runner_options_json ?? []);
        $profile = $this->releaseGateProfile($runnerOptions, $suiteOptions, $run);
        $policy = array_replace_recursive(
            $this->releaseGateProfilePolicy($profile),
            $this->primitives->arrayValue(data_get($run->suite?->metadata, 'release_gate.policy', [])),
            $this->primitives->arrayValue($runnerOptions['release_gate_policy'] ?? []),
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

    public function releaseGateProfile(
        array $runnerOptions,
        array $suiteOptions,
        AtlasEngineeringBenchmarkRun $run,
    ): string {
        $profile = $this->primitives->caseCode(
            $this->primitives->nonEmptyString($runnerOptions['release_gate_profile'] ?? null)
                ?: $this->primitives->nonEmptyString($suiteOptions['release_gate_profile'] ?? null)
                ?: $this->primitives->nonEmptyString(data_get($run->suite?->metadata, 'release_gate.profile'))
                ?: self::DEFAULT_RELEASE_GATE_PROFILE,
        );

        return in_array($profile, ['off', 'advisory', 'smoke', 'release', 'strict'], true)
            ? $profile
            : self::DEFAULT_RELEASE_GATE_PROFILE;
    }

    public function releaseGateProfilePolicy(string $profile): array
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

    public function failWhenAbove(array &$failures, string $label, mixed $value, mixed $limit): void
    {
        if (! is_numeric($limit) || ! is_numeric($value)) {
            return;
        }

        if ((float) $value > (float) $limit) {
            $failures[] = "{$label} acima do limite: observado {$value}, limite {$limit}.";
        }
    }

    public function failWhenBelow(array &$failures, string $label, mixed $value, mixed $limit, string $suffix = ''): void
    {
        if (! is_numeric($limit)) {
            return;
        }

        if (! is_numeric($value) || (float) $value < (float) $limit) {
            $observed = is_numeric($value) ? (string) $value.$suffix : 'indisponivel';
            $failures[] = "{$label} abaixo do minimo: observado {$observed}, minimo {$limit}{$suffix}.";
        }
    }

    public function warnWhenAbove(array &$warnings, string $label, mixed $value, mixed $limit): void
    {
        if (! is_numeric($limit) || ! is_numeric($value)) {
            return;
        }

        if ((float) $value > (float) $limit) {
            $warnings[] = "{$label} acima do limite de alerta: observado {$value}, limite {$limit}.";
        }
    }

    public function statusAfterReleaseGate(string $caseStatus, array $releaseGate): string
    {
        if ($caseStatus === 'empty') {
            return 'empty';
        }

        return ($releaseGate['status'] ?? null) === 'failed' ? 'failed' : $caseStatus;
    }

    public function emptyQualityMetrics(): array
    {
        return [
            'harness_version' => $this->primitives->harnessVersion(),
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

    public function caseCorpusFields(
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
        $domain = $this->primitives->caseCode(
            $this->primitives->nonEmptyString($data['domain_slug'] ?? $data['domain'] ?? $metadata['domain_slug'] ?? null)
                ?: $this->primitives->nonEmptyString($fallbackDomain)
                ?: $this->primitives->nonEmptyString(data_get($contract, 'domain'))
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

    public function normalizedTags(mixed $tags): array
    {
        return collect((array) $tags)
            ->filter(fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')
            ->map(fn (mixed $tag): string => $this->primitives->caseCode($tag))
            ->unique()
            ->values()
            ->all();
    }

    public function tierFromTags(array $tags): string
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

    public function riskFromTags(array $tags): string
    {
        foreach (['critical', 'high', 'medium', 'low'] as $risk) {
            if (in_array('risk_'.$risk, $tags, true) || in_array($risk.'_risk', $tags, true)) {
                return $risk;
            }
        }

        return in_array('migration', $tags, true) || in_array('security', $tags, true) ? 'high' : 'medium';
    }

    public function normalizedChoice(mixed $value, array $allowed, string $default): string
    {
        $normalized = $this->primitives->nonEmptyString($value) ? $this->primitives->caseCode($value) : $default;

        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    public function corpusFingerprint(string $caseCode, array $contract, array $expectation): string
    {
        return hash('sha256', json_encode([
            'case_code' => $caseCode,
            'contract' => $contract,
            'expectation' => $expectation,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function corpusManifestFor(Collection $cases): array
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

    public function distribution(Collection $cases, string $field): array
    {
        return $cases
            ->groupBy(fn (AtlasEngineeringBenchmarkCase $case): string => (string) ($case->{$field} ?: 'unknown'))
            ->map->count()
            ->sortKeys()
            ->all();
    }

    public function rolloutFor(AtlasEngineeringBenchmarkRun $run, string $status, array $releaseGate): array
    {
        $run->loadMissing('suite');
        $policy = array_replace_recursive([
            'default_status_after_passed_gate' => 'release_ready',
            'default_status_after_warning_gate' => 'needs_review',
            'default_status_after_failed_gate' => 'blocked',
            'outcome_required_within_hours' => 72,
            'healthy_outcome_min_score' => 80,
        ], $this->primitives->arrayValue(data_get($run->suite?->metadata, 'rollout_policy', [])));
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

    public function applyRolloutCalibrationOverrides(AtlasEngineeringBenchmarkRun $run, array $policy): array
    {
        $run->loadMissing(['suite', 'results.benchmarkCase']);
        $overrides = collect($this->primitives->arrayValue(data_get($run->suite?->metadata, 'rollout_calibration.risk_overrides', [])));
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
            $override = $this->primitives->arrayValue($override);
            $dimension = $this->primitives->nonEmptyString($override['dimension'] ?? null);
            $value = $this->primitives->nonEmptyString($override['value'] ?? null);
            if (! $dimension || ! $value || ! in_array($value, (array) ($dimensions[$dimension] ?? []), true)) {
                continue;
            }

            $policy = array_replace_recursive($policy, $this->primitives->arrayValue($override['policy'] ?? []));
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

    public function outcomeStatus(mixed $value): string
    {
        $status = $this->primitives->nonEmptyString($value) ? $this->primitives->caseCode($value) : 'healthy';

        return in_array($status, ['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'], true)
            ? $status
            : 'healthy';
    }

    public function rolloutStatusAfterOutcome(string $outcomeStatus): string
    {
        return match ($outcomeStatus) {
            'healthy' => 'healthy',
            'accepted' => 'accepted',
            'degraded', 'incident' => 'degraded',
            'rolled_back' => 'rolled_back',
            default => 'monitoring',
        };
    }
}
