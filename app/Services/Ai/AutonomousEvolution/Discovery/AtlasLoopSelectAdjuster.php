<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use Throwable;

/**
 * ARBOR-GRAFT SEL1 — the SELECT term the Arbor coordinator LACKS (its SELECT is a pure-LLM free choice,
 * zero algorithm). Arbor's tree maintains COMPETING siblings; a good selector spreads exploration across
 * distinct directions instead of over-committing to one. This adjuster adds a deterministic, machine-
 * resolved DIVERSITY/NOVELTY penalty: a candidate whose touched-path/objective tokens are near-duplicates
 * of work ALREADY queued (or of a pruned/exhausted tree sibling) is de-prioritized so fresh directions
 * surface first.
 *
 * FLOOR-SAFE BY CONSTRUCTION:
 *  - It is EXPLORATION CONTROL, never a quality/cert signal (Arbor lesson 5). It only re-orders the queue;
 *    the unchanged out-of-process FrozenJudge + SemanticImplementationCertifier still certify every output.
 *  - It uses ONLY machine-resolved signals (normalized paths, queued-sibling paths, tree prune-status) —
 *    never a model self-report of value.
 *  - It only ever LOWERS within the band, sharing the SAME single clamp as the leverage offset and the
 *    work-class prior: result = band + max(0, min(BAND_WIDTH-1, offset - penalty)). No combination of the
 *    three within-band terms can cross into another shape band — SHAPE always dominates.
 *  - Flag-gated default-OFF + fail-open: any error returns the inputs unchanged (byte-identical).
 *  - Pétreo (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS): the loop can never edit its own selector.
 */
final class AtlasLoopSelectAdjuster
{
    /** MUST equal AtlasLoopNextWorkDecider::BAND_WIDTH (pinned by AtlasLoopSelectAdjusterTest). */
    public const BAND_WIDTH = 1000;

    // ── Pure core (no DB / no provider) — the unit-tested logic ────────────────────────────────

    /**
     * Normalized tokens for a candidate: path segments (sans extension) + objective words. Lowercased,
     * de-duplicated, stop-segments dropped. Deterministic.
     *
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    public static function tokens(string $targetPath, array $signals): array
    {
        $path = strtolower(trim($targetPath));
        $path = preg_replace('/\.[a-z0-9]+$/', '', $path) ?? $path;
        $parts = preg_split('/[^a-z0-9]+/', $path) ?: [];

        $objective = '';
        foreach (['objective', 'work_class', 'shape', 'reason'] as $k) {
            if (isset($signals[$k]) && is_string($signals[$k])) {
                $objective .= ' '.strtolower($signals[$k]);
            }
        }
        $objParts = preg_split('/[^a-z0-9]+/', $objective) ?: [];

        $drop = ['', 'app', 'src', 'php', 'services', 'ai', 'the', 'a', 'to', 'of', 'in', 'and'];
        $tokens = array_values(array_unique(array_filter(
            array_merge($parts, $objParts),
            static fn (string $t): bool => $t !== '' && strlen($t) > 1 && ! in_array($t, $drop, true),
        )));

        return $tokens;
    }

    /**
     * Jaccard similarity of two token sets in [0,1]. Pure.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $sa = array_unique($a);
        $sb = array_unique($b);
        $inter = count(array_intersect($sa, $sb));
        $union = count(array_unique(array_merge($sa, $sb)));

        return $union === 0 ? 0.0 : $inter / $union;
    }

    /**
     * Max similarity of the candidate to any already-present token set. 0 when there is nothing to clash
     * with (a brand-new direction). Pure.
     *
     * @param  list<string>        $candidate
     * @param  list<list<string>>  $others
     */
    public static function maxSimilarity(array $candidate, array $others): float
    {
        $max = 0.0;
        foreach ($others as $other) {
            $sim = self::jaccard($candidate, $other);
            if ($sim > $max) {
                $max = $sim;
            }
        }

        return $max;
    }

    /**
     * The within-band penalty: a near-duplicate (similarity → 1) gets up to maxFraction*offset; a novel
     * candidate (similarity → 0) gets ~0. Pure, bounded to [0, offset].
     */
    public static function penalty(float $maxSimilarity, int $offset, float $maxFraction): int
    {
        $sim = max(0.0, min(1.0, $maxSimilarity));
        $frac = max(0.0, min(1.0, $maxFraction));
        $offset = max(0, $offset);

        return (int) round($sim * $frac * $offset);
    }

    /**
     * THE single shared within-band clamp. result = band + max(0, min(BAND_WIDTH-1, offset - penalty)).
     * Composes on top of the leverage offset and the work-class prior (both also only-lower the offset),
     * so the combined position can NEVER cross into another band. Pure.
     */
    public static function applyWithinBand(int $band, int $offset, int $penalty, int $bandWidth = self::BAND_WIDTH): int
    {
        $cap = max(0, $bandWidth - 1);
        $adjusted = max(0, min($cap, $offset - max(0, $penalty)));

        return $band + $adjusted;
    }

    // ── Thin DB wrapper (delegates to the pure core) ──────────────────────────────────────────

    /**
     * Re-rank one candidate WITHIN its band against the directions already queued for the campaign (and
     * pruned/exhausted tree siblings). Returns [newPriority, receipt-fragment]. Fail-open: errors return
     * [$priority, null]. Machine-resolved only.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0:int, 1:array<string,mixed>|null}
     */
    public function adjust(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, int $band, int $offset, float $maxFraction): array
    {
        try {
            if ($offset <= 0) {
                return [$band + $offset, null];
            }

            $candTokens = self::tokens((string) $target->target_path, $signals);
            if ($candTokens === []) {
                return [$band + $offset, null];
            }

            // Already-committed directions in this campaign (queued/claimed) + pruned/exhausted tree siblings.
            $others = AtlasLoopTarget::query()
                ->where('campaign_id', $campaign->id)
                ->where('id', '!=', $target->id)
                ->where(function ($q): void {
                    $q->whereIn('status', [AtlasLoopTarget::STATUS_QUEUED, AtlasLoopTarget::STATUS_CLAIMED])
                        ->orWhereIn('tree_status', [
                            AtlasLoopIdeaTreeAccessor::STATUS_PRUNED,
                            AtlasLoopIdeaTreeAccessor::STATUS_DONE,
                        ]);
                })
                ->limit(200)
                ->get(['target_path']);

            $otherSets = [];
            foreach ($others as $o) {
                $otherSets[] = self::tokens((string) $o->target_path, []);
            }

            $maxSim = self::maxSimilarity($candTokens, $otherSets);
            $penalty = self::penalty($maxSim, $offset, $maxFraction);
            $newPriority = self::applyWithinBand($band, $offset, $penalty);

            return [$newPriority, [
                'max_similarity' => round($maxSim, 4),
                'penalty' => $penalty,
                'compared_against' => count($otherSets),
            ]];
        } catch (Throwable) {
            return [$band + $offset, null];
        }
    }
}
