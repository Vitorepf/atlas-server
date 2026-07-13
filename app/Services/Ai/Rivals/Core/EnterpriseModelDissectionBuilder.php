<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Dissecção por modelo@runtime — realidade ABSOLUTA do que foi MEDIDO, nunca omnisciência.
 *
 * Contrato epistêmico:
 * - "completo" = todas as facetas Fase A cobertas OU explicitamente unknown
 * - nunca inventa score / nunca colapsa em composite claim
 * - atlas_dev sempre aparece (present=false se não rodou)
 */
final class EnterpriseModelDissectionBuilder
{
    /**
     * Facetas obrigatórias para declarar dissecção Fase A "completa" (honest completeness).
     *
     * @return list<string>
     */
    public static function requiredFacets(): array
    {
        return [
            'suite_coverage_10',
            'intelligence_itt',
            'wilson_95',
            'cost_per_task',
            'tokens_in_out',
            'tokens_per_task',
            'tokens_per_second',
            'speed_wall',
            'stability',
            'failure_taxonomy',
            'env_vs_model_failures',
            'native_signals',
            'capacity_dimensions_when_supported',
            'uplift_families_5',
            'artifacts_and_reports',
            'claim_readiness_flags',
            'delivery_coverage_gaps',
        ];
    }

    /**
     * @param  array<string, mixed>  $report  enterprise report (com suite_rows enriquecidos)
     * @return array<string, mixed>
     */
    public function build(array $report): array
    {
        $primary = (string) ($report['executive_summary']['primary_model'] ?? config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7'));
        $suiteRows = (array) ($report['suite_rows'] ?? []);
        $upliftFamilies = (array) ($report['atlas_uplift']['families'] ?? []);
        $suiteIds = array_values(array_map(
            fn (array $row): string => (string) ($row['suite_id'] ?? ''),
            $suiteRows,
        ));

        $modelIds = array_values(array_unique(array_filter(array_merge(
            [(string) ($report['executive_summary']['primary_model'] ?? '')],
            (array) ($report['executive_summary']['models_observed'] ?? []),
            $this->modelsFromSuiteRows($suiteRows),
        ))));
        if ($modelIds === []) {
            $modelIds = [$primary];
        }
        sort($modelIds);

        $dissections = [];
        foreach ($modelIds as $modelId) {
            foreach (['bare', 'atlas_dev'] as $runtime) {
                $dissections[] = $this->dissectModelRuntime(
                    $modelId,
                    $runtime,
                    $suiteRows,
                    $upliftFamilies,
                    $suiteIds,
                );
            }
        }

        $globalUnknowns = $this->globalUnknowns($suiteRows, $upliftFamilies, $dissections);

        return [
            'epistemic_contract' => [
                'goal' => 'Absolute honesty about measured model reality under Rivals Fase A — not omniscience of the model.',
                'forbidden' => [
                    'composite_best_overall_claim',
                    'invented_zeros_for_missing_fields',
                    'hiding_not_run_as_zero',
                    'claiming_knowledge_outside_measured_suites',
                ],
                'required_facets' => self::requiredFacets(),
                'completeness_definition' => 'A dissection is complete iff every required facet is either measured with evidence or explicitly listed as unknown_with_reason.',
            ],
            'models' => $dissections,
            'global_unknowns' => $globalUnknowns,
            'completeness' => $this->globalCompleteness($dissections),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @return list<string>
     */
    private function modelsFromSuiteRows(array $suiteRows): array
    {
        $out = [];
        foreach ($suiteRows as $row) {
            foreach ((array) (($row['full_metrics']['arm_ids'] ?? []) ?: []) as $armId) {
                if (! is_string($armId) || ! str_contains($armId, '@')) {
                    continue;
                }
                $out[] = explode('@', $armId, 2)[0];
            }
            foreach ((array) ($row['report_rows'] ?? []) as $reportRow) {
                $armId = (string) ($reportRow['arm_id'] ?? '');
                if ($armId !== '' && str_contains($armId, '@')) {
                    $out[] = explode('@', $armId, 2)[0];
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $upliftFamilies
     * @param  list<string>  $suiteIds
     * @return array<string, mixed>
     */
    private function dissectModelRuntime(
        string $modelId,
        string $runtime,
        array $suiteRows,
        array $upliftFamilies,
        array $suiteIds,
    ): array {
        $perSuite = [];
        $measuredFacets = [];
        $unknowns = [];

        $intel = [];
        $costs = [];
        $tokens = [];
        $tokensPerTask = [];
        $tokensPerSecond = [];
        $speeds = [];
        $stabilities = [];
        $failures = [];
        $envFails = [];
        $modelFails = [];
        $wilsonPresent = 0;
        $nativeKeys = [];
        $dimensions = [];
        $artifactsPresent = [];
        $suitesMeasured = 0;
        $suitesMissingData = 0;
        $suitesNotRun = 0;
        $claimFlags = [];

        foreach ($suiteRows as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            $delivery = (array) ($row['delivery'] ?? []);
            $armMatch = $this->armRowsFor($row, $modelId, $runtime);
            $status = (string) ($row['status'] ?? 'not_run');
            $relevant = $armMatch !== [] || ($runtime === 'bare' && $status !== 'not_run' && $this->suiteLikelyPrimaryBare($row, $modelId));

            if ($status === 'not_run') {
                $suitesNotRun++;
                $perSuite[$suiteId] = [
                    'status' => 'not_run',
                    'present' => false,
                    'unknown_reason' => 'suite_not_run',
                ];

                continue;
            }

            if (! $relevant && $runtime === 'atlas_dev') {
                $perSuite[$suiteId] = [
                    'status' => 'not_run',
                    'present' => false,
                    'unknown_reason' => 'atlas_dev_arm_not_observed',
                    'uplift_eligible' => (bool) ($delivery['uplift_eligible'] ?? false),
                ];

                continue;
            }

            if (! $relevant && $runtime === 'bare') {
                // Suite ran but for another model — still record as not this model.
                $perSuite[$suiteId] = [
                    'status' => 'not_this_model',
                    'present' => false,
                    'unknown_reason' => 'suite_run_belongs_to_other_model',
                ];

                continue;
            }

            $suitesMeasured++;
            if ($status === 'missing_data') {
                $suitesMissingData++;
            }

            $metrics = $armMatch[0] ?? null;
            $full = (array) ($row['full_metrics'] ?? []);
            $success = $metrics['success_rate_itt'] ?? ($row['success_rate_itt'] ?? null);
            $cost = $metrics['cost_per_task'] ?? ($row['cost_per_task'] ?? null);
            $wall = $metrics['median_wall_ms'] ?? ($row['median_wall_ms'] ?? null);
            $tokIn = $metrics['avg_tokens_in'] ?? ($row['tokens_in_avg'] ?? null);
            $tokOut = $metrics['avg_tokens_out'] ?? ($row['tokens_out_avg'] ?? null);
            $tokPerTask = $metrics['tokens_per_task'] ?? ($full['tokens_per_task'] ?? ($row['tokens_per_task'] ?? null));
            $tokPerSec = $metrics['tokens_per_second'] ?? ($full['tokens_per_second'] ?? ($row['tokens_per_second'] ?? null));
            $totalTok = $metrics['total_tokens'] ?? ($full['total_tokens'] ?? ($row['total_tokens'] ?? null));
            $costPer1k = $metrics['cost_per_1k_tokens'] ?? ($full['cost_per_1k_tokens'] ?? ($row['cost_per_1k_tokens'] ?? null));
            $stability = $metrics['stability'] ?? ($full['stability'] ?? null);
            $wilson = $metrics['success_rate_wilson_95'] ?? ($full['success_rate_wilson_95'] ?? null);
            $failClasses = (array) ($metrics['failure_classes'] ?? ($full['failure_classes'] ?? []));
            $envRate = $metrics['environment_failure_rate'] ?? ($row['env_failure_rate'] ?? null);
            $dims = $metrics['dimensions'] ?? ($full['dimensions'] ?? null);

            if (is_numeric($success)) {
                $intel[] = (float) $success;
            }
            if (is_numeric($cost)) {
                $costs[] = (float) $cost;
            }
            if (is_numeric($tokIn) || is_numeric($tokOut)) {
                $tokens[] = (float) ($tokIn ?? 0) + (float) ($tokOut ?? 0);
            }
            if (is_numeric($tokPerTask)) {
                $tokensPerTask[] = (float) $tokPerTask;
            }
            if (is_numeric($tokPerSec)) {
                $tokensPerSecond[] = (float) $tokPerSec;
            }
            if (is_numeric($wall)) {
                $speeds[] = (float) $wall;
            }
            if (is_numeric($stability)) {
                $stabilities[] = (float) $stability;
            }
            if (is_array($wilson)) {
                $wilsonPresent++;
            }
            foreach ($failClasses as $cls => $count) {
                $failures[(string) $cls] = ($failures[(string) $cls] ?? 0) + (int) $count;
                if ((string) $cls === 'environment_failure') {
                    $envFails[] = (int) $count;
                } else {
                    $modelFails[] = (int) $count;
                }
            }
            foreach ((array) ($row['observed_native_metric_keys'] ?? []) as $key) {
                $nativeKeys[(string) $key] = true;
            }
            if (is_array($dims)) {
                foreach ($dims as $dim => $value) {
                    if (is_numeric($value)) {
                        $dimensions[(string) $dim][] = (float) $value;
                    }
                }
            }
            foreach ((array) ($row['artifacts'] ?? []) as $name => $meta) {
                if (($meta['present'] ?? false) === true) {
                    $artifactsPresent[(string) $name] = true;
                }
            }
            $claimFlags[] = [
                'suite_id' => $suiteId,
                'pipeline_valid' => (bool) ($row['pipeline_valid'] ?? false),
                'internal_claim_allowed' => (bool) ($row['internal_claim_allowed'] ?? false),
                'missing_fields' => (array) ($row['missing_fields'] ?? []),
            ];

            $perSuite[$suiteId] = [
                'status' => $status,
                'present' => true,
                'run_id' => $row['run_id'] ?? null,
                'success_rate_itt' => $success,
                'success_rate_wilson_95' => $wilson,
                'cost_per_task' => $cost,
                'cost_basis' => $row['cost_basis'] ?? null,
                'cost_per_1k_tokens' => $costPer1k,
                'tokens_in_avg' => $tokIn,
                'tokens_out_avg' => $tokOut,
                'total_tokens' => $totalTok,
                'tokens_per_task' => $tokPerTask,
                'tokens_per_second' => $tokPerSec,
                'tokens_in_per_second' => $metrics['tokens_in_per_second'] ?? ($full['tokens_in_per_second'] ?? null),
                'tokens_out_per_second' => $metrics['tokens_out_per_second'] ?? ($full['tokens_out_per_second'] ?? null),
                'median_wall_ms' => $wall,
                'p95_wall_ms' => $metrics['p95_wall_ms'] ?? ($full['p95_wall_ms'] ?? null),
                'stability' => $stability,
                'environment_failure_rate' => $envRate,
                'failure_classes' => $failClasses,
                'dimensions' => $dims,
                'native_signals' => (array) ($row['native_signals'] ?? []),
                'delivery_coverage' => $row['delivery_coverage'] ?? null,
                'missing_fields' => (array) ($row['missing_fields'] ?? []),
                'category' => $row['category'] ?? ($delivery['category'] ?? null),
                'uplift_eligible' => (bool) ($delivery['uplift_eligible'] ?? false),
            ];
        }

        // Ensure all suite ids present in map.
        foreach ($suiteIds as $suiteId) {
            if ($suiteId !== '' && ! isset($perSuite[$suiteId])) {
                $perSuite[$suiteId] = [
                    'status' => 'not_run',
                    'present' => false,
                    'unknown_reason' => 'suite_absent_from_map',
                ];
            }
        }

        $uplift = [];
        foreach ($upliftFamilies as $family) {
            $familyId = (string) ($family['family'] ?? '');
            $suiteId = (string) ($family['suite_id'] ?? '');
            $status = (string) ($family['status'] ?? 'not_run');
            $uplift[] = [
                'family' => $familyId,
                'suite_id' => $suiteId,
                'status' => $status,
                'present' => $status === 'real_uplift',
                'unknown_reason' => $status === 'real_uplift' ? null : 'uplift_family_not_run_or_unsupported',
            ];
        }

        if ($suitesMeasured > 0) {
            $measuredFacets[] = 'suite_coverage_10';
            $measuredFacets[] = 'intelligence_itt';
            $measuredFacets[] = 'cost_per_task';
            $measuredFacets[] = 'speed_wall';
            $measuredFacets[] = 'failure_taxonomy';
            $measuredFacets[] = 'env_vs_model_failures';
            $measuredFacets[] = 'claim_readiness_flags';
            $measuredFacets[] = 'delivery_coverage_gaps';
            $measuredFacets[] = 'artifacts_and_reports';
        }
        if ($wilsonPresent > 0) {
            $measuredFacets[] = 'wilson_95';
        }
        if ($tokens !== []) {
            $measuredFacets[] = 'tokens_in_out';
        }
        if ($tokensPerTask !== []) {
            $measuredFacets[] = 'tokens_per_task';
        }
        if ($tokensPerSecond !== []) {
            $measuredFacets[] = 'tokens_per_second';
        }
        if ($stabilities !== []) {
            $measuredFacets[] = 'stability';
        }
        if ($nativeKeys !== []) {
            $measuredFacets[] = 'native_signals';
        }
        if ($dimensions !== []) {
            $measuredFacets[] = 'capacity_dimensions_when_supported';
        }
        if ($runtime === 'atlas_dev') {
            $ready = count(array_filter($uplift, fn (array $u): bool => ($u['present'] ?? false) === true));
            if ($ready > 0) {
                $measuredFacets[] = 'uplift_families_5';
            }
        } else {
            // Bare side: uplift facet is "comparison readiness" — measured when families exist as rows.
            if ($uplift !== []) {
                $measuredFacets[] = 'uplift_families_5';
            }
        }

        $required = self::requiredFacets();
        $measuredFacets = array_values(array_unique($measuredFacets));
        foreach ($required as $facet) {
            if (! in_array($facet, $measuredFacets, true)) {
                $unknowns[] = [
                    'facet' => $facet,
                    'reason' => $this->unknownReasonForFacet($facet, $runtime, $suitesMeasured, $nativeKeys, $dimensions, $uplift),
                ];
            }
        }

        if ($suitesNotRun > 0 || $suitesMissingData > 0) {
            $unknowns[] = [
                'facet' => 'full_10_suite_clean_ok',
                'reason' => "suites_not_run={$suitesNotRun}; suites_missing_data={$suitesMissingData}",
            ];
        }

        $dimMeans = [];
        foreach ($dimensions as $dim => $values) {
            $dimMeans[$dim] = round(array_sum($values) / max(1, count($values)), 6);
        }

        $completenessRatio = count($required) === 0
            ? 0.0
            : round(count(array_intersect($required, $measuredFacets)) / count($required), 4);

        return [
            'model_id' => $modelId,
            'runtime' => $runtime,
            'present' => $suitesMeasured > 0,
            'summary' => [
                'suites_measured' => $suitesMeasured,
                'suites_missing_data' => $suitesMissingData,
                'suites_not_run_or_other' => count($suiteIds) - $suitesMeasured,
                'intelligence_mean_itt' => $intel === [] ? null : round(array_sum($intel) / count($intel), 4),
                'cost_per_task_mean' => $costs === [] ? null : round(array_sum($costs) / count($costs), 6),
                'tokens_mean' => $tokens === [] ? null : round(array_sum($tokens) / count($tokens), 2),
                'tokens_per_task_mean' => $tokensPerTask === [] ? null : round(array_sum($tokensPerTask) / count($tokensPerTask), 4),
                'tokens_per_second_mean' => $tokensPerSecond === [] ? null : round(array_sum($tokensPerSecond) / count($tokensPerSecond), 4),
                'median_wall_ms_mean' => $speeds === [] ? null : round(array_sum($speeds) / count($speeds), 2),
                'stability_mean' => $stabilities === [] ? null : round(array_sum($stabilities) / count($stabilities), 4),
                'failure_classes' => $failures,
                'environment_failure_events' => array_sum($envFails),
                'model_failure_events' => array_sum($modelFails),
                'capacity_dimensions' => $dimMeans === [] ? null : $dimMeans,
                'native_metric_keys_observed' => array_keys($nativeKeys),
                'artifacts_present' => array_keys($artifactsPresent),
            ],
            'per_suite' => $perSuite,
            'uplift_families' => $uplift,
            'claim_flags' => $claimFlags,
            'measured_facets' => $measuredFacets,
            'unknowns' => $unknowns,
            'completeness_ratio' => $completenessRatio,
            'dissection_complete' => $unknowns === [] && $completenessRatio >= 1.0,
            'reality_statement' => $this->realityStatement($modelId, $runtime, $suitesMeasured, $completenessRatio, $unknowns),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function armRowsFor(array $row, string $modelId, string $runtime): array
    {
        $needle = $modelId.'@'.$runtime;
        $out = [];
        foreach ((array) (($row['full_metrics']['per_arm'] ?? []) ?: []) as $arm) {
            if (! is_array($arm)) {
                continue;
            }
            if ((string) ($arm['arm_id'] ?? '') === $needle) {
                $out[] = $arm;
            }
        }
        foreach ((array) ($row['report_rows'] ?? []) as $arm) {
            if (! is_array($arm)) {
                continue;
            }
            if ((string) ($arm['arm_id'] ?? '') === $needle) {
                $out[] = $arm;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    private function suiteLikelyPrimaryBare(array $row, string $modelId): bool
    {
        $arms = (array) (($row['full_metrics']['arm_ids'] ?? []) ?: []);
        if ($arms === []) {
            foreach ((array) ($row['report_rows'] ?? []) as $reportRow) {
                $arms[] = (string) ($reportRow['arm_id'] ?? '');
            }
        }
        $bare = array_values(array_filter(
            $arms,
            fn ($arm): bool => is_string($arm) && str_ends_with($arm, '@bare'),
        ));
        if ($bare === []) {
            // Enterprise best-run often single-model battery without arm parse — treat as primary bare.
            return true;
        }
        foreach ($bare as $arm) {
            if (str_starts_with($arm, $modelId.'@')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $nativeKeys
     * @param  array<string, list<float>>  $dimensions
     * @param  list<array<string, mixed>>  $uplift
     */
    private function unknownReasonForFacet(
        string $facet,
        string $runtime,
        int $suitesMeasured,
        array $nativeKeys,
        array $dimensions,
        array $uplift,
    ): string {
        return match ($facet) {
            'suite_coverage_10', 'intelligence_itt', 'cost_per_task', 'speed_wall',
            'failure_taxonomy', 'env_vs_model_failures', 'claim_readiness_flags',
            'delivery_coverage_gaps', 'artifacts_and_reports' => $suitesMeasured === 0
                ? ($runtime === 'atlas_dev' ? 'atlas_dev_not_run' : 'no_suite_runs_for_model')
                : 'partial_suite_coverage',
            'wilson_95' => 'wilson_intervals_absent_from_report_rows',
            'tokens_in_out' => 'harness_omitted_usage_or_not_run',
            'tokens_per_task' => 'usage_present_but_tokens_per_task_not_derivable_or_not_run',
            'tokens_per_second' => 'usage_or_wall_missing_so_tokens_per_second_not_derivable',
            'stability' => 'stability_absent_needs_repetitions',
            'native_signals' => 'native_unit_json_absent',
            'capacity_dimensions_when_supported' => 'no_dimension_bearing_suite_measured_yet',
            'uplift_families_5' => $runtime === 'atlas_dev'
                ? 'dual_arm_uplift_battery_not_run'
                : 'uplift_family_rows_missing',
            default => 'not_measured',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $unknowns
     */
    private function realityStatement(
        string $modelId,
        string $runtime,
        int $suitesMeasured,
        float $completenessRatio,
        array $unknowns,
    ): string {
        if ($suitesMeasured === 0) {
            return "{$modelId}@{$runtime}: NONE measured — reality is empty set, not a zero score.";
        }
        $unknownCount = count($unknowns);
        $pct = (int) round($completenessRatio * 100);

        return "{$modelId}@{$runtime}: measured reality covers {$pct}% of required Fase A facets"
            ." across {$suitesMeasured} suite(s); {$unknownCount} explicit unknown(s). "
            .'This is absolute honesty about evidence, not absolute knowledge of the model.';
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $upliftFamilies
     * @param  list<array<string, mixed>>  $dissections
     * @return list<array<string, mixed>>
     */
    private function globalUnknowns(array $suiteRows, array $upliftFamilies, array $dissections): array
    {
        $out = [];
        foreach ($suiteRows as $row) {
            if (($row['status'] ?? '') === 'not_run') {
                $out[] = ['kind' => 'suite_not_run', 'suite_id' => $row['suite_id'] ?? null];
            }
            if (($row['status'] ?? '') === 'missing_data') {
                $out[] = [
                    'kind' => 'suite_missing_data',
                    'suite_id' => $row['suite_id'] ?? null,
                    'fields' => $row['missing_fields'] ?? [],
                ];
            }
        }
        foreach ($upliftFamilies as $family) {
            if (($family['status'] ?? '') !== 'real_uplift') {
                $out[] = [
                    'kind' => 'uplift_not_ready',
                    'family' => $family['family'] ?? null,
                    'suite_id' => $family['suite_id'] ?? null,
                    'status' => $family['status'] ?? null,
                ];
            }
        }
        $atlasPresent = false;
        foreach ($dissections as $d) {
            if (($d['runtime'] ?? '') === 'atlas_dev' && ($d['present'] ?? false) === true) {
                $atlasPresent = true;
            }
        }
        if (! $atlasPresent) {
            $out[] = [
                'kind' => 'atlas_runtime_absent',
                'reason' => 'No atlas_dev arms measured — cannot dissect model-with-Atlas yet.',
                'command' => 'php artisan atlas:rivals battery --mode=execute --kind=uplift --approve-provider-spend --json',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $dissections
     * @return array<string, mixed>
     */
    private function globalCompleteness(array $dissections): array
    {
        $ratios = array_map(fn (array $d): float => (float) ($d['completeness_ratio'] ?? 0), $dissections);
        $present = array_values(array_filter($dissections, fn (array $d): bool => ($d['present'] ?? false) === true));
        $complete = array_values(array_filter($dissections, fn (array $d): bool => ($d['dissection_complete'] ?? false) === true));

        return [
            'dissections_total' => count($dissections),
            'dissections_present' => count($present),
            'dissections_complete' => count($complete),
            'mean_completeness_ratio' => $ratios === [] ? 0.0 : round(array_sum($ratios) / count($ratios), 4),
            'absolute_knowledge_claim' => false,
            'absolute_measured_reality_claim' => count($complete) === count($dissections) && count($dissections) > 0,
        ];
    }
}
