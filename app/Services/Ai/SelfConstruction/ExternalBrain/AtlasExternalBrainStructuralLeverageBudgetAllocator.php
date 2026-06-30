<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, deterministic budget allocator for brain-originator batches.
 *
 * Allocates the next batch budget across six lanes:
 *   build, repair, simplify, research, verification, learning.
 *
 * Shifts budget AWAY from build when give_back, poison, simplification debt,
 * or weak evidence trends say the highest leverage is elsewhere.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainStructuralLeverageBudgetAllocator
{
    public const SCHEMA = 'atlas.external_brain.structural_leverage_budget_allocator.v1';

    public const LANES = ['build', 'repair', 'simplify', 'research', 'verification', 'learning'];

    /**
     * @param  array{
     *     give_back_rate?:float,
     *     poison_rate?:float,
     *     simplification_debt?:float,
     *     research_freshness?:float,
     *     build_demand?:float,
     *     evidence_strength?:float,
     *     candidate_leverage_proven?:bool,
     * }  $facts
     * @return array{
     *     schema:string,
     *     lane_percentages:array<string,int>,
     *     rationale:array<string,string>,
     *     blocked_lanes:list<string>,
     * }
     */
    public function allocate(array $facts): array
    {
        $giveBackRate = $this->clamp($facts['give_back_rate'] ?? 0.0);
        $poisonRate = $this->clamp($facts['poison_rate'] ?? 0.0);
        $simpDebt = $this->clamp($facts['simplification_debt'] ?? 0.0);
        $researchFreshness = $this->clamp($facts['research_freshness'] ?? 1.0);
        $evidenceStrength = $this->clamp($facts['evidence_strength'] ?? 1.0);
        $candidateProven = (bool) ($facts['candidate_leverage_proven'] ?? false);

        // Start with a balanced baseline.
        $lanes = [
            'build'        => 40,
            'repair'       => 10,
            'simplify'     => 10,
            'research'     => 15,
            'verification' => 15,
            'learning'     => 10,
        ];

        $rationale = [];
        $blocked = [];

        // ── High give_back or poison → shift to repair ─────────────────────
        $distress = max($giveBackRate, $poisonRate);
        if ($distress > 0.2) {
            $shift = min(30, (int) round($distress * 50));
            $lanes['build'] -= $shift;
            $lanes['repair'] += $shift;
            $rationale['repair'] = "give_back_rate={$giveBackRate} poison_rate={$poisonRate} → allocate {$shift}% from build to repair";
        }

        // ── Simplification debt → non-zero simplify lane ───────────────────
        if ($simpDebt > 0.3) {
            $shift = min(15, (int) round($simpDebt * 20));
            $lanes['build'] -= $shift;
            $lanes['simplify'] += $shift;
            $rationale['simplify'] = "simplification_debt={$simpDebt} → allocate {$shift}% to simplify";
        }

        // ── Low research freshness + unproven leverage → research only if needed ──
        if ($researchFreshness < 0.3 && $candidateProven) {
            // Leverage already proven by repo facts → don't waste on research.
            $shifted = $lanes['research'];
            $lanes['research'] = 0;
            $lanes['verification'] += $shifted;
            $rationale['research'] = "research_freshness={$researchFreshness} but leverage proven → redirect research to verification";
            $blocked[] = 'research';
        } elseif ($researchFreshness < 0.5) {
            $rationale['research'] = "research_freshness={$researchFreshness} → reduced research allocation";
            $shift = 5;
            $lanes['research'] -= $shift;
            $lanes['build'] += $shift;
        }

        // ── Weak evidence → shift to verification ──────────────────────────
        if ($evidenceStrength < 0.4) {
            $shift = 10;
            $lanes['build'] -= $shift;
            $lanes['verification'] += $shift;
            $rationale['verification'] = "evidence_strength={$evidenceStrength} → allocate {$shift}% to verification";
        }

        // Clamp and normalize to 100.
        foreach ($lanes as $k => $v) {
            $lanes[$k] = max(0, $v);
        }
        $total = array_sum($lanes);
        if ($total > 0) {
            $scale = 100 / $total;
            foreach ($lanes as $k => $v) {
                $lanes[$k] = (int) round($v * $scale);
            }
            // Fix rounding drift on the largest lane.
            $drift = 100 - array_sum($lanes);
            if ($drift !== 0) {
                $maxLane = array_keys($lanes, max($lanes), true)[0];
                $lanes[$maxLane] += $drift;
            }
        }

        foreach (self::LANES as $lane) {
            if (! isset($rationale[$lane])) {
                $rationale[$lane] = "baseline allocation";
            }
        }

        return [
            'schema'          => self::SCHEMA,
            'lane_percentages' => $lanes,
            'rationale'        => $rationale,
            'blocked_lanes'    => array_values(array_unique($blocked)),
        ];
    }

    private function clamp(mixed $v): float
    {
        return max(0.0, min(1.0, (float) $v));
    }
}
