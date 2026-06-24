<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordAccountRiskSignal — surfaces, per keyword, the landmines that KILL the ad account (report §6:
 * conta morta = CPA infinito). It is a SIGNAL to the operator, NEVER a freio: the engine still builds and
 * scores the keyword; this only attaches the account-death / clawback risk as information, because
 * compliance is the operator's decision ON TOP — coerente com a constituição [[atlas-no-moral-limit-in-engine]].
 *
 * It catches what KeywordQualityIndex::eliminate() does NOT: a restricted term EMBEDDED in a surviving
 * keyword ("retatrutide protocol reviews" survives — bare-drug kill only catches the lone token), a brand
 * that triggers a program clawback, a celebrity name that triggers trademark / right-of-publicity.
 *
 * Detection is structural (named-entity presence flagged by policy), not a quality-scorer — it states a
 * FACT ("this term is LegitScript-gated") and leaves the call to the operator.
 */
class KeywordAccountRiskSignal
{
    /** L0 KnowledgeCore: as leis que ESTE motor aplica (proveniência por decisão). */
    public const LAWS = ['restricted-drug-suspension'];

    /** Restricted drug terms — name in keyword/ad/landing without LegitScript certification = suspension. */
    private const RESTRICTED_DRUGS = [
        'retatrutide', 'semaglutide', 'tirzepatide', 'ozempic', 'wegovy', 'mounjaro', 'zepbound',
        'saxenda', 'victoza', 'glp-1', 'glp 1', 'hcg', 'ephedra',
    ];

    /**
     * @param  array<string,mixed>  $ctx  brand_lexicon[] (own/competitor brands), celebrity_lexicon[]
     * @return array{risk_level:string,flags:array<int,array{type:string,term:string,level:string,why:string}>,decision:string}
     */
    public function assess(string $keyword, array $ctx = []): array
    {
        $k = ' '.mb_strtolower(preg_replace('/\s+/', ' ', trim($keyword))).' ';
        $flags = [];

        foreach (self::RESTRICTED_DRUGS as $d) {
            if (str_contains($k, ' '.$d.' ') || str_contains($k, ' '.$d) || str_contains($k, $d.' ')) {
                $flags[] = [
                    'type' => 'restricted_drug',
                    'term' => $d,
                    'level' => 'high',
                    'why' => "nome de fármaco restrito ('{$d}') — sem certificação LegitScript em keyword/copy/landing = SUSPENSÃO de conta (pode escalar a domain-flagging)",
                ];
            }
        }

        foreach (array_map('strval', (array) ($ctx['brand_lexicon'] ?? [])) as $b) {
            $b = mb_strtolower(trim($b));
            if ($b !== '' && str_contains($k, $b)) {
                $flags[] = [
                    'type' => 'brand_bidding',
                    'term' => $b,
                    'level' => 'medium',
                    'why' => "marca '{$b}' na keyword — muitos programas de afiliado PROÍBEM brand-bidding por contrato (clawback retroativo da comissão + ban); checar a cláusula antes de rodar",
                ];
            }
        }

        foreach (array_map('strval', (array) ($ctx['celebrity_lexicon'] ?? [])) as $c) {
            $c = mb_strtolower(trim($c));
            if ($c !== '' && str_contains($k, $c)) {
                $flags[] = [
                    'type' => 'celebrity',
                    'term' => $c,
                    'level' => 'high',
                    'why' => "nome de celebridade '{$c}' — bid/copy com pessoa real = queixa de trademark/direito de imagem + 'unacceptable business practices' (suspensão sem aviso)",
                ];
            }
        }

        $level = 'none';
        foreach ($flags as $f) {
            if ($f['level'] === 'high') {
                $level = 'high';
                break;
            }
            $level = 'medium';
        }

        return [
            'risk_level' => $level,
            'flags' => $flags,
            'decision' => 'SINAL ao operador — NÃO é freio; o motor constrói/pontua normal, compliance é decisão dele por cima',
        ];
    }
}
