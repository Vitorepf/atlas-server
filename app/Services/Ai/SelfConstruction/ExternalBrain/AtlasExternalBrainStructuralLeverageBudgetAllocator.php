<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure, deterministic budget allocator for brain-originator batches.
 *
 * Allocates the next batch budget across eight lanes:
 *   build, repair, simplify, research, verification, learning, autonomy, unblock.
 *
 * Shifts budget AWAY from build when give_back, poison, simplification debt,
 * weak evidence, autonomy debt or unblock debt trends say the highest leverage
 * is elsewhere. capability_lane_percentages re-expresses the same shares under
 * the canonical simplification/proof/autonomy/unblock/learning/additive_capability
 * vocabulary demanded of every originator-effort allocator.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainStructuralLeverageBudgetAllocator
{
    public const SCHEMA = 'atlas.external_brain.structural_leverage_budget_allocator.v1';

    public const LANES = ['build', 'repair', 'simplify', 'research', 'verification', 'learning', 'autonomy', 'unblock'];

    /** AC3: additive (build) work is capped once proof or simplification debt is high. */
    private const ADDITIVE_CAP_WHEN_DEBT_HIGH = 20;

    /**
     * @param  array{
     *     give_back_rate?:float,
     *     poison_rate?:float,
     *     simplification_debt?:float,
     *     research_freshness?:float,
     *     proof_freshness?:float,
     *     build_demand?:float,
     *     evidence_strength?:float,
     *     candidate_leverage_proven?:bool,
     *     autonomy_debt?:float,
     *     unblock_debt?:float,
     * }  $facts
     * @return array{
     *     schema:string,
     *     lane_percentages:array<string,int>,
     *     capability_lane_percentages:array<string,int>,
     *     rationale:array<string,string>,
     *     blocked_lanes:list<string>,
     *     next_lane_recommendation:string,
     * }
     */
    public function allocate(array $facts): array
    {
        $giveBackRate = $this->clamp($facts['give_back_rate'] ?? 0.0);
        $poisonRate = $this->clamp($facts['poison_rate'] ?? 0.0);
        $simpDebt = $this->clamp($facts['simplification_debt'] ?? 0.0);
        $researchFreshness = $this->clamp($facts['research_freshness'] ?? 1.0);
        $proofFreshness = $this->clamp($facts['proof_freshness'] ?? 1.0);
        $evidenceStrength = $this->clamp($facts['evidence_strength'] ?? 1.0);
        $candidateProven = (bool) ($facts['candidate_leverage_proven'] ?? false);
        $autonomyDebt = $this->clamp($facts['autonomy_debt'] ?? 0.0);
        $unblockDebt = $this->clamp($facts['unblock_debt'] ?? 0.0);

        // Start with a balanced baseline.
        $lanes = [
            'build'        => 25,
            'repair'       => 10,
            'simplify'     => 10,
            'research'     => 10,
            'verification' => 15,
            'learning'     => 10,
            'autonomy'     => 7,
            'unblock'      => 8,
        ];

        $rationale = [];
        $blocked = [];
        $evidenceRefs = [];

        // Track evidence that triggered any shift.
        $shifted = false;

        // ── AC3: cap additive (build) work when proof or simplification debt is high,
        //     BEFORE any other shift is applied — a high debt signal caps the additive
        //     ceiling itself, it doesn't just compete with other shifts for it.
        if (($simpDebt > 0.3 || $proofFreshness < 0.5) && $lanes['build'] > self::ADDITIVE_CAP_WHEN_DEBT_HIGH) {
            $lanes['build'] = self::ADDITIVE_CAP_WHEN_DEBT_HIGH;
            $rationale['build'] = "additive work capped at ".self::ADDITIVE_CAP_WHEN_DEBT_HIGH."% while proof or simplification debt is high";
            $evidenceRefs[] = $simpDebt > 0.3 ? 'simplification_debt' : 'proof_freshness';
            $shifted = true;
        }

        // ── High give_back or poison → shift to repair ─────────────────────
        $distress = max($giveBackRate, $poisonRate);
        if ($distress > 0.2) {
            $shift = min(40, (int) round($distress * 60));
            $lanes['build'] -= $shift;
            $lanes['repair'] += $shift;
            $rationale['repair'] = "give_back_rate={$giveBackRate} poison_rate={$poisonRate} → allocate {$shift}% from build to repair";
            $evidenceRefs[] = $giveBackRate > 0.2 ? 'give_back_rate' : 'poison_rate';
            $shifted = true;
        }

        // ── Simplification debt → non-zero simplify lane ───────────────────
        if ($simpDebt > 0.3) {
            $shift = min(15, (int) round($simpDebt * 20));
            $lanes['build'] -= $shift;
            $lanes['simplify'] += $shift;
            $rationale['simplify'] = "simplification_debt={$simpDebt} → allocate {$shift}% to simplify";
            $evidenceRefs[] = 'simplification_debt';
            $shifted = true;
        }

        // ── Low research freshness + unproven leverage → research only if needed ──
        if ($researchFreshness < 0.3 && $candidateProven) {
            // Leverage already proven by repo facts → don't waste on research.
            $shifted = $lanes['research'];
            $lanes['research'] = 0;
            $lanes['verification'] += $shifted;
            $rationale['research'] = "research_freshness={$researchFreshness} but leverage proven → redirect research to verification";
            $blocked[] = 'research';
            $evidenceRefs[] = 'research_freshness';
            $shifted = true;
        } elseif ($researchFreshness < 0.5) {
            $rationale['research'] = "research_freshness={$researchFreshness} → reduced research allocation";
            $shift = 5;
            $lanes['research'] -= $shift;
            $lanes['build'] += $shift;
            $evidenceRefs[] = 'research_freshness';
            $shifted = true;
        }

        // ── Weak evidence → shift to verification ──────────────────────────
        if ($evidenceStrength < 0.4) {
            $shift = 10;
            $lanes['build'] -= $shift;
            $lanes['verification'] += $shift;
            $rationale['verification'] = "evidence_strength={$evidenceStrength} → allocate {$shift}% to verification";
            $evidenceRefs[] = 'evidence_strength';
            $shifted = true;
        }

        // ── Stale proof freshness → non-zero verification (proof) lane ─────
        if ($proofFreshness < 0.5) {
            $shift = min(15, (int) round((1.0 - $proofFreshness) * 20));
            $lanes['build'] -= $shift;
            $lanes['verification'] += $shift;
            $rationale['verification'] = "proof_freshness={$proofFreshness} → allocate {$shift}% to proof/verification refresh";
            $evidenceRefs[] = 'proof_freshness';
            $shifted = true;
        }

        // ── Autonomy debt → non-zero autonomy lane (underfunded high-leverage) ──
        if ($autonomyDebt > 0.3) {
            $shift = min(15, (int) round($autonomyDebt * 20));
            $lanes['build'] -= $shift;
            $lanes['autonomy'] += $shift;
            $rationale['autonomy'] = "autonomy_debt={$autonomyDebt} → allocate {$shift}% to autonomy";
            $evidenceRefs[] = 'autonomy_debt';
            $shifted = true;
        }

        // ── Unblock debt → non-zero unblock lane (underfunded high-leverage) ──
        if ($unblockDebt > 0.3) {
            $shift = min(15, (int) round($unblockDebt * 20));
            $lanes['build'] -= $shift;
            $lanes['unblock'] += $shift;
            $rationale['unblock'] = "unblock_debt={$unblockDebt} → allocate {$shift}% to unblock";
            $evidenceRefs[] = 'unblock_debt';
            $shifted = true;
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

        // AC3: no single lane may consume the whole wave unless an emergency blocker
        // is evidenced (shifted-by-evidence lanes get a pass for their floor).
        $maxAllowedPerLane = 60;
        foreach ($lanes as $k => $v) {
            if ($v > $maxAllowedPerLane && ! $shifted) {
                $excess = $v - $maxAllowedPerLane;
                $lanes[$k] = $maxAllowedPerLane;
                // Redistribute excess to other lanes proportionally.
                $others = array_filter($lanes, static fn ($ov, $ok) => $ok !== $k && $ov < $maxAllowedPerLane, ARRAY_FILTER_USE_BOTH);
                $otherTotal = array_sum($others);
                if ($otherTotal > 0) {
                    foreach ($others as $ok => $ov) {
                        $lanes[$ok] += (int) round($excess * $ov / $otherTotal);
                    }
                }
                $rationale['max_lane_cap'] = "{$k} capped at {$maxAllowedPerLane}% — no single category consumes the whole wave";
            }
        }

        foreach (self::LANES as $lane) {
            if (! isset($rationale[$lane])) {
                $rationale[$lane] = "baseline allocation";
            }
        }

        // recommended_next_batch_shape: name the dominant lane(s) (>=25%), highest first,
        // so the originator knows what shape of work to fetch for the next batch.
        $sortedLanes = $lanes;
        arsort($sortedLanes);
        $dominantLanes = array_keys(array_filter($sortedLanes, static fn (int $pct): bool => $pct >= 25));
        $recommendedShape = $dominantLanes !== []
            ? implode('_then_', $dominantLanes).'_focused'
            : 'balanced';

        // AC4: single next-lane recommendation — the dominant lane, or the highest-funded
        // lane when nothing clears the dominance floor.
        $nextLane = $dominantLanes[0] ?? array_keys($sortedLanes)[0];

        // AC4: rebalance reason — a compact summary of why this allocation differs from baseline.
        $rebalanceReason = $shifted
            ? 'budget_rebalanced_from_baseline:'.implode(',', array_unique($evidenceRefs))
            : 'baseline_no_rebalance_needed';

        return [
            'schema'                     => self::SCHEMA,
            'lane_percentages'           => $lanes,
            'capability_lane_percentages' => $this->capabilityLaneView($lanes),
            'budget_shares'               => $this->budgetSharesView($lanes),
            'rationale'                  => $rationale,
            'blocked_lanes'              => array_values(array_unique($blocked)),
            'evidence_refs'              => array_values(array_unique($evidenceRefs)),
            'rebalance_reason'           => $rebalanceReason,
            'recommended_next_batch_shape' => $recommendedShape,
            'next_lane_recommendation'  => $nextLane,
        ];
    }

    /**
     * AC2: re-expresses lane_percentages under the canonical capability vocabulary —
     * simplification, proof, autonomy, unblock, learning, additive_capability.
     *
     * @param  array<string,int>  $lanes
     * @return array<string,int>
     */
    private function capabilityLaneView(array $lanes): array
    {
        return [
            'simplification'      => $lanes['simplify'],
            'proof'                => $lanes['verification'],
            'autonomy'             => $lanes['autonomy'],
            'unblock'              => $lanes['unblock'] + $lanes['repair'],
            'learning'             => $lanes['learning'],
            'additive_capability'  => $lanes['build'] + $lanes['research'],
        ];
    }

    /**
     * AC2: budget_shares across the five portfolio categories — proof, simplification,
     * task_fabric, model_amplifier and autonomy_runtime — matching the structural
     * leverage portfolio vocabulary that every originator-effort allocator exposes.
     *
     * @param  array<string,int>  $lanes
     * @return array<string,int>
     */
    private function budgetSharesView(array $lanes): array
    {
        return [
            'proof'             => $lanes['verification'],
            'simplification'    => $lanes['simplify'],
            'task_fabric'       => $lanes['build'] + $lanes['repair'],
            'model_amplifier'   => $lanes['research'] + $lanes['learning'],
            'autonomy_runtime'  => $lanes['autonomy'] + $lanes['unblock'],
        ];
    }

    private function clamp(mixed $v): float
    {
        return AiValueNormalizer::clampUnit((float) $v);
    }
}
