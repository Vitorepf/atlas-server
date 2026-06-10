<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringHarnessabilityCalibration;
use App\Models\AtlasEngineeringRun;
use App\Services\Ai\Runtime\WorkspaceProfiler;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class EngineeringHarnessabilityService
{
    private const BAD_OUTCOME_STATUSES = ['degraded', 'incident', 'rolled_back'];

    private const GOOD_OUTCOME_STATUSES = ['healthy', 'accepted'];

    private const DEFAULT_THRESHOLDS = [
        'medium_min_score' => 55,
        'high_min_score' => 80,
        'require_worktree_below_score' => 80,
        'danger_permission_min_score' => 80,
        'write_permission_min_score' => 55,
        'cap_attempts_to_one_below_score' => 55,
        'cap_attempts_to_two_below_score' => 80,
        'require_auto_test_below_score' => 80,
    ];

    public function __construct(
        private readonly WorkspaceProfiler $profiler,
        private readonly ?EngineeringHarnessabilityInput $input = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function score(string $workspace): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $profile = $this->profiler->profile($workspace);
        $stack = $profile->stack;
        $scripts = array_keys($profile->scripts);
        $files = $profile->files;

        $checks = [
            'fast_tests' => $this->check($profile->testCommands !== [], 20, 'Comandos de teste detectados.'),
            'typecheck_or_lint' => $this->check($this->hasScript($scripts, ['typecheck', 'lint']) || in_array('php', $stack, true), 15, 'Typecheck, lint ou stack PHP detectada.'),
            'architecture_docs' => $this->check($this->hasAny($files, ['docs/', 'README.md', 'CLAUDE.md', 'AGENTS.md']), 15, 'Documentacao de arquitetura/projeto detectada.'),
            'boundaries' => $this->check($this->hasAny($files, ['app/Http/', 'app/Services/', 'routes/', 'tests/Feature/', 'tests/Unit/']), 15, 'Boundaries verificaveis detectados.'),
            'behaviour_checks' => $this->check($this->hasAny($files, ['tests/Feature/', 'e2e/', 'playwright.config.ts']) || $this->hasScript($scripts, ['test:front', 'e2e']), 15, 'Checks funcionais/E2E detectados.'),
            'standard_scripts' => $this->check($profile->scripts !== [] || File::exists($workspace.'/artisan'), 10, 'Scripts ou artisan detectados.'),
            'low_dirty_state' => $this->check(count($profile->dirtyFiles) <= 20, 10, 'Dirty state dentro de limite.'),
        ];

        $score = collect($checks)->sum(fn (array $check): int => (int) ($check['earned'] ?? 0));
        $missing = collect($checks)
            ->filter(fn (array $check): bool => ! (bool) $check['passed'])
            ->keys()
            ->values()
            ->all();

        return [
            'score' => min(100, max(0, $score)),
            'level' => match (true) {
                $score >= 80 => 'high',
                $score >= 55 => 'medium',
                default => 'low',
            },
            'workspace' => $workspace,
            'stack' => $stack,
            'package_manager' => $profile->packageManager,
            'test_commands' => $profile->testCommands,
            'dirty_count' => count($profile->dirtyFiles),
            'checks' => $checks,
            'missing' => $missing,
            'recommendations' => $this->recommendations($missing),
            'calibration' => $this->latestCalibration(),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function calibrate(array $options = []): array
    {
        $limit = $this->harnessabilityInput()->calibrationLimit($options['limit'] ?? null);
        $runs = AtlasEngineeringRun::query()
            ->with(['controlResults', 'testRuns', 'reviewFindings'])
            ->whereNotNull('harnessability_score')
            ->latest('finished_at')
            ->latest('created_at')
            ->limit($limit)
            ->get();

        $outcomes = $this->outcomesByEngineeringRun($runs);
        $signals = $runs->map(fn (AtlasEngineeringRun $run): array => $this->runSignal($run, $outcomes[$run->id] ?? []));
        $bucketMetrics = $this->bucketMetrics($signals);
        $outcomeMetrics = $this->outcomeMetrics($signals);
        $confidence = $this->calibrationConfidence($signals->count());
        $thresholds = $this->recommendedThresholds($bucketMetrics, $confidence);
        $recommendations = $this->calibrationRecommendations($bucketMetrics, $thresholds, $confidence);
        $sampleWindow = [
            'latest_finished_at' => $runs->first()?->finished_at?->toJSON() ?: $runs->first()?->created_at?->toJSON(),
            'oldest_finished_at' => $runs->last()?->finished_at?->toJSON() ?: $runs->last()?->created_at?->toJSON(),
        ];

        $calibration = [
            'generated_at' => now()->toJSON(),
            'sample_limit' => $limit,
            'sample_count' => $signals->count(),
            'confidence' => $confidence,
            'sample_window' => $sampleWindow,
            'current_thresholds' => self::DEFAULT_THRESHOLDS,
            'recommended_thresholds' => $thresholds,
            'bucket_metrics' => $bucketMetrics,
            'outcome_metrics' => $outcomeMetrics,
            'recommendations' => $recommendations,
        ];

        if (DatabaseTableAvailability::has('atlas_engineering_harnessability_calibrations')) {
            AtlasEngineeringHarnessabilityCalibration::query()->create([
                'sample_limit' => $limit,
                'sample_count' => $signals->count(),
                'confidence' => $confidence,
                'sample_window_json' => $sampleWindow,
                'current_thresholds_json' => self::DEFAULT_THRESHOLDS,
                'recommended_thresholds_json' => $thresholds,
                'bucket_metrics_json' => $bucketMetrics,
                'outcome_metrics_json' => $outcomeMetrics,
                'recommendations_json' => $recommendations,
                'metadata' => [
                    'source' => 'atlas_engineering_harnessability_calibration',
                    'bad_outcome_statuses' => self::BAD_OUTCOME_STATUSES,
                    'good_outcome_statuses' => self::GOOD_OUTCOME_STATUSES,
                ],
                'calibrated_at' => now(),
            ]);
        }

        return $calibration;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestCalibration(): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_harnessability_calibrations')) {
            return null;
        }

        $calibration = AtlasEngineeringHarnessabilityCalibration::query()
            ->latest('calibrated_at')
            ->latest('created_at')
            ->first();

        if (! $calibration) {
            return null;
        }

        return [
            'id' => $calibration->id,
            'generated_at' => $calibration->calibrated_at?->toJSON() ?: $calibration->created_at?->toJSON(),
            'sample_limit' => $calibration->sample_limit,
            'sample_count' => $calibration->sample_count,
            'confidence' => $calibration->confidence,
            'sample_window' => $calibration->sample_window_json,
            'current_thresholds' => $calibration->current_thresholds_json,
            'recommended_thresholds' => $calibration->recommended_thresholds_json,
            'bucket_metrics' => $calibration->bucket_metrics_json,
            'outcome_metrics' => $calibration->outcome_metrics_json,
            'recommendations' => $calibration->recommendations_json,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function check(bool $passed, int $weight, string $detail): array
    {
        return [
            'passed' => $passed,
            'weight' => $weight,
            'earned' => $passed ? $weight : 0,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<int,string>  $scripts
     * @param  array<int,string>  $candidates
     */
    private function hasScript(array $scripts, array $candidates): bool
    {
        $normalized = collect($scripts)->map(fn (string $script): string => strtolower($script))->all();

        return collect($candidates)->contains(fn (string $candidate): bool => in_array(strtolower($candidate), $normalized, true));
    }

    /**
     * @param  array<int,string>  $files
     * @param  array<int,string>  $prefixes
     */
    private function hasAny(array $files, array $prefixes): bool
    {
        return collect($files)->contains(function (string $file) use ($prefixes): bool {
            return collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with($file, $prefix) || $file === $prefix);
        });
    }

    /**
     * @param  array<int,string>  $missing
     * @return array<int,string>
     */
    private function recommendations(array $missing): array
    {
        $map = [
            'fast_tests' => 'Adicionar um comando rapido de teste ou smoke test.',
            'typecheck_or_lint' => 'Adicionar typecheck/lint barato para rodar em todo attempt.',
            'architecture_docs' => 'Documentar boundaries e decisoes arquiteturais em README/docs.',
            'boundaries' => 'Organizar codigo em boundaries verificaveis por modulo/camada.',
            'behaviour_checks' => 'Adicionar fixture, teste feature, E2E ou evidencia manual estruturada.',
            'standard_scripts' => 'Padronizar scripts de teste/lint/typecheck.',
            'low_dirty_state' => 'Reduzir dirty state antes de aumentar autonomia do agente.',
        ];

        return collect($missing)
            ->map(fn (string $key): ?string => $map[$key] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasEngineeringRun>  $runs
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function outcomesByEngineeringRun(Collection $runs): array
    {
        if (
            $runs->isEmpty()
            || ! DatabaseTableAvailability::all([
                'atlas_engineering_benchmark_results',
                'atlas_engineering_benchmark_runs',
            ])
        ) {
            return [];
        }

        return AtlasEngineeringBenchmarkResult::query()
            ->with('benchmarkRun')
            ->whereIn('engineering_run_id', $runs->pluck('id')->all())
            ->get()
            ->groupBy('engineering_run_id')
            ->map(fn (Collection $results): array => $results
                ->map(fn (AtlasEngineeringBenchmarkResult $result): array => [
                    'benchmark_run_id' => $result->benchmark_run_id,
                    'status' => $result->benchmarkRun?->outcome_status,
                    'score' => $result->benchmarkRun?->outcome_score,
                    'recorded_at' => $result->benchmarkRun?->outcome_recorded_at?->toJSON(),
                ])
                ->filter(fn (array $outcome): bool => is_string($outcome['status'] ?? null) && $outcome['status'] !== '')
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    private function runSignal(AtlasEngineeringRun $run, array $outcomes): array
    {
        $failedControls = $run->controlResults
            ->filter(fn ($result): bool => in_array((string) $result->status, ['failed', 'blocked'], true))
            ->count();
        $skippedRequired = $run->controlResults
            ->filter(fn ($result): bool => (string) $result->status === 'skipped' && (bool) data_get($result->metadata, 'required', false))
            ->count();
        $failedTests = $run->testRuns
            ->filter(fn ($testRun): bool => (string) $testRun->status === 'failed')
            ->count();
        $blockingFindings = $run->reviewFindings
            ->filter(fn ($finding): bool => (string) $finding->status === 'open' && in_array((string) $finding->severity, ['p0', 'p1'], true))
            ->count();
        $badOutcomeCount = collect($outcomes)
            ->filter(fn (array $outcome): bool => in_array((string) ($outcome['status'] ?? ''), self::BAD_OUTCOME_STATUSES, true))
            ->count();
        $goodOutcomeCount = collect($outcomes)
            ->filter(fn (array $outcome): bool => in_array((string) ($outcome['status'] ?? ''), self::GOOD_OUTCOME_STATUSES, true))
            ->count();
        $qualityDebt = $failedControls + $skippedRequired + $failedTests + $blockingFindings;

        return [
            'run_id' => $run->id,
            'score' => (int) $run->harnessability_score,
            'bucket' => $this->scoreBucket((int) $run->harnessability_score),
            'decision' => $run->decision,
            'resolved' => $run->decision === 'resolved',
            'quality_debt' => $qualityDebt,
            'has_quality_debt' => $qualityDebt > 0,
            'failed_controls' => $failedControls,
            'skipped_required_controls' => $skippedRequired,
            'failed_tests' => $failedTests,
            'blocking_findings' => $blockingFindings,
            'bad_outcome_count' => $badOutcomeCount,
            'good_outcome_count' => $goodOutcomeCount,
            'has_bad_outcome' => $badOutcomeCount > 0,
            'created_at' => $run->created_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $signals
     * @return array<string,array<string,mixed>>
     */
    private function bucketMetrics(Collection $signals): array
    {
        return collect(['low', 'medium', 'high'])
            ->mapWithKeys(function (string $bucket) use ($signals): array {
                $items = $signals->where('bucket', $bucket)->values();
                $total = $items->count();
                $resolved = $items->where('resolved', true)->count();
                $qualityDebt = $items->where('has_quality_debt', true)->count();
                $badOutcomes = $items->where('has_bad_outcome', true)->count();
                $scores = $items->pluck('score')->filter(fn (mixed $score): bool => is_numeric($score));

                return [$bucket => [
                    'sample_count' => $total,
                    'average_score' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null,
                    'resolved_count' => $resolved,
                    'resolved_rate' => $this->rate($resolved, $total),
                    'quality_debt_count' => $qualityDebt,
                    'quality_debt_rate' => $this->rate($qualityDebt, $total),
                    'bad_outcome_count' => $badOutcomes,
                    'bad_outcome_rate' => $this->rate($badOutcomes, $total),
                ]];
            })
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $signals
     * @return array<string,mixed>
     */
    private function outcomeMetrics(Collection $signals): array
    {
        $withOutcome = $signals
            ->filter(fn (array $signal): bool => ((int) ($signal['bad_outcome_count'] ?? 0)) > 0 || ((int) ($signal['good_outcome_count'] ?? 0)) > 0);
        $bad = $withOutcome->where('has_bad_outcome', true)->count();

        return [
            'runs_with_outcome' => $withOutcome->count(),
            'bad_outcome_runs' => $bad,
            'bad_outcome_rate' => $this->rate($bad, $withOutcome->count()),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $bucketMetrics
     * @return array<string,int|string>
     */
    private function recommendedThresholds(array $bucketMetrics, string $confidence): array
    {
        $thresholds = self::DEFAULT_THRESHOLDS;
        $highDebt = (float) data_get($bucketMetrics, 'high.quality_debt_rate', 0);
        $highBadOutcome = (float) data_get($bucketMetrics, 'high.bad_outcome_rate', 0);
        $mediumDebt = (float) data_get($bucketMetrics, 'medium.quality_debt_rate', 0);
        $mediumBadOutcome = (float) data_get($bucketMetrics, 'medium.bad_outcome_rate', 0);
        $lowResolved = (float) data_get($bucketMetrics, 'low.resolved_rate', 0);
        $lowDebt = (float) data_get($bucketMetrics, 'low.quality_debt_rate', 100);

        if ($confidence !== 'none') {
            if ($highDebt > 20 || $highBadOutcome > 10) {
                $thresholds['high_min_score'] = 85;
                $thresholds['require_worktree_below_score'] = 85;
                $thresholds['danger_permission_min_score'] = 85;
            }

            if ($mediumDebt > 35 || $mediumBadOutcome > 15) {
                $thresholds['medium_min_score'] = 65;
                $thresholds['write_permission_min_score'] = 65;
                $thresholds['cap_attempts_to_one_below_score'] = 65;
            } elseif ($lowResolved >= 80 && $lowDebt <= 20) {
                $thresholds['medium_min_score'] = 50;
                $thresholds['write_permission_min_score'] = 50;
            }
        }

        $thresholds['policy_source'] = $confidence === 'none' ? 'static_default' : 'historical_calibration';

        return $thresholds;
    }

    /**
     * @param  array<string,array<string,mixed>>  $bucketMetrics
     * @param  array<string,int|string>  $thresholds
     * @return array<int,string>
     */
    private function calibrationRecommendations(array $bucketMetrics, array $thresholds, string $confidence): array
    {
        $recommendations = [];
        if ($confidence === 'none' || $confidence === 'limited') {
            $recommendations[] = 'Coletar mais runs antes de confiar totalmente nos thresholds calibrados.';
        }

        if ((int) $thresholds['high_min_score'] > self::DEFAULT_THRESHOLDS['high_min_score']) {
            $recommendations[] = 'Aumentar exigencia para autonomia alta: historico mostrou divida em scores altos.';
        }

        if ((float) data_get($bucketMetrics, 'medium.quality_debt_rate', 0) > 35) {
            $recommendations[] = 'Reforcar testes/controles para workspaces medium antes de liberar permissao write ampla.';
        }

        if ((float) data_get($bucketMetrics, 'low.resolved_rate', 0) < 40 && (int) data_get($bucketMetrics, 'low.sample_count', 0) > 0) {
            $recommendations[] = 'Manter workspaces low em worktree com auto-test obrigatorio.';
        }

        return array_values(array_unique($recommendations));
    }

    private function calibrationConfidence(int $sampleCount): string
    {
        return match (true) {
            $sampleCount >= 100 => 'high',
            $sampleCount >= 30 => 'normal',
            $sampleCount >= 5 => 'limited',
            default => 'none',
        };
    }

    private function scoreBucket(int $score): string
    {
        return match (true) {
            $score >= self::DEFAULT_THRESHOLDS['high_min_score'] => 'high',
            $score >= self::DEFAULT_THRESHOLDS['medium_min_score'] => 'medium',
            default => 'low',
        };
    }

    private function harnessabilityInput(): EngineeringHarnessabilityInput
    {
        return $this->input ?? app(EngineeringHarnessabilityInput::class);
    }

    private function rate(int $count, int $total): ?float
    {
        return $total > 0 ? round(($count / $total) * 100, 2) : null;
    }
}
