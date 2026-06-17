<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * ARBOR-GRAFT T1 — read-mostly accessor over the idea-tree edges on AtlasLoopTarget.
 *
 * Arbor's substrate is a hypothesis tree (sibling competition + path-to-root insight). This accessor is
 * the deterministic, provider-free lens over the additive tree columns (parent_target_id / depth /
 * node_kind / tree_status / hypothesis / node_insight). It is the substrate SEL1 (SELECT adjuster) and
 * CB1 (constraints-block) read; it never decides anything itself.
 *
 * PÉTREO INVARIANT — ADVISORY, NEVER A GATE: NONE of the tree fields (parent_target_id, depth, node_kind,
 * tree_status, hypothesis, node_insight) may EVER be read by AtlasEvolutionFrozenJudge,
 * AtlasLoopSemanticImplementationCertifier, AtlasLoopProposalPromotionGate, any *AutoMergeService, any
 * trust ladder, or any readiness gate's pass/fail decision. tree_status='merged' is a descriptive MIRROR
 * of a real merge, never a substitute for it — merge authority stays in the certified out-of-process
 * gate. This invariant is mechanically enforced by Tests\Feature\Architecture\AtlasLoopAdvisoryFirewallTest
 * (which forbids any gate-spine class from referencing this class or these fields). This class is itself
 * pétreo (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS) so the loop can never edit its own tree layer.
 *
 * Depth is immutable post-attach: a node's parent (and therefore depth) is set exactly once via
 * attachChild(); re-parenting throws. Ids are monotonic UUIDs (HasUuids) and are never reused — a pruned
 * branch can never be silently recreated under the same id.
 */
