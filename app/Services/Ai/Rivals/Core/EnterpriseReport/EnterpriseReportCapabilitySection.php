<?php

namespace App\Services\Ai\Rivals\Core\EnterpriseReport;

use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;
use App\Services\Ai\Rivals\Core\StatisticalPolicy;

/**
 * Agregados de capacidade, cobertura, eixo de risco e perfil da arena. Extraido VERBATIM de EnterpriseReportBuilder (GOD-DEBULK).
 */
class EnterpriseReportCapabilitySection
{
    public function __construct(private EnterpriseReportSupport $support) {}

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    /**
     * Perfil legível por modelo: onde é forte/fraco no braço bare (por suíte
     * medida) e o que muda com Atlas (deltas das famílias de uplift). Deriva
     * SÓ do que foi medido — suíte sem dado fica fora, nunca vira zero.
     *
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @return list<array<string,mixed>>
     */
    /**
     * Agrega as suítes nas CAPACIDADES que elas medem — a visão principal.
     * Score bare = média das suítes CONFIÁVEIS da capacidade, ponderada por
     * unidades atribuíveis ao modelo. Atlas = média das suítes da capacidade
     * com par bare×Atlas medido. Eficiência (tokens/task, tempo/task) por
     * capacidade e global. Nada inventado: suíte não confiável fica fora do
     * score e é listada à parte.
     *
     * @param  list<array<string,mixed>>  $suiteRows
     * @param  array<string,mixed>  $atlasUplift
     * @return array<string,mixed>
     */
    public function buildCapabilityAggregates(array $suiteRows, array $atlasUplift, string $primaryModel): array
    {
        $bySuite = [];
        foreach ($suiteRows as $row) {
            if (is_array($row) && isset($row['suite_id'])) {
                $bySuite[(string) $row['suite_id']] = $row;
            }
        }
        // Atlas por suíte (via famílias de uplift): só o que foi realmente medido.
        $atlasBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $fam) {
            if (! is_array($fam) || ! isset($fam['suite_id'])) {
                continue;
            }
            if (is_numeric($fam['atlas_intelligence'] ?? null) && is_numeric($fam['bare_intelligence'] ?? null)) {
                $atlasBySuite[(string) $fam['suite_id']] = [
                    'atlas' => (float) $fam['atlas_intelligence'],
                    'bare' => (float) $fam['bare_intelligence'],
                    'delta' => (float) ($fam['delta_intelligence'] ?? ((float) $fam['atlas_intelligence'] - (float) $fam['bare_intelligence'])),
                    'diagnostic_only' => ($fam['diagnostic_only'] ?? false) === true,
                ];
            }
        }

