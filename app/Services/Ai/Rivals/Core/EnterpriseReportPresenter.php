<?php

namespace App\Services\Ai\Rivals\Core;

use App\Support\YesNo;

/**
 * Projeções humanas (Markdown + HTML com gráficos SVG) do enterprise report canônico.
 * JSON permanece a fonte de verdade; HTML/MD nunca alteram claim_allowed.
 */
final class EnterpriseReportPresenter
{
    /** @var array<string, array{title: string, purpose: string, what_ok: string, what_missing: string}> */
    private const SUITE_GUIDE = [
        'tau2_bench' => [
            'title' => 'τ²-Bench (tool-use dialog)',
            'purpose' => 'Mede uso de ferramentas em diálogos multi-turno (domínio airline).',
            'what_ok' => 'Pipeline válido com usage e sucesso/fracasso do agent reportados.',
            'what_missing' => 'Sem tokens ou wall-clock honestos → missing_data (nunca inventamos 0).',
        ],
        'bfcl' => [
            'title' => 'BFCL (Berkeley Function Calling)',
            'purpose' => 'Chamada de função estruturada (simple/multiple/parallel).',
            'what_ok' => 'Generate + evaluate nativos normalizados; Accuracy honesta.',
            'what_missing' => 'Score ausente ou evaluate parcial sem síntese → gap de dados.',
        ],
        'terminal_bench' => [
            'title' => 'Terminal-Bench',
            'purpose' => 'Agente em terminal real (shell) resolvendo tarefas curtas.',
            'what_ok' => 'Trial Harbor/TB com reward e métricas de tempo.',
            'what_missing' => 'Harness sem usage → tokens marcados como ausentes.',
        ],
        'senior_swe_bench' => [
            'title' => 'Senior SWE-Bench',
            'purpose' => 'Engenharia sênior em tasks Harbor longas (spec + patch + verify).',
            'what_ok' => 'Verifier concluiu; falha de modelo ≠ falha de pipeline.',
            'what_missing' => 'Env start/timeout ou tokens omitidos pelo harness.',
        ],
        'swe_bench_live' => [
            'title' => 'SWE-Bench Live',
            'purpose' => 'Reparos reais em issues públicas (live distribution).',
            'what_ok' => 'Resolved/unresolved com custo/tempo quando o harness reporta.',
            'what_missing' => 'Sem coverage de tokens → missing_data mesmo com pipeline_valid.',
        ],
        'live_code_bench' => [
            'title' => 'LiveCodeBench',
            'purpose' => 'Problemas de coding contest com avaliação automática.',
            'what_ok' => 'Pass/fail do judge nativo.',
            'what_missing' => 'Muitos runners LCB não exportam tokens — isso é honestidade, não zero.',
        ],
        'inspect_evals' => [
            'title' => 'Inspect Evals',
            'purpose' => 'Evals Inspect AI (ex.: GSM8K) via runtime Hermes+Verboo.',
            'what_ok' => 'Score e latency presentes.',
            'what_missing' => 'Usage omitido pelo Inspect → tokens missing.',
        ],
        'hal_harness' => [
            'title' => 'HAL Harness (long-horizon)',
            'purpose' => 'Horizonte longo / agentic SWE-style via HAL upload.',
            'what_ok' => 'Cardinality 1:1 unit↔result; success/fail por task.',
            'what_missing' => 'UPLOAD sem task_costs/tokens → campos marcados ausentes.',
        ],
        'aider_polyglot' => [
            'title' => 'Aider Polyglot',
            'purpose' => 'Edição multi-linguagem com Aider como solver.',
            'what_ok' => 'Pass rate e tokens quando Aider/provider reportam usage.',
            'what_missing' => 'Sem usage → missing_data.',
        ],
        'swe_marathon' => [
            'title' => 'SWE-Marathon',
            'purpose' => 'Tasks Harbor multi-hora (ex.: nextjs-vite-rewrite) — stress de ambiente.',
            'what_ok' => 'Trial completa agent+verifier sem EnvironmentStartTimeout.',
            'what_missing' => 'Timeout de Docker/env ou tokens nulos → missing_data / env_failure.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     */
    public function markdown(array $report, array $runs = []): string
    {
        $summary = (array) ($report['executive_summary'] ?? []);
        $details = $this->suiteDetails($report, $runs);
        $md = "# Rivals Fase A — Relatório de Capacidades\n\n";
        $md .= "> **Objetivo:** comparar capacidades de modelos **bare** vs **com Atlas** (`atlas_dev`) — inteligência, custo/task, tokens, velocidade, estabilidade, capacidade (dimensões) e uplift.\n";
        $md .= "> **claim_allowed = false** — agregado nunca é claim. Pipeline status ≠ capacidade.\n\n";
        $md .= "- **provider_binding:** ".($summary['provider_binding'] ?? 'hermes+verboo')."\n";
        $md .= '- **primary_model:** '.($summary['primary_model'] ?? '')."\n";
        $md .= '- **built_at:** '.($report['built_at'] ?? '')."\n";
        $md .= '- **report_hash:** `'.($report['report_hash'] ?? '')."`\n";
        $md .= "- **HTML:** `enterprise/report.html` (Relatório de Capacidades)\n\n";

        $md .= "## Eixos de confiança (4 eixos — fato vs diagnóstico)\n\n";
        $md .= "| Eixo | Significado |\n|---|---|\n";
        $md .= "| pipeline | bytes/receipts/adjudicação coerentes |\n";
        $md .= "| measurement | tokens/wall completos · parciais · `harness_omit` |\n";
        $md .= "| intelligence | `intelligence_rate` (exclui `environment_failure`); ITT inclui env |\n";
        $md .= "| claim | `internal_claim_allowed` + blockers |\n\n";
        $md .= "**Fato Atlas** (`is_atlas_fact=true`) exige os quatro eixos + `events_complete`. ";
        $md .= "Chip de pipeline `ok` **não** é score de modelo. Autopsy: `atlas:rivals autopsy --run=<id>`.\n\n";

        $md .= "## Eixos de capacidade (contrato)\n\n";
        $md .= "| Eixo | Significado |\n|---|---|\n";
        $md .= "| Intelligence | `intelligence_rate` (ex-env); ITT separado |\n";
        $md .= "| Cost / task | USD reportado por tarefa |\n";
        $md .= "| Tokens | média in+out quando presente |\n";
        $md .= "| Speed | median wall-clock (menor = mais rápido) |\n";
        $md .= "| Stability | estabilidade entre repetitions |\n";
        $md .= "| Capacity | dimensões nativas da suite (quando expõe) |\n";
        $md .= "| Atlas uplift Δ | só `real_uplift` claimável; exclusions ⇒ `diagnostic_only` |\n\n";
        $md .= "Sem Atlas nos runs ⇒ coluna atlas = **not run** (não some, não vira 0).\n\n";

        $md .= "## 1. Como ler este relatório\n\n";
        $md .= "Fase A mede o **pipeline** Hermes+Verboo nas 10 suites e a **face Atlas** nas 5 famílias de uplift. ";
        $md .= "Status `ok` = pipeline válido **e** métricas honestas — **não** é fato de inteligência sozinho. ";
        $md .= "`missing_data` = pipeline rodou, mas algum eixo crítico veio ausente — **não** inventamos zero. ";
        $md .= "`harness_omit` (Inspect) = usage permanentemente omitido pelo harness. ";
        $md .= "`failed` / `blocked` / `not_run` são falhas de execução ou ausência de run.\n\n";
        $md .= "| status | significado |\n|---|---|\n";
        $md .= "| ok | Pipeline válido + campos obrigatórios presentes |\n";
        $md .= "| missing_data | Pipeline válido, mas tokens/tempo incompletos |\n";
        $md .= "| failed | Adjudicação sem pipeline_valid |\n";
        $md .= "| blocked | Sem report utilizável |\n";
        $md .= "| not_run | Nenhum run para a suite |\n\n";

        $md .= "| suite | fato | events | intel (ex-env) | ITT | measurement | claim |\n";
        $md .= "|---|---|---|---|---|---|---|\n";
        foreach ((array) ($report['suite_rows'] ?? []) as $trustRow) {
            $axes = (array) ($trustRow['axes'] ?? []);
            $md .= '| '.$trustRow['suite_id']
                .' | '.((($trustRow['is_atlas_fact'] ?? false) === true) ? 'fato' : 'diagnóstico')
                .' | '.((($trustRow['events_complete'] ?? false) === true) ? 'ok' : 'gap')
                .' | '.$this->fmtPct($trustRow['intelligence_rate'] ?? null)
                .' | '.$this->fmtPct($trustRow['success_rate_itt'] ?? null)
                .' | '.($axes['measurement']['status'] ?? '—')
                .' | '.($axes['claim']['status'] ?? '—')
                ." |\n";
        }
        $md .= "\n";

        $md .= "## 2. Capa executiva (pipeline readiness — não é score de capacidade)\n\n";
        $md .= $this->executiveNarrative($summary)."\n\n";
        $md .= "| métrica | valor |\n|---|---|\n";
        foreach ([
            'suites_ok' => 'Suites OK',
            'suites_missing_data' => 'Suites missing_data',
            'suites_failed' => 'Suites failed',
            'suites_blocked' => 'Suites blocked',
            'suites_not_run' => 'Suites not_run',
        ] as $key => $label) {
            $md .= '| '.$label.' | '.($summary[$key] ?? 0)." |\n";
        }
        $md .= '| Uplift families ready | '.($summary['uplift_families_ready'] ?? 0)
            .'/'.($summary['uplift_families_total'] ?? 0)." |\n";
        $md .= '| Models observed | '.implode(', ', (array) ($summary['models_observed'] ?? []))." |\n\n";

        $md .= "## 3. Matriz das 10 suites\n\n";
        $md .= "| suite | category | status | success ITT | median wall | tokens in→out | cost/task | env fail | uplift | run |\n";
        $md .= "|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            $tokens = (($row['tokens_in_avg'] ?? null) === null && ($row['tokens_out_avg'] ?? null) === null)
                ? 'n/a'
                : $this->fmtNum($row['tokens_in_avg'] ?? null).' → '.$this->fmtNum($row['tokens_out_avg'] ?? null);
            $uplift = (($row['delivery']['uplift_eligible'] ?? false) === true)
                ? (string) ($row['delivery']['uplift_family'] ?? 'yes')
                : '—';
            $md .= '| '.$row['suite_id']
                .' | '.($row['category'] ?? ($row['delivery']['category'] ?? ''))
                .' | **'.$row['status'].'**'
                .' | '.$this->fmtPct($row['success_rate_itt'] ?? null)
                .' | '.$this->fmtDurationMs($row['median_wall_ms'] ?? null)
                .' | '.$tokens
                .' | '.$this->fmtMoney($row['cost_per_task'] ?? null, $row['cost_basis'] ?? null)
                .' | '.$this->fmtPct($row['env_failure_rate'] ?? null)
                .' | '.$uplift
                .' | `'.($row['run_id'] ?? '—').'`'
                ." |\n";
        }

        $md .= "\n## 4. Inventário de entrega (contrato dos 10)\n\n";
        $md .= "Lista **tudo** que cada suite deve entregar — métricas nativas, eixos Atlas, categorias, artefatos e gráficos. ";
        $md .= "Observed vs expected aparece em cada dossier.\n\n";
        foreach ((array) ($report['delivery_inventory'] ?? $report['suite_rows'] ?? []) as $inv) {
            if (isset($inv['delivery']) && is_array($inv['delivery'])) {
                $inv = $inv['delivery'];
            }
            $suiteId = (string) ($inv['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $md .= '### Contrato `'.$suiteId."`\n\n";
            $md .= '- **Título:** '.($inv['title'] ?? $suiteId).' · **Categoria:** '.($inv['category'] ?? '')."\n";
            $md .= '- **Origem:** '.($inv['origin'] ?? '—')."\n";
            $md .= '- **Propósito:** '.($inv['purpose'] ?? '')."\n";
            $md .= '- **Task types:** `'.implode('`, `', (array) ($inv['task_types'] ?? []))."`\n";
            $md .= '- **Case pack Fase A:** `'.implode('`, `', (array) ($inv['fase_a_case_pack'] ?? []))."`\n";
            $md .= '- **Artefato nativo:** `'.($inv['native_artifact'] ?? '—')."`\n";
            $md .= '- **Métricas nativas:** `'.implode('`, `', (array) ($inv['native_metrics'] ?? []))."`\n";
            $md .= '- **Métricas Atlas report:** `'.implode('`, `', (array) ($inv['atlas_report_metrics'] ?? []))."`\n";
            $dims = (array) ($inv['capability_dimensions'] ?? []);
            $md .= '- **Dimensões de capacidade:** '.($dims === [] ? '_(nenhuma)_' : '`'.implode('`, `', $dims).'`')."\n";
            $md .= '- **Categorias nativas:** '.implode('; ', (array) ($inv['native_categories'] ?? []))."\n";
            $md .= '- **Relatórios por run:** `'.implode('`, `', (array) ($inv['per_run_reports'] ?? []))."`\n";
            $md .= '- **Gráficos:** `'.implode('`, `', (array) ($inv['graphs'] ?? []))."`\n";
            $md .= '- **Uplift:** '.((($inv['uplift_eligible'] ?? false) ? ('família `'.$inv['uplift_family'].'`') : 'não elegível'))."\n";
            if (($inv['notes'] ?? []) !== []) {
                $md .= '- **Notas:** '.implode('; ', (array) $inv['notes'])."\n";
            }
            $md .= "\n";
        }

        $md .= "## 5. Leitura suite a suite (dossier completo)\n\n";
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            $suiteId = (string) $row['suite_id'];
            $guide = self::SUITE_GUIDE[$suiteId] ?? [
                'title' => $suiteId,
                'purpose' => 'Suite externa Fase A.',
                'what_ok' => 'Pipeline válido com métricas honestas.',
                'what_missing' => 'Campos ausentes viram missing_data.',
            ];
            $detail = $details[$suiteId] ?? [];
            $delivery = (array) ($row['delivery'] ?? []);
            $full = (array) ($row['full_metrics'] ?? []);
            $coverage = (array) ($row['delivery_coverage'] ?? []);
            $md .= '### '.$guide['title']."\n\n";
            $md .= '- **Status:** `'.$row['status'].'`'
                .' · pipeline_valid='.(YesNo::trueFalse($row['pipeline_valid'] ?? false))
                .' · internal_claim='.(YesNo::trueFalse($row['internal_claim_allowed'] ?? false))
                .' · events_complete='.(YesNo::trueFalse($row['events_complete'] ?? false))
                .' · is_atlas_fact='.(YesNo::trueFalse($row['is_atlas_fact'] ?? false))."\n";
            if (is_array($row['axes'] ?? null)) {
                $ax = $row['axes'];
                $md .= '- **Eixos:** pipeline='.($ax['pipeline']['status'] ?? '?')
                    .' · measurement='.($ax['measurement']['status'] ?? '?')
                    .' · intelligence='.$this->fmtPct($ax['intelligence']['rate'] ?? null)
                    .' (ITT '.$this->fmtPct($ax['intelligence']['itt'] ?? null).')'
                    .' · claim='.($ax['claim']['status'] ?? '?')."\n";
            }
            $md .= '- **Categoria:** '.($row['category'] ?? ($delivery['category'] ?? ''))."\n";
            $md .= '- **Para quê existe:** '.($delivery['purpose'] ?? $guide['purpose'])."\n";
            $md .= '- **Leitura deste run:** '.$this->suiteVerdict($row, $guide, $detail)."\n";
            $cases = (array) ($row['case_ids'] ?? ($detail['cases'] ?? []));
            if ($cases !== []) {
                $md .= '- **Cases:** `'.implode('`, `', $cases)."`\n";
            }
            if ($full !== []) {
                $md .= "- **Métricas Atlas (agregado do report do run):**\n";
                $md .= '  - n/successes: '.($full['n'] ?? 'n/a').' / '.($full['successes'] ?? 'n/a')."\n";
                $md .= '  - success_itt: '.$this->fmtPct($full['success_rate_itt'] ?? null)."\n";
                if (is_array($full['success_rate_wilson_95'] ?? null)) {
                    $w = $full['success_rate_wilson_95'];
                    $md .= '  - Wilson 95%: '
                        .sprintf('%.1f%%–%.1f%%', 100 * (float) ($w['low'] ?? 0), 100 * (float) ($w['high'] ?? 0))."\n";
                }
                $md .= '  - wall median/p95: '
                    .$this->fmtDurationMs($full['median_wall_ms'] ?? null)
                    .' / '.$this->fmtDurationMs($full['p95_wall_ms'] ?? null)."\n";
                $md .= '  - tokens in/out avg: '
                    .$this->fmtNum($full['avg_tokens_in'] ?? null)
                    .' → '.$this->fmtNum($full['avg_tokens_out'] ?? null)."\n";
                $md .= '  - tokens/task: '.$this->fmtNum($full['tokens_per_task'] ?? null)
                    .' · total_tokens: '.$this->fmtNum($full['total_tokens'] ?? null)."\n";
                $md .= '  - tokens/s: '.$this->fmtNum($full['tokens_per_second'] ?? null)
                    .' (agg '.$this->fmtNum($full['tokens_per_second_aggregate'] ?? null)
                    .' · in '.$this->fmtNum($full['tokens_in_per_second'] ?? null)
                    .' · out '.$this->fmtNum($full['tokens_out_per_second'] ?? null).")\n";
                $md .= '  - cost/task: '.$this->fmtMoney($full['cost_per_task'] ?? null, $row['cost_basis'] ?? null)
                    .' · cost/$1k tok: '.$this->fmtMoney($full['cost_per_1k_tokens'] ?? null, null)."\n";
                $md .= '  - stability: '.(($full['stability'] ?? null) === null ? 'n/a' : (string) $full['stability'])."\n";
                $md .= '  - env_failure_rate: '.$this->fmtPct($full['environment_failure_rate'] ?? null)."\n";
                if (($full['failure_classes'] ?? []) !== []) {
                    $md .= '  - failure_classes: '.$this->fmtFailureClasses((array) $full['failure_classes'])."\n";
                }
                if (is_array($full['dimensions'] ?? null) && $full['dimensions'] !== []) {
                    $parts = [];
                    foreach ($full['dimensions'] as $dim => $val) {
                        $parts[] = $dim.'='.(is_numeric($val) ? round((float) $val, 3) : $val);
                    }
                    $md .= '  - dimensions: `'.implode('`, `', $parts)."`\n";
                }
                if (is_array($full['tokens_coverage'] ?? null)) {
                    $cov = $full['tokens_coverage'];
                    $md .= '  - tokens_coverage: in='.($cov['in'] ?? 0).'/'.($cov['n'] ?? 0)
                        .' out='.($cov['out'] ?? 0).'/'.($cov['n'] ?? 0)."\n";
                }
            }
            $md .= '- **Cobertura de entrega:** native '
                .($coverage['native_observed'] ?? 0).'/'.($coverage['native_expected'] ?? 0)
                .' · report '.($coverage['report_observed'] ?? 0).'/'.($coverage['report_expected'] ?? 0)."\n";
            if (($coverage['native_missing'] ?? []) !== []) {
                $md .= '- **Native ainda não observados neste run:** `'
                    .implode('`, `', (array) $coverage['native_missing'])."`\n";
            }
            if (($row['native_signals'] ?? []) !== []) {
                $md .= "- **Sinais nativos observados:**\n\n";
                $md .= "```json\n"
                    .json_encode($row['native_signals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    ."\n```\n";
            } elseif (($row['status'] ?? '') !== 'not_run') {
                $md .= "- **Sinais nativos observados:** _(unit JSON ausente neste run)_\n";
            }
            if (($row['report_rows'] ?? []) !== []) {
                $md .= "- **Report rows (Atlas, por arm):**\n\n";
                $md .= "```json\n"
                    .json_encode($row['report_rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    ."\n```\n";
            }
            if (($row['artifacts'] ?? []) !== []) {
                $present = [];
                foreach ((array) $row['artifacts'] as $name => $meta) {
                    if (($meta['present'] ?? false) === true) {
                        $present[] = $name;
                    }
                }
                $md .= '- **Artefatos do run presentes:** '
                    .($present === [] ? '_(nenhum)_' : '`'.implode('`, `', $present).'`')."\n";
            }
            if (($row['missing_fields'] ?? []) !== []) {
                $md .= '- **Campos ausentes (honesty):** `'.implode('`, `', $row['missing_fields'])."`\n";
            }
            $md .= "\n";
        }

        $md .= "## 6. Dissecção por modelo (realidade medida)\n\n";
        $md .= "> **Contrato epistêmico:** `absolute_knowledge_claim = false`. "
            ."Completo = toda faceta Fase A medida **ou** listada como unknown com razão. "
            ."Nunca inventamos score; nunca escondemos not_run.\n\n";
        $dissect = (array) ($report['model_dissections'] ?? []);
        $completeness = (array) ($dissect['completeness'] ?? []);
        $md .= '- **mean_completeness_ratio:** '.($completeness['mean_completeness_ratio'] ?? 0)."\n";
        $md .= '- **dissections_present / total:** '
            .($completeness['dissections_present'] ?? 0).' / '.($completeness['dissections_total'] ?? 0)."\n";
        $md .= '- **absolute_measured_reality_claim:** '
            .((YesNo::trueFalse($completeness['absolute_measured_reality_claim'] ?? false)))."\n";
        $md .= '- **Facets obrigatórias:** `'
            .implode('`, `', (array) (($dissect['epistemic_contract']['required_facets'] ?? []) ?: []))."`\n\n";
        foreach ((array) ($dissect['models'] ?? []) as $model) {
            $md .= '### `'.($model['model_id'] ?? '').'@'.($model['runtime'] ?? '')."`\n\n";
            $md .= '- **present:** '.((YesNo::trueFalse($model['present'] ?? false)))
                .' · **completeness:** '.($model['completeness_ratio'] ?? 0)
                .' · **dissection_complete:** '.((YesNo::trueFalse($model['dissection_complete'] ?? false)))."\n";
            $md .= '- **reality:** '.($model['reality_statement'] ?? '')."\n";
            $sum = (array) ($model['summary'] ?? []);
            $md .= '- **summary:** intel='.$this->fmtPct($sum['intelligence_mean_itt'] ?? null)
                .' · cost='.$this->fmtMoney($sum['cost_per_task_mean'] ?? null, null)
                .' · tokens='.$this->fmtNum($sum['tokens_mean'] ?? null)
                .' · tok/task='.$this->fmtNum($sum['tokens_per_task_mean'] ?? null)
                .' · tok/s='.$this->fmtNum($sum['tokens_per_second_mean'] ?? null)
                .' · wall='.$this->fmtDurationMs($sum['median_wall_ms_mean'] ?? null)
                .' · stability='.(($sum['stability_mean'] ?? null) === null ? 'n/a' : (string) $sum['stability_mean'])."\n";
            if (($sum['capacity_dimensions'] ?? null) !== null) {
                $md .= '- **capacity_dimensions:** `'.json_encode($sum['capacity_dimensions'], JSON_UNESCAPED_SLASHES)."`\n";
            }
            if (($model['unknowns'] ?? []) !== []) {
                $md .= "- **unknowns (explicit):**\n";
                foreach ((array) $model['unknowns'] as $unk) {
                    $md .= '  - `'.($unk['facet'] ?? '').'`: '.($unk['reason'] ?? '')."\n";
                }
            }
            $md .= "- **per_suite (10):**\n\n";
            $md .= "```json\n"
                .json_encode($model['per_suite'] ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ."\n```\n\n";
        }
        if (($dissect['global_unknowns'] ?? []) !== []) {
            $md .= "### Unknowns globais\n\n";
            foreach ((array) $dissect['global_unknowns'] as $unk) {
                $md .= '- `'.json_encode($unk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."`\n";
            }
            $md .= "\n";
        }

        $md .= "## 6b. Perfil por modelo — onde é bom, onde é fraco, o que o Atlas muda\n\n";
        $profiles = (array) ($report['model_profiles'] ?? []);
        if ($profiles === []) {
            $md .= "_Sem perfil: nenhum modelo com braço bare medido._\n\n";
        }
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $md .= '### `'.(string) ($profile['model_id'] ?? '')."`\n\n";
            $md .= '> '.(string) ($profile['narrative'] ?? '')."\n\n";
            $md .= "| Faixa | Suítes (success ITT, braço bare) |\n|---|---|\n";
            $fmtCells = static fn (array $cells): string => $cells === [] ? '—' : implode(' · ', array_map(
                static fn (array $c): string => '`'.$c['suite_id'].'` '.round(((float) $c['success_rate_itt']) * 100).'%',
                array_filter($cells, 'is_array'),
            ));
            $md .= '| Forte (≥50%) | '.$fmtCells((array) ($profile['strengths'] ?? []))." |\n";
            $md .= '| Mediano | '.$fmtCells((array) ($profile['middle'] ?? []))." |\n";
            $md .= '| Fraco (≤20%) | '.$fmtCells((array) ($profile['weaknesses'] ?? []))." |\n";
            $unrel = array_filter((array) ($profile['unreliable'] ?? []), 'is_array');
            $md .= '| ⚠ Não confiável (execução) | '.($unrel === [] ? '—' : implode(' · ', array_map(
                static fn (array $c): string => '`'.$c['suite_id'].'` '.(string) (($c['unreliable_reason'] ?? '')),
                $unrel,
            )))." |\n\n";
            if ($unrel !== []) {
                $md .= "> ⚠ Suítes acima **não julgam o modelo**: execução incompleta ou falha de ambiente/fluxo engoliu o run (cobertura < 70%). Veja `execution_evidence` no report.json para os logs por unidade.\n\n";
            }
            $deltas = array_filter((array) ($profile['atlas_deltas'] ?? []), 'is_array');
            if ($deltas !== []) {
                $md .= "| Família (uplift) | Suíte | bare | atlas | Δ | Status |\n|---|---|---|---|---|---|\n";
                foreach ($deltas as $delta) {
                    $pct = static fn (mixed $v): string => is_numeric($v) ? round(((float) $v) * 100).'%' : 'n/d';
                    $dpp = is_numeric($delta['delta_intelligence'] ?? null)
                        ? (($delta['delta_intelligence'] >= 0 ? '+' : '').round(((float) $delta['delta_intelligence']) * 100).'pp')
                        : 'n/d';
                    $md .= '| '.(string) ($delta['family'] ?? '').' | `'.(string) ($delta['suite_id'] ?? '').'` | '
                        .$pct($delta['bare_intelligence'] ?? null).' | '.$pct($delta['atlas_intelligence'] ?? null).' | '
                        .$dpp.' | '.(string) ($delta['status'] ?? '')
                        .((($delta['diagnostic_only'] ?? false) === true) ? ' (diagnóstico)' : '')." |\n";
                }
                $md .= "\n";
            }
        }

        $md .= "## 7. Face modelo × modelo / modelo com e sem Atlas\n\n";
        $matrix = (array) ($report['model_matrix'] ?? []);
        if (($matrix['mode'] ?? '') === 'single_model_battery') {
            $md .= 'Modo **single_model_battery** — face **com e sem Atlas** para `'
                .($matrix['model_id'] ?? '')."`.\n\n";
            foreach ((array) ($matrix['rows'] ?? []) as $mrow) {
                $md .= '| modelo | sem Atlas | com Atlas (pares) | Δ | cobertura |\n|---|---|---|---|---|\n';
                $md .= '| `'.($mrow['model_id'] ?? '').'`'
                    .' | '.$this->fmtPct($mrow['bare_intelligence'] ?? null)
                    .' | '.$this->fmtPct($mrow['atlas_intelligence'] ?? null)
                    .' | '.$this->fmtPct($mrow['delta_intelligence'] ?? null)
                    .' | '.($mrow['pair_coverage'] ?? '—')
                    ." |\n\n";
                if (($mrow['per_suite'] ?? []) !== []) {
                    $md .= "### Suite a suite\n\n";
                    $md .= "| suite | bare | atlas | Δ | comparável | status uplift |\n|---|---|---|---|---|---|\n";
                    foreach ((array) $mrow['per_suite'] as $ps) {
                        $md .= '| '.($ps['suite_id'] ?? '')
                            .' | '.$this->fmtPct($ps['bare_intelligence'] ?? null)
                            .' | '.(($ps['comparable'] ?? false) ? $this->fmtPct($ps['atlas_intelligence'] ?? null) : '—')
                            .' | '.(($ps['comparable'] ?? false) ? $this->fmtPct($ps['delta_intelligence'] ?? null) : '—')
                            .' | '.((($ps['comparable'] ?? false) ? 'sim' : 'não'))
                            .' | '.($ps['uplift_status'] ?? '—')
                            ." |\n";
                    }
                    $md .= "\n";
                }
            }
            if (($matrix['rows'] ?? []) === []) {
                $md .= "_Ranking ainda sem linhas — rode report-enterprise após baterias._\n\n";
            }
        } else {
            $md .= 'Modo **model_vs_model** — models: '
                .implode(', ', (array) ($matrix['model_ids'] ?? []))."\n\n";
            if (($matrix['rows'] ?? []) !== []) {
                $md .= "| suite | run | status | models |\n|---|---|---|---|\n";
                foreach ((array) $matrix['rows'] as $mrow) {
                    $md .= '| '.($mrow['suite_id'] ?? '')
                        .' | `'.($mrow['run_id'] ?? '').'`'
                        .' | '.($mrow['status'] ?? '')
                        .' | '.implode(', ', (array) ($mrow['models'] ?? []))
                        ." |\n";
                }
                $md .= "\n";
            }
        }

        if (($report['facts'] ?? null) !== null) {
            $facts = (array) $report['facts'];
            $md .= "## 7b. Fatos medidos\n\n";
            $md .= '**Headline:** '.($facts['headline'] ?? '—')."\n\n";
            $md .= "### Confirmado\n\n";
            if (($facts['measured'] ?? []) === []) {
                $md .= "- (nenhum par válido ainda)\n";
            } else {
                foreach ((array) $facts['measured'] as $line) {
                    $md .= '- '.$line."\n";
                }
            }
            $md .= "\n### Ainda incompleto\n\n";
            if (($facts['incomplete'] ?? []) === []) {
                $md .= "- (nenhum)\n";
            } else {
                foreach (array_slice((array) $facts['incomplete'], 0, 20) as $line) {
                    $md .= '- '.$line."\n";
                }
            }
            $md .= "\n";
        }

        $md .= "## 8. Face Atlas × modelo (uplift)\n\n";
        $md .= "Uplift compara `bare` vs `atlas_dev` nas 5 famílias. "
            ."Só `real_uplift` entra como fato comparável — `unsupported`/`not_run` nunca viram 0%.\n\n";
        $md .= "| family | suite | status | bare | atlas | Δ | run |\n|---|---|---|---|---|---|---|\n";
        foreach ((array) ($report['atlas_uplift']['families'] ?? []) as $family) {
            $md .= '| '.($family['family'] ?? '')
                .' | '.($family['suite_id'] ?? '')
                .' | '.($family['status'] ?? '')
                .' | '.$this->fmtPct($family['bare_intelligence'] ?? null)
                .' | '.((($family['comparable'] ?? false) ? $this->fmtPct($family['atlas_intelligence'] ?? null) : '—'))
                .' | '.((($family['comparable'] ?? false) ? $this->fmtPct($family['delta_intelligence'] ?? null) : '—'))
                .' | `'.($family['run_id'] ?? '—').'`'
                ." |\n";
        }

        $md .= "\n## 9. Gaps (honestidade)\n\n";
        $md .= "Gaps são códigos máquina para o que falta — nunca silêncio.\n\n";
        if (($report['gaps'] ?? []) === []) {
            $md .= "- (none)\n";
        } else {
            foreach ($report['gaps'] as $gap) {
                $md .= '- `'.$gap.'` — '.$this->explainGap((string) $gap)."\n";
            }
        }

        $md .= "\n## 10. Runs incluídos / excluídos\n\n";
        $md .= '**Incluídos:** '.(($report['included_run_ids'] ?? []) === []
            ? '(nenhum)'
            : implode(', ', array_map(fn ($id) => '`'.$id.'`', $report['included_run_ids'])))."\n\n";
        if (($report['excluded_run_ids'] ?? []) === []) {
            $md .= "**Excluídos:** (nenhum)\n";
        } else {
            $md .= "**Excluídos:**\n";
            foreach ($report['excluded_run_ids'] as $ex) {
                $md .= '- `'.($ex['run_id'] ?? '').'` ('.($ex['suite_id'] ?? '').'): '.($ex['reason'] ?? '')."\n";
            }
        }

        $md .= "\n## 11. Aviso de claim\n\n";
        $md .= 'Blockers: `'.implode('`, `', (array) ($report['claim_blockers'] ?? []))."`.\n";
        $md .= "Não use médias das 10 suites como prova pública. Abra o HTML (Visão geral / Dissecção / Dossiês) para leitura completa.\n";

        return $md."\n";
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     */
    public function html(array $report, array $runs = []): string
    {
        return (new EnterpriseReportDashboardHtml)->render($report, $runs);
    }

    /** @param array<string, mixed> $summary */
    private function executiveNarrative(array $summary): string
    {
        $ok = (int) ($summary['suites_ok'] ?? 0);
        $miss = (int) ($summary['suites_missing_data'] ?? 0);
        $fail = (int) ($summary['suites_failed'] ?? 0);
        $blocked = (int) ($summary['suites_blocked'] ?? 0);
        $notRun = (int) ($summary['suites_not_run'] ?? 0);
        $upReady = (int) ($summary['uplift_families_ready'] ?? 0);
        $upTotal = (int) ($summary['uplift_families_total'] ?? 0);
        $model = (string) ($summary['primary_model'] ?? '—');

        $parts = [
            "Bateria consolidada no modelo {$model} via Hermes+Verboo.",
            "{$ok}/10 suites com status ok; {$miss} com missing_data (pipeline ok, métricas incompletas); "
            ."{$fail} failed; {$blocked} blocked; {$notRun} not_run.",
            "Uplift Atlas×modelo: {$upReady}/{$upTotal} famílias com real_uplift.",
        ];
        if ($miss > 0) {
            $parts[] = 'Missing_data costuma ser harness sem usage (tokens) ou env_failure — não preencha com zero.';
        }
        if ($upReady === 0) {
            $parts[] = 'Face de uplift ainda não rodou (precisa dual-arm bare+atlas_dev).';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, array<string, mixed>>
     */
    private function suiteDetails(array $report, array $runs): array
    {
        $bySuiteRun = [];
        foreach ($runs as $run) {
            $suiteId = (string) ($run['suite_id'] ?? '');
            $runId = (string) ($run['run_id'] ?? '');
            if ($suiteId === '' || $runId === '') {
                continue;
            }
            $bySuiteRun[$suiteId][$runId] = $run;
        }

        $out = [];
        foreach ((array) ($report['suite_rows'] ?? []) as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            $runId = (string) ($row['run_id'] ?? '');
            $run = $bySuiteRun[$suiteId][$runId] ?? null;
            $reportRows = is_array($run) ? (array) (($run['report']['rows'] ?? []) ?: []) : [];
            $first = is_array($reportRows[0] ?? null) ? $reportRows[0] : [];
            $cases = (array) (($run['claim_scope']['cases'] ?? ($run['report']['claim_scope']['cases'] ?? [])) ?: []);
            $wilson = $first['success_rate_wilson_95'] ?? null;
            $wilsonStr = null;
            if (is_array($wilson) && isset($wilson['low'], $wilson['high'])) {
                $wilsonStr = sprintf('%.1f%%–%.1f%%', 100 * (float) $wilson['low'], 100 * (float) $wilson['high']);
            }
            $out[$suiteId] = [
                'cases' => array_values(array_map('strval', $cases)),
                'wilson' => $wilsonStr,
                'failure_classes' => (array) ($first['failure_classes'] ?? []),
                'n' => $first['n'] ?? null,
                'successes' => $first['successes'] ?? null,
                'task_type' => $first['task_type'] ?? null,
                'p95_wall_ms' => $first['p95_wall_ms'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{title: string, purpose: string, what_ok: string, what_missing: string}  $guide
     * @param  array<string, mixed>  $detail
     */
    private function suiteVerdict(array $row, array $guide, array $detail): string
    {
        return match ((string) ($row['status'] ?? '')) {
            'ok' => $guide['what_ok']
                .' Success ITT '.$this->fmtPct($row['success_rate_itt'] ?? null)
                .' em '.$this->fmtDurationMs($row['median_wall_ms'] ?? null)
                .(((float) ($row['env_failure_rate'] ?? 0)) > 0
                    ? ' (atenção: env_failure_rate '.$this->fmtPct($row['env_failure_rate']).').'
                    : '.'),
            'missing_data' => $guide['what_missing']
                .' Campos: '.implode(', ', (array) ($row['missing_fields'] ?? []))
                .(((float) ($row['env_failure_rate'] ?? 0)) >= 1.0
                    ? ' Env failure rate 100% — provável timeout/start de ambiente, não só tokens.'
                    : '.')
                .(($detail['cases'] ?? []) !== [] ? ' Case: '.implode(', ', $detail['cases']).'.' : ''),
            'failed' => 'Pipeline não validou. Não use este run para claim interno.',
            'blocked' => 'Run presente mas sem report utilizável (bloqueado).',
            'not_run' => 'Ainda não há run para esta suite neste storage.',
            default => 'Status desconhecido.',
        };
    }

    private function explainGap(string $gap): string
    {
        if (str_starts_with($gap, 'not_run:')) {
            return 'Suite ainda sem run no storage.';
        }
        if (str_starts_with($gap, 'uplift_not_run:')) {
            return 'Família de uplift sem par bare×atlas_dev.';
        }
        if (str_contains($gap, ':tokens_')) {
            return 'Harness não reportou usage de tokens (não inventar 0).';
        }
        if (str_contains($gap, ':wall_ms')) {
            return 'Wall-clock ausente no report do run.';
        }
        if (str_starts_with($gap, 'missing_data:')) {
            return 'Pipeline válido com eixo de dados incompleto.';
        }

        return 'Gap declarado pelo consolidado.';
    }

    private function fmtFailureClasses(array $classes): string
    {
        $parts = [];
        foreach ($classes as $name => $count) {
            if (is_int($name) && is_array($count)) {
                $parts[] = json_encode($count);
            } else {
                $parts[] = $name.'='.$count;
            }
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    private function fmtPct(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'n/a';
        }

        return round(100 * (float) $value, 1).'%';
    }

    private function fmtNum(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'n/a';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function fmtDurationMs(mixed $ms): string
    {
        if ($ms === null || $ms === '') {
            return 'n/a';
        }
        $seconds = ((float) $ms) / 1000;
        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }
        if ($seconds < 3600) {
            return round($seconds / 60, 1).'min';
        }

        return round($seconds / 3600, 2).'h';
    }

    private function fmtMoney(mixed $cost, mixed $basis): string
    {
        if ($cost === null || $cost === '') {
            return 'n/a';
        }
        $base = '$'.rtrim(rtrim(number_format((float) $cost, 4, '.', ''), '0'), '.');
        if ($basis) {
            $base .= ' ('.$basis.')';
        }

        return $base;
    }
}
