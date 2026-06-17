<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use Throwable;

/**
 * ARBOR-GRAFT T2 — insight backprop up the parent-linked tree. Arbor distills child insights into an
 * ancestor "what this direction taught us" so later siblings start smarter (the compounding spine).
 *
 * DELIBERATE DIVERGENCE FROM ARBOR: the aggregation is DETERMINISTIC concatenation/dedup, NOT an LLM
 * "synthesizer" call. This avoids BOTH the Claude-token burn (operator rule) AND Arbor's insight-as-fact
 * trust hazard — there is no model claim to trust.
 *
 * FLOOR-SAFE: ADVISORY ONLY. The distilled insight is read solely by CB1's next-draft constraints-block and
 * is wired AWAY from every gate (enforced by AtlasLoopAdvisoryFirewallTest). node_kind (direction at depth-1,
 * implementation at depth-2+; max depth 2) is a descriptive type — it MUST NOT change any readiness verdict.
 * The unchanged out-of-process cert independently re-proves every proposal regardless of any insight.
 */
final class AtlasLoopInsightBackpropService
{
    private const MAX_LESSONS = 12;

    /**
     * Deterministically fold child lessons into an ancestor insight payload. Pure. Dedup + bounded.
     *
     * @param  list<string>            $childLessons
     * @param  array<string,mixed>|null  $existing
     * @return array<string,mixed>
     */
    public static function aggregate(array $childLessons, ?array $existing): array
    {
        $existing ??= [];
        $distilled = array_values((array) ($existing['distilled'] ?? []));

        foreach ($childLessons as $lesson) {
            $line = trim(preg_replace('/\s+/', ' ', (string) $lesson) ?? (string) $lesson);
            if ($line !== '' && ! in_array($line, $distilled, true)) {
                $distilled[] = $line;
            }
        }

        // Keep the most recent MAX_LESSONS (bounded growth).
        if (count($distilled) > self::MAX_LESSONS) {
            $distilled = array_slice($distilled, -self::MAX_LESSONS);
        }

        $existing['distilled'] = $distilled;

        return $existing;
    }

    /**
     * Lessons carried by a node's insight payload (both direct pruned-lessons and prior distilled). Pure.
     *
     * @param  array<string,mixed>|null  $insight
     * @return list<string>
     */
    public static function lessonsOf(?array $insight): array
    {
        $insight ??= [];
        $out = [];
        foreach ((array) ($insight['pruned_lessons'] ?? []) as $l) {
            $out[] = (string) $l;
        }
        foreach ((array) ($insight['distilled'] ?? []) as $l) {
            $out[] = (string) $l;
        }

        return array_values(array_unique(array_filter($out, static fn ($s) => trim((string) $s) !== '')));
    }

    /**
     * The advisory node_kind for a depth (direction at depth 1, implementation at depth 2+). Pure.
     */
    public static function kindForDepth(int $depth): string
    {
        return $depth <= 1 ? AtlasLoopIdeaTreeAccessor::KIND_DIRECTION : AtlasLoopIdeaTreeAccessor::KIND_IMPLEMENTATION;
    }

    /**
     * Walk path-to-root from $targetId and fold its lessons into each ancestor's distilled insight. Advisory
     * write only; fail-open. Reuses the T1 accessor; never touches a gate.
     */
    public function backpropagate(string $targetId): void
    {
        try {
            $target = AtlasLoopTarget::query()->find($targetId);
            if ($target === null || $target->parent_target_id === null) {
                return; // root or flat-ledger node: nothing to propagate
            }

            $childLessons = self::lessonsOf(is_array($target->node_insight) ? $target->node_insight : null);
            if ($childLessons === []) {
                return;
            }

            $rows = AtlasLoopTarget::query()
                ->where('campaign_id', $target->campaign_id)
                ->get(['id', 'parent_target_id'])
                ->keyBy('id');
            $byId = $rows->map(fn ($r) => ['parent_target_id' => $r->parent_target_id])->all();

            $path = AtlasLoopIdeaTreeAccessor::pathToRootIds($byId, $targetId); // root-first chain incl. self
            // Fold into every ancestor (exclude self, the last element).
            $ancestors = array_slice($path, 0, -1);
            foreach ($ancestors as $ancestorId) {
                $node = AtlasLoopTarget::query()->find($ancestorId);
                if ($node === null) {
                    continue;
                }
                $node->node_insight = self::aggregate(
                    $childLessons,
                    is_array($node->node_insight) ? $node->node_insight : null,
                );
                $node->save();
            }
        } catch (Throwable) {
            // advisory: never break the run
        }
    }
}
