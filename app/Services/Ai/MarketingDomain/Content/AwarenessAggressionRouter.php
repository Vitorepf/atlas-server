<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * AwarenessAggressionRouter — picks WHICH aggressive levers fit the reader's awareness stage (Schwartz).
 *
 * Aggression is not one-size: a cold UNAWARE reader who does not even know they have the problem will
 * bounce off scarcity/price/urgency — they need fear, story, curiosity, enemy first. A MOST-AWARE reader
 * who already wants the product just needs the deal + scarcity + a reason to act NOW. Firing the wrong
 * aggressive lever at the wrong stage kills conversion. This routes the AggressiveConversionTacticsLibrary
 * keys by awareness so the deploy hits in the right order. Deterministic, niche-agnostic, provider-free.
 */
class AwarenessAggressionRouter
{
    /** awareness stage → ordered aggressive tactic keys that fit it (highest-fit first). */
    private const ROUTE = [
        // unaware: story/enemy/dream FIRST (make them recognize the problem); fear after; NO social
        // proof (it presupposes the reader already owns the problem).
        'unaware' => ['conspiracy_enemy', 'future_pacing_vivid', 'fear_amplification', 'guilt_shame_trigger'],
        // problem_aware: the external ENEMY mobilizes (above guilt, which demobilizes).
        'problem_aware' => ['fear_amplification', 'conspiracy_enemy', 'guilt_shame_trigger', 'future_pacing_vivid', 'social_proof_pressure'],
        'solution_aware' => ['authority_borrowing', 'social_proof_pressure', 'future_pacing_vivid', 'price_anchoring_extreme', 'identity_threat'],
        'product_aware' => ['price_anchoring_extreme', 'risk_reversal_aggressive', 'manufactured_scarcity', 'rival_loss', 'identity_threat'],
        // most_aware compares the DEAL: lead with price/offer/risk-reversal; scarcity+deadline CLOSE.
        'most_aware' => ['price_anchoring_extreme', 'risk_reversal_aggressive', 'rival_loss', 'manufactured_scarcity', 'false_deadline'],
    ];

    private const RATIONALE = [
        'unaware' => 'Não sabe que tem o problema — escassez/preço espantam. Abre com medo, inimigo, prova social e história/sonho.',
        'problem_aware' => 'Sente a dor, não conhece a solução — agita medo/culpa e o vilão; ainda cedo pra oferta dura.',
        'solution_aware' => 'Sabe que há solução, compara — autoridade, prova e começo de ancoragem; planta identidade.',
        'product_aware' => 'Conhece seu produto — ancoragem extrema, reversão de risco, escassez e medo do rival fecham.',
        'most_aware' => 'Já quer — só precisa do deal e de um motivo pra agir AGORA: escassez, deadline, preço, garantia.',
    ];

    public function normalize(string $awareness): string
    {
        $a = mb_strtolower(trim($awareness));

        return match (true) {
            $a === '' => 'problem_aware',                                    // sane default for cold DR traffic
            str_contains($a, 'unaware') || str_contains($a, 'inconsciente') => 'unaware',
            str_contains($a, 'most') || str_contains($a, 'mais consciente') => 'most_aware',
            str_contains($a, 'product') || str_contains($a, 'produto') => 'product_aware',
            str_contains($a, 'solution') || str_contains($a, 'solução') || str_contains($a, 'solucao') => 'solution_aware',
            str_contains($a, 'problem') || str_contains($a, 'problema') => 'problem_aware',
            default => 'problem_aware',
        };
    }

    /**
     * @return array<int,string> ordered aggressive tactic keys that fit the awareness stage
     */
    public function fittingTactics(string $awareness): array
    {
        return self::ROUTE[$this->normalize($awareness)];
    }

    public function rationale(string $awareness): string
    {
        return self::RATIONALE[$this->normalize($awareness)];
    }

    /**
     * Rank a set of candidate tactic keys so the awareness-fitting ones come first (stable for the rest).
     *
     * @param  array<int,string>  $keys
     * @return array<int,string>
     */
    public function prioritize(array $keys, string $awareness): array
    {
        $fit = array_flip($this->fittingTactics($awareness));
        usort($keys, static fn (string $a, string $b): int => ($fit[$a] ?? 99) <=> ($fit[$b] ?? 99));

        return $keys;
    }
}
