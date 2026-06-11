<?php

namespace App\Services\Ai\VentureFoundry\Assessment;

use App\Models\AiVenture;
use App\Models\AiVentureAssessmentRun;
use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureIdea;
use App\Models\AiVentureMetricObservation;
use App\Models\AiVentureQuestionAnswer;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\VentureBusinessRuleService;
use Illuminate\Support\Str;

/**
 * Answers each catalog question for a venture from the most HONEST data
 * available — comprehension findings, observed metrics, the venture's idea and
 * its business-rule canon. It never fabricates: a question it cannot answer
 * from internal data is marked blocked_internal (run a deeper scan) or
 * blocked_external (a data source must be wired — the explicit autonomy bridge).
 */
class VentureQuestionEngine
{
    public function __construct(
        private readonly VentureQuestionCatalog $catalog,
        private readonly VentureBusinessRuleService $rules,
    ) {}

    /**
     * Answer every non-focus question for the run and persist the rows.
     * (DIM_FOCUS questions are answered by the focus decider afterwards.)
     *
     * @return array<int,AiVentureQuestionAnswer>
     */
    public function answer(AiVenture $venture, AiVentureAssessmentRun $run, ?AiVentureComprehensionRun $comprehension): array
    {
        $context = $this->buildContext($venture, $comprehension);
        $stage = (string) $run->stage;

        $records = [];
        foreach ($this->catalog->forStage($stage) as $question) {
            if ($question['dimension'] === VentureQuestionCatalog::DIM_FOCUS) {
                continue; // answered by the decider once every other answer exists
            }
            $answer = $this->answerOne($question, $context);
            $records[] = $this->persist($run, $venture, $question, $answer);
        }

        return $records;
    }