        $capabilities = [];
        $globalTokens = [];
        $globalWall = [];
        foreach (EnterpriseReportBuilder::CAPABILITIES as $capId => $cap) {
            $bareNum = 0.0;
            $bareDen = 0.0;
            $tokens = [];
            $walls = [];
            $reliableSuites = [];
            $unreliableSuites = [];
            $atlasNum = 0.0;
            $atlasDen = 0.0;
            $atlasBareNum = 0.0;
            $atlasSuites = [];
            $anyDiagnostic = false;
            $subCaps = [];

            foreach ($cap['suites'] as $suiteId) {
                $row = $bySuite[$suiteId] ?? null;
                if ($row === null) {
                    continue;
                }
                $ev = (array) ($row['execution_evidence'] ?? []);
                $blame = (array) ($ev['blame_summary'] ?? []);
                $subN = (int) (($blame['model_failures'] ?? 0) + ($blame['successes'] ?? 0));
                $score = $row['intelligence_rate'] ?? $row['success_rate_itt'] ?? null;
                $rowReliable = ($row['reliable'] ?? true) === true;
                $subReason = $row['unreliable_reason_human']
                    ?? $row['unreliable_reason']
                    ?? (($row['status'] ?? '') === 'not_run' ? 'Esta suíte não foi executada nesta bateria.' : 'Sem dados registrados.');

                // Capacidade que fatia a suíte por task_type (suíte multi-domínio):
                // usa a evidência daquele domínio, com sua própria confiabilidade —
                // env-failure de gsm8k não pode reprovar mmlu, e vice-versa.
                $gradedMean = null;
                $gradedMax = null;
                if (($cap['task_types'] ?? null) !== null) {
                    $slice = $this->support->sliceByTaskTypes($ev, (array) $cap['task_types']);
                    $gradedMean = $slice['graded_mean'] ?? null;
                    $gradedMax = $slice['graded_max'] ?? null;
                    // Sem nenhuma unidade do domínio: a habilidade continua
                    // existindo e aparece como NÃO MEDIDA — sumir do card é pior
                    // que dizer "não medido", porque some sem o leitor notar.
                    $subN = $slice['tasks_decidable'] ?? 0;
                    $score = $slice['intelligence_rate'] ?? null;
                    $rowReliable = $slice['reliable'] ?? false;
                    $subReason = $slice['unreliable_reason_human']
                        ?? 'Esta bateria não registrou nenhuma tarefa desta habilidade.';
                }
                $weight = (float) ($subN ?: 1);

                // Sub-capacidade: o instrumento como habilidade nomeada própria,
                // com seu score, faixa Wilson e amostra — não some no agregado.
                $subReliable = $rowReliable && is_numeric($score);
                $subCi = ($subReliable && $subN > 0)
                    ? StatisticalPolicy::wilson((int) round((float) $score * $subN), $subN)
                    : null;
                // Rótulo por (suíte, task_type) quando a capacidade fatia; senão
                // mmlu/gpqa/gsm8k herdariam o mesmo nome (todos inspect_evals).
                $subKey = ($cap['task_types'] ?? null) !== null
                    ? $suiteId.':'.((array) $cap['task_types'])[0]
                    : $suiteId;
                $sub = EnterpriseReportBuilder::SUB_CAPABILITIES[$subKey]
                    ?? EnterpriseReportBuilder::SUB_CAPABILITIES[$suiteId]
                    ?? ['label' => $suiteId, 'measures' => ''];
                $subCaps[] = [
                    'suite_id' => $suiteId,
                    'label' => $sub['label'],
                    'measures' => $sub['measures'],
                    'bare_intelligence' => $subReliable ? round((float) $score, 4) : null,
                    'bare_ci_low' => $subCi['low'] ?? null,
                    'bare_ci_high' => $subCi['high'] ?? null,
                    'tasks_scored' => $subN,
                    'reliable' => $subReliable,
                    // Escala graduada: a nota média impede o binário de mentir
                    // por omissão ("0% acima de 7" ≠ "não sabe fazer").
                    'graded_mean' => $gradedMean,
                    'graded_max' => $gradedMax,
                    'unreliable_reason' => $subReliable ? null : $subReason,
                    'tokens_per_task' => is_numeric($row['tokens_per_task'] ?? null) ? round((float) $row['tokens_per_task']) : null,
                    'median_wall_ms' => is_numeric($row['median_wall_ms'] ?? null) ? round((float) $row['median_wall_ms']) : null,
                ];

                // $rowReliable/$score já refletem a fatia por task_type quando a
                // capacidade define uma — o agregado tem de usar a mesma base.
                if ($subReliable) {
                    $reliableSuites[] = $suiteId;
                    $bareNum += (float) $score * $weight;
                    $bareDen += $weight;
                    if (is_numeric($row['tokens_per_task'] ?? null)) {
                        $tokens[] = (float) $row['tokens_per_task'];
                        $globalTokens[] = (float) $row['tokens_per_task'];
                    }
                    if (is_numeric($row['median_wall_ms'] ?? null)) {
                        $walls[] = (float) $row['median_wall_ms'];
                        $globalWall[] = (float) $row['median_wall_ms'];
                    }
                } else {
                    $unreliableSuites[] = ['suite_id' => $suiteId, 'reason' => $row['unreliable_reason'] ?? null];
                }

                // Atlas: só suítes desta capacidade com par medido.
                if (isset($atlasBySuite[$suiteId])) {
                    $a = $atlasBySuite[$suiteId];
                    $atlasNum += $a['atlas'] * $weight;
                    $atlasBareNum += $a['bare'] * $weight;
                    $atlasDen += $weight;
                    $atlasSuites[] = $suiteId;
                    $anyDiagnostic = $anyDiagnostic || $a['diagnostic_only'];
                }
            }

            $bareScore = $bareDen > 0 ? round($bareNum / $bareDen, 4) : null;
            $atlasScore = $atlasDen > 0 ? round($atlasNum / $atlasDen, 4) : null;
            $atlasBare = $atlasDen > 0 ? round($atlasBareNum / $atlasDen, 4) : null;
            $delta = ($atlasScore !== null && $atlasBare !== null) ? round($atlasScore - $atlasBare, 4) : null;
            // Intervalo de confiança 95% (Wilson) sobre a amostra agregada: um "45%"
            // de 40 tarefas tem margem menor que de 12. Sem a banda, o número cru
            // sugere precisão que a amostra não tem. n = tarefas pontuadas.
            $bareN = (int) round($bareDen);
            $bareCi = ($bareScore !== null && $bareN > 0)
                ? StatisticalPolicy::wilson((int) round($bareScore * $bareN), $bareN)
                : null;

            $capabilities[] = [
                'id' => $capId,
                'label' => $cap['label'],
                'measures' => $cap['measures'],
                'suites_total' => count($cap['suites']),
                'suites_reliable' => count($reliableSuites),
                'reliable_suite_ids' => $reliableSuites,
                'unreliable_suites' => $unreliableSuites,
                // Tamanho da amostra: quantas tarefas realmente entraram no score.
                // Sem isto, 56% de 9 tarefas parece igual a 56% de 500.
                'tasks_scored' => (int) round($bareDen),
                'tasks_atlas_paired' => (int) round($atlasDen),
                'bare_intelligence' => $bareScore,
                'bare_ci_low' => $bareCi['low'] ?? null,
                'bare_ci_high' => $bareCi['high'] ?? null,
                'atlas_intelligence' => $atlasScore,
                'atlas_bare_baseline' => $atlasBare,
                'delta_intelligence' => $delta,
                'atlas_measured_on' => count($atlasSuites),
                'atlas_diagnostic_only' => $anyDiagnostic,
                'tokens_per_task' => $tokens === [] ? null : round(array_sum($tokens) / count($tokens)),
                'median_wall_ms' => $walls === [] ? null : round(array_sum($walls) / count($walls)),
                // Sub-capacidades: as habilidades distintas dentro do domínio, cada
                // uma um instrumento real. É aqui que "Programação" deixa de ser
                // uma caixa e vira corrigir-bug + feature + algoritmo + terminal…
                'sub_capabilities' => $subCaps,
            ];
        }