final class AtlasLoopIdeaTreeAccessor
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_NEEDS_RETRY = 'needs_retry';

    public const STATUS_MERGED = 'merged';

    public const STATUS_PRUNED = 'pruned';

    public const KIND_DIRECTION = 'direction';

    public const KIND_IMPLEMENTATION = 'implementation';

    // ── Pure core (no DB / no provider) — the unit-tested logic ────────────────────────────────

    /**
     * Root-first chain of ids from $id up to its root, following parent_target_id.
     *
     * @param  array<string, array{parent_target_id?: ?string}>  $byId
     * @return list<string>
     */
    public static function pathToRootIds(array $byId, string $id): array
    {
        $path = [];
        $current = $id;
        $seen = [];
        while ($current !== null && isset($byId[$current]) && ! isset($seen[$current])) {
            $seen[$current] = true;
            $path[] = $current;
            $current = $byId[$current]['parent_target_id'] ?? null;
        }

        return array_reverse($path);
    }

    /**
     * Ids of pending LEAF tree-nodes: tree_status === pending, depth > 0, and no node names them as parent.
     * Flat-ledger rows (tree_status null) are NOT tree nodes and never appear — byte-identical when OFF.
     *
     * @param  array<int, array{id: string, parent_target_id?: ?string, depth?: int, tree_status?: ?string}>  $nodes
     * @return list<string>
     */
    public static function pendingLeafIds(array $nodes): array
    {
        $hasChild = [];
        foreach ($nodes as $n) {
            $p = $n['parent_target_id'] ?? null;
            if ($p !== null) {
                $hasChild[$p] = true;
            }
        }

        $leaves = [];
        foreach ($nodes as $n) {
            $status = $n['tree_status'] ?? null;
            $depth = $n['depth'] ?? 0;
            if ($status === self::STATUS_PENDING && $depth > 0 && ! isset($hasChild[$n['id']])) {
                $leaves[] = $n['id'];
            }
        }

        return $leaves;
    }

    /**
     * Ids of the subtree rooted at $rootId (inclusive), in pre-order. Used to prune a whole direction.
     *
     * @param  array<string, array{parent_target_id?: ?string}>  $byId
     * @return list<string>
     */
    public static function subtreeIds(array $byId, string $rootId): array
    {
        $children = [];
        foreach ($byId as $id => $row) {
            $p = $row['parent_target_id'] ?? null;
            if ($p !== null) {
                $children[$p][] = $id;
            }
        }

        $out = [];
        $walk = function (string $id) use (&$walk, &$out, $children): void {
            $out[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $walk($child);
            }
        };
        if (isset($byId[$rootId])) {
            $walk($rootId);
        }

        return $out;
    }

    /**
     * The depth a child gets when attached under $parent (root children = 1). Pure.
     *
     * @param  array<string, array{depth?: int}>  $byId
     */
    public static function childDepth(array $byId, ?string $parentId): int
    {
        if ($parentId === null || ! isset($byId[$parentId])) {
            return 1;
        }

        return (int) ($byId[$parentId]['depth'] ?? 0) + 1;
    }

    /**
     * Append a [Pruned: reason] lesson onto an existing node_insight payload (advisory narrative). Pure.
     *
     * @param  array<string, mixed>|null  $insight
     * @return array<string, mixed>
     */
    public static function withPruneLesson(?array $insight, string $reason): array
    {
        $insight ??= [];
        $lessons = $insight['pruned_lessons'] ?? [];
        $lessons[] = trim($reason) === '' ? 'pruned' : '[Pruned: '.trim($reason).']';
        $insight['pruned_lessons'] = array_values($lessons);

        return $insight;
    }

    // ── Thin DB wrappers (delegate to the pure core) ──────────────────────────────────────────

    /** @return list<AtlasLoopTarget> root-first chain. */
    public function getPathToRoot(string $targetId): array
    {
        $target = AtlasLoopTarget::query()->find($targetId);
        if ($target === null) {
            return [];
        }
        $rows = AtlasLoopTarget::query()
            ->where('campaign_id', $target->campaign_id)
            ->get(['id', 'parent_target_id'])
            ->keyBy('id');

        $byId = $rows->map(fn ($r) => ['parent_target_id' => $r->parent_target_id])->all();
        $ids = self::pathToRootIds($byId, $targetId);

        $byModel = AtlasLoopTarget::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (string $id) => $byModel->get($id), $ids)));
    }

    /** @return Collection<int, AtlasLoopTarget> */
    public function getPendingLeaves(string $campaignId): Collection
    {
        $rows = AtlasLoopTarget::query()
            ->where('campaign_id', $campaignId)
            ->whereNotNull('tree_status')
            ->get();

        $ids = self::pendingLeafIds($rows->map(fn ($r) => [
            'id' => $r->id,
            'parent_target_id' => $r->parent_target_id,
            'depth' => (int) ($r->depth ?? 0),
            'tree_status' => $r->tree_status,
        ])->all());

        $lookup = array_flip($ids);

        return $rows->filter(fn ($r) => isset($lookup[$r->id]))->values();
    }

    /**
     * Attach $child under $parent ONCE — sets parent edge + depth (immutable thereafter). Re-parenting throws.
     */
    public function attachChild(AtlasLoopTarget $child, ?AtlasLoopTarget $parent, string $kind = self::KIND_IMPLEMENTATION): void
    {
        if ($child->parent_target_id !== null) {
            throw new RuntimeException('AtlasLoopIdeaTreeAccessor: re-parenting is forbidden — depth is immutable post-attach.');
        }
        $parentDepth = $parent !== null ? (int) ($parent->depth ?? 0) : 0;
        $child->parent_target_id = $parent?->id;
        $child->depth = $parentDepth + 1;
        $child->node_kind = $kind;
        if ($child->tree_status === null) {
            $child->tree_status = self::STATUS_PENDING;
        }
        $child->save();
    }

    /** Recursively mark the subtree rooted at $targetId as pruned; record the lesson on the root node only. */
    public function pruneNode(string $targetId, string $reason = ''): void
    {
        $target = AtlasLoopTarget::query()->find($targetId);
        if ($target === null) {
            return;
        }
        $rows = AtlasLoopTarget::query()
            ->where('campaign_id', $target->campaign_id)
            ->get(['id', 'parent_target_id'])
            ->keyBy('id');
        $byId = $rows->map(fn ($r) => ['parent_target_id' => $r->parent_target_id])->all();

        foreach (self::subtreeIds($byId, $targetId) as $id) {
            $node = AtlasLoopTarget::query()->find($id);
            if ($node === null) {
                continue;
            }
            $node->tree_status = self::STATUS_PRUNED;
            if ($id === $targetId) {
                $node->node_insight = self::withPruneLesson(is_array($node->node_insight) ? $node->node_insight : null, $reason);
            }
            $node->save();
        }
    }
}
