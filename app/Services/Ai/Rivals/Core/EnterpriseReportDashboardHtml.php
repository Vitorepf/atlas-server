<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Core\EnterpriseReport\EnterpriseReportDashboardHtmlTemplate;

/**
 * Relatório de CAPACIDADES (não pipeline theater).
 * UI em pt-BR. Eixos: inteligência, custo/task, tokens, velocidade, uplift Atlas.
 * Sempre mostra bare + atlas_dev — missing = "não rodou", nunca omitido.
 * Custo $0 de assinatura NÃO vira eixo de scatter; uplift agregado só em pares suite×suite.
 */
final class EnterpriseReportDashboardHtml
{
    /** @var array<string, array{title: string, category: string, blurb: string}> */
    private const SUITES = [
        'tau2_bench' => ['title' => 'τ²-Bench', 'category' => 'Ferramentas', 'blurb' => 'Chamada de ferramentas multi-turno.'],
        'bfcl' => ['title' => 'BFCL', 'category' => 'Ferramentas', 'blurb' => 'Function calling estruturado.'],
        'terminal_bench' => ['title' => 'Terminal-Bench', 'category' => 'Terminal', 'blurb' => 'Tarefas reais de agente no shell.'],
        'senior_swe_bench' => ['title' => 'Senior SWE', 'category' => 'SWE', 'blurb' => 'Tarefas Harbor de engenharia sênior.'],
        'swe_bench_live' => ['title' => 'SWE-Bench Live', 'category' => 'SWE', 'blurb' => 'Reparos de issues GitHub ao vivo.'],
        'live_code_bench' => ['title' => 'LiveCodeBench', 'category' => 'Código', 'blurb' => 'Coding contest com juiz automático.'],
        'inspect_evals' => ['title' => 'Inspect Evals', 'category' => 'Raciocínio', 'blurb' => 'Evals de raciocínio Inspect AI.'],
        'hal_harness' => ['title' => 'HAL', 'category' => 'Longo horizonte', 'blurb' => 'Harness agentico de longo horizonte.'],
        'aider_polyglot' => ['title' => 'Aider Polyglot', 'category' => 'Código', 'blurb' => 'Edição multi-linguagem.'],
        'swe_marathon' => ['title' => 'SWE-Marathon', 'category' => 'Longo horizonte', 'blurb' => 'Stress Harbor multi-hora.'],
    ];

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     */
    public function render(array $report, array $runs = []): string
    {
        $board = $this->buildCapabilityBoard($report, $runs);
        $payload = [
            'objective' => 'Cada modelo aparece uma vez: capacidade sem Atlas e desempenho com Atlas no mesmo ranking. Agregado nunca é claim. Custo $0 de assinatura não discrimina — nesses casos o gráfico usa tempo.',
            'axes' => [
                ['id' => 'intelligence', 'label' => 'Inteligência', 'unit' => '%', 'higher_better' => true, 'hint' => 'Taxa de sucesso ITT'],
                ['id' => 'cost_per_task', 'label' => 'Custo / tarefa', 'unit' => '$', 'higher_better' => false, 'hint' => 'USD reportado; $0 = assinatura / não discrimina'],
                ['id' => 'tokens', 'label' => 'Tokens', 'unit' => '', 'higher_better' => false, 'hint' => 'Média tokens in+out'],
                ['id' => 'tokens_per_task', 'label' => 'Tokens / tarefa', 'unit' => '', 'higher_better' => false, 'hint' => 'Tokens observados ÷ casos planejados'],
                ['id' => 'tokens_per_second', 'label' => 'Tokens / s', 'unit' => 'tok/s', 'higher_better' => true, 'hint' => 'Média (in+out) / wall quando usage existe'],
                ['id' => 'speed', 'label' => 'Velocidade', 'unit' => 'ms', 'higher_better' => false, 'hint' => 'Mediana wall-clock (menor = mais rápido)'],
                ['id' => 'stability', 'label' => 'Estabilidade', 'unit' => '', 'higher_better' => true, 'hint' => 'Score de estabilidade por repetição'],
                ['id' => 'capacity', 'label' => 'Capacidade', 'unit' => '', 'higher_better' => true, 'hint' => 'Dimensões nativas da suite quando existem'],
                ['id' => 'atlas_uplift', 'label' => 'Δ Atlas', 'unit' => 'pp', 'higher_better' => true, 'hint' => 'Delta de inteligência só em pares bare×Atlas da mesma suite'],
            ],
            'models' => $board['models'],
            'suite_points' => $board['suite_points'],
            'suites' => $board['suites'],
            'uplift_families' => $board['uplift_families'],
            'model_matrix' => $report['model_matrix'] ?? [],
            'skills' => $report['skills'] ?? [
                'rows' => [],
                'total' => 0,
                'with_atlas' => 0,
                'by_instrument' => [],
                'atlas_arm_note' => null,
            ],
            'facts' => $report['facts'] ?? ['measured' => [], 'incomplete' => [], 'headline' => null],
            'delivery_inventory' => $report['delivery_inventory'] ?? [],
            'model_profiles' => $report['model_profiles'] ?? [],
            'model_capabilities' => $report['model_capabilities'] ?? ['capabilities' => [], 'efficiency' => []],
            'arena_capability_profile' => $report['arena_capability_profile'] ?? ['capabilities' => []],
            'model_dissections' => $report['model_dissections'] ?? [
                'epistemic_contract' => [],
                'models' => [],
                'global_unknowns' => [],
                'completeness' => [],
            ],
            'suite_dossiers' => array_map(static function (array $row): array {
                return [
                    'suite_id' => $row['suite_id'] ?? null,
                    'title' => $row['title'] ?? ($row['delivery']['title'] ?? $row['suite_id'] ?? null),
                    'category' => $row['category'] ?? ($row['delivery']['category'] ?? null),
                    'status' => $row['status'] ?? null,
                    'run_id' => $row['run_id'] ?? null,
                    'delivery' => $row['delivery'] ?? null,
                    'reliable' => $row['reliable'] ?? true,
                    'unreliable_reason' => $row['unreliable_reason'] ?? null,
                    'execution_evidence' => $row['execution_evidence'] ?? null,
                    'full_metrics' => $row['full_metrics'] ?? null,
                    'native_signals' => $row['native_signals'] ?? [],
                    'report_rows' => $row['report_rows'] ?? [],
                    'case_ids' => $row['case_ids'] ?? [],
                    'artifacts' => $row['artifacts'] ?? [],
                    'adjudication' => $row['adjudication'] ?? null,
                    'delivery_coverage' => $row['delivery_coverage'] ?? null,
                    'observed_native_metric_keys' => $row['observed_native_metric_keys'] ?? [],
                    'observed_report_metric_keys' => $row['observed_report_metric_keys'] ?? [],
                    'missing_fields' => $row['missing_fields'] ?? [],
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'intelligence_rate' => $row['intelligence_rate'] ?? null,
                    'events_complete' => $row['events_complete'] ?? false,
                    'is_atlas_fact' => $row['is_atlas_fact'] ?? false,
                    'axes' => $row['axes'] ?? null,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'tokens_in_avg' => $row['tokens_in_avg'] ?? null,
                    'tokens_out_avg' => $row['tokens_out_avg'] ?? null,
                    'tokens_per_task' => $row['tokens_per_task'] ?? null,
                    'tokens_per_second' => $row['tokens_per_second'] ?? null,
                    'total_tokens' => $row['total_tokens'] ?? null,
                    'cost_per_1k_tokens' => $row['cost_per_1k_tokens'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'cost_basis' => $row['cost_basis'] ?? null,
                    'env_failure_rate' => $row['env_failure_rate'] ?? null,
                ];
            }, (array) ($report['suite_rows'] ?? [])),
            'summary' => [
                'primary_model' => $report['executive_summary']['primary_model'] ?? null,
                'provider_binding' => $report['executive_summary']['provider_binding'] ?? 'hermes+verboo',
                'models_observed' => $report['executive_summary']['models_observed'] ?? [],
                'atlas_points' => $board['atlas_points'],
                'bare_points' => $board['bare_points'],
                'uplift_ready' => (int) ($report['executive_summary']['uplift_families_ready'] ?? 0),
                'uplift_total' => (int) ($report['executive_summary']['uplift_families_total'] ?? 0),
                'narrative' => $report['executive_summary']['narrative'] ?? null,
            ],
            'pipeline' => [
                'suites_ok' => (int) ($report['executive_summary']['suites_ok'] ?? 0),
                'suites_missing_data' => (int) ($report['executive_summary']['suites_missing_data'] ?? 0),
                'suites_failed' => (int) ($report['executive_summary']['suites_failed'] ?? 0),
                'suites_blocked' => (int) ($report['executive_summary']['suites_blocked'] ?? 0),
                'suites_not_run' => (int) ($report['executive_summary']['suites_not_run'] ?? 0),
                'gaps' => $report['gaps'] ?? [],
            ],
            'claim_allowed' => false,
            'built_at' => $report['built_at'] ?? null,
            'report_hash' => $report['report_hash'] ?? null,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if ($json === false) {
            $json = '{}';
        }

        $html = EnterpriseReportDashboardHtmlTemplate::shell();


        // Chart.js vendorizado inline: a tela definitiva abre offline/file://
        // sem depender de CDN (local-first).
        $chartJs = (string) @file_get_contents(base_path('resources/js/vendor/chart.umd.min.js'));

        return str_replace(['__JSON__', '__CHARTJS__'], [$json, $chartJs], $html);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function buildCapabilityBoard(array $report, array $runs): array
    {
        $suitePoints = $this->collectSuitePoints($report, $runs);
        $models = $this->aggregateModelsAlwaysPairAtlas($suitePoints);
        $suites = $this->suiteCards($report, $suitePoints);
        $upliftFamilies = $this->upliftFamilies($report, $suitePoints, $runs);

        return [
            'suite_points' => $suitePoints,
            'models' => $models,
            'suites' => $suites,
            'uplift_families' => $upliftFamilies,
            'bare_points' => count(array_filter($suitePoints, fn (array $p): bool => ($p['runtime'] ?? '') === 'bare')),
            'atlas_points' => count(array_filter($suitePoints, fn (array $p): bool => ($p['runtime'] ?? '') === 'atlas_dev' && ($p['present'] ?? false))),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function collectSuitePoints(array $report, array $runs): array
    {
        $preferred = [];
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            if (! empty($row['run_id']) && ! empty($row['suite_id'])) {
                $preferred[(string) $row['suite_id']] = (string) $row['run_id'];
            }
        }

        $best = [];
        foreach ($runs as $run) {
            $suiteId = (string) ($run['suite_id'] ?? '');
            $runId = (string) ($run['run_id'] ?? '');
            if ($suiteId === '' || ! isset(self::SUITES[$suiteId])) {
                continue;
            }
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if ($armId === '' || ! str_contains($armId, '@')) {
                    continue;
                }
                [$modelId, $runtime] = array_pad(explode('@', $armId, 2), 2, 'bare');
                if ($this->isNoise($modelId)) {
                    continue;
                }
                $key = $suiteId.'|'.$modelId.'|'.$runtime;
                $stability = $row['stability'] ?? null;
                if (is_array($stability)) {
                    $stability = $stability['score'] ?? $stability['value'] ?? null;
                }
                $capacity = null;
                $dimensions = (array) ($row['dimensions'] ?? []);
                if ($dimensions !== []) {
                    $vals = [];
                    foreach ($dimensions as $dim) {
                        if (is_numeric($dim)) {
                            $vals[] = (float) $dim;
                        } elseif (is_array($dim) && isset($dim['score']) && is_numeric($dim['score'])) {
                            $vals[] = (float) $dim['score'];
                        }
                    }
                    if ($vals !== []) {
                        $capacity = array_sum($vals) / count($vals);
                    }
                }
                $candidate = [
                    'suite_id' => $suiteId,
                    'suite_title' => self::SUITES[$suiteId]['title'],
                    'category' => self::SUITES[$suiteId]['category'],
                    'model_id' => $modelId,
                    'runtime' => $runtime,
                    'present' => true,
                    'intelligence' => ($row['success_rate_itt'] ?? null) === null ? null : (float) $row['success_rate_itt'],
                    'cost_per_task' => ($row['cost_per_task'] ?? null) === null ? null : (float) $row['cost_per_task'],
                    'tokens' => (($row['avg_tokens_in'] ?? null) === null && ($row['avg_tokens_out'] ?? null) === null)
                        ? null
                        : (float) ($row['avg_tokens_in'] ?? 0) + (float) ($row['avg_tokens_out'] ?? 0),
                    'tokens_per_task' => ($row['tokens_per_task'] ?? null) === null ? null : (float) $row['tokens_per_task'],
                    'tokens_per_second' => ($row['tokens_per_second'] ?? null) === null ? null : (float) $row['tokens_per_second'],
                    'speed_ms' => ($row['median_wall_ms'] ?? null) === null ? null : (float) $row['median_wall_ms'],
                    'stability' => is_numeric($stability) ? (float) $stability : null,
                    'capacity' => $capacity,
                    'run_id' => $runId,
                    'preferred' => isset($preferred[$suiteId]) && $preferred[$suiteId] === $runId,
                ];
                if (! isset($best[$key]) || ($candidate['preferred'] && ! $best[$key]['preferred'])
                    || ($candidate['preferred'] === $best[$key]['preferred']
                        && ($candidate['intelligence'] ?? -1) > ($best[$key]['intelligence'] ?? -1))) {
                    $best[$key] = $candidate;
                }
            }
        }

        // Synthesize enterprise suite rows onto primary@bare when arm rows missing.
        $primary = (string) (($report['executive_summary']['primary_model'] ?? '') ?: 'verboo_kimi_k2_7');
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            if ($suiteId === '' || ! isset(self::SUITES[$suiteId])) {
                continue;
            }
            $key = $suiteId.'|'.$primary.'|bare';
            if (isset($best[$key]) || ($row['success_rate_itt'] ?? null) === null) {
                continue;
            }
            $best[$key] = [
                'suite_id' => $suiteId,
                'suite_title' => self::SUITES[$suiteId]['title'],
                'category' => self::SUITES[$suiteId]['category'],
                'model_id' => $primary,
                'runtime' => 'bare',
                'present' => true,
                'intelligence' => (float) $row['success_rate_itt'],
                'cost_per_task' => ($row['cost_per_task'] ?? null) === null ? null : (float) $row['cost_per_task'],
                'tokens' => (($row['tokens_in_avg'] ?? null) === null && ($row['tokens_out_avg'] ?? null) === null)
                    ? null
                    : (float) ($row['tokens_in_avg'] ?? 0) + (float) ($row['tokens_out_avg'] ?? 0),
                'tokens_per_task' => ($row['tokens_per_task'] ?? null) === null ? null : (float) $row['tokens_per_task'],
                'tokens_per_second' => ($row['tokens_per_second'] ?? null) === null ? null : (float) $row['tokens_per_second'],
                'speed_ms' => ($row['median_wall_ms'] ?? null) === null ? null : (float) $row['median_wall_ms'],
                'stability' => null,
                'capacity' => null,
                'run_id' => $row['run_id'] ?? null,
                'preferred' => true,
            ];
        }

        return array_values($best);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function aggregateModelsAlwaysPairAtlas(array $points): array
    {
        $acc = [];
        foreach ($points as $p) {
            $key = $p['model_id'].'@'.$p['runtime'];
            if (! isset($acc[$key])) {
                $acc[$key] = [
                    'model_id' => $p['model_id'],
                    'runtime' => $p['runtime'],
                    'present' => (bool) ($p['present'] ?? false),
                    'intel' => [],
                    'costs' => [],
                    'tokens' => [],
                    'tok_task' => [],
                    'tok_sec' => [],
                    'speeds' => [],
                    'stabs' => [],
                    'caps' => [],
                    'suites' => [],
                ];
            }
            foreach ([
                'intel' => 'intelligence',
                'costs' => 'cost_per_task',
                'tokens' => 'tokens',
                'tok_task' => 'tokens_per_task',
                'tok_sec' => 'tokens_per_second',
                'speeds' => 'speed_ms',
                'stabs' => 'stability',
                'caps' => 'capacity',
            ] as $bucket => $field) {
                if (($p[$field] ?? null) !== null) {
                    $acc[$key][$bucket][] = (float) $p[$field];
                }
            }
            $acc[$key]['suites'][$p['suite_id']] = true;
        }

        $modelIds = [];
        foreach ($acc as $row) {
            $modelIds[$row['model_id']] = true;
        }
        // Guarantee atlas_dev placeholder for every bare model.
        foreach (array_keys($modelIds) as $modelId) {
            $atlasKey = $modelId.'@atlas_dev';
            if (! isset($acc[$atlasKey])) {
                $acc[$atlasKey] = [
                    'model_id' => $modelId,
                    'runtime' => 'atlas_dev',
                    'present' => false,
                    'intel' => [],
                    'costs' => [],
                    'tokens' => [],
                    'tok_task' => [],
                    'tok_sec' => [],
                    'speeds' => [],
                    'stabs' => [],
                    'caps' => [],
                    'suites' => [],
                ];
            }
        }

        $out = [];
        foreach ($acc as $row) {
            $avg = fn (array $vals): ?float => $vals === [] ? null : array_sum($vals) / count($vals);
            $intelligence = $avg($row['intel']);
            $out[] = [
                'model_id' => $row['model_id'],
                'runtime' => $row['runtime'],
                'present' => (bool) $row['present'],
                'intelligence' => $intelligence,
                'cost_per_task' => $avg($row['costs']),
                'tokens' => $avg($row['tokens']),
                'tokens_per_task' => $avg($row['tok_task']),
                'tokens_per_second' => $avg($row['tok_sec']),
                'speed_ms' => $avg($row['speeds']),
                'stability' => $avg($row['stabs']),
                'capacity' => $avg($row['caps']),
                'atlas_uplift' => null,
                'n_suites' => count($row['suites']),
            ];
        }

        // Uplift só em pares suite×suite (mesmo model_id). Nunca média bare-global vs atlas-global.
        foreach ($out as &$row) {
            if (($row['runtime'] ?? '') !== 'atlas_dev') {
                continue;
            }
            $deltas = [];
            foreach ($points as $barePoint) {
                if (($barePoint['model_id'] ?? null) !== $row['model_id'] || ($barePoint['runtime'] ?? '') !== 'bare') {
                    continue;
                }
                if (($barePoint['intelligence'] ?? null) === null) {
                    continue;
                }
                $suiteId = (string) ($barePoint['suite_id'] ?? '');
                foreach ($points as $atlasPoint) {
                    if (($atlasPoint['model_id'] ?? null) !== $row['model_id']) {
                        continue;
                    }
                    if (($atlasPoint['runtime'] ?? '') !== 'atlas_dev' || ! ($atlasPoint['present'] ?? false)) {
                        continue;
                    }
                    if ((string) ($atlasPoint['suite_id'] ?? '') !== $suiteId) {
                        continue;
                    }
                    if (($atlasPoint['intelligence'] ?? null) === null) {
                        continue;
                    }
                    $deltas[] = (float) $atlasPoint['intelligence'] - (float) $barePoint['intelligence'];
                }
            }
            $row['atlas_uplift'] = $deltas === [] ? null : array_sum($deltas) / count($deltas);
            $row['atlas_uplift_pairs'] = count($deltas);
        }
        unset($row);

        usort($out, function (array $a, array $b): int {
            $cmp = strcmp((string) $a['model_id'], (string) $b['model_id']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['runtime'] === 'bare' ? 0 : 1) <=> ($b['runtime'] === 'bare' ? 0 : 1);
        });

        return $out;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function suiteCards(array $report, array $points): array
    {
        $bySuite = [];
        foreach ($points as $p) {
            $bySuite[$p['suite_id']][] = $p;
        }
        $status = [];
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            $status[(string) $row['suite_id']] = $row;
        }
        $out = [];
        foreach (self::SUITES as $suiteId => $meta) {
            $rows = $bySuite[$suiteId] ?? [];
            // Ensure atlas placeholder for each model on the suite.
            $models = [];
            foreach ($rows as $r) {
                $models[$r['model_id']] = true;
            }
            foreach (array_keys($models) as $modelId) {
                $hasAtlas = false;
                foreach ($rows as $r) {
                    if ($r['model_id'] === $modelId && $r['runtime'] === 'atlas_dev') {
                        $hasAtlas = true;
                        break;
                    }
                }
                if (! $hasAtlas) {
                    $rows[] = [
                        'suite_id' => $suiteId,
                        'suite_title' => $meta['title'],
                        'model_id' => $modelId,
                        'runtime' => 'atlas_dev',
                        'present' => false,
                        'intelligence' => null,
                        'cost_per_task' => null,
                        'speed_ms' => null,
                    ];
                }
            }
            usort($rows, fn (array $a, array $b): int => ($b['intelligence'] ?? -1) <=> ($a['intelligence'] ?? -1));
            $out[] = [
                'suite_id' => $suiteId,
                'title' => $meta['title'],
                'category' => $meta['category'],
                'blurb' => $meta['blurb'],
                'status' => (string) ($status[$suiteId]['status'] ?? 'not_run'),
                'run_id' => $status[$suiteId]['run_id'] ?? null,
                'leaderboard' => array_slice($rows, 0, 8),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $points
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function upliftFamilies(array $report, array $points, array $runs): array
    {
        $primary = (string) (($report['executive_summary']['primary_model'] ?? '') ?: 'verboo_kimi_k2_7');
        $out = [];
        foreach ((array) ($report['atlas_uplift']['families'] ?? []) as $family) {
            $suiteId = (string) ($family['suite_id'] ?? '');
            $status = (string) ($family['status'] ?? 'not_run');
            $comparable = ($family['comparable'] ?? null) === true
                || ($status === 'real_uplift'
                    && ($family['bare_intelligence'] ?? null) !== null
                    && ($family['atlas_intelligence'] ?? null) !== null);

            $barePoint = null;
            $atlasPoint = null;
            foreach ($points as $p) {
                if ($p['suite_id'] !== $suiteId || $p['model_id'] !== $primary) {
                    continue;
                }
                if ($p['runtime'] === 'bare') {
                    $barePoint = $p;
                }
                if ($p['runtime'] === 'atlas_dev' && ($p['present'] ?? false)) {
                    $atlasPoint = $p;
                }
            }

            $bareIntel = ($family['bare_intelligence'] ?? null) !== null
                ? (float) $family['bare_intelligence']
                : (($barePoint['intelligence'] ?? null) !== null ? (float) $barePoint['intelligence'] : null);
            $atlasIntel = null;
            $delta = null;
            if ($comparable) {
                $atlasIntel = ($family['atlas_intelligence'] ?? null) !== null
                    ? (float) $family['atlas_intelligence']
                    : (($atlasPoint['intelligence'] ?? null) !== null ? (float) $atlasPoint['intelligence'] : null);
                $delta = ($family['delta_intelligence'] ?? null) !== null
                    ? (float) $family['delta_intelligence']
                    : (($bareIntel !== null && $atlasIntel !== null) ? round($atlasIntel - $bareIntel, 4) : null);
            }

            $out[] = [
                'family' => $family['family'] ?? null,
                'label' => $family['label'] ?? EnterpriseReportBuilder::familyLabel($family['family'] ?? null),
                'suite_id' => $suiteId,
                'status' => $status,
                'reason' => $family['reason'] ?? null,
                'comparable' => $comparable,
                'diagnostic_only' => ($family['diagnostic_only'] ?? false) === true,
                'run_id' => $family['run_id'] ?? null,
                'bare_intelligence' => $bareIntel,
                'atlas_intelligence' => $atlasIntel,
                'delta_intelligence' => $delta,
                'bare_cost' => $barePoint['cost_per_task'] ?? null,
                'atlas_cost' => $comparable ? ($atlasPoint['cost_per_task'] ?? null) : null,
            ];
        }

        return $out;
    }

    private function isNoise(string $modelId): bool
    {
        return EnterpriseReportNoiseModels::isNoise($modelId);
    }
}