        return [
            'model_id' => $primaryModel,
            'schema' => 'capacidades = o que os benchmarks medem; suíte = instrumento (drill-down)',
            'capabilities' => $capabilities,
            // Escopo declarado: quantas habilidades a bateria realmente mediu vs
            // quantas tem instrumento, e quais domínios de capacidade de IA estão
            // fora do alcance destes 10 benchmarks. Sem isto, "Relatório de
            // Capacidades" sugere cobertura da IA inteira — e é código/agente.
            'coverage' => $this->buildCoverage($capabilities),
            'risk' => $this->buildRiskAxis($bySuite),
            'efficiency' => [
                'tokens_per_task_mean' => $globalTokens === [] ? null : round(array_sum($globalTokens) / count($globalTokens)),
                'median_wall_ms_mean' => $globalWall === [] ? null : round(array_sum($globalWall) / count($globalWall)),
                'cost_basis' => 'verboo_subscription_marginal',
                'note' => 'Custo marginal $0 (assinatura Verboo); eficiência real se lê em tokens/task e tempo/task.',
            ],
        ];
    }

    /**
     * Eixo de risco: mesma evidência por task_type das capacidades, mas com o
     * sinal INVERTIDO e declarado. Nunca entra na média de capacidade.
     *
     * @param  array<string, array<string,mixed>>  $bySuite
     * @return array<string,mixed>
     */
    /**
     * Perfil de capacidades da Arena (com-vs-sem-Atlas) — mesma fonte que o app
     * nativo consome em /arena/capabilities. Fail-open: relatório nunca morre
     * por causa do perfil (sem dado → seção vazia, nunca inventada).
     *
     * @return array<string,mixed>
     */
    public function arenaCapabilityProfile(): array
    {
        try {
            return app(\App\Services\Ai\Arena\ArenaCapabilityProfileService::class)->profile();
        } catch (\Throwable $e) {
            return ['capabilities' => [], 'unavailable_reason' => $e::class];
        }
    }

    private function buildRiskAxis(array $bySuite): array
    {
        $ev = (array) ($bySuite['inspect_evals']['execution_evidence'] ?? []);
        $items = [];
        foreach (EnterpriseReportBuilder::RISK_AXIS as $taskType => $meta) {
            $slice = $this->support->sliceByTaskTypes($ev, [$taskType]);
            $items[] = [
                'id' => $taskType,
                'label' => $meta['label'],
                'measures' => $meta['measures'],
                'higher_is_worse' => $meta['higher_is_worse'],
                'rate' => $slice['intelligence_rate'] ?? null,
                'tasks_scored' => $slice['tasks_decidable'] ?? 0,
                'reliable' => $slice['reliable'] ?? false,
                'unreliable_reason' => ($slice['reliable'] ?? false)
                    ? null
                    : ($slice['unreliable_reason_human'] ?? 'Esta bateria não registrou nenhuma tarefa deste risco.'),
            ];
        }

        return [
            'items' => $items,
            'note' => 'Eixo separado porque o sinal é INVERTIDO: aqui MAIOR = PIOR. '
                .'Estes números nunca entram na média das capacidades nem no veredito do Atlas — '
                .'somar "sabe fazer" com "sabe causar dano" produziria uma nota sem significado.',
        ];
    }

    private function buildCoverage(array $capabilities): array
    {
        $skillsWired = 0;
        $skillsMeasured = 0;
        foreach ($capabilities as $cap) {
            foreach ((array) ($cap['sub_capabilities'] ?? []) as $sub) {
                $skillsWired++;
                if (($sub['reliable'] ?? false) === true) {
                    $skillsMeasured++;
                }
            }
        }
        $domainsCovered = count(array_filter(EnterpriseReportBuilder::COVERAGE_MAP, fn (array $d): bool => $d['covered']));
        // Instrumentos parados = o tamanho REAL da lacuna. Sem este número,
        // "domínio não coberto" soa como falta de ferramenta — quando na verdade
        // a ferramenta está instalada no repo e nunca foi executada.
        $dormant = array_sum(array_column(EnterpriseReportBuilder::COVERAGE_MAP, 'dormant'));

        // DERIVADO do mapa, nunca escrito à mão. Esta frase já mentiu: dizia
        // "raciocínio, cibersegurança, recusa, moral e escrita: ZERO medição"
        // enquanto o mapa logo abaixo mostrava os cinco medidos. Prosa fixa
        // envelhece contra o dado que ela resume; texto gerado não tem como.
        $total = count(EnterpriseReportBuilder::COVERAGE_MAP);
        $missing = array_map(
            static fn (array $d): string => self::shortDomainName($d['domain']),
            array_values(array_filter(EnterpriseReportBuilder::COVERAGE_MAP, static fn (array $d): bool => ! $d['covered'])),
        );

        return [
            'skills_measured' => $skillsMeasured,
            'skills_wired' => $skillsWired,
            'domains_covered' => $domainsCovered,
            'domains_total' => $total,
            'instruments_dormant' => $dormant,
            'map' => EnterpriseReportBuilder::COVERAGE_MAP,
            'scope_note' => "Esta bateria mede {$domainsCovered} dos {$total} domínios do mapa abaixo"
                .($missing === [] ? '. ' : '. Sem nenhuma medição: '.implode(', ', $missing).'. ')
                .'Não é um retrato da capacidade geral de uma IA — e a lacuna não é falta de '
                ."ferramenta: há {$dormant} instrumentos já instalados no repo que nunca rodaram. "
                .'Cada domínio abaixo diz quantos são e por que estão parados.',
        ];
    }

    /**
     * Nome curto para prosa: o mapa usa rótulos longos com exemplos entre
     * parênteses ("Raciocínio (leitura, senso comum, multi-etapa)"), úteis na
     * tabela e ilegíveis dentro de uma frase.
     */
    private static function shortDomainName(string $domain): string
    {
        $short = trim((string) preg_replace('/\s*\(.*$/u', '', $domain));

        return $short === '' ? $domain : $short;
    }
}
