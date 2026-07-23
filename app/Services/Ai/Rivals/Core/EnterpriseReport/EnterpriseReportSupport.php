<?php

namespace App\Services\Ai\Rivals\Core\EnterpriseReport;

use App\Services\Ai\Rivals\Core\FailureClass;
use App\Services\Ai\Rivals\Core\RunReceipt;

/**
 * Primitivas leaf compartilhadas do relatorio empresarial (run scan, arm scores, metricas, slice, stats). Extraido VERBATIM de EnterpriseReportBuilder (GOD-DEBULK).
 */
class EnterpriseReportSupport
{

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function bestRunForSuite(string $suiteId, array $runs): ?array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId,
        ));
        if ($candidates === []) {
            return null;
        }
        // A linha da suíte é a medição do modelo SOZINHO (bare). Um run só de
        // atlas_dev (ex.: bateria Atlas rodando agora) não pode virar a fonte
        // bare — senão "sem Atlas" sairia de dados com Atlas.
        usort($candidates, function (array $a, array $b): int {
            $aBare = $this->runHasBareArm($a) ? 1 : 0;
            $bBare = $this->runHasBareArm($b) ? 1 : 0;
            if ($aBare !== $bBare) {
                return $bBare <=> $aBare;
            }
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

    /** Um run tem braço bare quando alguma linha do report é @bare. */
    public function runHasBareArm(array $run): bool
    {
        foreach ((array) data_get($run, 'report.rows', []) as $row) {
            if (is_array($row) && str_ends_with((string) ($row['arm_id'] ?? ''), '@bare')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Backfill throughput fields from usage + wall when older report rows omit them.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function enrichTokenThroughput(array $metrics): array
    {
        $in = isset($metrics['tokens_in_avg']) && is_numeric($metrics['tokens_in_avg'])
            ? (float) $metrics['tokens_in_avg']
            : (isset($metrics['avg_tokens_in']) && is_numeric($metrics['avg_tokens_in'])
                ? (float) $metrics['avg_tokens_in']
                : null);
        $out = isset($metrics['tokens_out_avg']) && is_numeric($metrics['tokens_out_avg'])
            ? (float) $metrics['tokens_out_avg']
            : (isset($metrics['avg_tokens_out']) && is_numeric($metrics['avg_tokens_out'])
                ? (float) $metrics['avg_tokens_out']
                : null);
        $sum = ($in !== null || $out !== null) ? (float) ($in ?? 0) + (float) ($out ?? 0) : null;

        if (($metrics['total_tokens'] ?? null) === null && $sum !== null) {
            $totalIn = isset($metrics['total_tokens_in']) && is_numeric($metrics['total_tokens_in'])
                ? (float) $metrics['total_tokens_in'] : null;
            $totalOut = isset($metrics['total_tokens_out']) && is_numeric($metrics['total_tokens_out'])
                ? (float) $metrics['total_tokens_out'] : null;
            if ($totalIn !== null || $totalOut !== null) {
                $metrics['total_tokens'] = round((float) ($totalIn ?? 0) + (float) ($totalOut ?? 0), 2);
            } else {
                $metrics['total_tokens'] = round($sum, 2);
            }
        }

        if (($metrics['tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['tokens_per_task'] = round($sum, 4);
        }
        if (($metrics['avg_tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['avg_tokens_per_task'] = round($sum, 4);
        }

        $wallMs = null;
        if (isset($metrics['median_wall_ms']) && is_numeric($metrics['median_wall_ms']) && (float) $metrics['median_wall_ms'] > 0) {
            $wallMs = (float) $metrics['median_wall_ms'];
        } elseif (isset($metrics['avg_wall_ms']) && is_numeric($metrics['avg_wall_ms']) && (float) $metrics['avg_wall_ms'] > 0) {
            $wallMs = (float) $metrics['avg_wall_ms'];
        }
        if ($wallMs !== null && ($metrics['median_wall_sec'] ?? null) === null) {
            $metrics['median_wall_sec'] = round($wallMs / 1000.0, 6);
        }

        if (($metrics['tokens_per_second'] ?? null) === null && $sum !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_per_second'] = round($sum / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_per_second_aggregate'] ?? null) === null && ($metrics['tokens_per_second'] ?? null) !== null) {
            $metrics['tokens_per_second_aggregate'] = $metrics['tokens_per_second'];
        }
        if (($metrics['tokens_in_per_second'] ?? null) === null && $in !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_in_per_second'] = round($in / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_out_per_second'] ?? null) === null && $out !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_out_per_second'] = round($out / ($wallMs / 1000.0), 4);
        }

        $totalTok = isset($metrics['total_tokens']) && is_numeric($metrics['total_tokens'])
            ? (float) $metrics['total_tokens']
            : $sum;
        $totalCost = isset($metrics['total_cost_usd']) && is_numeric($metrics['total_cost_usd'])
            ? (float) $metrics['total_cost_usd']
            : null;
        if (($metrics['cost_per_1k_tokens'] ?? null) === null && $totalTok !== null && $totalTok > 0 && $totalCost !== null && $totalCost > 0) {
            $metrics['cost_per_1k_tokens'] = round(($totalCost / $totalTok) * 1000.0, 6);
        }

        return $metrics;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{bare: ?float, atlas: ?float}
     */
    public function armScoresForSuite(string $suiteId, string $modelId, array $runs): array
    {
        $bare = [];
        $atlas = [];
        // Fail-closed on stale pollution: only the best pipeline_valid (else newest) run.
        $best = $this->bestRunForSuite($suiteId, $runs);
        $scoped = $best === null ? [] : [$best[1]];
        // Caller may pass a single preferred run (e.g. upliftFamilyRow) — honor that.
        if (count($runs) === 1 && (string) ($runs[0]['suite_id'] ?? '') === $suiteId) {
            $scoped = $runs;
        }
        foreach ($scoped as $run) {
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $score = null;
                if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                    $score = (float) $row['intelligence_rate'];
                } elseif (($row['success_rate_itt'] ?? null) !== null && is_numeric($row['success_rate_itt'])) {
                    // Legacy rows without intelligence_rate: only use ITT when env rate is zero/absent.
                    $env = $row['environment_failure_rate'] ?? null;
                    if ($env === null || (is_numeric($env) && (float) $env === 0.0)) {
                        $score = (float) $row['success_rate_itt'];
                    }
                }
                if ($score === null) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if ($armId === $modelId.'@bare') {
                    $bare[] = $score;
                }
                if ($armId === $modelId.'@atlas_dev') {
                    $atlas[] = $score;
                }
            }
            // Receipts fallback when report rows omit intelligence_rate and env polluted ITT.
            $runId = (string) ($run['run_id'] ?? '');
            if ($runId !== '' && ($bare === [] || $atlas === [])) {
                $bareBits = [];
                $atlasBits = [];
                foreach (RunReceipt::loadAll($runId) as $receipt) {
                    if (($receipt->data['failure_class'] ?? null) === FailureClass::ENVIRONMENT) {
                        continue;
                    }
                    $armId = (string) ($receipt->data['arm_id'] ?? '');
                    $ok = ($receipt->data['status'] ?? null) === 'success' ? 1.0 : 0.0;
                    if ($armId === $modelId.'@bare') {
                        $bareBits[] = $ok;
                    }
                    if ($armId === $modelId.'@atlas_dev') {
                        $atlasBits[] = $ok;
                    }
                }
                if ($bare === [] && $bareBits !== []) {
                    $bare[] = round(array_sum($bareBits) / count($bareBits), 4);
                }
                if ($atlas === [] && $atlasBits !== []) {
                    $atlas[] = round(array_sum($atlasBits) / count($atlasBits), 4);
                }
            }
        }

        return [
            'bare' => $bare === [] ? null : round(array_sum($bare) / count($bare), 4),
            'atlas' => $atlas === [] ? null : round(array_sum($atlas) / count($atlas), 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @param  list<string>  $observedNative
     * @param  list<string>  $observedReport
     * @return array<string, mixed>
     */
    public function deliveryCoverage(array $delivery, array $observedNative, array $observedReport): array
    {
        $expectedNative = array_values(array_map('strval', (array) ($delivery['native_metrics'] ?? [])));
        $expectedReport = array_values(array_map('strval', (array) ($delivery['atlas_report_metrics'] ?? [])));
        $nativeHit = [];
        $nativeMiss = [];
        foreach ($expectedNative as $metric) {
            $base = explode('.', $metric, 2)[0];
            $hit = in_array($metric, $observedNative, true)
                || in_array($base, $observedNative, true)
                || ($base !== $metric && str_starts_with($metric, $base.'.') && in_array($base, $observedNative, true))
                || ($metric === 'verdicts.*' && count(array_filter($observedNative, fn (string $k): bool => str_starts_with($k, 'verdicts'))) > 0)
                || ($metric === 'scores' && in_array('scores', $observedNative, true));
            // wildcard / nested tolerance
            if (! $hit) {
                foreach ($observedNative as $obs) {
                    if ($obs === $base || str_starts_with($obs, $base.'.') || str_starts_with($metric, $obs)) {
                        $hit = true;
                        break;
                    }
                    if (str_ends_with($metric, '.*') && str_starts_with($obs, substr($metric, 0, -1))) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                $nativeHit[] = $metric;
            } else {
                $nativeMiss[] = $metric;
            }
        }
        $reportHit = array_values(array_intersect($expectedReport, $observedReport));
        $reportMiss = array_values(array_diff($expectedReport, $observedReport));

        return [
            'native_expected' => count($expectedNative),
            'native_observed' => count($nativeHit),
            'native_missing' => $nativeMiss,
            'report_expected' => count($expectedReport),
            'report_observed' => count($reportHit),
            'report_missing' => $reportMiss,
            'dimensions_expected' => array_values((array) ($delivery['capability_dimensions'] ?? [])),
            'uplift_eligible' => (bool) ($delivery['uplift_eligible'] ?? false),
            'uplift_family' => $delivery['uplift_family'] ?? null,
        ];
    }

    /** @param list<mixed> $values */
    public function meanNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)) / count($nums), 6);
    }

    /** @param list<mixed> $values */
    public function sumNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)), 6);
    }

    /** @param list<float> $values */
    public function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round($values[$mid], 6);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 6);
    }

    /**
     * Agrega a evidência dos task_types pedidos numa fatia única (mesma conta do
     * agregado da suíte, restrita ao domínio). null = a suíte não mede nenhum.
     *
     * @param  array<string,mixed>  $ev
     * @param  list<string>  $taskTypes
     * @return array<string,mixed>|null
     */
    public function sliceByTaskTypes(array $ev, array $taskTypes): ?array
    {
        // Nota média da escala graduada: sem ela, "Escrita 0%" lê como "não
        // escreve" quando as notas foram 3.6-6.4 numa escala 1-10 (escreve em
        // nível médio, abaixo do limiar). O binário é o veredito; a média é o
        // que impede o veredito de mentir por omissão.
        $graded = [];
        $gradedMax = null;
        foreach ((array) ($ev['units'] ?? []) as $u) {
            if (in_array((string) ($u['task_type'] ?? ''), $taskTypes, true)
                && is_numeric($u['graded_score'] ?? null)) {
                $graded[] = (float) $u['graded_score'];
                $gradedMax ??= $u['graded_max'] ?? null;
            }
        }

        $byType = (array) ($ev['blame_by_task_type'] ?? []);
        $successes = 0;
        $modelFailures = 0;
        $envFailures = 0;
        $found = false;
        foreach ($taskTypes as $taskType) {
            $g = $byType[$taskType] ?? null;
            if (! is_array($g)) {
                continue;
            }
            $found = true;
            $successes += (int) ($g['successes'] ?? 0);
            $modelFailures += (int) ($g['model_failures'] ?? 0);
            $envFailures += (int) ($g['environment_or_flow_failures'] ?? 0);
        }
        if (! $found) {
            return null;
        }
        $decidable = $successes + $modelFailures;
        $total = $decidable + $envFailures;
        $coverage = $total > 0 ? round($decidable / $total, 4) : 0.0;
        $minCoverage = (float) config('atlas_rivals.report.min_model_coverage', 0.7);
        $reliable = $total > 0 && $coverage >= $minCoverage;

        return [
            'tasks_decidable' => $decidable,
            'intelligence_rate' => $decidable > 0 ? round($successes / $decidable, 4) : null,
            'reliable' => $reliable,
            'graded_mean' => $graded === [] ? null : round(array_sum($graded) / count($graded), 2),
            'graded_max' => $graded === [] ? null : $gradedMax,
            'unreliable_reason_human' => $reliable ? null : ($total === 0
                ? 'Nenhuma tarefa registrada para esta habilidade.'
                : "{$envFailures} de {$total} tarefas quebraram por erro de ambiente/fluxo "
                    .'(o teste não rodou até o fim), não por erro do modelo.'),
        ];
    }
}
