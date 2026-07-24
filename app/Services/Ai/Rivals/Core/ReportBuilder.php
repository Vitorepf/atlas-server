<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use App\Support\YesNo;

/**
 * Report multi-eixo por task_type × arm. Sem best overall / média global.
 */
class ReportBuilder
{
    public function build(string $runId): array
    {
        $plan = RunPlan::load($runId);
        $receipts = RunReceipt::loadAll($runId);
        $adjPath = RunPaths::adjudicationPath($runId);
        $adjudication = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];
        $pipelineValid = ($adjudication['pipeline_valid'] ?? false) === true;
        $claimTier = (string) ($adjudication['claim_tier'] ?? ClaimTier::HARNESS);
        $internalAllowed = ($adjudication['internal_claim_allowed']
            ?? $adjudication['claim_allowed']
            ?? false) === true;
        $publicAllowed = ($adjudication['public_claim_allowed'] ?? false) === true;
        $blockers = $adjudication['internal_claim_blockers']
            ?? $adjudication['claim_blockers']
            ?? ['adjudication_missing'];
        $notReadyReasons = $adjudication['not_ready_reasons'] ?? $blockers;

        $rows = $this->buildRows($receipts, $plan);
        $calibration = (new DifficultyCalibrator)->calibrate($rows);
        foreach ($rows as &$row) {
            $row['difficulty_band'] = $calibration['row_bands']["{$row['task_type']}|{$row['arm_id']}"] ?? null;
        }
        unset($row);

        $upliftPath = RunPaths::runDir($runId).'/uplift.json';
        $uplift = is_file($upliftPath) ? (json_decode(file_get_contents($upliftPath), true) ?? null) : null;

        $report = [
            'schema_version' => SchemaContract::REPORT,
            'run_id' => $runId,
            'rows' => $rows,
            'difficulty_band' => $calibration['suite_band'],
            'difficulty_baseline' => $calibration['baseline'],
            'difficulty_flags' => $calibration['flags'],
            'uplift' => $uplift,
            'pipeline_valid' => $pipelineValid,
            'claim_tier' => $claimTier,
            'internal_claim_allowed' => $internalAllowed,
            'public_claim_allowed' => $publicAllowed,
            'not_ready_reasons' => array_values($notReadyReasons),
            // Backward-compatible alias for internal scoped claims.
            'claim_allowed' => $internalAllowed,
            'claim_blockers' => $blockers,
            'claim_scope' => $adjudication['claim_scope'] ?? null,
            'statistical_analysis' => $adjudication['statistical_analysis'] ?? [
                'adequate' => false,
                'blockers' => ['adjudication_missing'],
                'segments' => [],
            ],
            'missing_data_policy' => $this->missingDataPolicy($runId),
            'built_at' => $adjudication['adjudicated_at'] ?? $plan->data['created_at'],
        ];
        $report['report_hash'] = self::hashPayload($report);
        $violations = SchemaContract::validate($report, SchemaContract::REPORT);
        if ($violations !== []) {
            throw new \RuntimeException('rivals_invalid_report:'.implode(',', $violations));
        }

        AtomicWriter::write(
            RunPaths::reportPath($runId),
            json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );
        AtomicWriter::write(RunPaths::reportMarkdownPath($runId), $this->markdown($report));
        AtomicWriter::write(RunPaths::reportCsvPath($runId), $this->csv($report));

