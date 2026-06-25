<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * MarketSophisticationSignal — operacionaliza a lei `schwartz-sophistication`: estima o estágio de
 * sofisticação do nicho e diz a ESTRATÉGIA de keyword. Num mercado jaded (estágio 4-5: weight loss/ED/blood
 * sugar/memory — ouviu todo claim), a keyword de benefício genérico MORREU (CVR ~1,3%); só o NOME-DE-
 * MECANISMO coined ("gelatin trick"/"orivelle", ~30%) rompe. Num nicho menos saturado, benefício direto ainda
 * funciona e o mecanismo diferencia.
 *
 * É o PORQUÊ profundo de coined>>genérico, e diz ao OS: em nicho saturado, priorize coined-mechanism/mistype/
 * descritor e trate benefício-genérico como sonda fria. Provider-free, determinístico (prior por léxico de
 * nichos sabidamente saturados — o operador pode marcar o estágio à mão).
 */
class MarketSophisticationSignal
{
    /** nichos sabidamente JADED (estágio 4-5) — décadas de claims, mercado descrente. */
    private const SATURATED = [
        'weight loss', 'fat loss', 'lose weight', 'emagrec', 'bariatric', 'keto', 'diet',
        'erectile', 'ed ', 'libido', 'testosterone',
        'blood sugar', 'diabet', 'glucose', 'a1c',
        'tinnitus', 'hearing', 'memory', 'brain', 'cognit', 'alzhe',
        'prostate', 'hair loss', 'hair growth', 'anti aging', 'anti-aging', 'wrinkle',
        'nail fungus', 'toenail', 'fungus', 'joint pain', 'arthritis',
    ];

    /**
     * @return array{stage:int,saturated:bool,strategy:string,why:string}
     */
    public function assess(string $niche): array
    {
        $n = ' '.mb_strtolower(trim($niche)).' ';
        $saturated = false;
        foreach (self::SATURATED as $s) {
            if (str_contains($n, $s)) {
                $saturated = true;
                break;
            }
        }

        if ($saturated) {
            return [
                'stage' => 5,
                'saturated' => true,
                'strategy' => 'coined_mechanism_mandatory',
                'why' => 'nicho jaded (estágio 4-5): benefício genérico MORREU (CVR ~1,3%, mercado descrente). PRIORIZE coined-mechanism + mistype + descritor + celebridade (~30% CVR); trate benefício-genérico como sonda fria. Pra fabricar demanda, coine um mecanismo NOVO (moat).',
            ];
        }

        return [
            'stage' => 2,
            'saturated' => false,
            'strategy' => 'direct_benefit_plus_mechanism',
            'why' => 'nicho menos saturado: benefício direto ainda converte; introduza um MECANISMO pra diferenciar e blindar contra os copiadores (estágio 3). Coined ainda é o ativo de longo prazo (moat).',
        ];
    }
}
