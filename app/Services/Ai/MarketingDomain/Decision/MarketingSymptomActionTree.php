<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * MarketingSymptomActionTree — the deterministic Stage-1 decision engine.
 *
 * Walks the funnel TOP→BOTTOM, isolates the FIRST place it breaks, and prescribes ONE
 * highest-leverage lever (never "change everything at once"). Every prescription resolves to
 * exactly one action in the operator's canonical 9-action space, names the specific skill/lever,
 * and (when relevant) the VSL anatomy block to edit. Same input → same output.
 *
 * ANTI-GOODHART GUARD: if spend hasn't reached the decision threshold, the tree returns HOLD —
 * acting on noise is the #1 affiliate mistake (testMath: never cut the test early). It optimizes
 * profit-per-unit-economics, never a proxy (CTR/QS/clicks).
 */
class MarketingSymptomActionTree
{
    /**
     * Directional benchmark floors for each funnel stage (override via $context['floors']).
     * Below the floor = the stage is the bottleneck. Sources: cxl/unbounce/jetfuel (directional).
     */
    public const DEFAULT_FLOORS = [
        'ad_ctr' => 0.03,            // search ad CTR (3%)
        'bridge_to_vsl' => 0.40,     // advertorial → VSL click (congruency)
        'vsl_watch_through' => 0.30, // reached the pitch / meaningful watch
        'checkout_rate' => 0.10,     // pitch-arrival → checkout
    ];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<string,mixed>  $funnel     measured rates/counts: ad_ctr, bridge_to_vsl,
     *                                           vsl_watch_through, checkout_rate, spend, sales,
     *                                           clicks, ctr_declining(bool)
     * @param  array<string,mixed>  $economics  output of CampaignEconomicsCalculator (optional)
     * @param  array<string,mixed>  $context    optional: floors override, geo, notes
     * @return array<string,mixed>
     */
    public function diagnose(array $funnel, array $economics = [], array $context = []): array
    {
        $floors = array_merge(self::DEFAULT_FLOORS, (array) ($context['floors'] ?? []));

        $spend = $this->num($funnel['spend'] ?? null);
        $sales = $this->num($funnel['sales'] ?? null);
        $decisionSpend = $this->num($economics['test_decision_spend'] ?? null);
        $maxCpa = $this->num($economics['max_cpa'] ?? null);
        $cpa = ($sales !== null && $sales > 0 && $spend !== null) ? $spend / $sales : null;

        // ── ANTI-GOODHART: not enough data to read → HOLD (don't act on noise). ──
        $dataSufficient = $this->isDataSufficient($spend, $sales, $decisionSpend);
        if (! $dataSufficient) {
            return [
                'data_sufficient' => false,
                'spend' => $spend,
                'decision_spend' => $decisionSpend,
                'primary' => [
                    'stage' => 'data_gathering',
                    'symptom' => 'amostra insuficiente',
                    'action' => 'hold',
                    'lever' => 'cro-tester (test math)',
                    'numeric_rule' => $this->holdRule($spend, $decisionSpend),
                    'predicted_effect' => 'evita decidir em ruído (a regra: ≥95% confiança, ~50 conv/var + 7d; nunca corte o teste cedo)',
                ],
                'diagnoses' => [],
                'note' => 'HOLD: junte dados até o decision-spend antes de mexer em qualquer alavanca.',
            ];
        }

        // ── Walk the funnel top→bottom; collect every breaking stage. ──
        $diagnoses = [];
        foreach ($this->stageEvaluators() as $stage => $evaluator) {
            if (! array_key_exists($stage, $funnel) || $funnel[$stage] === null || $funnel[$stage] === '') {
                continue; // can't diagnose what we can't measure
            }
            $measured = (float) $funnel[$stage];
            $floor = (float) $floors[$stage];
            if ($measured < $floor) {
                $diagnoses[] = array_merge(['stage' => $stage, 'measured' => $measured, 'floor' => $floor], $evaluator());
            }
        }

        // ── Economics-level diagnoses (only when the funnel itself is healthy). ──
        $economicsDiagnosis = null;
        if ($diagnoses === []) {
            $economicsDiagnosis = $this->diagnoseEconomics($cpa, $maxCpa, $sales, (bool) ($funnel['ctr_declining'] ?? false));
            if ($economicsDiagnosis !== null) {
                $diagnoses[] = $economicsDiagnosis;
            }
        }

        // Primary = the FIRST break (top of funnel wins — fix the earliest leak first).
        $primary = $diagnoses[0] ?? $this->healthyAndScaling($cpa, $maxCpa);

        return [
            'data_sufficient' => true,
            'spend' => $spend,
            'sales' => $sales,
            'cpa' => $cpa !== null ? round($cpa, 2) : null,
            'max_cpa' => $maxCpa,
            'primary' => $primary,
            'diagnoses' => $diagnoses,
            'note' => $diagnoses === []
                ? 'Funil saudável e dentro do Max CPA — decisão de ESCALA.'
                : 'Conserte a alavanca PRIMÁRIA (a quebra mais alta no funil) antes de tocar o resto.',
        ];
    }