        return $report;
    }

    public function buildAll(): array
    {
        $runsDir = RunPaths::runsDir();
        $runIds = is_dir($runsDir) ? array_values(array_diff(scandir($runsDir) ?: [], ['.', '..'])) : [];

        $segments = [];
        $included = [];
        $excluded = [];
        foreach ($runIds as $runId) {
            $adjPath = RunPaths::adjudicationPath($runId);
            $adj = is_file($adjPath) ? (json_decode(file_get_contents($adjPath), true) ?? []) : [];
            if (($adj['pipeline_valid'] ?? false) !== true) {
                $excluded[] = ['run_id' => $runId, 'reason' => $adj === [] ? 'not_adjudicated' : 'invalid'];

                continue;
            }
            $scope = $adj['claim_scope'] ?? [];
            $segmentKey = implode('|', [
                $scope['suite'] ?? 'unknown',
                $adj['claim_tier'] ?? 'unknown',
                json_encode($scope['task_types'] ?? []),
                json_encode($scope['models'] ?? []),
                json_encode($scope['runtimes'] ?? []),
                json_encode($scope['environment'] ?? []),
                (string) ($scope['repo_commit'] ?? ''),
                (string) ($scope['adapter_hash'] ?? ''),
            ]);
            $included[] = $runId;
            foreach (RunReceipt::loadAll($runId) as $receipt) {
                $segments[$segmentKey]['claim_tier'] = $adj['claim_tier'] ?? 'unknown';
                $segments[$segmentKey]['internal_claim_allowed'] =
                    (bool) ($adj['internal_claim_allowed'] ?? false);
                $segments[$segmentKey]['groups']["{$receipt->data['task_type']}|{$receipt->data['arm_id']}"][] =
                    $receipt->data;
            }
        }

        $segmentRows = [];
        foreach ($segments as $segmentKey => $segment) {
            $rows = [];
            foreach ($segment['groups'] ?? [] as $key => $items) {
                [$taskType, $armId] = explode('|', $key, 2);
                $rows[] = $this->aggregateItems($taskType, $armId, $items);
            }
            $segmentRows[] = [
                'segment_key' => $segmentKey,
                'claim_tier' => $segment['claim_tier'],
                'internal_claim_allowed' => $segment['internal_claim_allowed'],
                'rows' => $rows,
            ];
        }

        return [
            'schema_version' => 'atlas.rivals2.report_all.v1',
            'segments' => $segmentRows,
            'included_runs' => $included,
            'excluded_runs' => $excluded,
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'built_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<int, RunReceipt> $receipts */
    private function buildRows(array $receipts, RunPlan $plan): array
    {
        $groups = [];
        $caseTypes = [];
        foreach ($receipts as $receipt) {
            $groups["{$receipt->data['task_type']}|{$receipt->data['arm_id']}"][] = $receipt->data;
            $caseTypes[$receipt->data['case_id']] = $receipt->data['task_type'];
        }
        foreach ($plan->data['case_ids'] as $caseId) {
            $caseTypes[$caseId] ??= $this->caseTaskType(
                (string) $plan->data['suite_id'],
                (string) $caseId,
            );
        }
        $planned = [];
        foreach ($plan->data['arms'] as $arm) {
            foreach ($caseTypes as $caseId => $taskType) {
                $key = "{$taskType}|{$arm['arm_id']}";
                $planned[$key]['attempts'] = ($planned[$key]['attempts'] ?? 0)
                    + (int) $plan->data['repetitions'];
                $planned[$key]['cases'][$caseId] = true;
                $groups[$key] ??= [];
            }
        }
        $rows = [];
        foreach ($groups as $key => $items) {
            [$taskType, $armId] = explode('|', $key, 2);
            $rows[] = $this->aggregateItems(
                $taskType,
                $armId,
                $items,
                (int) ($planned[$key]['attempts'] ?? count($items)),
                count((array) ($planned[$key]['cases'] ?? [])),
            );
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function aggregateItems(
        string $taskType,
        string $armId,
        array $items,
        ?int $plannedAttempts = null,
        ?int $plannedCases = null,
    ): array {
        $n = count($items);
        $plannedAttempts ??= $n;
        $plannedCases ??= count(array_unique(array_column($items, 'case_id')));
        $successes = count(array_filter($items, fn ($r) => $r['status'] === 'success'));
        $failures = count(array_filter($items, fn ($r) => $r['status'] === 'failure'));
        $validResults = $successes + $failures;
        $successFlags = array_map(fn ($r) => $r['status'] === 'success' ? 1.0 : 0.0, $items);
        $successRate = $plannedAttempts > 0 ? $successes / $plannedAttempts : 0.0;
        $conditionalSuccessRate = $validResults > 0 ? $successes / $validResults : 0.0;
        $variance = $n > 0 ? array_sum(array_map(fn ($f) => ($f - $successRate) ** 2, $successFlags)) / $n : 0.0;
        $totalCost = array_sum(array_column($items, 'cost_usd'));
        $tokenInPresent = 0;
        $tokenOutPresent = 0;
        $tokenInSum = 0;
        $tokenOutSum = 0;
        foreach ($items as $item) {
            $presence = $item['field_presence'] ?? [];
            $inOk = (($presence['tokens_in']['present'] ?? true) === true);
            $outOk = (($presence['tokens_out']['present'] ?? true) === true);
            if ($inOk) {
                $tokenInPresent++;
                $tokenInSum += (int) ($item['tokens_in'] ?? 0);
            }
            if ($outOk) {
                $tokenOutPresent++;
                $tokenOutSum += (int) ($item['tokens_out'] ?? 0);
            }
        }
        $failureClasses = [];
        $failureReasons = [];
        foreach ($items as $item) {
            $cls = $item['failure_class'] ?? ($item['status'] === 'success' ? null : 'model_failure');
            if ($cls !== null) {
                $failureClasses[$cls] = ($failureClasses[$cls] ?? 0) + 1;
            }
            $reason = $item['failure_reason'] ?? null;
            if ($item['status'] !== 'success' && is_string($reason) && trim($reason) !== '') {
                $reason = trim($reason);
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        }
        $dimensions = $this->dimensionsAggregate($items);
        $costs = array_values(array_map(
            fn (array $item): float => (float) $item['cost_usd'],
            array_filter(
                $items,
                fn (array $item): bool => (($item['field_presence']['cost_usd']['present'] ?? true) === true),
            ),
        ));
        $walls = array_values(array_map(
            fn (array $item): float => (float) $item['wall_ms'],
            array_filter(
                $items,
                fn (array $item): bool => (($item['field_presence']['wall_ms']['present'] ?? true) === true),
            ),
        ));
        $wilson = StatisticalPolicy::wilson($successes, $plannedAttempts);
        $environmentFailures = $failureClasses[FailureClass::ENVIRONMENT] ?? 0;

        $nonEnvItems = array_values(array_filter(
            $items,
            fn (array $r): bool => ($r['failure_class'] ?? null) !== FailureClass::ENVIRONMENT,
        ));
        $intelSuccesses = count(array_filter($nonEnvItems, fn (array $r): bool => $r['status'] === 'success'));
        $intelN = count($nonEnvItems);
        $intelligenceRate = $intelN > 0 ? round($intelSuccesses / $intelN, 4) : null;

        $tokensPerSecondSamples = [];
        $tokensInPerSecondSamples = [];
        $tokensOutPerSecondSamples = [];
        $tokensPerTaskSamples = [];
        foreach ($items as $item) {
            $presence = (array) ($item['field_presence'] ?? []);
            $wallOk = (($presence['wall_ms']['present'] ?? true) === true);
            $inOk = (($presence['tokens_in']['present'] ?? true) === true);
            $outOk = (($presence['tokens_out']['present'] ?? true) === true);
            $wallMs = (float) ($item['wall_ms'] ?? 0);
            $tokIn = $inOk ? (int) ($item['tokens_in'] ?? 0) : null;
            $tokOut = $outOk ? (int) ($item['tokens_out'] ?? 0) : null;
            if ($tokIn !== null || $tokOut !== null) {
                $tokensPerTaskSamples[] = (float) (($tokIn ?? 0) + ($tokOut ?? 0));
            }
            if ($wallOk && $wallMs > 0) {
                $sec = $wallMs / 1000.0;
                if ($tokIn !== null) {
                    $tokensInPerSecondSamples[] = $tokIn / $sec;
                }
                if ($tokOut !== null) {
                    $tokensOutPerSecondSamples[] = $tokOut / $sec;
                }
                if ($tokIn !== null || $tokOut !== null) {
                    $tokensPerSecondSamples[] = (($tokIn ?? 0) + ($tokOut ?? 0)) / $sec;
                }
            }
        }

        $totalTokensObserved = $tokenInSum + $tokenOutSum;
        $tokensPerTask = $plannedCases > 0 && ($tokenInPresent > 0 || $tokenOutPresent > 0)
            ? round($totalTokensObserved / $plannedCases, 4)
            : null;
        $avgTokensPerTask = $tokensPerTaskSamples === []
            ? null
            : round(array_sum($tokensPerTaskSamples) / count($tokensPerTaskSamples), 4);
        $avgTokensPerSecond = $tokensPerSecondSamples === []
            ? null
            : round(array_sum($tokensPerSecondSamples) / count($tokensPerSecondSamples), 4);
        $medianWallSec = ($walls !== [] && ($this->quantile($walls, 0.5) ?? 0) > 0)
            ? round(((float) $this->quantile($walls, 0.5)) / 1000.0, 6)
            : null;
        $totalWallSecObserved = array_sum($walls) / 1000.0;
        $tokensPerSecondAggregate = ($totalWallSecObserved > 0 && ($tokenInPresent > 0 || $tokenOutPresent > 0))
            ? round($totalTokensObserved / $totalWallSecObserved, 4)
            : null;
        $costPer1kTokens = ($totalTokensObserved > 0 && $totalCost > 0)
            ? round(($totalCost / $totalTokensObserved) * 1000.0, 6)
            : null;

        return [
            'task_type' => $taskType,
            'arm_id' => $armId,
            'n' => $n,
            'planned_attempts' => $plannedAttempts,
            'observed_attempts' => $n,
            'valid_results' => $validResults,
            'successes' => $successes,
            'success_rate' => round($successRate, 4),
            'success_rate_itt' => round($successRate, 4),
            'success_rate_valid_results' => round($conditionalSuccessRate, 4),
            'intelligence_rate' => $intelligenceRate,
            'success_rate_wilson_95' => $wilson,
            'environment_failure_rate' => $plannedAttempts > 0
                ? round($environmentFailures / $plannedAttempts, 4)
                : 0.0,
            'total_cost_usd' => round($totalCost, 6),
            'avg_cost_usd' => $n > 0 ? round($totalCost / $n, 6) : 0.0,
            'cost_per_task' => $plannedCases > 0 ? round($totalCost / $plannedCases, 6) : null,
            'median_cost_usd' => $this->quantile($costs, 0.5),
            'p95_cost_usd' => $this->quantile($costs, 0.95),
            'median_cost_ci_95' => $this->bootstrapMedianCi($costs, crc32($taskType.'|'.$armId.'|cost')),
            'cost_per_1k_tokens' => $costPer1kTokens,
            'avg_tokens_in' => $tokenInPresent > 0 ? round($tokenInSum / $tokenInPresent, 2) : null,
            'avg_tokens_out' => $tokenOutPresent > 0 ? round($tokenOutSum / $tokenOutPresent, 2) : null,
            'total_tokens_in' => $tokenInSum,
            'total_tokens_out' => $tokenOutSum,
            'total_tokens' => $tokenInPresent > 0 || $tokenOutPresent > 0 ? $totalTokensObserved : null,
            'tokens_per_task' => $tokensPerTask,
            'avg_tokens_per_task' => $avgTokensPerTask,
            'tokens_in_per_task' => $plannedCases > 0 && $tokenInPresent > 0
                ? round($tokenInSum / $plannedCases, 4)
                : null,
            'tokens_out_per_task' => $plannedCases > 0 && $tokenOutPresent > 0
                ? round($tokenOutSum / $plannedCases, 4)
                : null,
            'tokens_per_second' => $avgTokensPerSecond,
            'tokens_per_second_aggregate' => $tokensPerSecondAggregate,
            'tokens_in_per_second' => $tokensInPerSecondSamples === []
                ? null
                : round(array_sum($tokensInPerSecondSamples) / count($tokensInPerSecondSamples), 4),
            'tokens_out_per_second' => $tokensOutPerSecondSamples === []
                ? null
                : round(array_sum($tokensOutPerSecondSamples) / count($tokensOutPerSecondSamples), 4),
            'tokens_coverage' => [
                'in' => $tokenInPresent,
                'out' => $tokenOutPresent,
                'n' => $n,
                'in_rate' => $n > 0 ? round($tokenInPresent / $n, 4) : 0.0,
                'out_rate' => $n > 0 ? round($tokenOutPresent / $n, 4) : 0.0,
            ],
            'avg_wall_ms' => $n > 0 ? (int) round(array_sum(array_column($items, 'wall_ms')) / $n) : 0,
            'median_wall_ms' => $this->quantile($walls, 0.5),
            'p95_wall_ms' => $this->quantile($walls, 0.95),
            'median_wall_sec' => $medianWallSec,
            'median_wall_ci_95' => $this->bootstrapMedianCi($walls, crc32($taskType.'|'.$armId.'|wall')),
            'stability' => round(1.0 - sqrt($variance), 4),
            'failure_classes' => $failureClasses,
            'failure_reasons' => $failureReasons,
            'dimensions' => $dimensions,
            'avg_patch_bloat' => ($bloats = array_filter(array_column($items, 'patch_bloat_ratio'), 'is_numeric')) === []
                ? null
                : round(array_sum($bloats) / count($bloats), 3),
            'reality' => $this->realityAggregate($items),
        ];
    }

    private function dimensionsAggregate(array $items): ?array
    {
        $all = [];
        foreach ($items as $item) {
            if (! isset($item['dimensions']) || ! is_array($item['dimensions'])) {
                continue;
            }
            foreach ($item['dimensions'] as $key => $value) {
                if (is_numeric($value) || is_bool($value)) {
                    $all[$key][] = is_bool($value) ? ($value ? 1.0 : 0.0) : (float) $value;
                }
            }
        }
        if ($all === []) {
            return null;
        }
        $out = [];
        foreach ($all as $key => $values) {
            $out[$key] = round(array_sum($values) / count($values), 4);
        }

        return $out;
    }

    private function realityAggregate(array $items): ?array
    {
        $cards = array_values(array_filter(array_column($items, 'reality')));
        if ($cards === []) {
            return null;
        }
        $n = count($cards);
        $rate = fn (string $key) => round(count(array_filter($cards, fn ($c) => ($c[$key] ?? null) === true)) / $n, 4);

        return [
            'n' => $n,
            'hidden_regression_pass_rate' => $rate('hidden_regression_pass'),
            'minimal_rate' => $rate('minimal'),
            'hardcode_suspects' => count(array_filter($cards, fn ($c) => ($c['hardcode_suspect'] ?? null) === true)),
            'avg_blast_radius_outside_golden' => round(array_sum(array_column($cards, 'blast_radius_outside_golden')) / $n, 2),
            'requires_judge' => $cards[0]['requires_judge'] ?? [],
        ];
    }

    private function caseTaskType(string $suiteId, string $caseId): string
    {
        $candidates = [
            RunPaths::root()."/external/{$suiteId}/cases/{$caseId}.json",
            RunPaths::root()."/atlasbench/cases/{$caseId}.json",
            RunPaths::root()."/elite/cases/{$caseId}.json",
        ];
        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            $case = json_decode((string) file_get_contents($path), true);
            if (is_array($case) && is_string($case['task_type'] ?? null)) {
                return $case['task_type'];
            }
        }

        return 'unknown_unknown';
    }

    /** @return array<string, mixed> */
    private function missingDataPolicy(string $runId): array
    {
        try {
            return (array) Preregistration::load($runId)->data['missing_data_policy'];
        } catch (\Throwable) {
            return [
                'imputation' => 'forbidden',
                'primary_denominator' => 'all_planned_attempts',
                'environment_failures' => 'reported_separately',
                'status' => 'preregistration_missing',
            ];
        }
    }

    private function quantile(array $values, float $quantile): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $position = (count($values) - 1) * min(1.0, max(0.0, $quantile));
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $value = $lower === $upper
            ? $values[$lower]
            : $values[$lower] + (($values[$upper] - $values[$lower]) * ($position - $lower));

        return round((float) $value, 6);
    }

    /** @return array{low: ?float, high: ?float, samples: int} */
    private function bootstrapMedianCi(array $values, int $seed): array
    {
        if ($values === []) {
            return ['low' => null, 'high' => null, 'samples' => 0];
        }
        if (count($values) === 1) {
            $value = round((float) $values[0], 6);

            return ['low' => $value, 'high' => $value, 'samples' => 1];
        }
        $samples = app()->environment('testing')
            ? 500
            : (int) config('atlas_rivals.report.bootstrap_samples', 10_000);
        $state = (int) sprintf('%u', $seed);
        $medians = [];
        $n = count($values);
        for ($iteration = 0; $iteration < $samples; $iteration++) {
            $resample = [];
            for ($index = 0; $index < $n; $index++) {
                $state = (int) (($state * 1664525 + 1013904223) % 4294967296);
                $resample[] = $values[$state % $n];
            }
            $medians[] = $this->quantile($resample, 0.5);
        }

        return [
            'low' => $this->quantile($medians, 0.025),
            'high' => $this->quantile($medians, 0.975),
            'samples' => $samples,
        ];
    }

    private function markdown(array $report): string
    {
        $md = "# Rivals 2.0 — run {$report['run_id']}\n\n";
        if (! ($report['internal_claim_allowed'] ?? false)) {
            $md .= "NOT READY FOR PRODUCTION CLAIM\n\n";
        }
        $md .= 'pipeline_valid: '.(YesNo::trueFalse($report['pipeline_valid'] ?? false))."\n";
        $md .= 'claim_tier: '.($report['claim_tier'] ?? ClaimTier::HARNESS)."\n";
        $md .= 'internal_claim_allowed: '.(YesNo::trueFalse($report['internal_claim_allowed'] ?? false))."\n";
        $md .= 'public_claim_allowed: '.(YesNo::trueFalse($report['public_claim_allowed'] ?? false))."\n";
        $md .= 'report_hash: '.($report['report_hash'] ?? 'missing')."\n";
        $md .= 'statistical_adequacy: '.(YesNo::trueFalse($report['statistical_analysis']['adequate'] ?? false))."\n";
        $md .= 'difficulty_band: '.($report['difficulty_band'] ?? 'uncalibrated')."\n";
        $md .= 'claim_allowed: '.(YesNo::trueFalse($report['claim_allowed']))."\n";
        if (($report['not_ready_reasons'] ?? []) !== []) {
            $md .= "not_ready_reasons:\n".implode("\n", array_map(
                fn ($b) => '- '.$b,
                (array) $report['not_ready_reasons'],
            ))."\n";
        }
        if ($report['claim_blockers'] !== []) {
            $md .= "claim_blockers:\n".implode("\n", array_map(fn ($b) => "- {$b}", $report['claim_blockers']))."\n";
        }
        if (($report['difficulty_flags'] ?? []) !== []) {
            $md .= 'difficulty_flags: '.json_encode($report['difficulty_flags'], JSON_UNESCAPED_SLASHES)."\n";
        }
        $md .= "\nMissing-data policy: `".json_encode($report['missing_data_policy'], JSON_UNESCAPED_SLASHES)."`\n";

        $stats = (array) ($report['statistical_analysis'] ?? []);
        $md .= "\n## Statistical analysis\n\n";
        $md .= '- adequate: '.(YesNo::trueFalse($stats['adequate'] ?? false))."\n";
        if (($stats['blockers'] ?? []) !== []) {
            $md .= '- blockers: '.implode(', ', (array) $stats['blockers'])."\n";
        }
        if (($stats['segments'] ?? []) !== []) {
            $md .= '- segments: '.count((array) $stats['segments'])."\n";
        }

        if (is_array($report['uplift'] ?? null)) {
            $uplift = $report['uplift'];
            $md .= "\n## Uplift\n\n";
            $md .= '- uplift_supported: '.(YesNo::trueFalse($uplift['uplift_supported'] ?? false))."\n";
            $md .= '- uplift_kind: '.($uplift['uplift_kind'] ?? 'n/a')."\n";
            $md .= '- model_id: '.($uplift['model_id'] ?? 'n/a')."\n";
            if (($uplift['claim_blockers'] ?? []) !== []) {
                $md .= '- claim_blockers: '.implode(', ', (array) $uplift['claim_blockers'])."\n";
            }
        }

        $md .= "\n| task_type | arm | planned | observed | success_itt | Wilson95 | valid_success | cost/task | tokens/task | tok/s | median_ms | p95_ms | tokens_cov_in/out | env_fail | stability |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($report['rows'] as $row) {
            $ci = $row['success_rate_wilson_95'];
            $cov = (array) ($row['tokens_coverage'] ?? []);
            $covLabel = ($cov['in'] ?? 0).'/'.($cov['n'] ?? 0).' · '.($cov['out'] ?? 0).'/'.($cov['n'] ?? 0);
            $md .= "| {$row['task_type']} | {$row['arm_id']} | {$row['planned_attempts']} | {$row['observed_attempts']} | "
                ."{$row['success_rate_itt']} | [{$ci['low']}, {$ci['high']}] | {$row['success_rate_valid_results']} | "
                .($row['cost_per_task'] ?? 'n/a')
                .' | '.($row['tokens_per_task'] ?? 'n/a')
                .' | '.($row['tokens_per_second'] ?? 'n/a')
                .' | '.($row['median_wall_ms'] ?? 'n/a')
                .' | '.($row['p95_wall_ms'] ?? 'n/a')." | {$covLabel} | {$row['environment_failure_rate']} | {$row['stability']} |\n";
        }

        $md .= "\n## Failure reasons\n\n";
        foreach ($report['rows'] as $row) {
            $reasons = (array) ($row['failure_reasons'] ?? []);
            if ($reasons !== []) {
                $md .= '- `'.$row['task_type'].'` / `'.$row['arm_id'].'`: `'
                    .json_encode($reasons, JSON_UNESCAPED_SLASHES).'`'."\n";
            }
        }

        return $md."\nEscopo do claim: ".json_encode($report['claim_scope'], JSON_UNESCAPED_SLASHES)."\n";
    }

    private function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'run_id',
            'claim_tier',
            'pipeline_valid',
            'internal_claim_allowed',
            'public_claim_allowed',
            'task_type',
            'arm_id',
            'planned_attempts',
            'observed_attempts',
            'successes',
            'success_rate_itt',
            'ci_low',
            'ci_high',
            'total_cost_usd',
            'cost_per_task',
            'cost_per_1k_tokens',
            'median_wall_ms',
            'median_wall_sec',
            'p95_wall_ms',
            'avg_tokens_in',
            'avg_tokens_out',
            'total_tokens',
            'tokens_per_task',
            'avg_tokens_per_task',
            'tokens_in_per_task',
            'tokens_out_per_task',
            'tokens_per_second',
            'tokens_per_second_aggregate',
            'tokens_in_per_second',
            'tokens_out_per_second',
            'tokens_coverage_in',
            'tokens_coverage_out',
            'tokens_coverage_n',
            'environment_failure_rate',
            'stability',
            'failure_classes',
            'failure_reasons',
            'dimensions',
        ]);
        foreach ($report['rows'] as $row) {
            $cov = (array) ($row['tokens_coverage'] ?? []);
            fputcsv($handle, [
                $report['run_id'],
                $report['claim_tier'],
                YesNo::trueFalse($report['pipeline_valid'] ?? false),
                YesNo::trueFalse($report['internal_claim_allowed'] ?? false),
                YesNo::trueFalse($report['public_claim_allowed'] ?? false),
                $row['task_type'],
                $row['arm_id'],
                $row['planned_attempts'],
                $row['observed_attempts'],
                $row['successes'],
                $row['success_rate_itt'],
                $row['success_rate_wilson_95']['low'],
                $row['success_rate_wilson_95']['high'],
                $row['total_cost_usd'],
                $row['cost_per_task'],
                $row['cost_per_1k_tokens'] ?? null,
                $row['median_wall_ms'],
                $row['median_wall_sec'] ?? null,
                $row['p95_wall_ms'],
                $row['avg_tokens_in'] ?? null,
                $row['avg_tokens_out'] ?? null,
                $row['total_tokens'] ?? null,
                $row['tokens_per_task'] ?? null,
                $row['avg_tokens_per_task'] ?? null,
                $row['tokens_in_per_task'] ?? null,
                $row['tokens_out_per_task'] ?? null,
                $row['tokens_per_second'] ?? null,
                $row['tokens_per_second_aggregate'] ?? null,
                $row['tokens_in_per_second'] ?? null,
                $row['tokens_out_per_second'] ?? null,
                $cov['in'] ?? null,
                $cov['out'] ?? null,
                $cov['n'] ?? null,
                $row['environment_failure_rate'],
                $row['stability'] ?? null,
                json_encode($row['failure_classes'], JSON_UNESCAPED_SLASHES),
                json_encode($row['failure_reasons'] ?? [], JSON_UNESCAPED_SLASHES),
                json_encode($row['dimensions'], JSON_UNESCAPED_SLASHES),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['report_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
