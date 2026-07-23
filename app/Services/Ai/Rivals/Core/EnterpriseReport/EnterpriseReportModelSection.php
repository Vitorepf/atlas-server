<?php

namespace App\Services\Ai\Rivals\Core\EnterpriseReport;

use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;

/**
 * Matriz de modelos, face single-model, fatos medidos e perfis de modelo. Extraido VERBATIM de EnterpriseReportBuilder (GOD-DEBULK).
 */
class EnterpriseReportModelSection
{
    public function __construct(private EnterpriseReportSupport $support) {}

    /**
     * Face Atlas×modelo para bateria single-model: 1 linha de ranking + per_suite factual.
     *
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $atlasUplift
     * @return array<string, mixed>
     */
    public function singleModelAtlasFaceMatrix(
        string $primaryModel,
        array $suiteRows,
        array $runs,
        array $atlasUplift,
    ): array {
        $familyBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $sid = (string) ($family['suite_id'] ?? '');
            if ($sid !== '') {
                $familyBySuite[$sid] = $family;
            }
        }

        $perSuite = [];
        $bareAll = [];
        $barePaired = [];
        $atlasPaired = [];
        $pairsValidCount = 0;

        foreach ($suiteRows as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $scores = $this->support->armScoresForSuite($suiteId, $primaryModel, $runs);
            $family = $familyBySuite[$suiteId] ?? null;
            $continuous = is_array($family)
                && ($family['primary_outcome'] ?? null) === 'native_score';
            $bare = $scores['bare'] ?? (($row['intelligence_rate'] ?? null) === null
                ? null
                : (float) $row['intelligence_rate']);
            if ($bare === null && ($row['success_rate_itt'] ?? null) !== null
                && (($row['env_failure_rate'] ?? 0) == 0)) {
                $bare = (float) $row['success_rate_itt'];
            }
            $atlas = $scores['atlas'] ?? null;
            $upliftStatus = is_array($family) ? (string) ($family['status'] ?? 'not_run') : 'not_applicable';
            $comparable = $continuous
                ? $upliftStatus === 'real_uplift'
                    && is_numeric($family['bare_native_score'] ?? null)
                    && is_numeric($family['atlas_native_score'] ?? null)
                : $upliftStatus === 'real_uplift'
                    && $bare !== null
                    && ($family['atlas_intelligence'] ?? $atlas) !== null;

            $atlasShown = null;
            $delta = null;
            if ($comparable && ! $continuous) {
                $atlasShown = isset($family['atlas_intelligence'])
                    ? (float) $family['atlas_intelligence']
                    : (float) $atlas;
                $bareForDelta = isset($family['bare_intelligence'])
                    ? (float) $family['bare_intelligence']
                    : (float) $bare;
                $delta = round($atlasShown - $bareForDelta, 4);
                $barePaired[] = $bareForDelta;
                $atlasPaired[] = $atlasShown;
            }
            if ($comparable) {
                $pairsValidCount++;
            }

            if ($bare !== null && ! $continuous) {
                $bareAll[] = (float) $bare;
            }

            $perSuite[] = [
                'suite_id' => $suiteId,
                'status' => $row['status'] ?? null,
                'primary_outcome' => $family['primary_outcome'] ?? 'artifact_status',
                'native_score_metric' => $family['native_score_metric'] ?? null,
                'bare_native_score' => $continuous ? $family['bare_native_score'] : null,
                'atlas_native_score' => $continuous ? $family['atlas_native_score'] : null,
                'delta_native_score' => $continuous ? $family['delta_native_score'] : null,
                'delta_native_score_ci_95' => $continuous
                    ? $family['delta_native_score_ci_95']
                    : null,
                'bare_intelligence' => $continuous ? null : $bare,
                'atlas_intelligence' => $continuous ? null : ($comparable ? $atlasShown : null),
                'delta_intelligence' => $delta,
                'uplift_status' => $upliftStatus,
                'comparable' => $comparable,
                'reason' => $comparable ? null : ($family['reason'] ?? ($upliftStatus === 'not_applicable' ? 'suite_not_in_uplift_families' : $upliftStatus)),
            ];
        }

