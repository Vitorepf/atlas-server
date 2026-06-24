<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordPainModifierSignal — a regra #7 da dissecação: um qualificador de DOR específica/clínica/commited
 * multiplica o CVR 7-13× mesmo carregando um sufixo de informação. "bariatric gelatin recipe" 14.37% é 7× as
 * outras recipes (~2%); "bariatric gelatin for weight loss" 26.14% bate a maioria dos coined. O modificador
 * de dor revela um buscador com relação ESPECÍFICA e COMPROMETIDA com o problema (cirúrgico/clínico/meta
 * explícita) — intenção muito maior que a curiosidade genérica.
 *
 * Compõe com o KeywordSuffixGate: "recipe" rebaixa (×0.5), o pain-modifier resgata parcial (×1.4) → net ~0.7,
 * acima da recipe pura (×0.5) — exatamente o que a venda real mostra. Provider-free, determinístico.
 */
class KeywordPainModifierSignal
{
    /** frases de dor/meta/audiência comprometida (match por substring de palavra). */
    private const PHRASES = [
        'for weight loss', 'for fat loss', 'to lose weight', 'for diabetics', 'for diabetes',
        'for seniors', 'for men over', 'for women over', 'medical grade', 'prescription strength',
        'clinically proven', 'doctor recommended', 'fda approved',
    ];

    /** palavras clínicas/severidade (match palavra-inteira). */
    private const WORDS = [
        'bariatric', 'clinical', 'surgical', 'prescription', 'chronic', 'severe', 'menopausal',
        'postmenopausal', 'diabetic', 'hormonal', 'metabolic',
    ];

    private const LIFT = 1.4;

    /**
     * @return array{has_pain_modifier:bool,matched:array<int,string>,multiplier:float}
     */
    public function assess(string $keyword): array
    {
        $k = ' '.mb_strtolower(trim(preg_replace('/\s+/', ' ', $keyword))).' ';
        $matched = [];

        foreach (self::PHRASES as $p) {
            if (str_contains($k, ' '.$p.' ') || str_contains($k, ' '.$p)) {
                $matched[] = $p;
            }
        }
        foreach (self::WORDS as $w) {
            if (str_contains($k, ' '.$w.' ')) {
                $matched[] = $w;
            }
        }

        $has = $matched !== [];

        return [
            'has_pain_modifier' => $has,
            'matched' => array_values(array_unique($matched)),
            'multiplier' => $has ? self::LIFT : 1.0,
        ];
    }
}
