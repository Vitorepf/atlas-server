<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;

/**
 * Relatório empresarial consolidado Fase A — sempre 10 suites, nunca claim agregado.
 */
class EnterpriseReportBuilder
{
    public function build(): array
    {
        $suiteIds = (new SuiteRegistry)->externalSuiteIds();
        $upliftFamilies = (array) config('atlas_rivals.uplift_families', []);
        $primaryModel = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');

        $runs = $this->scanRuns();
        $suiteRows = [];
        $included = [];
        $excluded = [];
        $gaps = [];
        $modelsSeen = [];

        foreach ($suiteIds as $suiteId) {
            $match = $this->bestRunForSuite($suiteId, $runs);
            if ($match === null) {
                $suiteRows[] = $this->emptySuiteRow($suiteId);
                $gaps[] = "not_run:{$suiteId}";

                continue;
            }

            [$runId, $meta] = $match;
            $row = $this->suiteRowFromRun($suiteId, $runId, $meta);
            $suiteRows[] = $row;
            if (in_array($row['status'], ['ok', 'missing_data', 'failed'], true)) {
                $included[] = $runId;
            } else {
                $excluded[] = ['run_id' => $runId, 'suite_id' => $suiteId, 'reason' => $row['status']];
            }
            foreach ($row['missing_fields'] as $field) {
                $gaps[] = "missing_data:{$suiteId}:{$field}";
            }
            if ($row['status'] === 'missing_data') {
                $gaps[] = "missing_data:{$suiteId}";
            }
            foreach ((array) ($meta['claim_scope']['models'] ?? []) as $modelId) {
                if (is_string($modelId) && $modelId !== '') {
                    $modelsSeen[$modelId] = true;
                }
            }
        }

        $modelIds = array_keys($modelsSeen);
        sort($modelIds);
        $modelMatrix = count($modelIds) <= 1
            ? [
                'mode' => 'single_model_battery',
                'model_id' => $modelIds[0] ?? $primaryModel,
                'rows' => [],
            ]
            : [
                'mode' => 'model_vs_model',
                'model_ids' => $modelIds,
                'rows' => $this->modelMatrixRows($suiteRows, $runs),
            ];

        $atlasUplift = ['families' => []];
        foreach ($upliftFamilies as $family => $suiteId) {
            $familyRow = $this->upliftFamilyRow((string) $family, (string) $suiteId, $runs);
            $atlasUplift['families'][] = $familyRow;
            if ($familyRow['status'] === 'not_run') {
                $gaps[] = "uplift_not_run:{$family}";
            }
        }

        $counts = [
            'ok' => 0,
            'failed' => 0,
            'blocked' => 0,
            'missing_data' => 0,
            'not_run' => 0,
        ];
        foreach ($suiteRows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        $report = [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'built_at' => now()->toIso8601String(),
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'executive_summary' => [
                'primary_model' => $primaryModel,
                'models_observed' => $modelIds,
                'provider_binding' => 'hermes+verboo',
                'suites_ok' => $counts['ok'],
                'suites_failed' => $counts['failed'],
                'suites_blocked' => $counts['blocked'],
                'suites_missing_data' => $counts['missing_data'],
                'suites_not_run' => $counts['not_run'],
                'uplift_families_total' => count($upliftFamilies),
                'uplift_families_ready' => count(array_filter(
                    $atlasUplift['families'],
                    fn (array $f): bool => ($f['status'] ?? '') === 'real_uplift',
                )),
            ],
            'suite_rows' => $suiteRows,
            'model_matrix' => $modelMatrix,
            'atlas_uplift' => $atlasUplift,
            'gaps' => array_values(array_unique($gaps)),
            'included_run_ids' => array_values(array_unique($included)),
            'excluded_run_ids' => $excluded,
        ];
        $report['report_hash'] = self::hashPayload($report);

        $violations = SchemaContract::validate($report, SchemaContract::ENTERPRISE_REPORT);
        if ($violations !== []) {
            throw new RuntimeException('rivals_invalid_enterprise_report:'.implode(',', $violations));
        }

        AtomicWriter::write(
            RunPaths::enterpriseReportPath(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
        AtomicWriter::write(RunPaths::enterpriseMarkdownPath(), $this->markdown($report));
        AtomicWriter::write(RunPaths::enterpriseCsvPath(), $this->csv($report));

        return $report;
    }

    /** @return list<array{run_id: string, suite_id: string, adjudication: array<string, mixed>, report: ?array<string, mixed>, uplift: ?array<string, mixed>}> */
    private function scanRuns(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }
        $out = [];
        foreach (array_diff(scandir($runsDir) ?: [], ['.', '..']) as $runId) {
            if (! is_dir($runsDir.'/'.$runId)) {
                continue;
            }
            $planPath = RunPaths::planPath($runId);
            if (! is_file($planPath)) {
                continue;
            }
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $suiteId = (string) ($plan['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $adjPath = RunPaths::adjudicationPath($runId);
            $adjudication = is_file($adjPath)
                ? (json_decode((string) file_get_contents($adjPath), true) ?? [])
                : [];
            $reportPath = RunPaths::reportPath($runId);
            $report = is_file($reportPath)
                ? (json_decode((string) file_get_contents($reportPath), true) ?? null)
                : null;
            $upliftPath = RunPaths::runDir($runId).'/uplift.json';
            $uplift = is_file($upliftPath)
                ? (json_decode((string) file_get_contents($upliftPath), true) ?? null)
                : null;
            $out[] = [
                'run_id' => $runId,
                'suite_id' => $suiteId,
                'adjudication' => $adjudication,
                'report' => $report,
                'uplift' => $uplift,
                'claim_scope' => $adjudication['claim_scope'] ?? ($report['claim_scope'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function bestRunForSuite(string $suiteId, array $runs): ?array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId,
        ));
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function (array $a, array $b): int {
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });

        $best = $candidates[0];

        return [(string) $best['run_id'], $best];
    }

    /** @return array<string, mixed> */
    private function emptySuiteRow(string $suiteId): array
    {
        return [
            'suite_id' => $suiteId,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function suiteRowFromRun(string $suiteId, string $runId, array $meta): array
    {
        $adj = (array) ($meta['adjudication'] ?? []);
        $report = $meta['report'];
        $pipelineValid = ($adj['pipeline_valid'] ?? false) === true;
        $internalAllowed = ($adj['internal_claim_allowed'] ?? $adj['claim_allowed'] ?? false) === true;

        $metrics = $this->aggregateReportMetrics(is_array($report) ? $report : []);
        $missing = $metrics['missing_fields'];

        $status = 'failed';
        if (! $pipelineValid && $report === null) {
            $status = 'blocked';
        } elseif ($pipelineValid && $missing !== []) {
            $status = 'missing_data';
        } elseif ($pipelineValid) {
            $status = 'ok';
        }

        return [
            'suite_id' => $suiteId,
            'status' => $status,
            'run_id' => $runId,
            'success_rate_itt' => $metrics['success_rate_itt'],
            'median_wall_ms' => $metrics['median_wall_ms'],
            'tokens_in_avg' => $metrics['tokens_in_avg'],
            'tokens_out_avg' => $metrics['tokens_out_avg'],
            'cost_per_task' => $metrics['cost_per_task'],
            'cost_basis' => $metrics['cost_basis'],
            'env_failure_rate' => $metrics['env_failure_rate'],
            'missing_fields' => $missing,
            'pipeline_valid' => $pipelineValid,
            'internal_claim_allowed' => $internalAllowed,
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
                'median_wall_ms' => null,
                'tokens_in_avg' => null,
                'tokens_out_avg' => null,
                'cost_per_task' => null,
                'cost_basis' => null,
                'env_failure_rate' => null,
                'missing_fields' => ['report_rows'],
            ];
        }

        $successRates = [];
        $walls = [];
        $tokensIn = [];
        $tokensOut = [];
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
            if ($row['median_wall_ms'] !== null && $row['median_wall_ms'] !== '') {
                $walls[] = (float) $row['median_wall_ms'];
            }
            if ($row['avg_tokens_in'] !== null) {
                $tokensIn[] = (float) $row['avg_tokens_in'];
            }
            if ($row['avg_tokens_out'] !== null) {
                $tokensOut[] = (float) $row['avg_tokens_out'];
            }
            if ($row['cost_per_task'] !== null) {
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
            if ($n > 0 && $in === 0) {
                $missing[] = 'tokens_in';
            }
            if ($n > 0 && $out === 0) {
                $missing[] = 'tokens_out';
            }
        }

        if ($tokenCoverageIncomplete) {
            $missing[] = 'tokens_in';
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

        return [
            'success_rate_itt' => $successRates === [] ? null : round(array_sum($successRates) / count($successRates), 4),
            'median_wall_ms' => $walls === [] ? null : $this->median($walls),
            'tokens_in_avg' => $tokensIn === [] ? null : round(array_sum($tokensIn) / count($tokensIn), 2),
            'tokens_out_avg' => $tokensOut === [] ? null : round(array_sum($tokensOut) / count($tokensOut), 2),
            'cost_per_task' => $costs === [] ? null : round(array_sum($costs) / count($costs), 6),
            'cost_basis' => $costBasis,
            'env_failure_rate' => $envRates === [] ? null : round(array_sum($envRates) / count($envRates), 4),
            'missing_fields' => $missing,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function modelMatrixRows(array $suiteRows, array $runs): array
    {
        // Placeholder comparative rows — full pairing lands in Wave 5.
        return array_values(array_filter(array_map(
            function (array $row) use ($runs): ?array {
                if (($row['run_id'] ?? null) === null) {
                    return null;
                }
                $run = null;
                foreach ($runs as $candidate) {
                    if ($candidate['run_id'] === $row['run_id']) {
                        $run = $candidate;
                        break;
                    }
                }
                $models = (array) ($run['claim_scope']['models'] ?? []);

                return [
                    'suite_id' => $row['suite_id'],
                    'models' => $models,
                    'status' => $row['status'],
                    'success_rate_itt' => $row['success_rate_itt'],
                ];
            },
            $suiteRows,
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function upliftFamilyRow(string $family, string $suiteId, array $runs): array
    {
        foreach ($runs as $run) {
            if (($run['suite_id'] ?? null) !== $suiteId) {
                continue;
            }
            $uplift = $run['uplift'] ?? null;
            if (! is_array($uplift)) {
                continue;
            }
            $kind = (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $status = $kind === 'real_uplift' ? 'real_uplift' : (string) ($uplift['uplift_kind'] ?? 'unsupported');

            return [
                'family' => $family,
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'status' => $status,
                'uplift_supported' => (bool) ($uplift['uplift_supported'] ?? false),
                'internal_claim_allowed' => (bool) ($uplift['internal_claim_allowed'] ?? $uplift['claim_allowed'] ?? false),
                'stop_the_line' => (bool) ($uplift['stop_the_line'] ?? false),
            ];
        }

        return [
            'family' => $family,
            'suite_id' => $suiteId,
            'run_id' => null,
            'status' => 'not_run',
            'uplift_supported' => false,
            'internal_claim_allowed' => false,
            'stop_the_line' => false,
        ];
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round($values[$mid], 6);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 6);
    }

    /** @param array<string, mixed> $report */
    private function markdown(array $report): string
    {
        $summary = (array) $report['executive_summary'];
        $md = "# Rivals Fase A — Relatório Empresarial\n\n";
        $md .= 'claim_allowed: false (agregado nunca é claim)'."\n";
        $md .= 'provider_binding: '.($summary['provider_binding'] ?? 'hermes+verboo')."\n";
        $md .= 'primary_model: '.($summary['primary_model'] ?? '')."\n";
        $md .= 'built_at: '.($report['built_at'] ?? '')."\n";
        $md .= 'report_hash: '.($report['report_hash'] ?? '')."\n\n";

        $md .= "## Capa executiva\n\n";
        $md .= '- suites ok: '.($summary['suites_ok'] ?? 0)."\n";
        $md .= '- suites failed: '.($summary['suites_failed'] ?? 0)."\n";
        $md .= '- suites missing_data: '.($summary['suites_missing_data'] ?? 0)."\n";
        $md .= '- suites blocked: '.($summary['suites_blocked'] ?? 0)."\n";
        $md .= '- suites not_run: '.($summary['suites_not_run'] ?? 0)."\n";
        $md .= '- uplift families ready: '.($summary['uplift_families_ready'] ?? 0)
            .'/'.($summary['uplift_families_total'] ?? 0)."\n\n";

        $md .= "## Matriz das 10 suites\n\n";
        $md .= "| suite | status | success_itt | median_ms | tokens in/out | cost/task | missing |\n";
        $md .= "|---|---|---|---|---|---|---|\n";
        foreach ($report['suite_rows'] as $row) {
            $tokens = (($row['tokens_in_avg'] ?? null) === null && ($row['tokens_out_avg'] ?? null) === null)
                ? 'n/a'
                : ($row['tokens_in_avg'] ?? 'n/a').' / '.($row['tokens_out_avg'] ?? 'n/a');
            $missing = $row['missing_fields'] === [] ? '-' : implode(',', $row['missing_fields']);
            $md .= '| '.$row['suite_id']
                .' | '.$row['status']
                .' | '.($row['success_rate_itt'] ?? 'n/a')
                .' | '.($row['median_wall_ms'] ?? 'n/a')
                .' | '.$tokens
                .' | '.($row['cost_per_task'] ?? 'n/a')
                .' | '.$missing
                ." |\n";
        }

        $md .= "\n## Face modelo × modelo\n\n";
        $matrix = (array) $report['model_matrix'];
        $md .= '- mode: '.($matrix['mode'] ?? 'unknown')."\n";
        if (($matrix['mode'] ?? '') === 'single_model_battery') {
            $md .= '- model_id: '.($matrix['model_id'] ?? '')."\n";
        } else {
            $md .= '- model_ids: '.implode(', ', (array) ($matrix['model_ids'] ?? []))."\n";
        }

        $md .= "\n## Face Atlas × modelo\n\n";
        $md .= "| family | suite | status | supported | stop_the_line |\n|---|---|---|---|---|\n";
        foreach ((array) ($report['atlas_uplift']['families'] ?? []) as $family) {
            $md .= '| '.($family['family'] ?? '')
                .' | '.($family['suite_id'] ?? '')
                .' | '.($family['status'] ?? '')
                .' | '.((($family['uplift_supported'] ?? false) ? 'true' : 'false'))
                .' | '.((($family['stop_the_line'] ?? false) ? 'true' : 'false'))
                ." |\n";
        }

        $md .= "\n## Gaps\n\n";
        if ($report['gaps'] === []) {
            $md .= "- (none)\n";
        } else {
            foreach ($report['gaps'] as $gap) {
                $md .= '- '.$gap."\n";
            }
        }

        return $md."\n";
    }

    /** @param array<string, mixed> $report */
    private function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'suite_id',
            'status',
            'run_id',
            'success_rate_itt',
            'median_wall_ms',
            'tokens_in_avg',
            'tokens_out_avg',
            'cost_per_task',
            'cost_basis',
            'env_failure_rate',
            'pipeline_valid',
            'internal_claim_allowed',
            'missing_fields',
        ]);
        foreach ($report['suite_rows'] as $row) {
            fputcsv($handle, [
                $row['suite_id'],
                $row['status'],
                $row['run_id'],
                $row['success_rate_itt'],
                $row['median_wall_ms'],
                $row['tokens_in_avg'],
                $row['tokens_out_avg'],
                $row['cost_per_task'],
                $row['cost_basis'],
                $row['env_failure_rate'],
                $row['pipeline_valid'] ? 'true' : 'false',
                $row['internal_claim_allowed'] ? 'true' : 'false',
                implode('|', $row['missing_fields']),
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
