<?php

namespace App\Services\Ai\Rivals\Core\EnterpriseReport;

use App\Services\Ai\Rivals\Adapters\External\EngineeringNativeSuiteAdapter;
use App\Services\Ai\Rivals\Core\EnterpriseSuiteDeliveryCatalog;
use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\EventsLifecycleContract;
use App\Services\Ai\Rivals\Support\RunPaths;

/**
 * Montagem da linha por suite + evidencia de execucao. Extraido VERBATIM de EnterpriseReportBuilder (GOD-DEBULK).
 */
class EnterpriseReportSuiteRowSection
{
    public function __construct(private EnterpriseReportSupport $support, private string $profile = 'fase_a') {}

    /** @return array<string, mixed> */
    public function emptySuiteRow(string $suiteId): array
    {
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId, $this->profile);

        return [
            'suite_id' => $suiteId,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'intelligence_rate' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'tokens_per_task' => null,
            'tokens_per_second' => null,
            'total_tokens' => null,
            'cost_per_1k_tokens' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'tokens_coverage_incomplete' => false,
            'events_complete' => false,
            'is_atlas_fact' => false,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
            'axes' => [
                'pipeline' => ['ok' => false, 'status' => 'not_run'],
                'measurement' => ['ok' => false, 'status' => 'not_run', 'missing_fields' => []],
                'intelligence' => ['rate' => null, 'itt' => null, 'status' => 'not_run'],
                'claim' => ['internal_ok' => false, 'status' => 'not_run', 'blockers' => []],
            ],
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'full_metrics' => null,
            'report_rows' => [],
            'native_signals' => [],
            'case_ids' => $delivery['fase_a_case_pack'],
            'artifacts' => [],
            'adjudication' => null,
            'observed_native_metric_keys' => [],
            'observed_report_metric_keys' => [],
            'delivery_coverage' => $this->support->deliveryCoverage($delivery, [], []),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function suiteRowFromRun(string $suiteId, string $runId, array $meta): array
    {
        $adj = (array) ($meta['adjudication'] ?? []);
        $report = $meta['report'];
        $pipelineValid = ($adj['pipeline_valid'] ?? false) === true;
        $internalAllowed = ($adj['internal_claim_allowed'] ?? $adj['claim_allowed'] ?? false) === true;
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId, $this->profile);

        $metrics = $this->aggregateReportMetrics(is_array($report) ? $report : []);
        $missing = $metrics['missing_fields'];
        $reportRows = is_array($report) ? array_values(array_filter(
            (array) ($report['rows'] ?? []),
            'is_array',
        )) : [];
        $fullMetrics = $this->fullMetricsFromRows($reportRows);
        $nativeSignals = $this->harvestNativeSignals($suiteId, $runId);
        $caseIds = $this->caseIdsForRun($runId, $meta, $delivery);
        $unitsExpected = (int) ($meta['units_expected'] ?? data_get($report, 'units_expected', 0));
        $executionEvidence = $this->executionEvidenceForRun($runId, $unitsExpected);
        $artifacts = $this->runArtifacts($runId);
        $eventsComplete = $this->eventsCompleteForRun($runId);
        $measurementStatus = $this->measurementStatus(
            $missing,
            (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            $suiteId,
            $runId,
        );
        if (($metrics['intelligence_rate'] ?? null) === null) {
            $metrics['intelligence_rate'] = $this->intelligenceFromReceipts($runId);
        }

        $status = 'failed';
        if (! $pipelineValid && $report === null) {
            $status = 'blocked';
        } elseif ($pipelineValid && $missing !== []) {
            $status = 'missing_data';
        } elseif ($pipelineValid) {
            $status = 'ok';
        }

        $observedNativeKeys = $this->flattenObservedKeys($nativeSignals);
        $observedReportKeys = $reportRows === [] ? [] : array_values(array_unique(array_merge(
            ...array_map(fn (array $row): array => array_keys($row), $reportRows),
        )));

        $axes = [
            'pipeline' => [
                'ok' => $pipelineValid,
                'status' => $pipelineValid ? 'ok' : ($report === null ? 'blocked' : 'failed'),
                'blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
            ],
            'measurement' => [
                'ok' => $missing === [] && $measurementStatus !== 'harness_omit',
                'status' => $measurementStatus,
                'missing_fields' => $missing,
            ],
            'intelligence' => [
                'rate' => $metrics['intelligence_rate'] ?? null,
                'itt' => $metrics['success_rate_itt'] ?? null,
                'status' => ($metrics['intelligence_rate'] ?? null) === null && ($metrics['success_rate_itt'] ?? null) === null
                    ? 'unknown'
                    : 'measured',
            ],
            'claim' => [
                'internal_ok' => $internalAllowed,
                'status' => $internalAllowed ? 'allowed' : 'blocked',
                'blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
            ],
        ];
        $isAtlasFact = $pipelineValid
            && $internalAllowed
            && $eventsComplete
            && $missing === []
            && $measurementStatus !== 'harness_omit';

        return [
            'suite_id' => $suiteId,
            'status' => $status,
            'run_id' => $runId,
            'success_rate_itt' => $metrics['success_rate_itt'],
            'intelligence_rate' => $metrics['intelligence_rate'] ?? null,
            'median_wall_ms' => $metrics['median_wall_ms'],
            'tokens_in_avg' => $metrics['tokens_in_avg'],
            'tokens_out_avg' => $metrics['tokens_out_avg'],
            'tokens_per_task' => $metrics['tokens_per_task'],
            'tokens_per_second' => $metrics['tokens_per_second'],
            'total_tokens' => $metrics['total_tokens'],
            'cost_per_1k_tokens' => $metrics['cost_per_1k_tokens'],
            'cost_per_task' => $metrics['cost_per_task'],
            'cost_basis' => $metrics['cost_basis'],
            'env_failure_rate' => $metrics['env_failure_rate'],
            'tokens_coverage_incomplete' => (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            'events_complete' => $eventsComplete,
            'is_atlas_fact' => $isAtlasFact,
            'missing_fields' => $missing,
            'pipeline_valid' => $pipelineValid,
            'internal_claim_allowed' => $internalAllowed,
            'axes' => $axes,
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'reliable' => $executionEvidence['reliable'],
            'unreliable_reason' => $executionEvidence['unreliable_reason'],
            'unreliable_reason_human' => $executionEvidence['unreliable_reason_human'] ?? null,
            'execution_evidence' => $executionEvidence,
            'full_metrics' => $fullMetrics,
            'report_rows' => $reportRows,
            'native_signals' => $nativeSignals,
            'case_ids' => $caseIds,
            'artifacts' => $artifacts,
            'adjudication' => [
                'pipeline_valid' => $pipelineValid,
                'claim_tier' => $adj['claim_tier'] ?? ($report['claim_tier'] ?? null),
                'internal_claim_allowed' => $internalAllowed,
                'public_claim_allowed' => ($adj['public_claim_allowed'] ?? false) === true,
                'pipeline_blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
                'internal_claim_blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
                'not_ready_reasons' => array_values((array) ($adj['not_ready_reasons'] ?? [])),
                'statistical_analysis' => $adj['statistical_analysis'] ?? ($report['statistical_analysis'] ?? null),
            ],
            'observed_native_metric_keys' => $observedNativeKeys,
            'observed_report_metric_keys' => $observedReportKeys,
            'delivery_coverage' => $this->support->deliveryCoverage($delivery, $observedNativeKeys, $observedReportKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{
     *   success_rate_itt: ?float,
     *   median_wall_ms: ?float,
     *   tokens_in_avg: ?float,
     *   tokens_out_avg: ?float,
     *   cost_per_task: ?float,
     *   cost_basis: ?string,
     *   env_failure_rate: ?float,
     *   missing_fields: list<string>
     * }
     */
    private function aggregateReportMetrics(array $report): array
    {
        $rows = (array) ($report['rows'] ?? []);
        if ($rows === []) {
            return [
                'success_rate_itt' => null,
                'intelligence_rate' => null,
                'median_wall_ms' => null,
                'tokens_in_avg' => null,
                'tokens_out_avg' => null,
                'tokens_per_task' => null,
                'tokens_per_second' => null,
                'total_tokens' => null,
                'cost_per_1k_tokens' => null,
                'cost_per_task' => null,
                'cost_basis' => null,
                'env_failure_rate' => null,
                'missing_fields' => ['report_rows'],
            ];
        }

        $successRates = [];
        $intelligenceRates = [];
        $walls = [];
        $tokensIn = [];
        $tokensOut = [];
        $tokensPerTask = [];
        $tokensPerSecond = [];
        $totalTokens = [];
        $costPer1k = [];
        $costs = [];
        $envRates = [];
        $missing = [];
        $tokenCoverageIncomplete = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['success_rate_itt'])) {
                $successRates[] = (float) $row['success_rate_itt'];
            }
            if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                $intelligenceRates[] = (float) $row['intelligence_rate'];
            }
            if ($row['median_wall_ms'] !== null && $row['median_wall_ms'] !== '') {
                $walls[] = (float) $row['median_wall_ms'];
            }
            if (($row['avg_tokens_in'] ?? null) !== null) {
                $tokensIn[] = (float) $row['avg_tokens_in'];
            }
            if (($row['avg_tokens_out'] ?? null) !== null) {
                $tokensOut[] = (float) $row['avg_tokens_out'];
            }
            if (($row['tokens_per_task'] ?? null) !== null && is_numeric($row['tokens_per_task'])) {
                $tokensPerTask[] = (float) $row['tokens_per_task'];
            }
            if (($row['tokens_per_second'] ?? null) !== null && is_numeric($row['tokens_per_second'])) {
                $tokensPerSecond[] = (float) $row['tokens_per_second'];
            }
            if (($row['total_tokens'] ?? null) !== null && is_numeric($row['total_tokens'])) {
                $totalTokens[] = (float) $row['total_tokens'];
            }
            if (($row['cost_per_1k_tokens'] ?? null) !== null && is_numeric($row['cost_per_1k_tokens'])) {
                $costPer1k[] = (float) $row['cost_per_1k_tokens'];
            }
            if (($row['cost_per_task'] ?? null) !== null) {
                $costs[] = (float) $row['cost_per_task'];
            }
            if (isset($row['environment_failure_rate'])) {
                $envRates[] = (float) $row['environment_failure_rate'];
            }
            $coverage = (array) ($row['tokens_coverage'] ?? []);
            $n = (int) ($coverage['n'] ?? 0);
            $in = (int) ($coverage['in'] ?? 0);
            $out = (int) ($coverage['out'] ?? 0);
            if ($n > 0 && ($in < $n || $out < $n)) {
                $tokenCoverageIncomplete = true;
            }
        }

        // Honesty: missing_data only when no measured token averages exist.
        // Partial coverage (env failures / harness omit on some units) stays visible
        // via tokens_coverage_* facets — never invent zeros for absent units.
        if ($tokensIn === []) {
            $missing[] = 'tokens_in';
        }
        if ($tokensOut === []) {
            $missing[] = 'tokens_out';
        }
        if ($walls === []) {
            $missing[] = 'wall_ms';
        }
        $missing = array_values(array_unique($missing));

        $costBasis = null;
        if ($costs !== []) {
            $costBasis = max($costs) == 0.0
                ? 'verboo_subscription_marginal'
                : 'reported_usd';
        }

        return $this->support->enrichTokenThroughput([
            'success_rate_itt' => $successRates === [] ? null : round(array_sum($successRates) / count($successRates), 4),
            'intelligence_rate' => $intelligenceRates === [] ? null : round(array_sum($intelligenceRates) / count($intelligenceRates), 4),
            'median_wall_ms' => $walls === [] ? null : $this->support->median($walls),
            'tokens_in_avg' => $tokensIn === [] ? null : round(array_sum($tokensIn) / count($tokensIn), 2),
            'tokens_out_avg' => $tokensOut === [] ? null : round(array_sum($tokensOut) / count($tokensOut), 2),
            'tokens_per_task' => $tokensPerTask === [] ? null : round(array_sum($tokensPerTask) / count($tokensPerTask), 4),
            'tokens_per_second' => $tokensPerSecond === [] ? null : round(array_sum($tokensPerSecond) / count($tokensPerSecond), 4),
            'total_tokens' => $totalTokens === [] ? null : round(array_sum($totalTokens), 2),
            'cost_per_1k_tokens' => $costPer1k === [] ? null : round(array_sum($costPer1k) / count($costPer1k), 6),
            'cost_per_task' => $costs === [] ? null : round(array_sum($costs) / count($costs), 6),
            'cost_basis' => $costBasis,
            'env_failure_rate' => $envRates === [] ? null : round(array_sum($envRates) / count($envRates), 4),
            'tokens_coverage_incomplete' => $tokenCoverageIncomplete,
            'missing_fields' => $missing,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function fullMetricsFromRows(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $pick = static function (array $row, string $key): mixed {
            return $row[$key] ?? null;
        };

        $first = $rows[0];
        $dimensions = [];
        $failureClasses = [];
        $wilson = null;
        $stabilities = [];
        $p95Walls = [];
        $avgWalls = [];
        $tokenCoverages = [];
        $realities = [];
        $patchBloats = [];

        foreach ($rows as $row) {
            if (is_array($row['dimensions'] ?? null)) {
                foreach ($row['dimensions'] as $dim => $value) {
                    if (is_numeric($value)) {
                        $dimensions[(string) $dim][] = (float) $value;
                    }
                }
            }
            foreach ((array) ($row['failure_classes'] ?? []) as $cls => $count) {
                $failureClasses[(string) $cls] = ($failureClasses[(string) $cls] ?? 0) + (int) $count;
            }
            if ($wilson === null && is_array($row['success_rate_wilson_95'] ?? null)) {
                $wilson = $row['success_rate_wilson_95'];
            }
            if (isset($row['stability']) && is_numeric($row['stability'])) {
                $stabilities[] = (float) $row['stability'];
            }
            if (isset($row['p95_wall_ms']) && is_numeric($row['p95_wall_ms'])) {
                $p95Walls[] = (float) $row['p95_wall_ms'];
            }
            if (isset($row['avg_wall_ms']) && is_numeric($row['avg_wall_ms'])) {
                $avgWalls[] = (float) $row['avg_wall_ms'];
            }
            if (is_array($row['tokens_coverage'] ?? null)) {
                $tokenCoverages[] = $row['tokens_coverage'];
            }
            if (isset($row['reality'])) {
                $realities[] = $row['reality'];
            }
            if (isset($row['avg_patch_bloat']) && is_numeric($row['avg_patch_bloat'])) {
                $patchBloats[] = (float) $row['avg_patch_bloat'];
            }
        }

        $dimMeans = [];
        foreach ($dimensions as $dim => $values) {
            $dimMeans[$dim] = round(array_sum($values) / count($values), 6);
        }

        return $this->support->enrichTokenThroughput([
            'task_types' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['task_type'] ?? ''),
                $rows,
            )))),
            'arm_ids' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['arm_id'] ?? ''),
                $rows,
            )))),
            'n' => array_sum(array_map(fn (array $row): int => (int) ($row['n'] ?? 0), $rows)),
            'planned_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['planned_attempts'] ?? 0), $rows)),
            'observed_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['observed_attempts'] ?? 0), $rows)),
            'valid_results' => array_sum(array_map(fn (array $row): int => (int) ($row['valid_results'] ?? 0), $rows)),
            'successes' => array_sum(array_map(fn (array $row): int => (int) ($row['successes'] ?? 0), $rows)),
            'success_rate_itt' => $this->support->meanNullable(array_column($rows, 'success_rate_itt')),
            'success_rate' => $this->support->meanNullable(array_column($rows, 'success_rate')),
            'success_rate_valid_results' => $this->support->meanNullable(array_column($rows, 'success_rate_valid_results')),
            'success_rate_wilson_95' => $wilson,
            'environment_failure_rate' => $this->support->meanNullable(array_column($rows, 'environment_failure_rate')),
            'failure_classes' => $failureClasses,
            'total_cost_usd' => $this->support->sumNullable(array_column($rows, 'total_cost_usd')),
            'avg_cost_usd' => $this->support->meanNullable(array_column($rows, 'avg_cost_usd')),
            'cost_per_task' => $this->support->meanNullable(array_column($rows, 'cost_per_task')),
            'median_cost_usd' => $this->support->meanNullable(array_column($rows, 'median_cost_usd')),
            'p95_cost_usd' => $this->support->meanNullable(array_column($rows, 'p95_cost_usd')),
            'median_cost_ci_95' => $pick($first, 'median_cost_ci_95'),
            'avg_tokens_in' => $this->support->meanNullable(array_column($rows, 'avg_tokens_in')),
            'avg_tokens_out' => $this->support->meanNullable(array_column($rows, 'avg_tokens_out')),
            'total_tokens_in' => $this->support->sumNullable(array_column($rows, 'total_tokens_in')),
            'total_tokens_out' => $this->support->sumNullable(array_column($rows, 'total_tokens_out')),
            'total_tokens' => $this->support->sumNullable(array_column($rows, 'total_tokens')),
            'tokens_per_task' => $this->support->meanNullable(array_column($rows, 'tokens_per_task')),
            'avg_tokens_per_task' => $this->support->meanNullable(array_column($rows, 'avg_tokens_per_task')),
            'tokens_in_per_task' => $this->support->meanNullable(array_column($rows, 'tokens_in_per_task')),
            'tokens_out_per_task' => $this->support->meanNullable(array_column($rows, 'tokens_out_per_task')),
            'tokens_per_second' => $this->support->meanNullable(array_column($rows, 'tokens_per_second')),
            'tokens_per_second_aggregate' => $this->support->meanNullable(array_column($rows, 'tokens_per_second_aggregate')),
            'tokens_in_per_second' => $this->support->meanNullable(array_column($rows, 'tokens_in_per_second')),
            'tokens_out_per_second' => $this->support->meanNullable(array_column($rows, 'tokens_out_per_second')),
            'cost_per_1k_tokens' => $this->support->meanNullable(array_column($rows, 'cost_per_1k_tokens')),
            'tokens_coverage' => $tokenCoverages[0] ?? null,
            'avg_wall_ms' => $avgWalls === [] ? null : round(array_sum($avgWalls) / count($avgWalls), 6),
            'median_wall_ms' => $this->support->meanNullable(array_column($rows, 'median_wall_ms')),
            'median_wall_sec' => $this->support->meanNullable(array_column($rows, 'median_wall_sec')),
            'p95_wall_ms' => $p95Walls === [] ? null : round(array_sum($p95Walls) / count($p95Walls), 6),
            'median_wall_ci_95' => $pick($first, 'median_wall_ci_95'),
            'stability' => $stabilities === [] ? null : round(array_sum($stabilities) / count($stabilities), 4),
            'dimensions' => $dimMeans === [] ? null : $dimMeans,
            'avg_patch_bloat' => $patchBloats === [] ? null : round(array_sum($patchBloats) / count($patchBloats), 6),
            'reality' => $realities[0] ?? null,
            'per_arm' => array_map(function (array $row): array {
                return $this->support->enrichTokenThroughput([
                    'task_type' => $row['task_type'] ?? null,
                    'arm_id' => $row['arm_id'] ?? null,
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'success_rate_wilson_95' => $row['success_rate_wilson_95'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'cost_per_1k_tokens' => $row['cost_per_1k_tokens'] ?? null,
                    'total_cost_usd' => $row['total_cost_usd'] ?? null,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'median_wall_sec' => $row['median_wall_sec'] ?? null,
                    'p95_wall_ms' => $row['p95_wall_ms'] ?? null,
                    'avg_tokens_in' => $row['avg_tokens_in'] ?? null,
                    'avg_tokens_out' => $row['avg_tokens_out'] ?? null,
                    'total_tokens_in' => $row['total_tokens_in'] ?? null,
                    'total_tokens_out' => $row['total_tokens_out'] ?? null,
                    'total_tokens' => $row['total_tokens'] ?? null,
                    'tokens_per_task' => $row['tokens_per_task'] ?? null,
                    'avg_tokens_per_task' => $row['avg_tokens_per_task'] ?? null,
                    'tokens_in_per_task' => $row['tokens_in_per_task'] ?? null,
                    'tokens_out_per_task' => $row['tokens_out_per_task'] ?? null,
                    'tokens_per_second' => $row['tokens_per_second'] ?? null,
                    'tokens_per_second_aggregate' => $row['tokens_per_second_aggregate'] ?? null,
                    'tokens_in_per_second' => $row['tokens_in_per_second'] ?? null,
                    'tokens_out_per_second' => $row['tokens_out_per_second'] ?? null,
                    'tokens_coverage' => $row['tokens_coverage'] ?? null,
                    'stability' => $row['stability'] ?? null,
                    'environment_failure_rate' => $row['environment_failure_rate'] ?? null,
                    'failure_classes' => $row['failure_classes'] ?? [],
                    'dimensions' => $row['dimensions'] ?? null,
                    'n' => $row['n'] ?? null,
                    'successes' => $row['successes'] ?? null,
                ]);
            }, $rows),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function harvestNativeSignals(string $suiteId, string $runId): array
    {
        $dir = RunPaths::runDir($runId).'/external_results/units';
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($dir.'/'.$file), true);
            if (! is_array($payload)) {
                continue;
            }
            foreach ($this->extractNativeRows($suiteId, $payload) as $row) {
                $row['_unit_file'] = $file;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractNativeRows(string $suiteId, array $payload): array
    {
        if (in_array($suiteId, EngineeringNativeSuiteAdapter::SUITE_IDS, true)) {
            $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId, $this->profile);

            return array_map(static fn (array $row): array => [
                'case_id' => $row['case_id'] ?? null,
                'repetition' => $row['repetition'] ?? null,
                'status' => $row['status'] ?? null,
                'failure_reason' => $row['failure_reason'] ?? null,
                'measurement_type' => $row['measurement_type']
                    ?? $delivery['measurement_type']
                    ?? null,
                'score_metric' => $row['score_metric']
                    ?? $delivery['primary_metric']
                    ?? null,
                'score' => $row['score'] ?? null,
                'native_metrics' => $row['native_metrics'] ?? null,
                'wall_ms' => $row['wall_ms'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'native_artifact' => $row['native_artifact'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array')));
        }

        return match ($suiteId) {
            'tau2_bench' => array_map(static function (array $sim): array {
                $usage = (array) ($sim['usage'] ?? []);

                return [
                    'simulation_id' => $sim['simulation_id'] ?? null,
                    'task_id' => $sim['task_id'] ?? null,
                    'reward' => $sim['reward'] ?? null,
                    'termination_reason' => $sim['termination_reason'] ?? null,
                    'duration_sec' => $sim['duration_sec'] ?? null,
                    'tokens_in' => $usage['input_tokens'] ?? null,
                    'tokens_out' => $usage['output_tokens'] ?? null,
                    'cost_usd' => $usage['cost_usd'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['simulations'] ?? []), 'is_array'))),
            'bfcl' => array_map(static fn (array $row): array => [
                'case_id' => $row['case_id'] ?? null,
                'test_category' => $row['test_category'] ?? null,
                'native_category' => $row['native_category'] ?? null,
                'accuracy' => $row['accuracy'] ?? null,
                'status' => $row['status'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'cost_usd' => $row['cost_usd'] ?? null,
                'field_presence' => $row['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'terminal_bench' => array_map(static fn (array $ep): array => [
                'episode_id' => $ep['episode_id'] ?? null,
                'exit_status' => $ep['exit_status'] ?? null,
                'failure_mode' => $ep['failure_mode'] ?? null,
                'duration_sec' => $ep['duration_sec'] ?? null,
                'input_tokens' => $ep['input_tokens'] ?? null,
                'output_tokens' => $ep['output_tokens'] ?? null,
                'cost_usd' => $ep['cost_usd'] ?? null,
                'field_presence' => $ep['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['episodes'] ?? []), 'is_array'))),
            'senior_swe_bench' => array_map(static function (array $task) use ($payload): array {
                return [
                    'task_id' => $task['task_id'] ?? null,
                    'task' => $task['task'] ?? null,
                    'resolved' => $task['resolved'] ?? null,
                    'exception_info' => $task['exception_info'] ?? null,
                    'duration_seconds' => $task['duration_seconds'] ?? null,
                    'usage' => $task['usage'] ?? null,
                    'verdicts' => $task['verdicts'] ?? null,
                    'judge_config' => $payload['judge_config'] ?? null,
                    'coverage' => $payload['coverage'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            'swe_bench_live' => array_map(static fn (array $inst): array => [
                'instance_id' => $inst['instance_id'] ?? null,
                'resolved' => $inst['resolved'] ?? null,
                'eval_status' => $inst['eval_status'] ?? null,
                'duration_sec' => $inst['duration_sec'] ?? null,
                'usage' => $inst['usage'] ?? null,
                'model_name_or_path' => $inst['model_name_or_path'] ?? null,
            ], array_values(array_filter((array) ($payload['instances'] ?? []), 'is_array'))),
            'live_code_bench' => array_map(static fn (array $row): array => [
                'question_id' => $row['question_id'] ?? ($row['native_question_id'] ?? null),
                'pass@1' => $row['pass@1'] ?? null,
                'graded_list' => $row['graded_list'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
                'platform' => $row['platform'] ?? null,
                'contest_id' => $row['contest_id'] ?? null,
                'usage_capture' => $row['usage_capture'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'inspect_evals' => array_map(static function (array $sample) use ($payload): array {
                return [
                    'sample_id' => $sample['id'] ?? null,
                    'scores' => $sample['scores'] ?? null,
                    'total_time' => $sample['total_time'] ?? null,
                    'working_time' => $sample['working_time'] ?? null,
                    'model_usage' => $sample['model_usage'] ?? null,
                    'completed' => $sample['completed'] ?? null,
                    'error' => $sample['error'] ?? null,
                    'retries' => $sample['retries'] ?? null,
                    'eval' => $payload['eval'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['samples'] ?? []), 'is_array'))),
            'hal_harness' => array_map(static fn (array $run): array => [
                'task_id' => $run['task_id'] ?? null,
                'success' => $run['success'] ?? null,
                'total_cost_usd' => $run['total_cost_usd'] ?? null,
                'latency_sec' => $run['latency_sec'] ?? null,
                'input_tokens' => $run['input_tokens'] ?? null,
                'output_tokens' => $run['output_tokens'] ?? null,
                'field_presence' => $run['field_presence'] ?? null,
                'runtime_bridge' => $run['runtime_bridge'] ?? null,
                'model' => $run['model'] ?? null,
                'agent' => $run['agent'] ?? null,
            ], array_values(array_filter((array) ($payload['runs'] ?? []), 'is_array'))),
            'aider_polyglot' => array_map(static fn (array $row): array => [
                'testcase' => $row['testcase'] ?? null,
                'language' => $row['language'] ?? null,
                'tries' => $row['tries'] ?? null,
                'tests_outcomes' => $row['tests_outcomes'] ?? null,
                'duration' => $row['duration'] ?? null,
                'cost' => $row['cost'] ?? null,
                'sent_tokens' => $row['sent_tokens'] ?? null,
                'received_tokens' => $row['received_tokens'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'swe_marathon' => array_map(static fn (array $task): array => [
                'task_id' => $task['task_id'] ?? null,
                'resolved' => $task['resolved'] ?? null,
                'exception_info' => $task['exception_info'] ?? null,
                'duration_seconds' => $task['duration_seconds'] ?? null,
                'usage' => $task['usage'] ?? null,
                'agent' => $task['agent'] ?? null,
                'model' => $task['model'] ?? null,
            ], array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $delivery
     * @return list<string>
     */
    private function caseIdsForRun(string $runId, array $meta, array $delivery): array
    {
        $planPath = RunPaths::planPath($runId);
        if (is_file($planPath)) {
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $fromPlan = array_values(array_filter(
                array_map('strval', (array) ($plan['case_ids'] ?? [])),
                fn (string $id): bool => $id !== '',
            ));
            if ($fromPlan !== []) {
                return $fromPlan;
            }
        }
        $fromScope = array_values(array_filter(
            array_map('strval', (array) ($meta['claim_scope']['cases'] ?? [])),
            fn (string $id): bool => $id !== '',
        ));
        if ($fromScope !== []) {
            return $fromScope;
        }

        return array_values(array_map('strval', (array) ($delivery['fase_a_case_pack'] ?? [])));
    }

    /** @return array<string, mixed> */
    private function runArtifacts(string $runId): array
    {
        $dir = RunPaths::runDir($runId);
        $map = [
            'report_json' => $dir.'/report.json',
            'report_md' => $dir.'/report.md',
            'report_csv' => $dir.'/report.csv',
            'adjudication_json' => $dir.'/adjudication.json',
            'evidence_pack_json' => $dir.'/evidence_pack.json',
            'plan_json' => $dir.'/plan.json',
            'native_execution_manifest_json' => $dir.'/native_execution_manifest.json',
            'receipts_jsonl' => $dir.'/receipts.jsonl',
            'events_jsonl' => RunPaths::eventsPath($runId),
            'uplift_json' => $dir.'/uplift.json',
        ];
        $out = [];
        foreach ($map as $key => $path) {
            $out[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return list<string>
     */
    private function flattenObservedKeys(array $signals): array
    {
        $keys = [];
        foreach ($signals as $signal) {
            foreach (array_keys($signal) as $key) {
                if ($key === '_unit_file') {
                    continue;
                }
                $value = $signal[$key];
                if ($value === null) {
                    continue;
                }
                $keys[$key] = true;
                if (is_array($value) && ! array_is_list($value)) {
                    foreach (array_keys($value) as $child) {
                        $keys[$key.'.'.$child] = true;
                    }
                }
            }
        }
        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    private function eventsCompleteForRun(string $runId): bool
    {
        return EventsLifecycleContract::isClaimGradeComplete(
            RunPaths::eventsPath($runId),
        );
    }

    /**
     * @param  list<string>  $missing
     */
    private function measurementStatus(array $missing, bool $coverageIncomplete, string $suiteId, ?string $runId = null): string
    {
        if ($this->harnessOmitsUsage($suiteId, $runId, $missing)) {
            return 'harness_omit';
        }
        if ($missing === [] && ! $coverageIncomplete) {
            return 'complete';
        }
        if ($missing !== [] && $coverageIncomplete === false) {
            return 'omitted';
        }
        if ($coverageIncomplete) {
            return 'partial';
        }

        return $missing === [] ? 'complete' : 'omitted';
    }

    /**
     * @param  list<string>  $missing
     */
    private function harnessOmitsUsage(string $suiteId, ?string $runId, array $missing): bool
    {
        if ($suiteId === 'inspect_evals' && $missing !== []) {
            return true;
        }
        if ($runId === null || $missing === []) {
            return false;
        }
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            $presence = (array) ($receipt->data['field_presence'] ?? []);
            foreach (['tokens_in', 'tokens_out', 'cost_usd'] as $field) {
                $reason = (string) (($presence[$field]['reason'] ?? '') ?: '');
                if ($reason !== '' && preg_match('/omit|harness_omit|inspect_logs_omit/i', $reason) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ledger de execução por unidade: a evidência que separa "modelo errou" de
     * "teste/ambiente falhou ou não terminou". Cada unidade carrega status,
     * failure_class, exit code, wall_ms e — para as que NÃO deram success — o
     * tail do stderr do log nativo (o "porquê"). Somado a isso, um veredito de
     * confiabilidade: env-failure alta ou unidades faltando ⇒ suíte não_confiável
     * (não conta como fraqueza do modelo).
     *
     * @return array<string,mixed>
     */
    private function executionEvidenceForRun(string $runId, int $unitsExpected = 0): array
    {
        $receipts = RunReceipt::loadAll($runId);

        // exit_code + log tail por unidade nativa, indexado pelo prefixo do
        // expected_result_path (<case>__<arm>__r<rep>__<hash>.json).
        $native = [];
        foreach (NativeExecutionReceipt::loadAll($runId) as $nr) {
            $file = basename((string) ($nr->data['expected_result_path'] ?? ''));
            $key = preg_replace('/__[0-9a-f]+\.json$/', '', $file) ?: $file;
            $stdoutRelative = $nr->data['stdout']['path'] ?? null;
            $stderrRelative = $nr->data['stderr']['path'] ?? null;
            $stdoutPath = is_string($stdoutRelative)
                ? RunPaths::runDir($runId).'/'.$stdoutRelative
                : null;
            $stderrPath = is_string($stderrRelative)
                ? RunPaths::runDir($runId).'/'.$stderrRelative
                : null;
            $native[$key] = [
                'execution_id' => $nr->data['execution_id'] ?? null,
                'exit_code' => $nr->data['exit_code'] ?? null,
                'exit_nonzero_promoted' => (bool) ($nr->data['exit_nonzero_promoted'] ?? false),
                'failure_reason' => $nr->data['failure_reason'] ?? null,
                'wall_ms' => $nr->data['wall_ms'] ?? null,
                'stdout_path' => is_string($stdoutPath) && is_file($stdoutPath) ? $stdoutPath : null,
                'stderr_path' => is_string($stderrPath) && is_file($stderrPath) ? $stderrPath : null,
            ];
        }

        $units = [];
        $classes = ['success' => 0, 'model_failure' => 0, 'environment_failure' => 0, 'timeout' => 0, 'invalid_result' => 0, 'other' => 0];
        foreach ($receipts as $r) {
            $status = (string) ($r->data['status'] ?? '');
            $failureClass = (string) ($r->data['failure_class'] ?? '');
            $bucket = match (true) {
                $status === 'success' => 'success',
                $failureClass === FailureClass::ENVIRONMENT => 'environment_failure',
                $failureClass === 'model_failure' => 'model_failure',
                $failureClass === 'timeout', $status === 'timeout' => 'timeout',
                $failureClass === 'invalid_result' => 'invalid_result',
                default => 'other',
            };
            $key = ($r->data['case_id'] ?? '').'__'.str_replace('@', '_', (string) ($r->data['arm_id'] ?? '')).'__r'.($r->data['repetition'] ?? '');
            $nat = $native[$key] ?? [];
            $stderrTail = null;
            $raw = '';
            if ($bucket !== 'success' && is_string($nat['stderr_path'] ?? null)) {
                $raw = (string) file_get_contents($nat['stderr_path']);
                $stderrTail = mb_substr(rtrim($raw), -800);
            }
            // Rede de segurança cross-adapter: se o log mostra erro de API/infra
            // (400, role incompatível, timeout, conexão), a tarefa NÃO foi o
            // modelo errando — reclassifica para ambiente/fluxo mesmo que o
            // adapter tenha marcado model_failure. Impede "0% de raciocínio"
            // quando a verdade é a API recusando a chamada.
            $reclassified = null;
            if (in_array($bucket, ['model_failure', 'invalid_result', 'other'], true)
                && $raw !== ''
                && preg_match('/BadRequestError|error code: 4\d\d|unsupported_message_role|invalid_request_error|does not support|ConnectionError|ReadTimeout|RateLimitError|ServiceUnavailable|InternalServerError|502 Bad Gateway|503 Service/i', $raw) === 1) {
                $reclassified = $bucket;
                $bucket = 'environment_failure';
            }
            $classes[$bucket]++;
            $units[] = [
                'case_id' => $r->data['case_id'] ?? null,
                // task_type é o que a unidade REALMENTE mede. Uma suíte pode
                // abranger domínios distintos (inspect_evals = gsm8k matemática
                // + mmlu conhecimento + gpqa ciência); sem isto, capacidade só
                // pode ser mapeada por suíte e conhecimento viraria "raciocínio".
                'task_type' => $r->data['task_type'] ?? null,
                // Nota bruta quando a escala é graduada: o binário sozinho lê
                // "0% = não sabe" quando as notas foram médias. Ver graded_summary.
                'graded_score' => $r->data['metadata']['native']['graded_score'] ?? null,
                'graded_max' => $r->data['metadata']['native']['graded_max'] ?? null,
                'arm_id' => $r->data['arm_id'] ?? null,
                'repetition' => $r->data['repetition'] ?? null,
                'status' => $status,
                'failure_class' => $bucket === 'environment_failure' ? FailureClass::ENVIRONMENT : ($failureClass ?: null),
                'failure_reason' => $r->data['failure_reason'] ?? ($nat['failure_reason'] ?? null),
                'reclassified_from' => $reclassified,
                'blame' => match ($bucket) {
                    'success' => 'success',
                    'model_failure', 'invalid_result' => 'model',
                    'environment_failure', 'timeout' => 'environment_or_flow',
                    default => 'unknown',
                },
                'exit_code' => $nat['exit_code'] ?? null,
                'exit_nonzero_promoted' => $nat['exit_nonzero_promoted'] ?? false,
                'wall_ms' => $r->data['wall_ms'] ?? ($nat['wall_ms'] ?? null),
                'stdout_log' => $nat['stdout_path'] ?? null,
                'stderr_log' => $nat['stderr_path'] ?? null,
                'stderr_tail' => $stderrTail,
            ];
        }

        $total = count($units);
        $envAndFlow = $classes['environment_failure'] + $classes['timeout'];
        $modelAttributable = $classes['success'] + $classes['model_failure'] + $classes['invalid_result'];
        $unitsMissing = $unitsExpected > 0 ? max(0, $unitsExpected - $total) : 0;
        $envRate = $total > 0 ? round($envAndFlow / $total, 4) : 0.0;
        // Confiável = dá para JULGAR O MODELO nesta suíte. O critério é COBERTURA
        // (fração de unidades com desfecho atribuível ao modelo), não o gate de
        // claim de 5%: uma suíte 17/18 model_failure é uma fraqueza real do
        // modelo; uma suíte 7/9 environment_failure é o teste que não rodou.
        $coverage = $total > 0 ? round($modelAttributable / $total, 4) : 0.0;
        $minCoverage = (float) config('atlas_rivals.report.min_model_coverage', 0.7);
        $reliable = $total > 0 && $unitsMissing === 0 && $coverage >= $minCoverage;
        $reason = match (true) {
            $total === 0 => 'no_units_recorded',
            $unitsMissing > 0 => 'units_missing:'.$unitsMissing.'_of_'.$unitsExpected,
            $coverage < $minCoverage => 'model_coverage_'.$coverage.'_below_'.$minCoverage.'_env_or_flow_ate_the_run',
            default => null,
        };
        // O slug acima é para máquina. O humano precisa da frase: um leitor não
        // pode ter de decifrar "model_coverage_0.22_below_0.7" para entender que
        // o teste quebrou e o modelo não está sendo julgado.
        $reasonHuman = match (true) {
            $total === 0 => 'Nenhuma tarefa foi registrada — a suíte não chegou a rodar.',
            $unitsMissing > 0 => "{$unitsMissing} de {$unitsExpected} tarefas não foram registradas — execução incompleta.",
            $coverage < $minCoverage => "{$envAndFlow} de {$total} tarefas quebraram por erro de ambiente/fluxo (o teste não rodou até o fim), "
                ."não por erro do modelo. Sobra pouco para julgar: não é falha do modelo, é medição que não aconteceu.",
            default => null,
        };

        return [
            'units_expected' => $unitsExpected,
            'units_recorded' => $total,
            'units_missing' => $unitsMissing,
            'class_counts' => $classes,
            'environment_or_flow_rate' => $envRate,
            'model_coverage' => $coverage,
            'reliable' => $reliable,
            'unreliable_reason' => $reason,
            'unreliable_reason_human' => $reasonHuman,
            'blame_summary' => [
                'model_failures' => $classes['model_failure'] + $classes['invalid_result'],
                'environment_or_flow_failures' => $envAndFlow,
                'successes' => $classes['success'],
            ],
            // Mesmo cálculo do agregado, fatiado por task_type: permite tratar
            // cada domínio de uma suíte multi-domínio como capacidade própria,
            // com sua confiabilidade (env-failure de um não contamina o outro).
            'blame_by_task_type' => $this->blameByTaskType($units, $minCoverage),
            'units' => $units,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $units
     * @return array<string, array<string,mixed>>
     */
    private function blameByTaskType(array $units, float $minCoverage): array
    {
        $groups = [];
        foreach ($units as $unit) {
            $taskType = (string) ($unit['task_type'] ?? '');
            if ($taskType === '') {
                continue;
            }
            $groups[$taskType] ??= ['successes' => 0, 'model_failures' => 0, 'environment_or_flow_failures' => 0];
            match ((string) ($unit['blame'] ?? '')) {
                'success' => $groups[$taskType]['successes']++,
                'model' => $groups[$taskType]['model_failures']++,
                'environment_or_flow' => $groups[$taskType]['environment_or_flow_failures']++,
                default => null,
            };
        }

        $out = [];
        foreach ($groups as $taskType => $g) {
            $decidable = $g['successes'] + $g['model_failures'];
            $total = $decidable + $g['environment_or_flow_failures'];
            $coverage = $total > 0 ? round($decidable / $total, 4) : 0.0;
            $reliable = $total > 0 && $coverage >= $minCoverage;
            $out[$taskType] = [
                'successes' => $g['successes'],
                'model_failures' => $g['model_failures'],
                'environment_or_flow_failures' => $g['environment_or_flow_failures'],
                'tasks_decidable' => $decidable,
                'model_coverage' => $coverage,
                'reliable' => $reliable,
                'intelligence_rate' => $decidable > 0 ? round($g['successes'] / $decidable, 4) : null,
                'unreliable_reason_human' => $reliable ? null : ($total === 0
                    ? 'Nenhuma tarefa registrada para esta habilidade.'
                    : "{$g['environment_or_flow_failures']} de {$total} tarefas quebraram por erro de ambiente/fluxo "
                        .'(o teste não rodou até o fim), não por erro do modelo.'),
            ];
        }

        return $out;
    }

    /**
     * Prefer report intelligence_rate; fall back to receipts (excludes environment_failure).
     */
    private function intelligenceFromReceipts(string $runId): ?float
    {
        $items = RunReceipt::loadAll($runId);
        if ($items === []) {
            return null;
        }
        $nonEnv = array_values(array_filter(
            $items,
            fn (RunReceipt $r): bool => ($r->data['failure_class'] ?? null) !== FailureClass::ENVIRONMENT,
        ));
        if ($nonEnv === []) {
            return null;
        }
        $successes = count(array_filter(
            $nonEnv,
            fn (RunReceipt $r): bool => ($r->data['status'] ?? null) === 'success',
        ));

        return round($successes / count($nonEnv), 4);
    }
}