    /**
     * One evaluator per funnel stage → the prescription when that stage breaks.
     *
     * @return array<string,callable():array<string,mixed>>
     */
    private function stageEvaluators(): array
    {
        return [
            'ad_ctr' => fn (): array => [
                'symptom' => 'CTR do anúncio baixo',
                'root_cause' => 'lead/ângulo errado pra intenção da keyword (awareness mismatch) ou headline fraca',
                'action' => MarketingPlaybook::ACTION_EDIT_BRIDGE_HEADLINE,
                'lever' => 'awareness-router + copy-framework (4U headline)',
                'vsl_block' => null,
                'numeric_rule' => 'caso a keyword e o lead estejam casados mas CTR siga baixo: testar novo ângulo; se a intenção da keyword não bate com a oferta → close_audience desse tema',
                'predicted_effect' => 'CTR sobe quando o lead casa com a consciência do tráfego (Schwartz × Great Leads)',
                'alt_action' => MarketingPlaybook::ACTION_CLOSE_AUDIENCE,
            ],
            'bridge_to_vsl' => fn (): array => [
                'symptom' => 'CTR alto no anúncio, poucos chegam na VSL (bridge→VSL baixo)',
                'root_cause' => 'congruência/message-match quebrada — a promessa do advertorial ≠ a promessa da VSL',
                'action' => MarketingPlaybook::ACTION_EDIT_BRIDGE_HEADLINE,
                'lever' => 'bridge-builder + page-architect (message-match: H1 = headline do anúncio)',
                'vsl_block' => null,
                'numeric_rule' => 'alinhar H1 da bridge à headline do anúncio e à promessa da VSL; congruência pode ~dobrar o EPC',
                'predicted_effect' => 'mais cliques qualificados chegam na VSL; CVR sobe sem mexer no tráfego',
            ],
            'vsl_watch_through' => fn (): array => [
                'symptom' => 'VSL inicia mas poucos assistem até o pitch',
                'root_cause' => 'gancho fraco (primeiros 5s) ou nível de consciência errado pro tráfego',
                'action' => MarketingPlaybook::ACTION_EDIT_HOOK,
                'lever' => 'vsl-architect (hook 5s, pattern-interrupt, double-hook)',
                'vsl_block' => 'hook',
                'numeric_rule' => 'hook com dado/claim ousado nos 5s; trocar abertura genérica por hook com dado rendeu +28% num caso',
                'predicted_effect' => 'mais retenção até o mecanismo/pitch → mais vendas pelo mesmo tráfego',
            ],
            'checkout_rate' => fn (): array => [
                'symptom' => 'assiste o pitch mas não compra (checkout baixo)',
                'root_cause' => 'oferta/prova/fechamento fracos (Value Equation) ou preço/risco mal resolvidos',
                'action' => MarketingPlaybook::ACTION_STRENGTHEN_CLOSE,
                'lever' => 'offer-doctor (Value Equation) + grand-slam-builder + pricing psych (decoy/anchor/risk-reversal)',
                'vsl_block' => 'offer',
                'numeric_rule' => 'foque o DENOMINADOR (imediato + sem esforço); empilhe bônus ancorado + garantia que inverte risco + escassez real',
                'predicted_effect' => 'CVR de checkout sobe sem mexer no tráfego (multiplicador puro de ROAS)',
            ],
        ];
    }

