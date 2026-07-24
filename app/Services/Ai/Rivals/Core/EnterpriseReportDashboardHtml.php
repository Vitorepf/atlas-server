<?php

namespace App\Services\Ai\Rivals\Core;

use App\Support\YesNo;

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

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Atlas Rivals — Relatório de Capacidades</title>
<script>__CHARTJS__</script>
<style>
:root{--bg:#05060a;--card:#0e1016;--line:#242836;--ink:#f3f5f8;--muted:#8a93a3;--bare:#6cb6ff;--atlas:#3dd68c;--warn:#f0b429;--bad:#ff6b6b;--chip:#171a22;
/* COR = VEREDITO. NUNCA IDENTIDADE.
   --atlas (verde) carregava dois sentidos opostos no mesmo componente: o BRAÇO
   ("com Atlas") e o RESULTADO ("melhorou"). Quando o Atlas piora, a tela
   renderizava o número dele em VERDE colado num delta VERMELHO — o mesmo fato,
   duas cores contraditórias, a 8px de distância. E implicava, de graça, que
   Atlas=bom por definição.
   O braço já é identificado por POSIÇÃO em toda a tela (esquerda = sem Atlas,
   direita = com Atlas). Isso basta. A cor fica exclusiva do veredito.
   Os tons são escolhidos por separação de LUMINÂNCIA, não de matiz: sob
   deuteranopia o par antigo (#3dd68c / #ff6b6b) tinha 1,23:1 entre si — os dois
   viravam o mesmo caqui, e TODO veredito do relatório era a mesma cor para um
   daltônico. Ainda assim a cor nunca é o único canal: a régua dá posição e o
   sinal +/- dá direção. */
--melhor:#6ee7b7;--pior:#e5484d;--sem-sinal:#8a93a3}
/* A RÉGUA DO ZERO — a incerteza vira a FORMA do elemento, não nota de rodapé.
   Faixa larga = pouca evidência, automático. Faixa tocando a linha do zero =
   não dá para afirmar, óbvio sem legenda. Sem isto a tela imprimia "+33 pp" em
   verde-negrito sobre um intervalo [-3,+65] — afirmando ganho onde não há
   sinal. Com 9 tarefas por braço só se afirma acima de 44 pp. */
.regua{position:relative;height:14px;min-width:120px;background:linear-gradient(var(--line),var(--line)) 50%/1px 100% no-repeat}
.faixa{position:absolute;top:4px;height:6px;border-radius:3px;background:var(--sem-sinal);opacity:.55}
.faixa.melhor{background:var(--melhor);opacity:1}
.faixa.pior{background:var(--pior);opacity:1}
.obs{position:absolute;top:1px;width:2px;height:12px;background:var(--ink)}
.dgroup td{padding-top:18px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700}
.sk-nome{font-weight:600}
.sk-fatia{display:block;font-size:11px;color:var(--muted);margin-top:2px}
.sk-ci{font-size:11px;color:var(--muted);font-variant-numeric:tabular-nums;display:block}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 ui-sans-serif,system-ui,-apple-system,sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:0 24px 72px}
.nav{display:flex;justify-content:space-between;align-items:center;padding:18px 0;border-bottom:1px solid var(--line)}
.logo{font-weight:750;letter-spacing:-.03em}.pill{font-size:12px;font-weight:700;padding:6px 12px;border-radius:999px;border:1px solid rgba(255,107,107,.35);background:rgba(255,107,107,.1);color:#ffb4b4}
h1{font-size:36px;letter-spacing:-.045em;margin:24px 0 8px} .lede{color:var(--muted);max-width:72ch;margin:0 0 10px}
.meta{color:var(--muted);font-size:12px;margin-bottom:14px} code{color:#d7dde8}
.verdict{border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin:0 0 16px;background:linear-gradient(180deg,#12151d,#0e1016)}
.verdict .title{display:block;font-size:16px;font-weight:750;letter-spacing:-.02em;margin-bottom:12px}
.verdict-stats{display:flex;flex-wrap:wrap;gap:10px 28px;align-items:baseline}
.verdict-stats .stat{min-width:120px}
.verdict-stats .v{font-size:22px;font-weight:750;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.verdict-stats .l{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;margin-top:2px}
.verdict .note{margin:12px 0 0;color:var(--muted);font-size:12px;line-height:1.6}
.uplift-strip{margin-top:14px;border-top:1px solid var(--line);padding-top:10px}
.uplift-strip .row{display:grid;grid-template-columns:minmax(180px,1.2fr) 70px 26px 70px 90px;gap:8px;align-items:center;padding:5px 0;font-size:13px;font-variant-numeric:tabular-nums}
.uplift-strip .row .name{color:var(--ink)}
.uplift-strip .row .arrow{color:var(--muted);text-align:center}
.uplift-strip .row .b{color:var(--bare);text-align:right}.uplift-strip .row .a{color:var(--atlas);text-align:right}
.uplift-strip .row .d{font-weight:750;text-align:right}
@media(max-width:640px){.uplift-strip .row{grid-template-columns:1fr 60px 20px 60px 70px}}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px}
.tab{border:1px solid var(--line);background:var(--card);color:var(--muted);border-radius:999px;padding:8px 14px;cursor:pointer;font-weight:650;font-size:13px}
.tab.on{color:var(--ink);background:#1a2030;border-color:#36415a}
.grid{display:grid;gap:12px}.g4{grid-template-columns:repeat(3,minmax(0,1fr))}.g2{grid-template-columns:1.15fr .85fr}
@media(max-width:980px){.g4,.g2{grid-template-columns:1fr 1fr}}@media(max-width:640px){.g4,.g2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px}
.card h2{margin:0 0 4px;font-size:18px;letter-spacing:-.02em}.hint{color:var(--muted);font-size:12px;margin:0 0 12px}
.kpi{font-size:28px;font-weight:750;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
.kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:750;color:var(--muted)}
.chart{position:relative;height:360px}.chart.sm{height:300px}
table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left;font-variant-numeric:tabular-nums;vertical-align:middle}
th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
.chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;background:var(--chip)}
.chip.bare{color:var(--bare)}.chip.atlas{color:var(--atlas)}.chip.miss{color:var(--warn)}.chip.na{color:var(--muted)}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px}
.panel{display:none}.panel.on{display:block}
.suite-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
@media(max-width:1000px){.suite-grid{grid-template-columns:1fr 1fr}}@media(max-width:700px){.suite-grid{grid-template-columns:1fr}}
.cat{color:var(--bare);font-size:11px;font-weight:750;letter-spacing:.07em;text-transform:uppercase;margin-bottom:4px}
.empty{border:1px dashed #333948;border-radius:12px;padding:14px;color:var(--muted);font-size:13px;background:rgba(255,255,255,.02)}
.pos{color:var(--atlas)}.neg{color:var(--bad)}
footer{margin-top:28px;padding-top:14px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
.axis-note{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px;margin-top:8px}
.axis-note div{font-size:12px;color:var(--muted)} .axis-note strong{color:var(--ink)}
.dossier{margin-bottom:12px}.dossier pre{background:#090b10;border:1px solid var(--line);border-radius:10px;padding:12px;overflow:auto;max-height:280px;font-size:11px;color:#c9d0dc}
.kv{display:grid;grid-template-columns:180px 1fr;gap:6px 12px;font-size:13px;margin:10px 0}
.kv div:nth-child(odd){color:var(--muted)} .miss-list{color:var(--warn);font-size:12px}
.eff-row{display:flex;flex-wrap:wrap;gap:10px 32px;align-items:baseline;margin-top:6px}
.eff-row .e{min-width:150px}.eff-row .e .v{font-size:24px;font-weight:750;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.eff-row .e .l{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;margin-top:2px}
.cap-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media(max-width:820px){.cap-grid{grid-template-columns:1fr}}
.cap{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px}
.cap h3{margin:0 0 2px;font-size:17px;letter-spacing:-.02em}
.cap .measures{color:var(--muted);font-size:12px;margin:0 0 14px;line-height:1.5}
.cap .scores{display:flex;align-items:flex-end;gap:20px;margin-bottom:12px}
.cap .score .n{font-size:40px;font-weight:800;letter-spacing:-.04em;line-height:1;font-variant-numeric:tabular-nums}
.cap .score.bare .n{color:var(--bare)}.cap .score.atlas .n{color:var(--atlas)}
.cap .score .lab{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700;margin-top:4px}
.cap .arrow{font-size:22px;color:var(--muted);padding-bottom:14px}
.cap .delta{font-size:15px;font-weight:750;padding-bottom:18px}
.cap .bars{height:6px;border-radius:999px;background:#1a1f2b;overflow:hidden;margin:2px 0 10px}
.cap .bars .fill{height:100%;border-radius:999px}
.cap .score.bare .n{font-size:40px;font-weight:800;letter-spacing:-.04em;color:var(--bare);font-variant-numeric:tabular-nums}
.cap .atlas-line{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin:10px 0 12px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:rgba(61,214,140,.05)}
.cap .atlas-line .tag{font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.05em;color:var(--atlas)}
.cap .atlas-line .pair b.bare{color:var(--bare)}.cap .atlas-line .pair b.atlas{color:var(--atlas)}
.cap .atlas-line .pair{font-variant-numeric:tabular-nums;font-size:15px}
.cap .atlas-line .delta{font-weight:750;font-variant-numeric:tabular-nums}
.cap .foot{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12px;color:var(--muted)}
.cap .foot b{color:var(--ink);font-weight:650}
.cap .subcaps{margin:4px 0 12px;border-top:1px solid var(--line);padding-top:10px}
.cap .subcaps-h{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:750;margin-bottom:8px}
.cap .subcap{margin-bottom:9px}
.cap .subcap:last-child{margin-bottom:2px}
.cap .subhead{display:flex;justify-content:space-between;align-items:baseline;gap:10px;font-size:12.5px}
.cap .subname{color:var(--ink);font-weight:600;line-height:1.35}
.cap .subval{font-weight:750;font-variant-numeric:tabular-nums;color:var(--bare)}
.cap .subci{color:var(--muted);font-size:11px;font-variant-numeric:tabular-nums}
.cap .subn{color:var(--muted);font-size:10.5px;white-space:nowrap}
.cap .subgm{color:var(--warn);font-size:10.5px;white-space:nowrap;font-weight:650;cursor:help}
.cap .subna{color:var(--muted);font-size:11.5px;font-style:italic}
.cap .subwhy{color:var(--muted);font-size:11px;line-height:1.45;margin-top:4px;padding-left:8px;border-left:2px solid var(--line)}
.cap .subbar{height:4px;border-radius:999px;background:#1a1f2b;overflow:hidden;margin-top:4px}
.cap .subfill{height:100%;border-radius:999px;background:var(--bare);opacity:.75}
.cap .subfill.na{background:repeating-linear-gradient(90deg,#2a3142,#2a3142 4px,transparent 4px,transparent 8px);width:100%!important;opacity:.5}
/* Eixo de RISCO: vermelho e nunca verde — a cor tem de dizer o sinal. */
#riskPanel{border-left:3px solid var(--bad)}
.riskbox{display:flex;flex-direction:column;gap:1px;background:var(--line);border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-top:12px}
.riskrow{background:var(--card);padding:11px 14px}
.riskhead{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.risklabel{font-size:13.5px;font-weight:650;color:var(--ink)}
.riskdir{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--bad);
 border:1px solid var(--bad);border-radius:2px;padding:1px 5px;cursor:help}
.riskv{margin-left:auto;font-size:20px;font-weight:800;color:var(--bad);font-variant-numeric:tabular-nums}
.riskn{color:var(--muted);font-size:10.5px}
.riskmeas{margin:5px 0 0;font-size:12px;color:var(--muted);line-height:1.5;max-width:78ch}
.covstats{display:flex;flex-wrap:wrap;gap:8px 22px;margin:10px 0 14px;font-size:12.5px;color:var(--muted)}
.covstats b{color:var(--ink);font-size:15px;font-variant-numeric:tabular-nums}
.covmap{display:grid;gap:1px;background:var(--line);border:1px solid var(--line);border-radius:10px;overflow:hidden}
.covrow{display:grid;grid-template-columns:24px minmax(0,1.1fr) minmax(0,1fr);gap:10px;align-items:baseline;background:var(--card);padding:8px 12px;font-size:12.5px}
.covrow .covmark{font-weight:800;text-align:center}
.covrow.yes .covmark{color:var(--atlas)}
.covrow.no .covmark{color:var(--muted)}
.covrow .covdom{color:var(--ink);line-height:1.4}
.covdorm{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.04em;color:var(--warn);
 border:1px solid var(--warn);border-radius:2px;padding:1px 4px;margin-left:4px;white-space:nowrap}
.covrow.no .covdom{color:var(--muted)}
.covrow .covnote{color:var(--muted);font-size:11.5px;line-height:1.4}
@media(max-width:820px){.covrow{grid-template-columns:20px 1fr}.covrow .covnote{grid-column:2}}
.cap .warn{color:var(--warn);font-size:12px;margin-top:8px}
.glossary .gloss-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 24px;margin-top:8px}
@media(max-width:820px){.glossary .gloss-grid{grid-template-columns:1fr}}
.glossary .gloss-grid > div{font-size:13px}
.glossary .gloss-grid b{color:var(--ink);display:block;margin-bottom:2px}
.glossary .gloss-grid span{color:var(--muted);line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <div class="nav"><div class="logo">Atlas · Rivals</div><div style="display:flex;gap:8px;align-items:center"><span class="pill" id="liveBadge" style="display:none"></span><div class="pill">claim_allowed = false · não é prova pública</div></div></div>
  <h1>Relatório de Capacidades</h1>
  <p class="lede" id="objective"></p>
  <div class="meta" id="meta"></div>
  <div class="verdict" id="verdict"></div>
  <div class="tabs">
    <button class="tab on" data-tab="caps">Capacidades</button>
    <button class="tab" data-tab="skills">Habilidades (lista completa)</button>
    <button class="tab" data-tab="models">Ranking · com e sem Atlas</button>
    <button class="tab" data-tab="overview">Gráficos</button>
    <button class="tab" data-tab="dissect">Dissecção do modelo</button>
    <button class="tab" data-tab="suites">Benchmarks (drill-down)</button>
    <button class="tab" data-tab="dossiers">Dossiês das suites</button>
    <button class="tab" data-tab="uplift">Uplift Atlas</button>
  </div>

  <section id="tab-caps" class="panel on">
    <div class="card" style="margin-bottom:12px" id="arenaVerdictCard">
      <h2>Com Atlas vs sem Atlas — veredito por capacidade</h2>
      <p class="hint">A MESMA fonte que o app nativo (pool de rodadas, IC 95% de Wilson, delta de Newcombe, guarda de seleção e purga de dado sem prova). <strong>Confirmado</strong> só quando o intervalo de confiança do delta não cruza zero; caso contrário é "dentro do ruído", "poucos casos" ou "não medível" — nunca número inventado.</p>
      <div id="arenaVerdict" style="overflow-x:auto"></div>
    </div>
    <div class="card" style="margin-bottom:12px">
      <h2>O que o modelo sabe fazer — não em quais testes</h2>
      <p class="hint">Os 10 benchmarks são o instrumento; o que importa é a <strong>capacidade</strong> que eles medem. Cada domínio abaixo abre nas <strong>habilidades</strong> que o compõem — a média do domínio esconde que o modelo pode ir bem numa e zerar noutra. Só suítes <strong>confiáveis</strong> entram na média, ponderadas por tarefa. <strong>Escopo:</strong> esta bateria mede engenharia de software e uso agêntico de ferramentas — não é retrato da capacidade geral de uma IA (ver <em>Até onde este benchmark enxerga</em>).</p>
      <div class="eff-row" id="capEfficiency"></div>
    </div>
    <div class="cap-grid" id="capCards"></div>
    <div class="card" id="riskPanel" style="margin-top:12px"></div>
    <div class="card" id="coveragePanel" style="margin-top:12px"></div>
    <div class="grid g2" style="margin-top:12px">
      <div class="card"><h2>Mapa de capacidades — sem Atlas × com Atlas</h2><p class="hint">Cada eixo é um domínio <strong>medido</strong>. Polígono verde além do azul = Atlas amplia. <span id="radarOmitted"></span></p><div class="chart sm"><canvas id="capRadar"></canvas></div></div>
      <div class="card"><h2>Eficiência por capacidade</h2><p class="hint">Tokens por tarefa (menor = mais eficiente). Custo $0 de assinatura não discrimina — a eficiência real está aqui. <span id="tokensOmitted"></span></p><div class="chart sm"><canvas id="capTokens"></canvas></div></div>
    </div>
  </section>

  <section id="tab-skills" class="panel">
    <div class="card" style="margin-bottom:12px">
      <h2>Toda habilidade medida, uma por linha</h2>
      <p class="hint">Cada linha é uma habilidade que um instrumento mede de verdade, agrupada pelo domínio a que pertence e ordenada da maior ajuda para a maior piora. A barra mostra <strong>a faixa provável da diferença</strong>: quando ela toca a linha do zero, os dois resultados seguem possíveis e a linha fica <strong>cinza</strong> — não há sinal, e a tela não escolhe um lado que a amostra não sustenta. <strong>Verde</strong> e <strong>vermelho</strong> só aparecem quando a faixa inteira fica de um lado do zero. Barra larga é pouca evidência. Cinza nunca é elogio nem condenação: é lacuna declarada.</p>
      <div class="covstats" id="skillStats"></div>
      <div id="skillArmNote"></div>
    </div>
    <div class="card">
      <div id="skillTable"></div>
    </div>
  </section>

  <section id="tab-models" class="panel">
    <div class="card" style="margin-bottom:12px" id="factsPanel"></div>
    <div class="card">
      <h2>Ranking do modelo — um modelo, duas medições</h2>
      <p class="hint">Cada <strong>modelo</strong> é uma linha só. Colunas: capacidade <span class="chip bare">sem Atlas</span> e <span class="chip atlas">com Atlas</span> nos mesmos testes válidos. Nunca compara o modelo sozinho com o modelo+Atlas como se fossem modelos diferentes.</p>
      <div id="modelMatrix"></div>
    </div>
    <div class="card" style="margin-top:12px">
      <h2>Suite a suite — mesmos testes</h2>
      <p class="hint">Comparação só quando <code>comparable=true</code> (uplift real). Sem par válido = “—” — nunca 0% inventado.</p>
      <div id="suiteCompare"></div>
    </div>
  </section>

  <section id="tab-overview" class="panel">
    <div class="grid g4" id="kpis"></div>
    <div class="grid g2" style="margin-top:12px">
      <div class="card"><h2 id="scatterTitle">Inteligência × tempo</h2><p class="hint" id="scatterHint">Quando o custo USD é $0 (assinatura), o eixo X vira tempo — $0 empilhado não diz nada.</p><div class="chart"><canvas id="scatter"></canvas></div></div>
      <div class="card"><h2>Inteligência por suite</h2><p class="hint">Barras lado a lado só quando Atlas rodou naquela suite. Sem barra verde = Atlas ainda não comparável.</p><div class="chart sm"><canvas id="suiteIntel"></canvas></div></div>
    </div>
    <div class="grid g2" style="margin-top:12px">
      <div class="card"><h2>Radar de capacidades — sem Atlas × com Atlas</h2><p class="hint">Cinco famílias de trabalho como eixos. O polígono verde maior que o azul = Atlas amplia a capacidade naquela direção.</p><div class="chart sm"><canvas id="radar"></canvas></div></div>
      <div class="card"><h2>Onde o Atlas move o ponteiro</h2><p class="hint">Delta por família. Barra para a direita = Atlas melhora. Cinza = par diagnóstico (sem sinal confirmado).</p><div class="chart sm"><canvas id="deltaBars"></canvas></div></div>
    </div>
    <div class="card" style="margin-top:12px">
      <h2>O que cada eixo significa</h2>
      <div class="axis-note" id="axisNotes"></div>
    </div>
  </section>

  <section id="tab-dissect" class="panel">
    <div class="card" style="margin-bottom:12px">
      <h2>Perfil por modelo — onde é bom, onde é fraco, o que o Atlas muda</h2>
      <div id="modelProfiles"></div>
    </div>
    <div class="card" style="margin-bottom:12px">
      <h2>Dissecção — tudo que foi medido, faceta por faceta</h2>
      <p class="hint" id="epistemicNote"></p>
      <div id="dissectSummary" class="grid g4"></div>
    </div>
    <div id="dissectList"></div>
  </section>

  <section id="tab-suites" class="panel"><div class="suite-grid" id="suiteCards"></div></section>

  <section id="tab-dossiers" class="panel">
    <div class="card" style="margin-bottom:12px">
      <h2>Dossiês — nada omitido</h2>
      <p class="hint">Contrato de entrega + métricas nativas + linhas do relatório + lacunas de cobertura.</p>
    </div>
    <div id="dossierList"></div>
  </section>

  <section id="tab-uplift" class="panel">
    <div class="card" id="upliftPanel"></div>
  </section>

  <div class="card glossary" style="margin-top:16px">
    <h2>Como ler este relatório — glossário e método</h2>
    <p class="hint">Toda palavra que pode confundir está explicada aqui. Nenhum número aparece sem lastro.</p>
    <div class="gloss-grid">
      <div><b>Domínio × Habilidade</b><span>Domínio é a área (Programação, Conhecimento…). Habilidade é o que um instrumento concreto mede dentro dela (corrigir bug real, editar multi-linguagem…). O número do domínio é a média das habilidades — e a média esconde: dá para ir a 94% numa e zerar noutra. Sempre olhe as habilidades.</span></div>
      <div><b>Sem Atlas / Com Atlas</b><span>O mesmo modelo rodando sozinho ("sem Atlas") e envolvido pelo Atlas ("com Atlas"). Comparação sempre nas MESMAS tarefas.</span></div>
      <div><b>Sucesso (%)</b><span>Fração de tarefas resolvidas corretamente. Conta só tarefas em que o teste realmente rodou — falha de ambiente é excluída do denominador.</span></div>
      <div><b>Faixa provável (IC 95%)</b><span>O intervalo onde a taxa real deve estar, 95% das vezes (Wilson). "45% (31–60%)" quer dizer: o 45% é o palpite central, mas a amostra só sustenta essa faixa. Quanto menor a amostra, mais larga. Um % sozinho finge precisão que não tem.</span></div>
      <div><b>Δ pp (delta em pontos percentuais)</b><span>Quanto o Atlas mudou o sucesso. +11 pp = onze pontos a mais. Verde melhora, vermelho piora. Sempre comparado no mesmo conjunto de tarefas.</span></div>
      <div><b>Confirmado × Diagnóstico</b><span>Confirmado = par válido que mede ganho/perda de verdade. Diagnóstico = par sem sinal de uplift (ex.: os dois lados 0% — ninguém resolveu, ou caso excluído). Diagnóstico fica <b>cinza</b>, não conta na contagem nem no saldo, e nunca vira "fato".</span></div>
      <div><b>Confiável / Não confiável</b><span>"Não confiável" quando a execução não terminou ou o ambiente falhou em mais de 30% das tarefas — não julga o modelo, e fica fora do score.</span></div>
      <div><b>Falha do modelo × do ambiente</b><span>"Modelo" = o modelo errou a tarefa. "Ambiente/fluxo" = o teste não rodou (rede, container, role recusada, timeout). Cada tarefa tem seu log para provar qual foi. Confundir os dois é o erro mais caro deste relatório: já mostrou "0%" onde o modelo acertava 100%.</span></div>
      <div><b>Tokens por tarefa</b><span>Quanto o modelo consome por tarefa. Como o custo em dólar é $0 (assinatura), esta é a métrica real de eficiência.</span></div>
      <div><b>Não medido</b><span>Não rodou ou o dado não existe. Nunca vira 0 — dizemos explicitamente que falta, e por quê. "0%" significa que o modelo tentou e errou; "não medido" significa que não dá para julgar.</span></div>
      <div><b>Cobertura</b><span>Quantos domínios da capacidade de uma IA esta bateria alcança. Não são todos: ela mede engenharia de software e uso agêntico de ferramentas, mais alguns domínios via Inspect. O que falta está listado em "Até onde este benchmark enxerga".</span></div>
      <div><b>Prova pública</b><span>Este relatório é medição interna, não certificado público (<code>claim_allowed = false</code>). Um número só vira prova depois de repetição e verificação independentes.</span></div>
    </div>
    <p class="hint" style="margin-top:14px"><b>Método, em uma frase:</b> cada domínio é a média das suas habilidades confiáveis, ponderada pelo número de tarefas; o ganho com Atlas usa só os pares confirmados, com seu próprio ponto de partida. O JSON canônico (<code>report.json</code>) tem cada número com sua origem.</p>
  </div>

  <footer id="footer"></footer>
</div>
<script id="data" type="application/json">__JSON__</script>
<script>
(() => {
  const D = JSON.parse(document.getElementById('data').textContent);
  const pct = v => v==null||v===''?'—':(Math.round(Number(v)*1000)/10)+'%';
  const money = v => {
    if (v==null||v==='') return 'não medido';
    const n = Number(v);
    if (!Number.isFinite(n)) return 'não medido';
    if (n <= 0) return 'assinatura ($0)';
    return '$'+n.toFixed(4);
  };
  const num = v => v==null||v===''?'—':Math.round(Number(v)).toLocaleString('pt-BR');
  const dur = ms => { if(ms==null||ms==='')return '—'; const s=Number(ms)/1000; if(s<60)return s.toFixed(1)+'s'; if(s<3600)return (s/60).toFixed(1)+' min'; return (s/3600).toFixed(2)+' h'; };
  const stab = v => v==null||v===''?'—':(typeof v==='number'? (Math.round(v*1000)/1000) : String(v));
  const color = (runtime, model) => runtime==='atlas_dev' ? '#3dd68c' : ({verboo_kimi_k2_7:'#f0c14a',verboo_qwen_3_6_27b:'#6cb6ff',hermes_gpt_5_5_codex:'#ff6b6b'}[model]||'#6cb6ff');
  const statusPt = s => ({ok:'ok', missing_data:'dados incompletos', failed:'falhou', blocked:'bloqueado', not_run:'não rodou', unsupported:'não suportado', real_uplift:'uplift real'}[s]||s||'—');

  // === Veredito com-vs-sem-Atlas (mesma fonte do app nativo) ===
  const AP = D.arena_capability_profile || {capabilities:[]};
  const confPt = c => ({measured:'medida', low:'poucos casos', unmeasured:'não medível'}[c]||c||'—');
  const s10 = v => v==null ? '—' : (Math.round(Number(v)*100)/10).toFixed(1);
  document.getElementById('arenaVerdict').innerHTML = (AP.capabilities||[]).length === 0
    ? '<p class="hint">Perfil da Arena ainda sem dados.</p>'
    : '<table><thead><tr><th>capacidade</th><th>sem Atlas</th><th>com Atlas</th><th>delta (IC 95%)</th><th>N (sem/com)</th><th>descartes</th><th>mediana/unidade (sem→com)</th><th>veredito</th></tr></thead><tbody>'
      + (AP.capabilities||[]).map(c => {
          const d = c.delta || null;
          const measured = c.confidence === 'measured';
          let verdict = confPt(c.confidence);
          let cls = '';
          if (measured && d) {
            if (d.significant && d.value > 0) { verdict = 'Atlas MELHOR · confirmado'; cls = 'pos'; }
            else if (d.significant && d.value < 0) { verdict = 'Atlas PIOR · confirmado'; cls = 'neg'; }
            else { verdict = 'dentro do ruído'; }
          }
          const ic = d ? ` [${s10(d.ci_low ?? d.ciLow)}, ${s10(d.ci_high ?? d.ciHigh)}]` : '';
          const excl = `${c.baseline_excluded ?? 0}/${c.with_atlas_excluded ?? 0}`;
          // Eficiência: mediana de wall por unidade MEDIDA (braço com Atlas inclui
          // o harness de governança). Custo-por-vitória só sai com ≥5 vitórias/braço.
          const e = c.efficiency || null;
          const secs = ms => ms==null ? '—' : (ms/1000).toFixed(1)+'s';
          const effTxt = !e ? '—'
            : `${secs(e.baseline && e.baseline.median_wall_ms)} → ${secs(e.with_atlas && e.with_atlas.median_wall_ms)}`
              + (e.per_win ? ` · por vitória ${secs(e.per_win.baseline_median_wall_ms)} → ${secs(e.per_win.with_atlas_median_wall_ms)}` : '');
          return `<tr><td>${c.label_pt || c.labelPt || c.capability}</td>`
            + `<td>${s10(c.score)}</td><td>${s10(c.with_atlas ?? c.withAtlas)}</td>`
            + `<td class="${cls}">${d ? s10(d.value) + ic : '—'}</td>`
            + `<td>${c.baseline_cases ?? '—'}/${c.with_atlas_cases ?? '—'}</td>`
            + `<td>${excl}</td><td>${effTxt}</td><td class="${cls}">${verdict}</td></tr>`;
        }).join('')
      + '</tbody></table>';

  // === Capacidades (visão principal) ===
  const MC = D.model_capabilities || {capabilities:[], efficiency:{}};
  const eff = MC.efficiency || {};
  document.getElementById('capEfficiency').innerHTML = [
    ['Tokens por tarefa', num(eff.tokens_per_task_mean), 'média nas suítes confiáveis'],
    ['Tempo por tarefa', dur(eff.median_wall_ms_mean), 'mediana wall-clock'],
    ['Custo por tarefa', '$0', 'assinatura Verboo (marginal)'],
  ].map(([l,v,s])=>`<div class="e"><div class="v">${v}</div><span class="l">${l}</span><div class="hint" style="margin:0">${s}</div></div>`).join('');

  document.getElementById('capCards').innerHTML = (MC.capabilities||[]).map(c => {
    const bare = c.bare_intelligence==null ? null : Number(c.bare_intelligence)*100;
    const hasAtlas = c.atlas_intelligence!=null && c.atlas_bare_baseline!=null;
    const aBefore = hasAtlas ? Number(c.atlas_bare_baseline)*100 : null;
    const aAfter = hasAtlas ? Number(c.atlas_intelligence)*100 : null;
    const dv = c.delta_intelligence==null ? null : Math.round(Number(c.delta_intelligence)*1000)/10;
    // Par diagnóstico não é ganho/perda confirmado — nunca colorir de verde/vermelho.
    // Só delta confirmado (não-diagnóstico) recebe cor de veredito.
    const dCls = (dv==null||c.atlas_diagnostic_only)?'':(dv>0?'pos':(dv<0?'neg':''));
    const barBare = bare==null?0:bare;
    // Atlas em linha própria com SEU baseline pareado — não implica contra o
    // número grande da capacidade (suítes diferentes).
    // Delta de poucas tarefas é tendência, não veredito: um -66 pp de 9 tarefas
    // tem margem enorme. Sem esse aviso, o pp grande finge precisão que não tem.
    const atlasSmall = hasAtlas && c.tasks_atlas_paired>0 && c.tasks_atlas_paired < 20;
    const atlasLine = hasAtlas ? `
      <div class="atlas-line">
        <span class="tag">Com Atlas</span>
        <span class="pair"><b class="bare">${Math.round(aBefore)}%</b> → <b class="atlas">${Math.round(aAfter)}%</b></span>
        <span class="delta ${dCls}">${dv>=0?'+':''}${dv} pp</span>
        <span class="hint" style="margin:0">base ${c.tasks_atlas_paired||0} tarefa${c.tasks_atlas_paired==1?'':'s'} em ${c.atlas_measured_on} suíte(s)${c.atlas_diagnostic_only?' · diagnóstico (não é ganho confirmado)':''}${atlasSmall?' · tendência, não conclusão (amostra pequena)':''}</span>
      </div>` : `<div class="atlas-line"><span class="hint" style="margin:0">Atlas ainda não medido nesta capacidade</span></div>`;
    // Amostra pequena: um número de poucas tarefas não é conclusivo. Avisa.
    const smallSample = bare!=null && c.tasks_scored>0 && c.tasks_scored < 12;
    // Faixa de confiança 95% (Wilson): o intervalo onde a taxa real deve cair.
    // Mostra a incerteza do número em vez de fingir precisão de ponto único.
    const ciLo = c.bare_ci_low==null?null:Math.round(Number(c.bare_ci_low)*100);
    const ciHi = c.bare_ci_high==null?null:Math.round(Number(c.bare_ci_high)*100);
    const ciLine = (bare!=null&&ciLo!=null&&ciHi!=null)
      ? `<span class="lab" style="display:inline" title="Faixa de confiança 95% (Wilson): a taxa real cai aqui em 95% das vezes. Quanto menor a amostra, mais larga a faixa.">· faixa provável ${ciLo}–${ciHi}%</span>` : '';
    // Sub-capacidades: as habilidades distintas dentro do domínio. Cada uma é um
    // instrumento real com score próprio — "Programação" vira corrigir-bug +
    // feature + algoritmo + terminal, não uma caixa única.
    const subRows = (c.sub_capabilities||[]).map(s => {
      const sb = s.bare_intelligence==null ? null : Math.round(Number(s.bare_intelligence)*100);
      const sLo = s.bare_ci_low==null?null:Math.round(Number(s.bare_ci_low)*100);
      const sHi = s.bare_ci_high==null?null:Math.round(Number(s.bare_ci_high)*100);
      const sCi = (sb!=null&&sLo!=null&&sHi!=null)?` <span class="subci">(${sLo}–${sHi}%)</span>`:'';
      // Escala graduada: mostrar a NOTA MÉDIA junto do binário. "0% acima do
      // limiar" lê como "não sabe fazer" quando a nota média foi 4.2/10 — sabe,
      // só não chega ao limiar. O binário sozinho mente por omissão.
      const gm = (s.graded_mean!=null && s.graded_max)
        ? ` <span class="subgm" title="Nota média do juiz na escala do benchmark. O % ao lado conta só o que passou do limiar declarado.">nota média ${s.graded_mean}/${s.graded_max}</span>` : '';
      const val = s.reliable && sb!=null
        ? `<span class="subval">${sb}%</span>${sCi} <span class="subn">${s.tasks_scored} tf</span>${gm}`
        : `<span class="subna">não medido</span>`;
      const w = (s.reliable&&sb!=null)?sb:0;
      // Motivo visível, não escondido em tooltip: "não medido" sem o porquê deixa
      // o leitor supondo que o modelo falhou, quando o teste é que quebrou.
      const why = (!s.reliable && s.unreliable_reason)
        ? `<div class="subwhy">${s.unreliable_reason}</div>` : '';
      return `<div class="subcap" title="${s.measures}">
        <div class="subhead"><span class="subname">${s.label}</span>${val}</div>
        <div class="subbar"><div class="subfill${s.reliable&&sb!=null?'':' na'}" style="width:${w}%"></div></div>
        ${why}
      </div>`;
    }).join('');
    return `<div class="cap">
      <h3>${c.label}</h3>
      <p class="measures">${c.measures}</p>
      <div class="score bare" style="margin-bottom:6px"><span class="n">${bare==null?'—':Math.round(bare)+'%'}</span> <span class="lab" style="display:inline">média do domínio (sem Atlas)${c.tasks_scored?` · base ${c.tasks_scored} tarefa${c.tasks_scored==1?'':'s'}`:''}</span> ${ciLine}</div>
      <div class="bars"><div class="fill" style="width:${barBare}%;background:var(--bare)"></div></div>
      ${atlasLine}
      ${subRows?`<div class="subcaps"><div class="subcaps-h">Habilidades medidas neste domínio</div>${subRows}</div>`:''}
      <div class="foot">
        <span>Confiável em <b>${c.suites_reliable}/${c.suites_total}</b> habilidades</span>
        <span>Tokens/tarefa <b>${num(c.tokens_per_task)}</b></span>
        <span>Tempo/tarefa <b>${dur(c.median_wall_ms)}</b></span>
      </div>
      ${smallSample?`<div class="warn" style="color:var(--muted)">ℹ Amostra pequena (${c.tasks_scored} tarefas): use como indicativo, não como número definitivo.</div>`:''}
    </div>`;
  }).join('') || '<div class="empty">Sem capacidades medidas ainda.</div>';

  // EIXO DE RISCO: sinal invertido, painel separado. Nunca soma com capacidade —
  // "sabe fazer" + "sabe causar dano" não é uma nota, é um número sem sentido.
  const RISK = MC.risk || null;
  if (RISK && document.getElementById('riskPanel')) {
    const items = (RISK.items||[]).map(r => {
      const v = r.rate==null ? null : Math.round(Number(r.rate)*100);
      const val = (r.reliable && v!=null)
        ? `<span class="riskv">${v}%</span> <span class="riskn">${r.tasks_scored} tf</span>`
        : `<span class="subna">não medido</span>`;
      const why = (!r.reliable && r.unreliable_reason) ? `<div class="subwhy">${r.unreliable_reason}</div>` : '';
      return `<div class="riskrow">
        <div class="riskhead"><span class="risklabel">${r.label}</span>
          <span class="riskdir" title="Nesta métrica, pontuar mais é PIOR">↓ menor é melhor</span>${val}</div>
        <p class="riskmeas">${r.measures}</p>${why}
      </div>`;
    }).join('');
    document.getElementById('riskPanel').innerHTML = `
      <h2>Risco — aqui maior é PIOR</h2>
      <p class="hint">${RISK.note}</p>
      <div class="riskbox">${items}</div>`;
  }

  // Escopo declarado: o relatório diz o que NÃO alcança. Sem isto, "capacidades"
  // sugere retrato da IA inteira quando a bateria é código/agente.
  const COV = MC.coverage || null;
  if (COV && document.getElementById('coveragePanel')) {
    const rows = (COV.map||[]).map(d => `
      <div class="covrow ${d.YesNo::format(covered)}">
        <span class="covmark">${d.covered?'✓':'—'}</span>
        <span class="covdom">${d.domain}${d.dormant?` <span class="covdorm">${d.dormant} parados</span>`:''}</span>
        <span class="covnote">${d.note}</span>
      </div>`).join('');
    document.getElementById('coveragePanel').innerHTML = `
      <h2>Até onde este benchmark enxerga</h2>
      <p class="hint">${COV.scope_note}</p>
      <div class="covstats">
        <span><b>${COV.skills_measured}</b>/${COV.skills_wired} habilidades realmente medidas</span>
        <span><b>${COV.domains_covered}</b>/${COV.domains_total} domínios com instrumento</span>
        ${COV.instruments_dormant?`<span><b>${COV.instruments_dormant}</b> instrumentos instalados que nunca rodaram</span>`:''}
      </div>
      <div class="covmap">${rows}</div>`;
  }

  // Habilidades: a lista fina. Uma linha por habilidade que um instrumento
  // mede DE VERDADE — nome vindo do metadata do benchmark, não rótulo nosso.
  const SK = D.skills || {rows:[]};
  if (document.getElementById('skillTable')) {
    const pct = v => v === null || v === undefined ? '—' : Math.round(v*100)+'%';
    const pp  = v => (v > 0 ? '+' : '') + Math.round(v*100);
    // Motivo em palavra quando não há número. "—" sozinho o leitor confunde com
    // zero, e zero é um veredito que a ausência de dado não autoriza.
    const SEM = {
      atlas_nao_medido: 'sem braço Atlas — nada a comparar',
      sem_medicao:      'não medido',
    };
    // A régua: escala fixa de -100 a +100 pp. A faixa é o intervalo de 95% da
    // diferença; o traço é o valor observado. Só ganha cor quando o intervalo
    // INTEIRO fica de um lado do zero — `conclusive`, vindo do recibo.
    const regua = r => {
      if (r.delta_ci_low === null || r.delta_ci_low === undefined) return '';
      const lo = Math.max(-1, r.delta_ci_low), hi = Math.min(1, r.delta_ci_high);
      const cls = !r.conclusive ? '' : (lo > 0 ? 'melhor' : 'pior');
      return `<div class="regua">
        <div class="faixa ${cls}" style="left:${(lo+1)/2*100}%;width:${Math.max((hi-lo)/2*100,1.5)}%"></div>
        <div class="obs" style="left:${(r.delta+1)/2*100}%"></div>
      </div>`;
    };
    // Ganho no topo, perda no fim — mas SEM quebrar o agrupamento: ordenar tudo
    // por delta espalha as linhas de um mesmo domínio e o cabeçalho reaparece
    // ("Programação" saía 4x). Então ordena-se DENTRO do domínio, e os domínios
    // entre si pela melhor linha de cada um. O olho varre de cima (onde o Atlas
    // mais ajuda) para baixo (onde mais atrapalha), e cada domínio aparece uma
    // vez só.
    // Com par Atlas: a maior ajuda no topo, a maior piora no fundo.
    // Sem par Atlas (estado de hoje): NÃO há delta, então `delta ?? -9` dava o
    // MESMO peso a todas as 104 linhas — e a ordem virava alfabética do slug,
    // com as 78 linhas saturadas em 100% (teto do teste, nada a medir) enterrando
    // as ~26 onde o modelo cru É fraco. Vira o contrário: sem delta, o mais fraco
    // primeiro — é onde o Atlas terá o que provar; o teto afunda, onde não há o
    // que multiplicar.
    const peso = r => {
      if (r.delta !== null && r.delta !== undefined) return (r.conclusive ? 1000 : 0) + r.delta * 100;
      if (r.bare === null || r.bare === undefined) return -3000; // sem medição alguma: fundo
      return -1000 - r.bare * 100; // bare 0% → -1000 (topo do grupo sem-Atlas); 100% → -1100 (fundo)
    };
    const grupos = new Map();
    for (const r of (SK.rows||[])) {
      const dl = r.domain_label || 'Sem domínio';
      if (! grupos.has(dl)) grupos.set(dl, []);
      grupos.get(dl).push(r);
    }
    const ordenados = [...grupos.entries()]
      .map(([dl, rs]) => [dl, rs.sort((a,b) => peso(b) - peso(a))])
      .sort((a,b) => peso(b[1][0]) - peso(a[1][0]));
    let rows = '';
    for (const [dl, rs] of ordenados) {
      rows += `<tr class="dgroup"><td colspan="4">${dl} · ${rs.length}</td></tr>`;
      for (const r of rs) {
      // A FATIA sem jargão. Mostrava o slug cru "bbq:Age · bbq" — e o operador
      // via 6 linhas "Responder pelo contexto" idênticas, distinguidas só por um
      // slug que exige legenda. O eixo já vem nomeável (`axis`) e o valor está no
      // slug depois do ":". Vira "categoria: Age" — o que a fatia É, em português,
      // sem o instrumento repetido nem o underscore cru.
      const AXP = {category:'categoria', subject:'matéria', language:'idioma',
        high_level_domain:'área', instruction_id_list:'tipo de instrução',
        domain1:'domínio do texto', task_type:'tipo de tarefa'};
      const valorFatia = (r.skill.includes(':') ? r.skill.split(':').slice(1).join(':') : '')
        .replace(/_/g, ' ').trim();
      const fatia = (r.skill !== r.label && valorFatia)
        ? `<span class="sk-fatia">${(AXP[r.axis] || r.axis || 'fatia')}: ${valorFatia}</span>` : '';
      // O TETO É DO TESTE, NÃO DO ATLAS — e o teto é do `n`, não do instrumento.
      // Com 9 tarefas, uma habilidade em 66% já não consegue mostrar ganho
      // nenhum (o máximo é 33 pp e só se afirma acima de 44). Sem dizer isto, a
      // linha cinza parece "o Atlas não ajudou" quando a verdade é "este teste
      // não consegue mostrar ajuda". Como repetição é grátis, a saída é rodar
      // mais — e isso é acionável, ao contrário de um cinza mudo.
      const teto = r.gain_undemonstrable
        ? `<span class="sk-fatia">teto do teste: nem 100% provaria ganho com ${r.bare_n} tarefas — rode mais</span>`
        : '';
      // POR QUE falta o braço Atlas — e a resposta muda com o TIPO da habilidade.
      // As 5 famílias de código (aider, swe, terminal, bfcl, hal…) são medidas
      // pelo Atlas Dev, que gera patches. As de CONHECIMENTO/Q&A (mmlu, gpqa, bbq,
      // 95 de 104) o Atlas Dev NÃO responde — ele não faz pergunta-e-resposta.
      // Medir o Atlas nelas exige um bridge Decide/RAG que este fluxo não tem.
      // Sem dizer isto, o operador espera 104 pares que o Atlas Dev nunca produz.
      const CODIGO = new Set(['aider_polyglot','swe_bench_live','senior_swe_bench','terminal_bench','bfcl','hal_harness','live_code_bench','swe_marathon','tau2_bench']);
      const semAtlas = (r.verdict === 'atlas_nao_medido')
        ? (CODIGO.has(r.instrument)
            ? 'sem braço Atlas — falta rodar o Atlas Dev nesta suíte'
            : 'o Atlas Dev não responde Q&A — medir o Atlas aqui exige o bridge de conhecimento (não feito)')
        : (SEM[r.verdict] || r.verdict);
      const dcell = (r.delta === null || r.delta === undefined)
        ? `<span class="hint">${semAtlas}</span>`
        : `<b style="color:var(--${r.conclusive ? (r.delta>0?'melhor':'pior') : 'sem-sinal'})">${pp(r.delta)} pp</b>
           ${r.delta_ci_low !== null && r.delta_ci_low !== undefined
              ? `<span class="sk-ci">${pp(r.delta_ci_low)} a ${pp(r.delta_ci_high)}${r.conclusive ? '' : ' · inclui o zero'}</span>` : ''}`;
      // A FAIXA DE WILSON ao lado da taxa — o piso do goal: "Wilson visível,
      // nunca implícito". "67%" sobre 9 tarefas tem faixa real [35%, 88%]; o
      // dígito sozinho promete uma precisão que 9 amostras não têm. A faixa diz
      // ao operador quanto do número é dado e quanto é sorte.
      const wil = (lo, hi) => (lo === null || lo === undefined)
        ? '' : `<span class="sk-ci">${pct(lo)} a ${pct(hi)}</span>`;
      // POR QUE o Atlas perdeu, em português — para "Atlas pior" nunca ler como
      // "modelo incapaz" quando foi o Atlas rejeitando a resposta boa do modelo.
      const CAUSA = {
        provider_invalid_provider_contract: 'o modelo resolveu, mas o Atlas recusou o formato da resposta',
        candidate_preparation_blocked: 'o Atlas recusou a resposta do modelo antes de aplicar',
        governor_authority_absent: 'o Atlas gerou o patch, mas a governança não liberou',
        model_failure: 'o modelo errou a tarefa',
      };
      const causa = (r.atlas_loss_cause && (r.atlas ?? 1) < (r.bare ?? 0))
        ? `<span class="sk-fatia">por quê: ${CAUSA[r.atlas_loss_cause] || r.atlas_loss_cause}</span>` : '';
      rows += `<tr>
        <td><span class="sk-nome">${r.label || r.skill}</span>${fatia}</td>
        <td>${pct(r.bare)} <span class="subci">${r.bare_n} tarefas</span>${wil(r.bare_ci_low, r.bare_ci_high)}${teto}</td>
        <td>${pct(r.atlas)} <span class="subci">${r.atlas_n} tarefas</span>${wil(r.atlas_ci_low, r.atlas_ci_high)}${causa}</td>
        <td>${regua(r)}${dcell}</td></tr>`;
      }
    }
    document.getElementById('skillTable').innerHTML = (SK.rows||[]).length
      ? `<table><thead><tr><th>Habilidade</th><th>sem Atlas</th><th>com Atlas</th>
           <th>pior &#8592; 0 &#8594; melhor</th></tr></thead><tbody>${rows}</tbody></table>`
      : `<div class="empty">Nenhuma habilidade medida ainda. Execute a bateria.</div>`;
    const inst = Object.entries(SK.by_instrument||{})
      .map(([k,v]) => `${k}: ${v}`).join(' · ');
    document.getElementById('skillStats').innerHTML = `
      <span><b>${SK.total||0}</b> habilidades medidas</span>
      <span><b>${SK.with_atlas||0}</b> com os dois braços (as únicas que podem ficar verdes ou vermelhas)</span>
      ${inst ? `<span class="hint" style="flex-basis:100%">Por instrumento — ${inst}</span>` : ''}`;
    // Coluna Atlas inteira vazia é a informação mais importante da tela: sem o
    // motivo, o leitor supõe "a bateria não rodou" — a verdade é mais grave.
    if (SK.atlas_arm_note) {
      document.getElementById('skillArmNote').innerHTML =
        `<div class="empty" style="border-color:var(--bad);color:var(--ink);margin-top:10px">
           <b style="color:var(--bad)">Por que nenhuma linha tem cor</b><br>${SK.atlas_arm_note}
         </div>`;
    }
  }

  document.getElementById('objective').textContent = D.objective;
  document.getElementById('meta').innerHTML =
    `${D.summary.provider_binding} · modelo <code>${D.summary.primary_model||'—'}</code> · `+
    `pontos bare ${D.summary.bare_points} · pontos Atlas ${D.summary.atlas_points} · `+
    `famílias uplift ${D.summary.uplift_ready}/${D.summary.uplift_total}<br>`+
    `gerado ${D.built_at||''} · hash <code>${D.report_hash||''}</code>`;

  const primary = D.summary.primary_model;
  const bare = D.models.find(m => m.model_id===primary && m.runtime==='bare') || D.models.find(m => m.runtime==='bare');
  const atlas = bare ? D.models.find(m => m.model_id===bare.model_id && m.runtime==='atlas_dev') : null;

  // Δ Atlas só de famílias com uplift real E confirmado (não-diagnóstico):
  // par diagnóstico (ex.: ambos 0%) não é sinal de uplift, não pode entrar no
  // saldo nem na contagem. A tira abaixo ainda lista todos com marca · diagnóstico.
  const paired = (D.uplift_families||[]).filter(f => f.status==='real_uplift' && f.delta_intelligence!=null && !f.diagnostic_only);
  const upliftMean = paired.length
    ? paired.reduce((a,f)=>a+Number(f.delta_intelligence),0)/paired.length
    : null;
  const better = paired.filter(f => Number(f.delta_intelligence)>0).length;
  const worse = paired.filter(f => Number(f.delta_intelligence)<0).length;
  const incomplete = (D.uplift_families||[]).filter(f => f.status!=='real_uplift').length;

  // Contagem de vitórias e Δ médio precisam CONCORDAR para a capa tomar lado;
  // sinais opostos (ex.: 2↑/1↓ mas média −8%) = regressão concentrada, capa neutra.
  let verdictTitle = 'Leitura ainda incompleta';
  let verdictColor = 'var(--warn)';
  const meanSign = upliftMean==null ? 0 : Math.sign(upliftMean);
  const countSign = Math.sign(better - worse);
  if (paired.length === 0) {
    verdictTitle = 'Ainda não dá para julgar bare × Atlas';
  } else if (countSign < 0 && meanSign <= 0) {
    verdictTitle = 'Nos pares medidos, Atlas está pior no saldo';
    verdictColor = 'var(--bad)';
  } else if (countSign > 0 && meanSign >= 0) {
    verdictTitle = 'Nos pares medidos, Atlas está melhor no saldo';
    verdictColor = 'var(--atlas)';
  } else if (countSign > 0 && meanSign < 0) {
    verdictTitle = 'Dividido: Atlas ganha em mais famílias, mas uma regressão concentrada puxa o Δ médio para baixo';
  } else if (countSign < 0 && meanSign > 0) {
    verdictTitle = 'Dividido: Atlas perde em mais famílias, mas um ganho concentrado puxa o Δ médio para cima';
  } else {
    verdictTitle = 'Nos pares medidos, resultado misto';
  }
  const famLabel = (f) => f.label || f.family || f.suite_id || '';
  const upliftRows = (D.uplift_families||[])
    .filter(f => f.bare_intelligence!=null && f.atlas_intelligence!=null)
    .map(f => {
      const dv = Number(f.delta_intelligence ?? (f.atlas_intelligence - f.bare_intelligence));
      const dTxt = (dv>=0?'+':'')+Math.round(dv*1000)/10+' pp';
      const dCls = dv>0?'pos':(dv<0?'neg':'');
      return `<div class="row">
        <span class="name">${famLabel(f)}${f.diagnostic_only?' <span class="hint">· diagnóstico</span>':''}</span>
        <span class="b">${pct(f.bare_intelligence)}</span>
        <span class="arrow">→</span>
        <span class="a">${pct(f.atlas_intelligence)}</span>
        <span class="d ${dCls}">${dTxt}</span>
      </div>`;
    }).join('');
  document.getElementById('verdict').innerHTML =
    `<span class="title" style="color:${verdictColor}">${verdictTitle}</span>`+
    `<div class="verdict-stats">
      <div class="stat"><span class="v">${paired.length}<span style="color:var(--muted);font-size:14px">/${(D.uplift_families||[]).length}</span></span><span class="l">pares confirmados</span></div>
      <div class="stat"><span class="v"><span class="pos">${better}↑</span> <span class="neg">${worse}↓</span></span><span class="l">melhorou · piorou</span></div>
      <div class="stat"><span class="v">${upliftMean==null?'—':((upliftMean>=0?'+':'')+pct(upliftMean))}</span><span class="l">Δ médio (confirmados)</span></div>
      <div class="stat"><span class="v">${D.pipeline.suites_ok}<span style="color:var(--muted);font-size:14px">/10</span></span><span class="l">pipeline ok</span></div>
    </div>`+
    (upliftRows ? `<div class="uplift-strip">
      <div class="row" style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em"><span>Família</span><span style="text-align:right">sem Atlas</span><span></span><span style="text-align:right">com Atlas</span><span style="text-align:right">Δ</span></div>
      ${upliftRows}
    </div>` : '')+
    `<p class="note">Custo $0 = assinatura (não discrimina). O Δ médio usa só os pares realmente medidos — não confundir com a média geral das capacidades.</p>`;

  const atlasPairedMean = paired.length
    ? paired.reduce((a,f)=>a+Number(f.atlas_intelligence),0)/paired.length
    : null;
  const barePairedMean = paired.length
    ? paired.reduce((a,f)=>a+Number(f.bare_intelligence),0)/paired.length
    : null;

  const upliftKpi = upliftMean==null
    ? ['Δ Atlas (pares)', 'sem pares', `${D.summary.uplift_ready}/${D.summary.uplift_total} uplift pronto`, '#8a93a3']
    : ['Δ Atlas (pares)', (upliftMean>=0?'+':'')+pct(upliftMean), `${paired.length} pares · ${better}↑ ${worse}↓`, upliftMean>=0?'#3dd68c':'#ff6b6b'];

  const kpis = [
    ['Capacidade (sem Atlas)', pct(bare?.intelligence), bare?`média em ${bare.n_suites||'?'} suites · posição do modelo`:'—', '#f0c14a'],
    ['Com Atlas (pares válidos)', atlasPairedMean==null ? 'ainda sem pares' : pct(atlasPairedMean), barePairedMean==null?`${paired.length} testes válidos`:`vs ${pct(barePairedMean)} nos mesmos testes`, '#3dd68c'],
    upliftKpi,
    ['Custo / tarefa', money(bare?.cost_per_task), 'USD reportado · $0 não discrimina', '#6cb6ff'],
    ['Tokens / tarefa', num(bare?.tokens_per_task), 'usage observado ÷ casos', '#f0c14a'],
    ['Velocidade bare', dur(bare?.speed_ms), 'mediana wall (menor = melhor)', '#f0b429'],
  ];
  document.getElementById('kpis').innerHTML = kpis.map(([l,v,h,c])=>
    `<div class="card"><div class="kpi-label">${l}</div><div class="kpi" style="color:${c}">${v}</div><div class="hint" style="margin:0">${h}</div></div>`).join('');

  document.getElementById('axisNotes').innerHTML = D.axes.map(a=>
    `<div><strong>${a.label}</strong> — ${a.hint}${a.higher_better?' (↑ melhor)':' (↓ melhor)'}</div>`).join('');

  // Scatter: se custo ~0, usar tempo (mais rápido à direita)
  const pts = D.suite_points.filter(p => p.intelligence!=null && p.present!==false);
  const costs = pts.map(p => Number(p.cost_per_task)).filter(n => Number.isFinite(n) && n > 0);
  const useCost = costs.length >= Math.max(1, Math.floor(pts.length/3));
  const maxCost = useCost ? Math.max(...costs) : 0;
  const walls = pts.map(p => Number(p.speed_ms)).filter(n => Number.isFinite(n) && n > 0);
  const maxWall = walls.length ? Math.max(...walls) : 1;
  const sets = {bare:{label:'bare (modelo sozinho)',data:[],backgroundColor:[],pointRadius:[]}, atlas_dev:{label:'atlas_dev (com Atlas)',data:[],backgroundColor:[],pointRadius:[]}};
  pts.forEach(p => {
    const key = p.runtime==='atlas_dev'?'atlas_dev':'bare';
    const x = useCost
      ? (maxCost - Number(p.cost_per_task||0))
      : (maxWall - Number(p.speed_ms||maxWall));
    const meta = useCost ? Number(p.cost_per_task||0) : Number(p.speed_ms||0);
    sets[key].data.push({x, y:Number(p.intelligence)*100, label:`${p.suite_title} · ${p.model_id}@${p.runtime}`, meta});
    sets[key].backgroundColor.push(color(p.runtime,p.model_id));
    sets[key].pointRadius.push(p.runtime==='atlas_dev'?8:6);
  });
  const scatterHost = document.getElementById('scatter');
  if (!pts.length) {
    scatterHost.parentElement.innerHTML = '<div class="empty">Sem pontos de inteligência ainda. Execute as baterias bare/uplift.</div>';
  } else {
    if (!useCost) {
      document.getElementById('scatterTitle').textContent = 'Inteligência × tempo (custo USD inútil)';
      document.getElementById('scatterHint').textContent = 'Todos os custos estão em $0 (assinatura). Eixo X = tempo wall: mais à direita = mais rápido.';
    } else {
      document.getElementById('scatterTitle').textContent = 'Inteligência × custo / tarefa';
      document.getElementById('scatterHint').textContent = 'Cada ponto = suite × modelo@runtime. Mais à direita = mais barato.';
    }
    new Chart(scatterHost, {
      type:'scatter',
      data:{datasets:Object.values(sets).filter(s=>s.data.length).map(s=>({...s,borderColor:'#05060a',borderWidth:1}))},
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{labels:{color:'#8a93a3'}},tooltip:{callbacks:{label:c=>{
          const r=c.raw;
          const extra = useCost ? ('$'+Number(r.meta).toFixed(4)) : dur(r.meta);
          return `${r.label}: ${r.y.toFixed(1)}% · ${extra}`;
        }}}},
        scales:{
          x:{title:{display:true,text: useCost ? 'Custo / tarefa → mais barato' : 'Tempo → mais rápido', color:'#8a93a3'},
            ticks:{color:'#8a93a3',callback:v=> useCost ? ('$'+(maxCost-v).toFixed(2)) : dur(maxWall-v)},grid:{color:'#1b2030'}},
          y:{min:0,max:100,title:{display:true,text:'Inteligência (sucesso ITT %)',color:'#8a93a3'},ticks:{color:'#8a93a3',callback:v=>v+'%'},grid:{color:'#1b2030'}}
        }}
    });
  }

  const suiteIds = [...new Set((D.suites||[]).map(s=>s.suite_id))];
  const labels = suiteIds.map(id => (D.suites.find(s=>s.suite_id===id)?.title)||id);
  const bareScores = suiteIds.map(id => {
    const p = D.suite_points.find(x=>x.suite_id===id && x.runtime==='bare' && x.model_id===(primary||x.model_id));
    return p?.intelligence==null?null:Number(p.intelligence)*100;
  });
  const atlasScores = suiteIds.map(id => {
    const p = D.suite_points.find(x=>x.suite_id===id && x.runtime==='atlas_dev' && x.model_id===(primary||x.model_id) && x.present!==false);
    return p?.intelligence==null?null:Number(p.intelligence)*100;
  });
  new Chart(document.getElementById('suiteIntel'), {
    type:'bar',
    data:{labels, datasets:[
      {label:'bare', data:bareScores, backgroundColor:'#6cb6ff99', borderRadius:5, barThickness:10},
      {label:'atlas_dev', data:atlasScores, backgroundColor:'#3dd68c99', borderRadius:5, barThickness:10},
    ]},
    options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
      plugins:{legend:{labels:{color:'#8a93a3'}},tooltip:{callbacks:{label:c=> c.raw==null ? `${c.dataset.label}: não rodou` : `${c.dataset.label}: ${Number(c.raw).toFixed(1)}%`}}},
      scales:{x:{min:0,max:100,ticks:{color:'#8a93a3',callback:v=>v+'%'},grid:{color:'#1b2030'}},y:{ticks:{color:'#c9d0dc',font:{size:11}},grid:{display:false}}}
    }
  });

  // Radar de capacidades (clássico dos leaderboards): 5 famílias como eixos,
  // polígonos sem Atlas × com Atlas. Só famílias com valor medido entram.
  const famRows = (D.uplift_families||[]).filter(f => f.bare_intelligence!=null);
  if (famRows.length >= 3 && document.getElementById('radar')) {
    new Chart(document.getElementById('radar'), {
      type:'radar',
      data:{
        labels: famRows.map(f => (f.label||f.family||'').replace(/\s*\(.*\)$/,'')),
        datasets:[
          {label:'sem Atlas', data:famRows.map(f=>Number(f.bare_intelligence)*100),
            borderColor:'#6cb6ff', backgroundColor:'#6cb6ff22', pointBackgroundColor:'#6cb6ff', borderWidth:2},
          {label:'com Atlas', data:famRows.map(f=>f.atlas_intelligence==null?null:Number(f.atlas_intelligence)*100),
            borderColor:'#3dd68c', backgroundColor:'#3dd68c26', pointBackgroundColor:'#3dd68c', borderWidth:2},
        ],
      },
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{labels:{color:'#c9d0dc'}},tooltip:{callbacks:{label:c=> c.raw==null?`${c.dataset.label}: sem par`:`${c.dataset.label}: ${Number(c.raw).toFixed(1)}%`}}},
        scales:{r:{min:0,max:100,ticks:{display:false},grid:{color:'#242836'},angleLines:{color:'#242836'},
          pointLabels:{color:'#c9d0dc',font:{size:12,weight:650}}}}}
    });
  }
  if (document.getElementById('deltaBars')) {
    const withDelta = famRows.filter(f => f.delta_intelligence!=null);
    new Chart(document.getElementById('deltaBars'), {
      type:'bar',
      data:{
        labels: withDelta.map(f => (f.label||f.family||'').replace(/\s*\(.*\)$/,'') + (f.diagnostic_only?' (diag.)':'')),
        datasets:[{
          data: withDelta.map(f => Math.round(Number(f.delta_intelligence)*1000)/10),
          // Par diagnóstico = cinza (sem sinal); só confirmado ganha verde/vermelho.
          backgroundColor: withDelta.map(f => f.diagnostic_only ? '#8a93a366' : (Number(f.delta_intelligence)>=0 ? '#3dd68ccc' : '#ff6b6bcc')),
          borderRadius:5, barThickness:16,
        }],
      },
      options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>`Δ ${c.raw>=0?'+':''}${c.raw} pp`}}},
        scales:{x:{title:{display:true,text:'Δ pontos percentuais com Atlas',color:'#8a93a3'},
          ticks:{color:'#8a93a3',callback:v=>(v>=0?'+':'')+v},grid:{color:'#1b2030'}},
          y:{ticks:{color:'#c9d0dc',font:{size:12}},grid:{display:false}}}}
    });
  }

  // Charts da aba Capacidades (visão principal).
  const capList = (MC.capabilities||[]).filter(c => c.bare_intelligence!=null);
  // Um gráfico que plota só o que foi medido precisa DIZER o que ficou de fora:
  // um radar de 3 eixos chamado "mapa de capacidades" sugere que são 3 e pronto.
  const omitted = (MC.capabilities||[]).filter(c => c.bare_intelligence==null).map(c=>c.label);
  const omitNote = omitted.length
    ? `<strong>${omitted.length} domínio(s) fora do gráfico</strong> por não terem medição: ${omitted.join(', ')}.`
    : '';
  const radarNote = document.getElementById('radarOmitted');
  if (radarNote) radarNote.innerHTML = omitNote;
  const tkOmitted = (MC.capabilities||[]).filter(c => c.tokens_per_task==null).map(c=>c.label);
  const tokensNote = document.getElementById('tokensOmitted');
  if (tokensNote && tkOmitted.length) {
    tokensNote.innerHTML = `<strong>${tkOmitted.length} domínio(s) fora do gráfico</strong> por não terem medição: ${tkOmitted.join(', ')}.`;
  }
  if (capList.length >= 3 && document.getElementById('capRadar')) {
    new Chart(document.getElementById('capRadar'), {
      type:'radar',
      data:{labels: capList.map(c=>c.label), datasets:[
        {label:'sem Atlas', data:capList.map(c=>Number(c.bare_intelligence)*100),
          borderColor:'#6cb6ff', backgroundColor:'#6cb6ff22', pointBackgroundColor:'#6cb6ff', borderWidth:2},
        {label:'com Atlas', data:capList.map(c=>c.atlas_intelligence==null?null:Number(c.atlas_intelligence)*100),
          borderColor:'#3dd68c', backgroundColor:'#3dd68c26', pointBackgroundColor:'#3dd68c', borderWidth:2},
      ]},
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{labels:{color:'#c9d0dc'}},tooltip:{callbacks:{label:c=>c.raw==null?`${c.dataset.label}: não medido`:`${c.dataset.label}: ${Number(c.raw).toFixed(1)}%`}}},
        scales:{r:{min:0,max:100,ticks:{display:false},grid:{color:'#242836'},angleLines:{color:'#242836'},pointLabels:{color:'#c9d0dc',font:{size:12,weight:650}}}}}
    });
  }
  if (document.getElementById('capTokens')) {
    const tk = (MC.capabilities||[]).filter(c=>c.tokens_per_task!=null);
    new Chart(document.getElementById('capTokens'), {
      type:'bar',
      data:{labels: tk.map(c=>c.label), datasets:[{
        data: tk.map(c=>c.tokens_per_task),
        backgroundColor:'#f0c14acc', borderRadius:5, barThickness:18,
      }]},
      options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>num(c.raw)+' tokens/tarefa'}}},
        scales:{x:{title:{display:true,text:'Tokens por tarefa (menor = mais eficiente)',color:'#8a93a3'},ticks:{color:'#8a93a3',callback:v=>num(v)},grid:{color:'#1b2030'}},y:{ticks:{color:'#c9d0dc',font:{size:12}},grid:{display:false}}}}
    });
  }

  const mean = arr => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : null;

  // Preferir fatos canônicos do builder (model_matrix.rows + facts).
  const matrixRows = (D.model_matrix && Array.isArray(D.model_matrix.rows)) ? D.model_matrix.rows : [];
  const canonicalSingle = (D.model_matrix?.mode === 'single_model_battery' && matrixRows.length === 1)
    ? matrixRows[0] : null;

  let leaderboard;
  if (canonicalSingle && canonicalSingle.model_id) {
    leaderboard = [{
      model_id: canonicalSingle.model_id,
      bare_all: canonicalSingle.bare_intelligence,
      bare_paired: canonicalSingle.bare_on_paired,
      atlas_paired: canonicalSingle.atlas_intelligence,
      delta: canonicalSingle.delta_intelligence,
      pairCount: canonicalSingle.pairs_valid ?? 0,
      pairLabel: canonicalSingle.pair_coverage || '0/0',
      bare_suites: canonicalSingle.bare_suite_count ?? 0,
      speed_ms: bare?.speed_ms ?? null,
      tokens_per_task: bare?.tokens_per_task ?? null,
      cost_per_task: bare?.cost_per_task ?? null,
      atlas_present: (canonicalSingle.pairs_valid ?? 0) > 0,
      rankScore: canonicalSingle.bare_intelligence,
      per_suite: canonicalSingle.per_suite || [],
    }];
  } else {
    const modelIds = [...new Set((D.models||[]).map(m => m.model_id).filter(Boolean))];
    leaderboard = modelIds.map(id => {
      const bareRow = (D.models||[]).find(m => m.model_id===id && m.runtime==='bare');
      const atlasRow = (D.models||[]).find(m => m.model_id===id && m.runtime==='atlas_dev');
      const bareBySuite = {};
      const atlasBySuite = {};
      (D.suite_points||[]).forEach(p => {
        if (p.model_id !== id || p.intelligence==null) return;
        if (p.runtime==='bare') bareBySuite[p.suite_id] = Number(p.intelligence);
        if (p.runtime==='atlas_dev' && p.present!==false) atlasBySuite[p.suite_id] = Number(p.intelligence);
      });
      const realFams = (D.uplift_families||[]).filter(f =>
        f.status==='real_uplift' && f.bare_intelligence!=null && f.atlas_intelligence!=null
        && (id===primary || !primary)
      );
      let barePairedVals, atlasPairedVals, pairCount, pairLabel;
      if (id === primary && realFams.length) {
        barePairedVals = realFams.map(f => Number(f.bare_intelligence));
        atlasPairedVals = realFams.map(f => Number(f.atlas_intelligence));
        pairCount = realFams.length;
        pairLabel = `${pairCount}/${(D.uplift_families||[]).length} válidos`;
      } else {
        const pairedSuites = Object.keys(bareBySuite).filter(s => atlasBySuite[s]!=null);
        barePairedVals = pairedSuites.map(s => bareBySuite[s]);
        atlasPairedVals = pairedSuites.map(s => atlasBySuite[s]);
        pairCount = pairedSuites.length;
        pairLabel = pairCount ? `${pairCount} pares` : 'sem par';
      }
      const bareAll = mean(Object.values(bareBySuite));
      const barePairedM = mean(barePairedVals);
      const atlasPairedM = mean(atlasPairedVals);
      const delta = (barePairedM!=null && atlasPairedM!=null) ? (atlasPairedM - barePairedM) : null;
      return {
        model_id: id,
        bare_all: bareAll,
        bare_paired: barePairedM,
        atlas_paired: atlasPairedM,
        delta,
        pairCount,
        pairLabel,
        bare_suites: Object.keys(bareBySuite).length,
        speed_ms: bareRow?.speed_ms ?? null,
        tokens_per_task: bareRow?.tokens_per_task ?? null,
        cost_per_task: bareRow?.cost_per_task ?? null,
        atlas_present: !!(atlasRow && atlasRow.present),
        rankScore: bareAll,
        per_suite: [],
      };
    }).sort((a,b) => (b.rankScore??-1) - (a.rankScore??-1));
  }

  const facts = D.facts || {};
  document.getElementById('factsPanel').innerHTML = `
    <h2 style="margin:0 0 6px">Fatos medidos</h2>
    <p class="hint" style="margin:0 0 10px">${facts.headline || 'Sem headline factual ainda.'}</p>
    <div class="grid g2">
      <div>
        <div class="kpi-label">Confirmado (pares reais)</div>
        <ul style="margin:8px 0 0;padding-left:18px;font-size:13px">${
          (facts.measured||[]).length
            ? (facts.measured||[]).map(x=>`<li class="${x.includes('(-')?'neg':'pos'}">${x}</li>`).join('')
            : '<li class="hint">Nenhum par bare×Atlas confirmado ainda.</li>'
        }</ul>
        ${(facts.diagnostic||[]).length?`
        <div class="kpi-label" style="margin-top:12px">Só diagnóstico — não conta como fato</div>
        <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:var(--muted)" title="Par diagnóstico: ambos os lados 0% (ninguém resolveu) ou caso excluído. Serve para investigar, nunca como sinal de que o Atlas melhora ou piora.">${
          (facts.diagnostic||[]).map(x=>`<li>${x}</li>`).join('')
        }</ul>`:''}
      </div>
      <div>
        <div class="kpi-label">Ainda incompleto</div>
        <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:var(--muted)">${
          (facts.incomplete||[]).slice(0,12).map(x=>`<li>${x}</li>`).join('')
          || '<li>Nada listado.</li>'
        }${(facts.incomplete||[]).length>12?`<li>… +${facts.incomplete.length-12} itens</li>`:''}</ul>
      </div>
    </div>`;

  const lbRows = leaderboard.map((m,i) => {
    const dCls = m.delta==null ? '' : (m.delta>=0 ? 'pos' : 'neg');
    const dTxt = m.delta==null ? '—' : ((m.delta>=0?'+':'')+pct(m.delta));
    const atlasTxt = m.atlas_paired==null
      ? '<span class="chip miss">ainda sem Atlas</span>'
      : pct(m.atlas_paired);
    return `<tr>
      <td><strong>${i+1}</strong></td>
      <td><span class="dot" style="background:${color('bare',m.model_id)}"></span><strong>${m.model_id}</strong></td>
      <td>${pct(m.bare_all)} <span class="hint">(${m.bare_suites} suites)</span></td>
      <td>${atlasTxt}${m.atlas_paired!=null?` <span class="hint">(${m.pairLabel})</span>`:''}</td>
      <td class="${dCls}">${dTxt}</td>
      <td>${m.pairLabel}</td>
      <td>${dur(m.speed_ms)}</td>
      <td>${num(m.tokens_per_task)}</td>
      <td>${money(m.cost_per_task)}</td>
    </tr>`;
  }).join('') || `<tr><td colspan="9" class="hint">Nenhum modelo ainda.</td></tr>`;

  document.getElementById('modelMatrix').innerHTML = `
    <table><thead><tr>
      <th>#</th>
      <th>Modelo</th>
      <th>Sem Atlas<br><span class="hint" style="text-transform:none;letter-spacing:0">capacidade do modelo</span></th>
      <th>Com Atlas<br><span class="hint" style="text-transform:none;letter-spacing:0">só pares válidos</span></th>
      <th>Δ Atlas</th>
      <th>Pares</th>
      <th>Velocidade</th>
      <th>Tok/tarefa</th>
      <th>Custo/tarefa</th>
    </tr></thead><tbody>${lbRows}</tbody></table>
    <p class="hint" style="margin-top:12px">Leitura: a posição (#) ordena pela capacidade <em>sem Atlas</em>. “Com Atlas” e “Δ” usam só testes com uplift real — não misturam suites incompletas. Um modelo nunca aparece duas vezes no ranking.</p>`;

  const suiteCmpSource = (leaderboard[0]?.per_suite?.length)
    ? leaderboard[0].per_suite.map(s => {
        const dossier = (D.suite_dossiers||[]).find(d=>d.suite_id===s.suite_id) || {};
        return {...s, intelligence_rate: dossier.intelligence_rate ?? null, events_complete: !!dossier.events_complete, is_atlas_fact: !!dossier.is_atlas_fact, axes: dossier.axes || null};
      })
    : (D.suites||[]).map(s => {
        const bareP = D.suite_points.find(x=>x.suite_id===s.suite_id && x.runtime==='bare' && x.model_id===(primary||x.model_id));
        const fam = (D.uplift_families||[]).find(f=>f.suite_id===s.suite_id);
        const dossier = (D.suite_dossiers||[]).find(d=>d.suite_id===s.suite_id) || {};
        const comparable = fam && fam.status==='real_uplift' && fam.atlas_intelligence!=null && fam.comparable!==false && !fam.diagnostic_only;
        return {
          suite_id: s.suite_id,
          title: s.title,
          status: s.status,
          bare_intelligence: bareP?.intelligence ?? null,
          atlas_intelligence: comparable ? fam.atlas_intelligence : null,
          delta_intelligence: comparable ? fam.delta_intelligence : null,
          uplift_status: fam?.status || 'not_applicable',
          comparable: !!comparable,
          reason: comparable ? null : (fam?.reason || fam?.status || 'not_applicable'),
          proven_pair_count: fam?.proven_pair_count ?? null,
          excluded_pair_keys: fam?.excluded_pair_keys || [],
          intelligence_rate: dossier.intelligence_rate ?? null,
          events_complete: !!dossier.events_complete,
          is_atlas_fact: !!dossier.is_atlas_fact,
          axes: dossier.axes || null,
          run_id: dossier.run_id || null,
        };
      });

  const suiteCmpRows = suiteCmpSource.map(s => {
    const title = s.title || (D.suites.find(x=>x.suite_id===s.suite_id)?.title) || s.suite_id;
    const dCls = s.delta_intelligence==null ? '' : (s.delta_intelligence>=0?'pos':'neg');
    const barBare = s.bare_intelligence==null ? 0 : Math.max(0, Math.min(100, Number(s.bare_intelligence)*100));
    const barAtlas = s.comparable && s.atlas_intelligence!=null ? Math.max(0, Math.min(100, Number(s.atlas_intelligence)*100)) : null;
    const axes = s.axes || {};
    const meas = axes.measurement?.status || '—';
    const claimOk = !!(axes.claim?.internal_ok);
    const autopsy = s.run_id ? `<code>atlas:rivals autopsy --run=${s.run_id}</code>` : '—';
    return `<tr>
      <td><strong>${title}</strong><div class="hint">${s.suite_id}</div></td>
      <td><span class="chip ${s.status==='ok'?'atlas':(s.status==='missing_data'?'miss':'na')}">${statusPt(s.status)}</span>
        ${s.is_atlas_fact?'<div class="hint">confirmado</div>':'<div class="hint">indício (não confirmado)</div>'}
      </td>
      <td>${pct(s.intelligence_rate)}
        <div class="hint">bruto ${pct(s.bare_intelligence)} (inclui falha de ambiente)</div>
      </td>
      <td>${pct(s.bare_intelligence)}
        <div style="height:6px;background:#1b2030;border-radius:4px;margin-top:4px;overflow:hidden">
          <div style="height:100%;width:${barBare}%;background:#6cb6ff"></div>
        </div>
      </td>
      <td>${s.comparable ? pct(s.atlas_intelligence) : '<span class="chip miss">—</span>'}
        ${barAtlas==null?'':`<div style="height:6px;background:#1b2030;border-radius:4px;margin-top:4px;overflow:hidden"><div style="height:100%;width:${barAtlas}%;background:#3dd68c"></div></div>`}
      </td>
      <td class="${dCls}">${s.comparable && s.delta_intelligence!=null ? ((s.delta_intelligence>=0?'+':'')+pct(s.delta_intelligence)) : '—'}
        ${s.proven_pair_count!=null?`<div class="hint">pares ${s.proven_pair_count}${s.excluded_pair_keys?.length?` · excl ${s.excluded_pair_keys.length}`:''}</div>`:''}
      </td>
      <td><span class="chip ${s.events_complete?'atlas':'miss'}">${s.events_complete?'events ok':'events gap'}</span>
        <div class="hint">meas=${meas} · claim=${claimOk?'ok':'bloqueado'}</div>
        <div class="hint">${autopsy}</div>
      </td>
      <td>${s.comparable ? '<span class="chip atlas">comparável</span>' : `<span class="chip miss">${statusPt(s.uplift_status)}</span>`}
        ${s.reason && !s.comparable ? `<div class="hint">${s.reason}</div>` : ''}
      </td>
    </tr>`;
  }).join('') || `<tr><td colspan="8" class="hint">Sem suites.</td></tr>`;

  document.getElementById('suiteCompare').innerHTML = `<table><thead><tr>
    <th>Benchmark</th><th>Execução</th><th>Sucesso (tarefas válidas)</th><th>Sucesso bruto</th><th>Com Atlas</th><th>Δ</th><th>Rastro</th><th>Comparação</th>
  </tr></thead><tbody>${suiteCmpRows}</tbody></table>
  <p class="hint" style="margin-top:8px">Sucesso (tarefas válidas) exclui as tarefas onde o ambiente falhou. Sucesso bruto inclui tudo. "Confirmado" exige repetição verificada + rastro completo.</p>`;

  const j = (v) => JSON.stringify(v ?? null, null, 2);
  const cellFmt = (cells) => (cells && cells.length)
    ? cells.map(c=>`<code>${c.suite_id}</code> ${Math.round(c.success_rate_itt*100)}%`).join(' · ')
    : '—';
  document.getElementById('modelProfiles').innerHTML = (D.model_profiles||[]).map(p => `
    <p style="margin:4px 0 10px"><strong><code>${p.model_id}</code></strong></p>
    <p class="hint">${p.narrative||''}</p>
    <table style="width:100%"><tbody>
      <tr><td style="width:130px">Forte (≥50%)</td><td>${cellFmt(p.strengths)}</td></tr>
      <tr><td>Mediano</td><td>${cellFmt(p.middle)}</td></tr>
      <tr><td>Fraco (≤20%)</td><td>${cellFmt(p.weaknesses)}</td></tr>
      <tr><td style="color:var(--warn)">Não confiável</td><td>${(p.unreliable&&p.unreliable.length)?p.unreliable.map(c=>`<code>${c.suite_id}</code> <span class="hint">${(c.unreliable_reason||'').split(':')[0]}</span>`).join(' · '):'—'}</td></tr>
      <tr><td>Δ Atlas</td><td>${(p.atlas_deltas||[]).filter(f=>f.delta_intelligence!=null).map(f=>
        `<code>${f.suite_id}</code> ${(f.delta_intelligence>=0?'+':'')}${Math.round(f.delta_intelligence*100)}pp${f.diagnostic_only?' <span class="hint">(diagnóstico)</span>':''}`
      ).join(' · ') || 'sem par provado'}</td></tr>
    </tbody></table>
    <p class="hint">"Não confiável" = execução incompleta / falha de ambiente engoliu o run (cobertura do modelo &lt; 70%). Não é fraqueza do modelo — veja os logs por unidade no dossiê da suíte (aba Dossiês).</p>
  `).join('<hr style="border-color:#222">') || '<p class="hint">Sem perfil: nenhum modelo com braço bare medido.</p>';
  const MD = D.model_dissections || {};
  const epi = MD.epistemic_contract || {};
  const comp = MD.completeness || {};
  document.getElementById('epistemicNote').textContent =
    (epi.goal || 'Honestidade absoluta sobre a realidade medida.') +
    ' absolute_knowledge_claim=false. Completo = cada faceta medida ou explicitamente desconhecida.';
  document.getElementById('dissectSummary').innerHTML = [
    ['Completude', ((comp.mean_completeness_ratio||0)*100).toFixed(0)+'%'],
    ['Presentes', (comp.dissections_present||0)+'/'+(comp.dissections_total||0)],
    ['Completos', String(comp.dissections_complete||0)],
    ['Conhecimento absoluto', 'falso'],
  ].map(([l,v])=>`<div class="card"><div class="kpi-label">${l}</div><div class="kpi">${v}</div></div>`).join('');
  document.getElementById('dissectList').innerHTML = (MD.models||[]).map(m => {
    const s = m.summary||{};
    const unk = (m.unknowns||[]).map(u=>`<li><code>${u.facet}</code> — ${u.reason}</li>`).join('') || '<li class="hint">nenhum</li>';
    const suiteRows = Object.entries(m.per_suite||{}).map(([id,row])=>`<tr>
      <td><code>${id}</code></td>
      <td><span class="chip ${row.present?'atlas':(row.status==='missing_data'?'miss':'na')}">${statusPt(row.status)}</span></td>
      <td>${pct(row.success_rate_itt)}</td>
      <td>${money(row.cost_per_task)}</td>
      <td>${num(row.tokens_per_task)}</td>
      <td>${row.tokens_per_second==null?'—':Number(row.tokens_per_second).toFixed(2)}</td>
      <td>${dur(row.median_wall_ms)}</td>
      <td>${row.missing_fields&&row.missing_fields.length?row.missing_fields.join(', '):(row.unknown_reason||'—')}</td>
    </tr>`).join('');
    return `<article class="card dossier">
      <h2 style="margin:0 0 6px">${m.model_id} <span class="chip ${m.runtime==='atlas_dev'?'atlas':'bare'}">${m.runtime==='atlas_dev'?'com Atlas':'sem Atlas'}</span>
        ${m.present?'':'<span class="chip miss">não rodou</span>'}
        <span class="chip">${Math.round((m.completeness_ratio||0)*100)}%</span>
      </h2>
      <p class="hint">${m.reality_statement||''}</p>
      <div class="kv">
        <div>Inteligência média</div><div>${pct(s.intelligence_mean_itt)}</div>
        <div>Custo/tarefa médio</div><div>${money(s.cost_per_task_mean)}</div>
        <div>Tokens médios</div><div>${num(s.tokens_mean)}</div>
        <div>Tokens/tarefa</div><div>${num(s.tokens_per_task_mean)}</div>
        <div>Tokens/s</div><div>${s.tokens_per_second_mean==null?'—':Number(s.tokens_per_second_mean).toFixed(2)}</div>
        <div>Wall médio</div><div>${dur(s.median_wall_ms_mean)}</div>
        <div>Estabilidade</div><div>${stab(s.stability_mean)}</div>
        <div>Falhas de ambiente</div><div>${s.environment_failure_events??0}</div>
        <div>Falhas de modelo</div><div>${s.model_failure_events??0}</div>
      </div>
      <h2 style="font-size:15px;margin:12px 0 6px">Desconhecidos (explícitos)</h2>
      <ul>${unk}</ul>
      <h2 style="font-size:15px;margin:12px 0 6px">Por suite (10)</h2>
      <table><thead><tr><th>Suite</th><th>Status</th><th>Sucesso</th><th>Custo</th><th>Tok/tarefa</th><th>Tok/s</th><th>Velocidade</th><th>Lacuna</th></tr></thead>
      <tbody>${suiteRows}</tbody></table>
      <h2 style="font-size:15px;margin:12px 0 6px">JSON completo</h2>
      <pre>${j(m)}</pre>
    </article>`;
  }).join('') || `<div class="empty">Sem dissecções de modelo.</div>`;

  document.getElementById('suiteCards').innerHTML = D.suites.map(s => {
    const lb = (s.leaderboard||[]).map((r,i)=>`<tr>
      <td>${i+1}</td>
      <td>${r.model_id} <span class="chip ${r.runtime==='atlas_dev'?'atlas':'bare'}">${r.runtime==='atlas_dev'?'com Atlas':'sem Atlas'}</span>
        ${r.present?'':'<span class="chip miss">não rodou</span>'}</td>
      <td>${pct(r.intelligence)}</td>
      <td>${money(r.cost_per_task)}</td>
      <td>${dur(r.speed_ms)}</td>
    </tr>`).join('');
    return `<article class="card"><div class="cat">${s.category}</div>
      <h2 style="font-size:20px;margin:0 0 4px">${s.title}</h2>
      <p class="hint">${s.blurb}</p>
      <table><thead><tr><th>#</th><th>Modelo</th><th>Sucesso</th><th>Custo</th><th>Velocidade</th></tr></thead>
      <tbody>${lb||'<tr><td colspan="5" class="hint">Sem linhas</td></tr>'}</tbody></table>
      <div class="hint" style="margin-top:8px">status <span class="chip ${s.status==='ok'?'atlas':(s.status==='missing_data'?'miss':'na')}">${statusPt(s.status)}</span>
      · run <code>${s.run_id||'—'}</code></div></article>`;
  }).join('');

  document.getElementById('dossierList').innerHTML = (D.suite_dossiers||[]).map(d => {
    const del = d.delivery || {};
    const cov = d.delivery_coverage || {};
    const fm = d.full_metrics || {};
    const dims = fm.dimensions ? Object.entries(fm.dimensions).map(([k,v])=>`${k}=${v}`).join(', ') : '—';
    const arts = Object.entries(d.artifacts||{}).filter(([,m])=>m&&m.present).map(([k])=>k);
    const missNative = (cov.native_missing||[]).join(', ') || '—';
    const missReport = (cov.report_missing||[]).slice(0,12).join(', ') || '—';
    const ev = d.execution_evidence || {};
    const cc = ev.class_counts || {};
    const relChip = d.reliable === false
      ? `<span class="chip na" title="${d.unreliable_reason||''}">não confiável · execução</span>`
      : `<span class="chip atlas">confiável</span>`;
    const esc = (t) => String(t==null?'':t).replace(/[&<>]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
    const blameChip = (b) => b==='model' ? '<span class="chip miss">modelo</span>'
      : (b==='environment_or_flow' ? '<span class="chip na">ambiente/fluxo</span>'
      : (b==='success' ? '<span class="chip atlas">ok</span>' : '<span class="chip">?</span>'));
    const unitRows = (ev.units||[]).map(u=>`
      <tr>
        <td><code>${esc(u.case_id)}</code> r${esc(u.repetition)}</td>
        <td>${blameChip(u.blame)}</td>
        <td>${esc(u.status)}${u.failure_class?` · <span class="hint">${esc(u.failure_class)}</span>`:''}</td>
        <td>exit ${esc(u.exit_code)}${u.exit_nonzero_promoted?' <span class="hint">(promovido)</span>':''} · ${dur(u.wall_ms)}</td>
      </tr>
      ${u.stderr_tail?`<tr><td colspan="4"><details><summary class="hint">stderr (tail) · ${esc((u.stderr_log||'').split('/').pop())}</summary><pre style="max-height:200px;overflow:auto">${esc(u.stderr_tail)}</pre></details></td></tr>`:''}
    `).join('');
    const evidenceBlock = ev.units_recorded==null ? '' : `
      <h2 style="font-size:15px;margin:14px 0 6px">Logs & evidência — falha do modelo vs do teste/ambiente</h2>
      <p class="hint">Cobertura do modelo <strong>${pct(ev.model_coverage)}</strong> · unidades ${ev.units_recorded||0}${ev.units_missing?` <span style="color:var(--bad)">(${ev.units_missing} não registradas!)</span>`:''} · sucessos ${cc.success||0} · falha do modelo ${(cc.model_failure||0)+(cc.invalid_result||0)} · ambiente/fluxo ${(cc.environment_failure||0)+(cc.timeout||0)}${d.reliable===false?` · <strong style="color:var(--warn)">${esc(d.unreliable_reason)}</strong>`:''}</p>
      <table style="width:100%"><thead><tr><th>Unidade</th><th>Culpa</th><th>Status</th><th>Saída · duração</th></tr></thead><tbody>${unitRows||'<tr><td colspan=4 class="hint">sem unidades</td></tr>'}</tbody></table>`;
    return `<article class="card dossier">
      <div class="cat">${d.category||''}</div>
      <h2 style="margin:0 0 6px">${d.title||d.suite_id}
        <span class="chip ${d.status==='ok'?'atlas':(d.status==='missing_data'?'miss':'na')}">${statusPt(d.status)}</span>
        ${relChip}
      </h2>
      <p class="hint">${del.purpose||''}</p>
      <div class="kv">
        <div>Origem</div><div>${del.origin||'—'}</div>
        <div>Casos</div><div>${(d.case_ids||[]).map(c=>`<code>${c}</code>`).join(' ')||'—'}</div>
        <div>Cobertura</div><div>nativo ${cov.native_observed||0}/${cov.native_expected||0} · relatório ${cov.report_observed||0}/${cov.report_expected||0}</div>
        <div>Nativo faltando</div><div class="miss-list">${missNative}</div>
        <div>Relatório faltando</div><div class="miss-list">${missReport}</div>
        <div>Inteligência</div><div>${pct(d.success_rate_itt)} · wall ${dur(d.median_wall_ms)} · custo ${money(d.cost_per_task)} · env ${pct(d.env_failure_rate)}</div>
        <div>Tokens</div><div>in/out ${num(d.tokens_in_avg)}→${num(d.tokens_out_avg)} · /tarefa ${num(d.tokens_per_task)} · /s ${d.tokens_per_second==null?'—':Number(d.tokens_per_second).toFixed(2)}</div>
        <div>Dimensões</div><div>${dims}</div>
        <div>Artefatos</div><div>${arts.length?arts.map(a=>`<code>${a}</code>`).join(' '):'—'}</div>
        <div>Run</div><div><code>${d.run_id||'—'}</code></div>
      </div>
      ${evidenceBlock}
      <h2 style="font-size:15px;margin:14px 0 6px">Métricas completas (full_metrics)</h2>
      <pre>${j(d.full_metrics)}</pre>
      <h2 style="font-size:15px;margin:14px 0 6px">Sinais do benchmark nativo (native_signals)</h2>
      <pre>${j(d.native_signals)}</pre>
      <h2 style="font-size:15px;margin:14px 0 6px">Linhas do relatório por tarefa (report_rows)</h2>
      <pre>${j(d.report_rows)}</pre>
    </article>`;
  }).join('') || `<div class="empty">Sem dossiês.</div>`;

  const fam = (D.uplift_families||[]).map(f => {
    const d = f.delta_intelligence;
    const cls = d==null?'':(d>=0?'pos':'neg');
    const dt = d==null?'sem par':((d>=0?'+':'')+pct(d));
    return `<tr><td>${f.label||f.family}</td><td>${f.suite_id}</td><td>${statusPt(f.status)}</td>
      <td>${pct(f.bare_intelligence)}</td><td>${pct(f.atlas_intelligence)}</td>
      <td class="${cls}">${dt}</td>
      <td>${money(f.bare_cost)}</td><td>${money(f.atlas_cost)}</td>
      <td><code>${f.run_id||'—'}</code></td></tr>`;
  }).join('');
  document.getElementById('upliftPanel').innerHTML = `
    <h2>Ganho com Atlas — mesmas tarefas, sem e com</h2>
    <p class="hint">Mesmo modelo, mesmos casos/orçamento; só muda o runtime. Δ só conta quando os dois braços existem na mesma suite.</p>
    <table style="margin-top:12px"><thead><tr>
      <th>Família</th><th>Suite</th><th>Status</th><th>Sucesso sem Atlas</th><th>Sucesso com Atlas</th><th>Δ intel</th>
      <th>Custo bare</th><th>Custo Atlas</th><th>Run</th>
    </tr></thead><tbody>${fam||'<tr><td colspan="9" class="hint">Nenhuma família uplift.</td></tr>'}</tbody></table>`;

  document.getElementById('footer').innerHTML =
    `Prontidão de pipeline (não é capacidade): ok ${D.pipeline.suites_ok} · dados incompletos ${D.pipeline.suites_missing_data} · falhou ${D.pipeline.suites_failed} · bloqueado ${D.pipeline.suites_blocked} · não rodou ${D.pipeline.suites_not_run}. `+
    `JSON canônico: <code>enterprise/report.json</code>. Agregado nunca vira claim.`;

  document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('on'));
    btn.classList.add('on');
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('on'));
    document.getElementById('tab-'+btn.dataset.tab).classList.add('on');
    try { localStorage.setItem('rivals_tab', btn.dataset.tab); } catch (e) {}
    // Canvas criado dentro de painel oculto fica 0×0 para sempre; ao abrir a
    // aba, força o Chart.js a medir de novo.
    requestAnimationFrame(() => {
      if (typeof Chart === 'undefined') return;
      document.querySelectorAll('canvas').forEach(cv => {
        const inst = Chart.getChart(cv);
        if (inst) { try { inst.resize(); } catch (e) {} }
      });
    });
  }));

  // Tela viva: restaura aba/scroll após reload e recarrega sozinha quando o
  // report.json muda de hash. live_status.json (heartbeat da bateria) vira o
  // badge no topo. Via file:// o fetch falha → cai num reload periódico.
  try {
    const savedTab = localStorage.getItem('rivals_tab');
    if (savedTab && document.querySelector(`[data-tab="${savedTab}"]`)) {
      document.querySelector(`[data-tab="${savedTab}"]`).click();
    }
    const savedScroll = Number(localStorage.getItem('rivals_scroll') || 0);
    if (savedScroll > 0) requestAnimationFrame(() => window.scrollTo(0, savedScroll));
  } catch (e) {}
  const saveScroll = () => { try { localStorage.setItem('rivals_scroll', String(window.scrollY)); } catch (e) {} };
  window.addEventListener('scroll', saveScroll, {passive: true});

  const badge = document.getElementById('liveBadge');
  let fetchWorks = true;
  async function livePoll() {
    try {
      const ls = await fetch('live_status.json', {cache: 'no-store'});
      if (ls.ok) {
        const s = await ls.json();
        const fresh = s.updated_at && (Date.now() - Date.parse(s.updated_at)) < 30*60*1000;
        if (s.status === 'running' && fresh) {
          badge.style.display = '';
          badge.style.color = 'var(--atlas)';
          badge.textContent = `bateria ${s.kind||''} em andamento · ${s.current_suite||''} (${s.suite_position||''})`;
        } else {
          badge.style.display = 'none';
        }
      }
      const rj = await fetch('report.json', {cache: 'no-store'});
      if (rj.ok) {
        const fresh = await rj.json();
        if (fresh.report_hash && D.report_hash && fresh.report_hash !== D.report_hash) {
          saveScroll();
          location.reload();
        }
      }
    } catch (e) {
      if (fetchWorks) {
        // file:// ou servidor fora: sem como detectar mudança → reload periódico.
        fetchWorks = false;
        setInterval(() => { saveScroll(); location.reload(); }, 180000);
      }
    }
  }
  livePoll();
  setInterval(livePoll, 30000);
})();
</script>
</body>
</html>
HTML;

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
