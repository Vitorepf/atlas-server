<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * AdUptimeSignal — the ad-stage UPTIME intelligence (NOT a moral/compliance brake on the engine). A
 * Google Search ad only earns money while it is APPROVED and serving; assets that trip a known
 * disapproval category never run, so the spend they were meant to capture is lost. This deterministically
 * scans the forged RSA assets (headlines + descriptions) and SURFACES which ones carry a disapproval risk
 * and why — it informs, it does not refuse. The aggressive forged base stays untouched; the operator
 * layers the review-safe choice on top (the same Base→white pattern), now with the engine telling him
 * exactly which assets are the liability.
 *
 * Categories are GROUNDED in real disapprovals the operator hit:
 *  - restricted_drug_term (HIGH): naming a restricted GLP-1 drug brand (Ozempic, Wegovy, semaglutide…) —
 *    Google flags the drug NAME even in a "without X" framing.
 *  - bandwagon_clickbait (MEDIUM): "X are switching to…", "everyone is…", "doctors hate…" — Google's
 *    clickbait filter. (The operator's "women over 40 are switching" RSA was disapproved for exactly this.)
 *
 * Extensible: a new disapproval pattern plugs into one CATEGORIES list and is surfaced immediately.
 * Provider-free, deterministic. Surfaces (never blocks) — compliance stays the operator's call.
 */
class AdUptimeSignal
{
    /** Restricted GLP-1 / weight-loss drug brands + molecules — naming them risks a "Restricted drug terms" disapproval. */
    private const RESTRICTED_DRUGS = [
        'ozempic', 'wegovy', 'mounjaro', 'zepbound', 'saxenda', 'rybelsus', 'trulicity', 'victoza',
        'semaglutide', 'semaglutida', 'tirzepatide', 'tirzepatida', 'retatrutide', 'retatrutida', 'liraglutide', 'liraglutida',
    ];

    /** Bandwagon / curiosity-gap clickbait phrasings Google's clickbait filter disapproves. */
    private const BANDWAGON = [
        'are switching', 'is switching', 'switching to', 'everyone is', "everyone's", 'everyones',
        'doctors hate', 'you won\'t believe', 'you wont believe', 'this is why everyone', 'what doctors',
        'estão trocando', 'estao trocando', 'todo mundo está', 'todo mundo esta', 'médicos odeiam', 'medicos odeiam',
        'você não vai acreditar', 'voce nao vai acreditar', 'estão aderindo', 'estao aderindo',
    ];

    /** @var array<string,array{markers:array<int,string>,risk:string,why:string,fix:string}> */
    private const CATEGORIES = [
        'restricted_drug_term' => [
            'markers' => self::RESTRICTED_DRUGS,
            'risk' => 'high',
            'why' => 'Nomear uma droga GLP-1 restrita (mesmo em "sem X") dispara "Restricted drug terms" — o anúncio é reprovado e não roda.',
            'fix' => 'Troque o nome da droga pelo GENÉRICO da categoria ("a injeção semanal", "the weekly shot", "as injeções") — mantém o ângulo "sem injeção" e passa na revisão.',
        ],
        'bandwagon_clickbait' => [
            'markers' => self::BANDWAGON,
            'risk' => 'medium',
            'why' => 'Frase bandwagon/curiosity-gap ("X estão trocando", "todo mundo…") cai no filtro de clickbait do Google.',
            'fix' => 'Reformule pro benefício/mecanismo concreto ("Feito Pra Mulheres 40+", "Gotas, Não Injeção") — sem "estão trocando/todo mundo".',
        ],
    ];

    /**
     * @param  array<int,string>  $headlines
     * @param  array<int,string>  $descriptions
     * @return array{risk:string,flagged:array<int,array{asset:string,slot:string,category:string,trigger:string,risk:string,fix:string}>,counts:array<string,int>,clean:bool,note:string}
     */
    public function assess(array $headlines, array $descriptions): array
    {
        $flagged = [];
        $counts = ['restricted_drug_term' => 0, 'bandwagon_clickbait' => 0];

        foreach ([['slot' => 'headline', 'items' => $headlines], ['slot' => 'description', 'items' => $descriptions]] as $group) {
            foreach ((array) $group['items'] as $asset) {
                $asset = (string) $asset;
                $hay = mb_strtolower($asset);
                foreach (self::CATEGORIES as $cat => $spec) {
                    foreach ($spec['markers'] as $m) {
                        if ($m !== '' && str_contains($hay, mb_strtolower($m))) {
                            $flagged[] = [
                                'asset' => $asset,
                                'slot' => $group['slot'],
                                'category' => $cat,
                                'trigger' => $m,
                                'risk' => $spec['risk'],
                                'fix' => $spec['fix'],
                            ];
                            $counts[$cat]++;
                            break; // one flag per category per asset
                        }
                    }
                }
            }
        }

        $risk = $counts['restricted_drug_term'] > 0 ? 'high'
            : ($counts['bandwagon_clickbait'] > 0 ? 'medium' : 'low');

        return [
            'risk' => $risk,
            'flagged' => $flagged,
            'counts' => $counts,
            'clean' => $flagged === [],
            'note' => $flagged === []
                ? 'Nenhum gatilho de reprovação conhecido nos assets — devem rodar.'
                : count($flagged).' asset(s) com risco de reprovação ('.$risk.'). Uptime = ROI: asset reprovado não roda. Surface, não bloqueio — você decide trocar pelo review-safe (mantendo o ângulo).',
        ];
    }
}
