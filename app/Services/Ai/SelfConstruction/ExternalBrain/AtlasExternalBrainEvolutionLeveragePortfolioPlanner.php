<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes each task batch as a balanced portfolio across canonical
 * Autonomous OS layers instead of falling into a bug-only or template-only
 * tunnel.
 *
 * ALGORITHM:
 *   1. Tunnel-risk detection: if a single (layer, template_family) pair
 *      accounts for >= TUNNEL_RISK_SHARE of all candidates (and at least 3
 *      candidates), the batch is flagged tunnel_risk=true.
 *   2. Coverage-gap pass: a layer with a candidate carrying zero
 *      recent_commits AND high blocker_impact (>= 0.7 / true) gets its best
 *      implementable candidate force-included, with a coverage_gap:<layer>
 *      reason recorded — starved-but-important layers are never silently
 *      dropped.
 *   3. Diversity round: one highest-leverage candidate per layer is taken
 *      first, so structural leverage ranks ahead of raw count and the batch
 *      naturally spans layers before it repeats one.
 *   4. Fill round: remaining slots (up to max_batch) are filled by global
 *      leverage descending; candidates sharing the flagged tunnel-risk
 *      (layer, template_family) pair are withheld unless their leverage is
 *      high (>= 0.7), so tunnel duplicates cannot pad the batch.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainEvolutionLeveragePortfolioPlanner
{
    public const SCHEMA = 'atlas.external_brain.evolution_leverage_portfolio_planner.v1';

    public const CANONICAL_LAYERS = [
        'bug_hunt',
        'task_fabric',
        'outcome_learning',
        'simplification',
        'model_amplifier',
        'research_to_task',
        'control_plane',
    ];

    public const DEFAULT_MAX_BATCH = 8;

    private const TUNNEL_RISK_SHARE = 0.70;

    private const TUNNEL_RISK_MIN_COUNT = 3;

    private const HIGH_BLOCKER_IMPACT = 0.70;

    private const HIGH_LEVERAGE_BYPASS = 0.70;

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $options  {max_batch?: int}
     * @return array<string,mixed>
     */
    public function plan(array $candidates, array $options = []): array
    {
        $maxBatch = max(1, (int) ($options['max_batch'] ?? self::DEFAULT_MAX_BATCH));
        $candidates = array_values(array_filter($candidates, 'is_array'));

        $groupCounts = [];
        $byLayer = [];
        foreach ($candidates as $c) {
            $layer = (string) ($c['layer'] ?? '');
            $family = (string) ($c['template_family'] ?? '');
            $key = $layer.'|'.$family;
            $groupCounts[$key] = ($groupCounts[$key] ?? 0) + 1;
            $byLayer[$layer][] = $c;
        }

        $total = count($candidates);
        $tunnelRisk = false;
        $dominantKey = null;
        if ($total > 0) {
            arsort($groupCounts);
            $topKey = array_key_first($groupCounts);
            $topCount = $groupCounts[$topKey];
            if ($topCount >= self::TUNNEL_RISK_MIN_COUNT && ($topCount / $total) >= self::TUNNEL_RISK_SHARE) {
                $tunnelRisk = true;
                $dominantKey = $topKey;
            }
        }

        foreach ($byLayer as &$list) {
            usort($list, static fn (array $a, array $b): int =>
                ((float) ($b['structural_leverage'] ?? 0.0)) <=> ((float) ($a['structural_leverage'] ?? 0.0)));
        }
        unset($list);

        $selected = [];
        $selectedIds = [];
        $reasons = [];
        $withheld = [];

        // 2. Coverage-gap pass.
        foreach ($byLayer as $layer => $list) {
            $hasGap = false;
            foreach ($list as $c) {
                $recentCommits = (int) ($c['recent_commits'] ?? -1);
                $blockerRaw = $c['blocker_impact'] ?? false;
                $blockerScore = is_bool($blockerRaw) ? ($blockerRaw ? 1.0 : 0.0) : (float) $blockerRaw;
                if ($recentCommits === 0 && $blockerScore >= self::HIGH_BLOCKER_IMPACT) {
                    $hasGap = true;

                    break;
                }
            }
            if (! $hasGap) {
                continue;
            }
            foreach ($list as $c) {
                if (! (bool) ($c['implementable'] ?? true)) {
                    continue;
                }
                $id = (string) ($c['task_id'] ?? '');
                if (! isset($selectedIds[$id])) {
                    $selected[] = $c;
                    $selectedIds[$id] = true;
                    $reasons[] = "coverage_gap:{$layer}";
                }

                break;
            }
        }

        // 3. Diversity round — one best candidate per layer.
        foreach ($byLayer as $layer => $list) {
            if (count($selected) >= $maxBatch) {
                break;
            }
            foreach ($list as $c) {
                $id = (string) ($c['task_id'] ?? '');
                if (isset($selectedIds[$id]) || ! (bool) ($c['implementable'] ?? true)) {
                    continue;
                }
                $selected[] = $c;
                $selectedIds[$id] = true;

                break;
            }
        }

        // 4. Fill round — global leverage descending, tunnel duplicates withheld unless high leverage.
        $remaining = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => ! isset($selectedIds[(string) ($c['task_id'] ?? '')]),
        ));
        usort($remaining, static fn (array $a, array $b): int =>
            ((float) ($b['structural_leverage'] ?? 0.0)) <=> ((float) ($a['structural_leverage'] ?? 0.0)));

        foreach ($remaining as $c) {
            if (count($selected) >= $maxBatch) {
                break;
            }
            $id = (string) ($c['task_id'] ?? '');
            if (! (bool) ($c['implementable'] ?? true)) {
                continue;
            }
            $key = (string) ($c['layer'] ?? '').'|'.(string) ($c['template_family'] ?? '');
            $leverage = (float) ($c['structural_leverage'] ?? 0.0);
            $bypassReason = trim((string) ($c['bypass_reason'] ?? ''));
            $canBypass = $leverage >= self::HIGH_LEVERAGE_BYPASS && $bypassReason !== '';

            if ($tunnelRisk && $key === $dominantKey && ! $canBypass) {
                $withheld[] = $id;

                continue;
            }

            $selected[] = $c;
            $selectedIds[$id] = true;
        }

        $layersIncluded = array_values(array_unique(array_map(
            static fn (array $c): string => (string) ($c['layer'] ?? ''),
            $selected,
        )));
        sort($layersIncluded);

        $layerCoverage = [];
        foreach (self::CANONICAL_LAYERS as $layer) {
            $layerCoverage[$layer] = in_array($layer, $layersIncluded, true);
        }

        return [
            'schema' => self::SCHEMA,
            'batch' => array_values($selected),
            'layers_included' => $layersIncluded,
            'distinct_layer_count' => count($layersIncluded),
            'layer_coverage' => $layerCoverage,
            // Additive alias matching the caller-facing contract name exactly.
            'coverage_by_layer' => $layerCoverage,
            'tunnel_risk' => $tunnelRisk,
            'dominant_tunnel_key' => $dominantKey,
            'reasons' => $reasons,
            'withheld_low_leverage_duplicates' => $withheld,
        ];
    }
}