    /**
     * Economics branch — only when the funnel passes its floors.
     *
     * @return array<string,mixed>|null
     */
    private function diagnoseEconomics(?float $cpa, ?float $maxCpa, ?float $sales, bool $ctrDeclining): ?array
    {
        // Creative fatigue: CTR was scaling, now declining → refresh creative.
        if ($ctrDeclining) {
            return [
                'stage' => 'creative_fatigue',
                'symptom' => 'CTR em queda (fadiga de criativo) com funil saudável',
                'root_cause' => 'saturação de audiência / fadiga do criativo no escalonamento',
                'action' => MarketingPlaybook::ACTION_REFRESH_VSL,
                'lever' => 'creative-pipeline + video-ad-architect (novos hooks/ângulos)',
                'vsl_block' => 'hook',
                'numeric_rule' => 'velocidade de criativo é a alavanca nº1 de escala; gerar N hooks (HeyGen/Arcads) e rotacionar',
                'predicted_effect' => 'recupera CTR/CVR; destrava o teto de escala',
            ];
        }

        // Orders but unprofitable: CPA sustainedly above Max CPA → lower the bid ceiling.
        if ($cpa !== null && $maxCpa !== null && $maxCpa > 0 && $cpa > $maxCpa) {
            return [
                'stage' => 'unprofitable',
                'symptom' => 'vende, mas CPA real acima do Max CPA',
                'measured' => round($cpa, 2),
                'floor' => $maxCpa,
                'root_cause' => 'lance/competição altos demais pro teto econômico — Smart Bidding comprando caro',
                'action' => MarketingPlaybook::ACTION_LOWER_BID,
                'lever' => 'unit-economics (Max CPA) + bidding-strategist',
                'vsl_block' => null,
                'numeric_rule' => 'baixar o alvo tCPA/tROAS ≤ Max CPA; mudar target ≤20% por vez (não resetar o aprendizado)',
                'predicted_effect' => 'CPA volta pro teto; campanha fica lucrativa (ou revela que o funil precisa subir CVR)',
            ];
        }

        return null;
    }

    /**
     * Healthy funnel within Max CPA → scale decision (raise bid + open audience, safely).
     *
     * @return array<string,mixed>
     */
    private function healthyAndScaling(?float $cpa, ?float $maxCpa): array
    {
        $headroom = ($cpa !== null && $maxCpa !== null && $maxCpa > 0)
            ? 'CPA '.round($cpa, 2).' ≤ Max CPA '.$maxCpa.' → há margem'
            : 'dentro do teto';

        return [
            'stage' => 'scale',
            'symptom' => 'funil saudável e lucrativo',
            'root_cause' => null,
            'action' => MarketingPlaybook::ACTION_RAISE_BID,
            'lever' => 'scale-operator (+10-20%/7-14d) + open_audience horizontal',
            'vsl_block' => null,
            'numeric_rule' => 'subir budget só +10-20% por passo, espaçado 7-14 dias (pulo grande sobe CPA 25-50% + reseta learning); escalar horizontal (novos geos/temas) em paralelo',
            'predicted_effect' => 'mais volume mantendo o CPA — escala sem quebrar o aprendizado',
            'alt_action' => MarketingPlaybook::ACTION_OPEN_AUDIENCE,
            'headroom' => $headroom,
        ];
    }

    private function isDataSufficient(?float $spend, ?float $sales, ?float $decisionSpend): bool
    {
        // A real sale is itself a strong read; otherwise require reaching the decision spend.
        if ($sales !== null && $sales >= 1) {
            return true;
        }
        if ($decisionSpend === null || $decisionSpend <= 0) {
            return true; // no economics provided → caller drives; don't block on data
        }

        return $spend !== null && $spend >= $decisionSpend;
    }

    private function holdRule(?float $spend, ?float $decisionSpend): string
    {
        if ($decisionSpend === null) {
            return 'junte ~50 conversões/variação + 7 dias antes de decidir';
        }
        $spentTxt = $spend !== null ? round($spend, 2) : '0';

        return "gasto {$spentTxt} < decision-spend {$decisionSpend}: continue juntando dados (0 venda ainda não é um veredito)";
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }
}
