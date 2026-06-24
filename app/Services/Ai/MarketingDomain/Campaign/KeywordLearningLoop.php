<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * KeywordLearningLoop — closes the Keyword OS flywheel: real campaign outcomes (keyword → clicks / cost
 * / conversions / revenue) are turned into a Bayesian-shrunk "proven lift" per family + per root that
 * feeds back into the KeywordQualityIndex, so keywords/families that ACTUALLY sold rise and proven
 * money-losers fall — automatically, the more spend data accrues. This is what makes the OS get sharper
 * with use instead of staying frozen at launch heuristics. Pure PHP; deterministic given the outcomes.
 */
class KeywordLearningLoop
{
    /** Pseudo-cost prior (Bayesian shrink): how much "trust the launch heuristic" before data wins. */
    private const PRIOR_COST = 60.0;

    /**
     * @param  array<int,array<string,mixed>>  $outcomes  [{keyword, family?, clicks, cost, conversions, revenue}]
     * @param  array<string,mixed>  $opts  target_roas (default 1.0), kill_cost_floor (no-sale spend → demote)
     * @return array{proven_lift:array<string,float>,promote:array<int,array<string,mixed>>,demote:array<int,array<string,mixed>>,proven_keywords:array<int,string>,family_perf:array<string,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function calibrate(array $outcomes, array $opts = []): array
    {
        $targetRoas = (float) ($opts['target_roas'] ?? 1.0);
        $killFloor = (float) ($opts['kill_cost_floor'] ?? 40.0);

        $byFamily = [];
        $byRoot = [];
        $promote = [];
        $demote = [];
        $proven = [];
        $totCost = 0.0;
        $totRev = 0.0;

        foreach ($outcomes as $o) {
            $kw = mb_strtolower(trim((string) ($o['keyword'] ?? '')));
            if ($kw === '') {
                continue;
            }
            $family = (string) ($o['family'] ?? 'unknown');
            $cost = (float) ($o['cost'] ?? 0);
            $rev = (float) ($o['revenue'] ?? 0);
            $conv = (int) ($o['conversions'] ?? 0);
            $totCost += $cost;
            $totRev += $rev;

            $this->acc($byFamily, $family, $cost, $rev, $conv);
            $root = $this->root($kw);
            $this->acc($byRoot, $root, $cost, $rev, $conv);

            if ($conv > 0) {
                $proven[] = $kw;
                $promote[] = ['keyword' => $kw, 'family' => $family, 'roas' => $cost > 0 ? round($rev / $cost, 2) : null, 'conversions' => $conv];
            } elseif ($cost >= $killFloor) {
                $demote[] = ['keyword' => $kw, 'family' => $family, 'cost' => round($cost, 2), 'reason' => 'spend_without_sale'];
            }
        }

        // Bayesian-shrunk lift per family + per root → bounded multiplier around the target ROAS.
        $lift = [];
        foreach ($byFamily as $f => $a) {
            $lift['family:'.$f] = $this->lift($a, $targetRoas);
        }
        foreach ($byRoot as $r => $a) {
            $lift['root:'.$r] = $this->lift($a, $targetRoas);
        }

        usort($promote, fn ($a, $b) => ($b['roas'] ?? 0) <=> ($a['roas'] ?? 0));
        usort($demote, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return [
            'proven_lift' => $lift,
            'promote' => $promote,
            'demote' => $demote,
            'proven_keywords' => array_values(array_unique($proven)),
            'family_perf' => array_map(fn ($a) => [
                'cost' => round($a['cost'], 2), 'revenue' => round($a['revenue'], 2),
                'conversions' => $a['conv'], 'roas' => $a['cost'] > 0 ? round($a['revenue'] / $a['cost'], 2) : null,
                'lift' => $this->lift($a, $targetRoas),
            ], $byFamily),
            'summary' => [
                'outcomes' => count($outcomes),
                'total_cost' => round($totCost, 2),
                'total_revenue' => round($totRev, 2),
                'blended_roas' => $totCost > 0 ? round($totRev / $totCost, 2) : null,
                'proven' => count(array_unique($proven)),
                'to_demote' => count($demote),
            ],
        ];
    }

    /**
     * Calibração PER-NICHE (ciclo 29) — conserta a MESMA armadilha do flywheel (ciclo 28): o family-lift
     * de `calibrate()` mistura nichos (toda nicho tem 'mechanism_trick'/'slogan'), então uma família que
     * arrasa em weight_loss e morre em tinnitus vira um lift médio enganoso. Aqui cada nicho gera seu
     * proven_lift, e o family-lift reflete o ROAS DAQUELE nicho. (O root-lift de calibrate já era niche-safe:
     * root é niche-specific.) Determinístico.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $byNiche
     * @return array{by_niche:array<string,mixed>,proven_lift_by_niche:array<string,array<string,float>>}
     */
    public function calibrateByNiche(array $byNiche, array $opts = []): array
    {
        $byNicheResult = [];
        $liftByNiche = [];
        foreach ($byNiche as $niche => $outcomes) {
            $res = $this->calibrate((array) $outcomes, $opts);
            $byNicheResult[(string) $niche] = $res;
            $liftByNiche[(string) $niche] = $res['proven_lift'];
        }

        return ['by_niche' => $byNicheResult, 'proven_lift_by_niche' => $liftByNiche];
    }

    /**
     * @param  array<string,array<string,float|int>>  $acc
     */
    private function acc(array &$acc, string $key, float $cost, float $rev, int $conv): void
    {
        if (! isset($acc[$key])) {
            $acc[$key] = ['cost' => 0.0, 'revenue' => 0.0, 'conv' => 0];
        }
        $acc[$key]['cost'] += $cost;
        $acc[$key]['revenue'] += $rev;
        $acc[$key]['conv'] += $conv;
    }

    /**
     * Bayesian-shrunk ROAS → bounded multiplier. With little spend, lift ≈ 1.0 (trust the heuristic);
     * as cost accrues, the observed ROAS pulls the multiplier toward [0.6, 1.5].
     *
     * @param  array<string,float|int>  $a
     */
    private function lift(array $a, float $targetRoas): float
    {
        $cost = (float) $a['cost'];
        $rev = (float) $a['revenue'];
        // shrink ROAS toward the target with a pseudo-cost prior priced at exactly target ROAS.
        $shrunk = ($rev + $targetRoas * self::PRIOR_COST) / ($cost + self::PRIOR_COST);
        $mult = $targetRoas > 0 ? $shrunk / $targetRoas : 1.0;

        return round(max(0.6, min(1.5, $mult)), 3);
    }

    /** Strip the trailing commercial modifier so "X reviews"/"X where to buy" share a root with "X". */
    private function root(string $kw): string
    {
        $mods = ['reviews', 'review', 'where to buy', 'does it work', 'recipe', 'official', 'cost', 'legit', 'scam', 'real or fake', 'protocol', 'drops'];
        foreach ($mods as $m) {
            if (str_ends_with($kw, ' '.$m)) {
                return trim(mb_substr($kw, 0, -mb_strlen($m) - 1));
            }
        }

        return $kw;
    }
}
