<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ValueEquationAuditor — Hormozi's Value Equation as a structural-COMPLETENESS check (Eixo 5).
 *
 * Value = (Dream Outcome × Perceived Likelihood) / (Time Delay × Effort & Sacrifice). A grand-slam offer
 * pushes all four levers. This does NOT grade offer quality (that would be a vocabulary proxy — see the
 * cycles 43-45 meta-lesson); it checks, as a FACT, whether the copy ADDRESSES each of the four levers and
 * surfaces the GAPS. A page that never states a timeframe, or never collapses effort, has a real hole —
 * the reader's unanswered "how long / how hard" objection. Presence-completeness is structural truth, not
 * a quality score. Highest-leverage gaps (likelihood + effort) are flagged first. Provider-free, niche-agnostic.
 */
class ValueEquationAuditor
{
    /** Each lever: key, what it answers, direction, and the markers that show the copy ADDRESSES it. */
    private const LEVERS = [
        [
            'key' => 'dream_outcome', 'name' => 'Dream outcome (↑)', 'asks' => 'o resultado dos sonhos está pintado vívido?',
            'markers' => ['imagine', 'finally', 'transform', 'dream', 'wake up', 'picture', 'become', 'never again',
                'imagine', 'finalmente', 'transforme', 'sonho', 'acordar', 'se veja', 'imagina', 'nunca mais', 'a vida que'],
        ],
        [
            'key' => 'perceived_likelihood', 'name' => 'Perceived likelihood (↑)', 'asks' => 'por que VAI funcionar pra MIM (prova/garantia/risco)?',
            'markers' => ['guarantee', 'money-back', 'study', 'proven', 'results', 'testimonial', 'track record', 'as seen on', 'risk-free',
                'garantia', 'reembolso', 'estudo', 'comprovado', 'resultados', 'depoimento', 'sem risco', 'funciona mesmo'],
        ],
        [
            'key' => 'time_delay', 'name' => 'Time delay (↓)', 'asks' => 'em quanto tempo eu vejo resultado?',
            'markers' => ['in days', 'in weeks', 'in just', 'fast', 'overnight', 'immediately', 'starting today', 'within',
                'em dias', 'em semanas', 'em apenas', 'rápido', 'da noite pro dia', 'imediatamente', 'já a partir', 'em poucos'],
        ],
        [
            'key' => 'effort_sacrifice', 'name' => 'Effort & sacrifice (↓)', 'asks' => 'o quão FÁCIL é (sem dor/esforço/abrir mão)?',
            'markers' => ['no diet', 'no gym', 'effortless', 'simple', 'just take', 'without', 'no willpower', 'minutes a day', 'easy',
                'sem dieta', 'sem academia', 'sem esforço', 'simples', 'basta', 'sem abrir mão', 'minutos por dia', 'fácil'],
        ],
    ];

    /** These two levers are usually the conversion bottleneck — a gap here hurts most (Hormozi). */
    private const HIGH_LEVERAGE = ['perceived_likelihood', 'effort_sacrifice'];

    /**
     * @return array{covered:array<int,string>,gaps:array<int,array{key:string,name:string,asks:string,high_leverage:bool}>,score:int}
     */
    public function audit(string $copy): array
    {
        $text = mb_strtolower($copy);
        $covered = [];
        $gaps = [];
        foreach (self::LEVERS as $lever) {
            if ($this->hits($text, $lever['markers'])) {
                $covered[] = $lever['key'];
            } else {
                $gaps[] = [
                    'key' => $lever['key'],
                    'name' => $lever['name'],
                    'asks' => $lever['asks'],
                    'high_leverage' => in_array($lever['key'], self::HIGH_LEVERAGE, true),
                ];
            }
        }
        // High-leverage gaps first.
        usort($gaps, static fn (array $a, array $b): int => ($b['high_leverage'] ? 1 : 0) <=> ($a['high_leverage'] ? 1 : 0));

        return ['covered' => $covered, 'gaps' => $gaps, 'score' => (int) round(count($covered) / count(self::LEVERS) * 100)];
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function hits(string $text, array $markers): bool
    {
        foreach ($markers as $m) {
            if ($m !== '' && str_contains($text, $m)) {
                return true;
            }
        }

        return false;
    }
}