    /**
     * Persist a focus/priority answer produced by the decider.
     *
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $answer
     */
    public function persistFocusAnswer(AiVentureAssessmentRun $run, AiVenture $venture, array $question, array $answer): AiVentureQuestionAnswer
    {
        return $this->persist($run, $venture, $question, $answer);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildContext(AiVenture $venture, ?AiVentureComprehensionRun $comprehension): array
    {
        $idea = $venture->idea_id !== null
            ? AiVentureIdea::query()->whereKey($venture->idea_id)->first()
            : null;

        $findings = collect();
        if ($comprehension !== null) {
            $findings = AiVentureComprehensionFinding::query()
                ->where('run_id', $comprehension->id)
                ->get();
        }

        $metrics = [];
        foreach (AiVentureMetricObservation::query()->where('venture_id', $venture->id)->orderBy('observed_at')->get() as $obs) {
            $metrics[$obs->metric_key] = (float) $obs->value;
        }

        return [
            'venture' => $venture,
            'idea' => $idea,
            'has_comprehension' => $comprehension !== null,
            'findings' => $findings,
            'metrics' => $metrics,
            'active_rules' => $this->rules->activeRules($venture),
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function answerOne(array $question, array $context): array
    {
        return match ($question['data_kind']) {
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED => $this->blockedExternal($question),
            AiVentureQuestionAnswer::DATA_INTERNAL_METRIC => $this->answerFromMetric($question, $context),
            default => $this->answerFromComprehension($question, $context),
        };
    }

    /**
     * @param  array<string,mixed>  $question
     * @return array<string,mixed>
     */
    private function blockedExternal(array $question): array
    {
        $source = (string) ($question['external_source'] ?? 'external');
        $label = VentureQuestionCatalog::EXTERNAL_SOURCES[$source] ?? $source;

        return [
            'status' => AiVentureQuestionAnswer::STATUS_BLOCKED_EXTERNAL,
            'answer' => "Não respondível com os dados internos atuais. Requer fonte externa: {$label}.",
            'confidence' => null,
            'evidence_kind' => 'none',
            'evidence_refs' => null,
            'recommendation' => "Conectar a fonte [{$source}] para Atlas responder e agir nesta pergunta de forma autônoma.",
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function answerFromMetric(array $question, array $context): array
    {
        $metrics = $context['metrics'];
        $id = $question['id'];

        $needed = match ($id) {
            'Q-MON-002' => ['ltv_cac_ratio'],
            'Q-MON-003', 'Q-GROW-002', 'Q-FOC-002' => ['arr_usd'],
            default => ['arr_usd'],
        };

        $present = array_filter($needed, fn ($k) => isset($metrics[$k]));
        if ($present === []) {
            $source = (string) ($question['external_source'] ?? '');
            if ($source !== '') {
                return $this->blockedExternal($question);
            }

            return [
                'status' => AiVentureQuestionAnswer::STATUS_BLOCKED_INTERNAL,
                'answer' => 'Sem métrica observada ('.implode(', ', $needed).'). Registre via metric-record ou conecte a fonte de pagamento.',
                'confidence' => null,
                'evidence_kind' => 'none',
                'evidence_refs' => null,
                'recommendation' => 'Registrar a métrica real ('.implode(', ', $needed).') para esta pergunta tornar-se respondível.',
            ];
        }

        $parts = [];
        $refs = [];
        foreach ($present as $k) {
            $parts[] = sprintf('%s = %s', $k, rtrim(rtrim(number_format($metrics[$k], 2, '.', ''), '0'), '.'));
            $refs[] = ['metric' => $k, 'value' => $metrics[$k]];
        }

        $answer = 'Métricas observadas: '.implode(', ', $parts).'.';
        if ($id === 'Q-MON-002' && isset($metrics['ltv_cac_ratio'])) {
            $answer .= $metrics['ltv_cac_ratio'] >= 3
                ? ' LTV/CAC saudável (≥ 3).'
                : ' LTV/CAC abaixo de 3 — economia precisa de conserto antes de escalar gasto.';
        }

        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => $answer,
            'confidence' => 0.85,
            'evidence_kind' => 'metric',
            'evidence_refs' => $refs,
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function answerFromComprehension(array $question, array $context): array
    {
        if (! $context['has_comprehension']) {
            return [
                'status' => AiVentureQuestionAnswer::STATUS_BLOCKED_INTERNAL,
                'answer' => 'Ainda não há compreensão do código desta empresa.',
                'confidence' => null,
                'evidence_kind' => 'none',
                'evidence_refs' => null,
                'recommendation' => 'Rodar `atlas:venture comprehend` para Atlas entender o produto antes de responder.',
            ];
        }

        /** @var \Illuminate\Support\Collection<int,AiVentureComprehensionFinding> $findings */
        $findings = $context['findings'];

        return match ($question['dimension']) {
            VentureQuestionCatalog::DIM_PROBLEM => $this->answerProblem($question, $context),
            VentureQuestionCatalog::DIM_USERS => $this->answerUsers($question, $context, $findings),
            VentureQuestionCatalog::DIM_PRODUCT => $this->answerProduct($question, $context, $findings),
            VentureQuestionCatalog::DIM_HEALTH => $this->answerHealth($question, $findings),
            VentureQuestionCatalog::DIM_RISK => $this->answerRisk($question, $findings),
            VentureQuestionCatalog::DIM_MONETIZATION => $this->answerMonetizationRules($question, $findings),
            VentureQuestionCatalog::DIM_EXECUTION => $this->answerExecution($question, $findings),
            VentureQuestionCatalog::DIM_GROWTH => $this->answerGrowth($question, $findings),
            default => $this->thinAnswer($question),
        };
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function answerProblem(array $question, array $context): array
    {
        $idea = $context['idea'];
        if ($idea === null) {
            return $this->blockedInternal($question, 'A venture não tem ideia ligada com problema/ICP/dor.', 'Registrar a ideia (problema, ICP, dor) da empresa.');
        }

        // Honest: an idea text states the CLAIMED problem — it is not proof the
        // problem is real, frequent and painful. That validation needs users
        // (external data), so this stays PARTIAL until validated.
        return [
            'status' => AiVentureQuestionAnswer::STATUS_PARTIAL,
            'answer' => sprintf('Problema reivindicado: %s | ICP: %s | Dor: %s. Declarado, ainda NÃO validado com usuários/evidência.', $idea->problem, $idea->icp, $idea->pain),
            'confidence' => 0.5,
            'evidence_kind' => 'inferred',
            'evidence_refs' => [['kind' => 'idea', 'id' => $idea->idea_id]],
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerUsers(array $question, array $context, $findings): array
    {
        $audience = $findings->where('capability', 'audience_usage');
        $tiers = $audience->where('kind', 'plan_tier')->pluck('title')->take(6)->all();
        $locales = $audience->where('kind', 'locale')->count();
        $idea = $context['idea'];

        if ($tiers === [] && $idea === null) {
            return $this->blockedInternal($question, 'Sem sinais de segmento/ICP no código e sem ideia ligada.', 'Rodar comprehend e registrar a ideia para mapear o ICP.');
        }

        $answer = 'ICP declarado: '.($idea?->icp ?? 'n/d').'. ';
        if ($tiers !== []) {
            $answer .= 'Segmentos pagantes (planos no código): '.implode('; ', $tiers).'. ';
        }
        if ($locales > 0) {
            $answer .= "Alcance de mercado: {$locales} idiomas detectados.";
        }

        return [
            'status' => $tiers !== [] ? AiVentureQuestionAnswer::STATUS_ANSWERED : AiVentureQuestionAnswer::STATUS_PARTIAL,
            'answer' => trim($answer),
            'confidence' => $tiers !== [] ? 0.75 : 0.5,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($audience->take(5)),
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $context
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerProduct(array $question, array $context, $findings): array
    {
        $integrations = $findings->where('capability', 'audience_usage')->where('kind', 'integration')->pluck('title')->take(8)->all();
        $venture = $context['venture'];

        $answer = 'Tese/diferencial declarado: '.Str::limit((string) $venture->thesis, 280).'. ';
        if ($integrations !== []) {
            $answer .= 'Integrações/canais que sustentam o diferencial: '.implode(', ', $integrations).'.';
        }

        return [
            'status' => AiVentureQuestionAnswer::STATUS_PARTIAL,
            'answer' => trim($answer),
            'confidence' => 0.55,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($findings->where('capability', 'audience_usage')->where('kind', 'integration')->take(5)),
            'recommendation' => 'Validar o diferencial contra concorrentes (requer inteligência de concorrência) e dobrar a aposta nele.',
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerHealth(array $question, $findings): array
    {
        $problems = $findings->where('capability', 'problem');
        if ($question['id'] === 'Q-HLT-003') {
            $perf = $findings->where('capability', 'improvement')->whereIn('category', ['performance', 'reliability']);
            if ($perf->isEmpty()) {
                return $this->answeredNegative($question, 'Nenhum gargalo claro de performance/confiabilidade detectado pelo scan atual.');
            }

            return [
                'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
                'answer' => sprintf('%d oportunidades de performance/confiabilidade para suportar escala. Topo: %s', $perf->count(), (string) $perf->first()?->title),
                'confidence' => 0.7,
                'evidence_kind' => 'observed',
                'evidence_refs' => $this->refs($perf->take(5)),
                'recommendation' => $question['decision_trigger'],
            ];
        }

        $critical = $problems->where('severity', 'critical');
        $high = $problems->where('severity', 'high');
        if ($problems->isEmpty()) {
            return $this->answeredNegative($question, 'Nenhum problema detectado pelo scan atual (ou compreensão não cobriu o caminho crítico).');
        }

        $top = $critical->isNotEmpty() ? $critical : $high;
        $sample = $top->take(3)->map(fn ($f) => sprintf('%s (%s:%s)', $f->title, $f->evidence_path, $f->evidence_line ?? '?'))->all();

        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => sprintf('%d críticos e %d high. Exemplos: %s', $critical->count(), $high->count(), implode(' | ', $sample)),
            'confidence' => 0.85,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($top->take(5)),
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerRisk(array $question, $findings): array
    {
        $secrets = $findings->where('capability', 'problem')->whereIn('kind', ['secret', 'dangerous_code']);
        if ($secrets->isEmpty()) {
            return $this->answeredNegative($question, 'Nenhum secret/credencial vazada ou construção perigosa detectada pelo scan atual.');
        }

        $sample = $secrets->take(3)->map(fn ($f) => sprintf('%s (%s:%s)', $f->title, $f->evidence_path, $f->evidence_line ?? '?'))->all();

        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => sprintf('RISCO: %d achados de segurança (secret/dangerous_code). Exemplos: %s', $secrets->count(), implode(' | ', $sample)),
            'confidence' => 0.9,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($secrets->take(5)),
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerMonetizationRules(array $question, $findings): array
    {
        $pricing = $findings->where('capability', 'business_rule')->where('category', 'pricing');
        if ($pricing->isEmpty()) {
            return $this->blockedInternal($question, 'Nenhuma regra de preço/trial minerada do código.', 'Rodar comprehend (business_rule) para extrair a precificação real.');
        }

        $sample = $pricing->sortByDesc('confidence')->take(5)->map(fn ($f) => $f->title)->all();

        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => 'Precificação observada no código: '.implode('; ', $sample),
            'confidence' => 0.8,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($pricing->sortByDesc('confidence')->take(5)),
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerExecution(array $question, $findings): array
    {
        $testGaps = $findings->where('capability', 'problem')->where('kind', 'test_gap')->count();
        $debt = $findings->where('capability', 'problem')->whereIn('kind', ['debt_marker', 'large_file'])->count();

        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => sprintf('%d lacunas de teste e %d marcadores de dívida/arquivos grandes — indicadores de velocidade futura.', $testGaps, $debt),
            'confidence' => 0.7,
            'evidence_kind' => 'observed',
            'evidence_refs' => $this->refs($findings->where('capability', 'problem')->whereIn('kind', ['test_gap', 'debt_marker', 'large_file'])->take(5)),
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<string,mixed>
     */
    private function answerGrowth(array $question, $findings): array
    {
        $problems = $findings->where('capability', 'problem')->where('severity', 'critical')->count();
        $improvements = $findings->where('capability', 'improvement')->count();

        return [
            'status' => AiVentureQuestionAnswer::STATUS_PARTIAL,
            'answer' => sprintf('Balanço interno: %d riscos críticos e %d melhorias mapeadas. A decisão vender/melhorar/corrigir é resolvida pelo decisor de foco.', $problems, $improvements),
            'confidence' => 0.6,
            'evidence_kind' => 'observed',
            'evidence_refs' => null,
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @return array<string,mixed>
     */
    private function thinAnswer(array $question): array
    {
        return $this->blockedInternal($question, 'Sem resolvedor de dados interno para esta pergunta.', $question['decision_trigger']);
    }

    /**
     * @param  array<string,mixed>  $question
     * @return array<string,mixed>
     */
    private function blockedInternal(array $question, string $answer, string $recommendation): array
    {
        return [
            'status' => AiVentureQuestionAnswer::STATUS_BLOCKED_INTERNAL,
            'answer' => $answer,
            'confidence' => null,
            'evidence_kind' => 'none',
            'evidence_refs' => null,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param  array<string,mixed>  $question
     * @return array<string,mixed>
     */
    private function answeredNegative(array $question, string $answer): array
    {
        return [
            'status' => AiVentureQuestionAnswer::STATUS_ANSWERED,
            'answer' => $answer,
            'confidence' => 0.6,
            'evidence_kind' => 'observed',
            'evidence_refs' => null,
            'recommendation' => $question['decision_trigger'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,AiVentureComprehensionFinding>  $findings
     * @return array<int,array<string,mixed>>|null
     */
    private function refs($findings): ?array
    {
        $refs = $findings->map(fn ($f) => array_filter([
            'path' => $f->evidence_path,
            'line' => $f->evidence_line,
            'finding' => $f->uuid,
        ]))->values()->all();

        return $refs === [] ? null : $refs;
    }

    /**
     * @param  array<string,mixed>  $question
     * @param  array<string,mixed>  $answer
     */
    private function persist(AiVentureAssessmentRun $run, AiVenture $venture, array $question, array $answer): AiVentureQuestionAnswer
    {
        return AiVentureQuestionAnswer::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $venture->id,
            'question_id' => $question['id'],
            'dimension' => $question['dimension'],
            'question_text' => $question['question'],
            'status' => $answer['status'],
            'answer' => $answer['answer'] ?? null,
            'confidence' => $answer['confidence'] ?? null,
            'evidence_kind' => $answer['evidence_kind'] ?? 'none',
            'evidence_refs' => $answer['evidence_refs'] ?? null,
            'data_kind' => $question['data_kind'],
            'external_source' => $question['external_source'] ?? null,
            'recommendation' => $answer['recommendation'] ?? null,
            'priority_score' => $answer['priority_score'] ?? 0,
            'severity_if_blind' => $question['severity_if_blind'],
            'answer_hash' => StrategyCanonicalHash::sha256([
                'run_id' => $run->id,
                'question_id' => $question['id'],
            ]),
        ]);
    }
}