        $avg = static fn (array $vals): ?float => $vals === [] ? null : round(array_sum($vals) / count($vals), 4);
        $pairsValid = $pairsValidCount;
        $pairsTotal = count((array) ($atlasUplift['families'] ?? []));

        $row = [
            'model_id' => $primaryModel,
            'rank' => 1,
            'bare_intelligence' => $avg($bareAll),
            'bare_suite_count' => count($bareAll),
            'atlas_intelligence' => $avg($atlasPaired),
            'bare_on_paired' => $avg($barePaired),
            'delta_intelligence' => ($avg($barePaired) !== null && $avg($atlasPaired) !== null)
                ? round((float) $avg($atlasPaired) - (float) $avg($barePaired), 4)
                : null,
            'pairs_valid' => $pairsValid,
            'pairs_total' => $pairsTotal,
            'pair_coverage' => $pairsTotal > 0 ? "{$pairsValid}/{$pairsTotal}" : '0/0',
            'per_suite' => $perSuite,
        ];

        return [
            'mode' => 'single_model_battery',
            'model_id' => $primaryModel,
            'face' => 'model_with_without_atlas',
            'rows' => [$row],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  array<string, mixed>  $atlasUplift
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public function buildMeasuredFacts(
        string $primaryModel,
        array $suiteRows,
        array $atlasUplift,
        array $counts,
    ): array {
        $measured = [];
        $diagnostic = [];
        $incomplete = [];
        $better = 0;
        $worse = 0;
        $deltaSum = 0.0;
        $binaryMeasured = 0;
        $continuousMeasured = 0;

        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $name = EnterpriseReportBuilder::familyLabel((string) ($family['family'] ?? $family['suite_id'] ?? '?'));
            if (($family['status'] ?? '') === 'real_uplift'
                && ($family['primary_outcome'] ?? null) === 'native_score'
                && is_numeric($family['bare_native_score'] ?? null)
                && is_numeric($family['atlas_native_score'] ?? null)
                && is_numeric($family['delta_native_score'] ?? null)) {
                $metric = (string) ($family['native_score_metric'] ?? 'native_score');
                $metricLabel = match ($metric) {
                    'rougeL' => 'ROUGE-L',
                    'pass@1' => 'pass@1',
                    default => $metric,
                };
                $bareScore = round((float) $family['bare_native_score'], 6);
                $atlasScore = round((float) $family['atlas_native_score'], 6);
                $nativeDelta = (float) $family['delta_native_score'];
                $sign = $nativeDelta >= 0 ? '+' : '';
                $samples = (int) data_get($family, 'delta_native_score_ci_95.samples', 0);
                $line = "{$name}: {$metricLabel} {$bareScore} → {$atlasScore} "
                    ."({$sign}".round($nativeDelta, 6).')'
                    .($samples < 2 ? ' · N=1, sem CI inferencial' : '');
                if (($family['diagnostic_only'] ?? false) === true) {
                    $diagnostic[] = $line.' · diagnóstico';

                    continue;
                }
                $measured[] = $line;
                $continuousMeasured++;
                if ($nativeDelta > 0) {
                    $better++;
                } elseif ($nativeDelta < 0) {
                    $worse++;
                }

                continue;
            }
            if (($family['status'] ?? '') === 'real_uplift'
                && ($family['bare_intelligence'] ?? null) !== null
                && ($family['atlas_intelligence'] ?? null) !== null) {
                $delta = (float) ($family['delta_intelligence']
                    ?? ((float) $family['atlas_intelligence'] - (float) $family['bare_intelligence']));
                $pct = round($delta * 100, 1);
                $sign = $pct >= 0 ? '+' : '';
                $line = "{$name}: sem Atlas "
                    .round((float) $family['bare_intelligence'] * 100, 1).'% → com Atlas '
                    .round((float) $family['atlas_intelligence'] * 100, 1)."% ({$sign}{$pct} pp)";
                // Par diagnóstico (ex.: ambos 0% = ninguém resolveu, ou exclusão de
                // caso) NÃO é fato confirmado de uplift — não pode entrar na contagem
                // nem no saldo, senão infla/dilui o veredito com ruído sem sinal.
                if (($family['diagnostic_only'] ?? false) === true) {
                    $diagnostic[] = $line.' · diagnóstico (sem sinal de uplift)';

                    continue;
                }
                $measured[] = $line;
                $binaryMeasured++;
                $deltaSum += $delta;
                if ($delta > 0) {
                    $better++;
                } elseif ($delta < 0) {
                    $worse++;
                }
            } else {
                $reason = (string) ($family['reason'] ?? $family['status'] ?? 'incomplete');
                $incomplete[] = "{$name}: comparação Atlas ainda inválida ({$reason})";
            }
        }

        foreach ($suiteRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $suiteId = (string) ($row['suite_id'] ?? '?');
            if (in_array($status, ['missing_data', 'failed', 'blocked', 'not_run'], true)) {
                $fields = array_values((array) ($row['missing_fields'] ?? []));
                $suffix = $fields === [] ? '' : ' ('.implode(',', $fields).')';
                $incomplete[] = "{$suiteId}: suite status={$status}{$suffix}";
            } elseif (($row['tokens_coverage_incomplete'] ?? false) === true) {
                $incomplete[] = "{$suiteId}: tokens medidos com coverage parcial (env/harness omit em algumas units)";
            }
        }

        $total = count((array) ($atlasUplift['families'] ?? []));
        // Só pares confirmados (não-diagnósticos) formam o veredito. Diagnósticos
        // ficam num balde à parte — contam presença, nunca sinal de uplift.
        $confirmed = count($measured);

        // Contagem e magnitude precisam concordar para tomar um lado — senão o
        // relatório mente por spin. 2↑/1↓ com saldo médio NEGATIVO (uma regressão
        // grande concentrada) não é "melhorou mais vezes": é dividido. Mesma
        // lógica da capa. O saldo médio (pp) é o árbitro do sinal.
        $meanPp = $binaryMeasured > 0 ? round(($deltaSum / $binaryMeasured) * 100, 1) : 0.0;
        $countSign = $better <=> $worse;
        $meanSign = $meanPp <=> 0.0;
        $tally = "{$better}↑ / {$worse}↓, saldo médio ".($meanPp >= 0 ? '+' : '')."{$meanPp} pp";
        if ($confirmed === 0) {
            $diagNote = $diagnostic === [] ? '' : ' ('.count($diagnostic).' par(es) só diagnóstico)';
            $headline = "{$primaryModel}: ainda sem pares bare×Atlas confirmados{$diagNote}.";
        } elseif ($continuousMeasured > 0) {
            $binaryNote = $binaryMeasured > 0
                ? " e {$binaryMeasured} par(es) binário(s)"
                : '';
            $headline = "{$primaryModel}: {$continuousMeasured} par(es) contínuo(s){$binaryNote} "
                ."medido(s) ({$better}↑ / {$worse}↓); métricas heterogêneas não são somadas num placar global.";
        } elseif ($countSign !== 0 && $countSign === $meanSign) {
            $verb = $meanSign > 0 ? 'melhorou' : 'piorou';
            $headline = "{$primaryModel}: nos {$confirmed} pares confirmados, Atlas {$verb} em contagem e em saldo médio ({$tally}).";
        } elseif ($countSign > 0 && $meanSign < 0) {
            $headline = "{$primaryModel}: dividido — Atlas melhorou em mais famílias, mas uma regressão concentrada deixa o saldo médio negativo ({$tally}).";
        } elseif ($countSign < 0 && $meanSign > 0) {
            $headline = "{$primaryModel}: dividido — Atlas piorou em mais famílias, mas um ganho concentrado deixa o saldo médio positivo ({$tally}).";
        } else {
            $headline = "{$primaryModel}: resultado misto, sem lado definido ({$tally}).";
        }

        return [
            'headline' => $headline,
            'measured' => array_values(array_unique($measured)),
            'diagnostic' => array_values(array_unique($diagnostic)),
            'incomplete' => array_values(array_unique($incomplete)),
            'pairs_valid' => $confirmed,
            'pairs_diagnostic' => count($diagnostic),
            'pairs_total' => $total,
            'suites_ok' => (int) ($counts['ok'] ?? 0),
            'suites_missing_data' => (int) ($counts['missing_data'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    public function modelMatrixRows(array $suiteRows, array $runs): array
    {
        $bySuite = [];
        $suiteIds = array_values(array_unique(array_filter(array_map(
            fn (array $row): string => (string) ($row['suite_id'] ?? ''),
            $suiteRows,
        ))));
        foreach ($suiteIds as $suiteId) {
            $best = $this->support->bestRunForSuite($suiteId, $runs);
            if ($best === null) {
                continue;
            }
            $run = $best[1];
            $models = array_values(array_filter(
                (array) ($run['claim_scope']['models'] ?? []),
                fn ($model): bool => is_string($model) && $model !== '',
            ));
            if (count($models) < 2) {
                // Infer from report rows arm_ids when claim_scope is thin.
                $reportRows = (array) (($run['report']['rows'] ?? []) ?: []);
                $fromArms = [];
                foreach ($reportRows as $row) {
                    $armId = (string) ($row['arm_id'] ?? '');
                    if ($armId !== '' && str_contains($armId, '@bare')) {
                        $fromArms[explode('@', $armId, 2)[0]] = true;
                    }
                }
                $models = array_keys($fromArms);
            }
            if (count($models) < 2) {
                continue;
            }
            sort($models);
            $metrics = [];
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if (! str_ends_with($armId, '@bare')) {
                    continue;
                }
                $modelId = explode('@', $armId, 2)[0];
                $intel = ($row['intelligence_rate'] ?? null);
                if ($intel === null && (($row['environment_failure_rate'] ?? 0) == 0)) {
                    $intel = $row['success_rate_itt'] ?? null;
                }
                $metrics[$modelId] = [
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'intelligence_rate' => $intel,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'tokens_in_avg' => $row['avg_tokens_in'] ?? null,
                    'tokens_out_avg' => $row['avg_tokens_out'] ?? null,
                ];
            }
            $bySuite[$suiteId] = [
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'models' => $models,
                'per_model' => $metrics,
                'status' => (($run['adjudication']['pipeline_valid'] ?? false) === true) ? 'ok' : 'failed',
            ];
        }

        return array_values($bySuite);
    }

    /**
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @param  array<string,array{reliable:bool,reason:?string}>  $reliability
     * @return list<array<string,mixed>>
     */
    public function buildModelProfiles(array $dissections, array $atlasUplift, array $reliability = []): array
    {
        $dissections['_suite_reliability'] = $reliability;
        $byModel = [];
        foreach ((array) ($dissections['models'] ?? []) as $entry) {
            if (! is_array($entry) || ($entry['present'] ?? false) !== true) {
                continue;
            }
            $byModel[(string) ($entry['model_id'] ?? '')][(string) ($entry['runtime'] ?? '')] = $entry;
        }

        $profiles = [];
        foreach ($byModel as $modelId => $runtimes) {
            $strengths = [];
            $weaknesses = [];
            $middle = [];
            $unreliable = [];
            // suite_rows carrega reliable/unreliable_reason; per_suite (dissecção)
            // não — cruzamos por suite_id para não julgar modelo em suíte que não terminou.
            $reliabilityBySuite = [];
            foreach ((array) ($dissections['_suite_reliability'] ?? []) as $suiteId => $rel) {
                $reliabilityBySuite[(string) $suiteId] = $rel;
            }
            foreach ((array) data_get($runtimes['bare'] ?? [], 'per_suite', []) as $suiteId => $row) {
                if (! is_array($row) || ($row['present'] ?? false) !== true
                    || ! is_numeric($row['success_rate_itt'] ?? null)) {
                    continue;
                }
                $rate = (float) $row['success_rate_itt'];
                $rel = $reliabilityBySuite[(string) $suiteId] ?? ['reliable' => true, 'reason' => null];
                $cell = [
                    'suite_id' => (string) $suiteId,
                    'success_rate_itt' => $rate,
                    'status' => (string) ($row['status'] ?? ''),
                    'category' => $row['category'] ?? null,
                    'reliable' => (bool) ($rel['reliable'] ?? true),
                    'unreliable_reason' => $rel['reason'] ?? null,
                ];
                // Execução incompleta/env-failure alta NÃO é fraqueza do modelo:
                // vai para um balde à parte, fora de forte/mediano/fraco.
                if (($rel['reliable'] ?? true) !== true) {
                    $unreliable[] = $cell;

                    continue;
                }
                match (true) {
                    $rate >= 0.5 => $strengths[] = $cell,
                    $rate <= 0.2 => $weaknesses[] = $cell,
                    default => $middle[] = $cell,
                };
            }
            usort($strengths, static fn (array $a, array $b): int => $b['success_rate_itt'] <=> $a['success_rate_itt']);
            usort($weaknesses, static fn (array $a, array $b): int => $a['success_rate_itt'] <=> $b['success_rate_itt']);

            $atlasDeltas = [];
            foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
                if (! is_array($family)) {
                    continue;
                }
                $atlasDeltas[] = [
                    'family' => $family['family'] ?? null,
                    'suite_id' => $family['suite_id'] ?? null,
                    'status' => $family['status'] ?? null,
                    'diagnostic_only' => $family['diagnostic_only'] ?? null,
                    'bare_intelligence' => $family['bare_intelligence'] ?? null,
                    'atlas_intelligence' => $family['atlas_intelligence'] ?? null,
                    'delta_intelligence' => $family['delta_intelligence'] ?? null,
                ];
            }

            $fmt = static fn (array $cells): string => implode(', ', array_map(
                static fn (array $c): string => $c['suite_id'].' ('.round($c['success_rate_itt'] * 100).'%)',
                $cells,
            ));
            $measuredDeltas = array_values(array_filter(
                $atlasDeltas,
                static fn (array $f): bool => is_numeric($f['delta_intelligence'] ?? null),
            ));
            $deltaText = $measuredDeltas === []
                ? 'sem par bare×atlas provado ainda'
                : implode('; ', array_map(
                    static fn (array $f): string => $f['suite_id'].' '
                        .(($f['delta_intelligence'] >= 0 ? '+' : '').round($f['delta_intelligence'] * 100).'pp'
                        .(($f['diagnostic_only'] ?? false) === true ? ' (diagnóstico)' : '')),
                    $measuredDeltas,
                ));

            $unreliableText = $unreliable === []
                ? ''
                : ' NÃO CONFIÁVEL (execução incompleta/ambiente, não julga o modelo): '.implode(', ', array_map(
                    static fn (array $c): string => $c['suite_id'].' ['.($c['unreliable_reason'] ?? 'unreliable').']',
                    $unreliable,
                )).'.';

            $profiles[] = [
                'model_id' => $modelId,
                'runtimes_measured' => array_keys($runtimes),
                'strengths' => array_slice($strengths, 0, 5),
                'middle' => $middle,
                'weaknesses' => array_slice($weaknesses, 0, 5),
                'unreliable' => $unreliable,
                'atlas_deltas' => $atlasDeltas,
                'narrative' => sprintf(
                    '%s bare (só suítes confiáveis): forte em %s; mediano em %s; fraco em %s. Com Atlas: %s.%s',
                    $modelId,
                    $strengths !== [] ? $fmt($strengths) : 'nenhuma suíte confiável ≥50%',
                    $middle !== [] ? $fmt($middle) : '—',
                    $weaknesses !== [] ? $fmt($weaknesses) : 'nenhuma suíte confiável ≤20%',
                    $deltaText,
                    $unreliableText,
                ),
            ];
        }

        return $profiles;
    }
}
