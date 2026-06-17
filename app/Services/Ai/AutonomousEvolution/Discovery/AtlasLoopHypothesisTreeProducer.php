<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use Throwable;

/**
 * ARBOR-GRAFT TIER 0.1 — the TREE-PRODUCER (the feature that activates the dormant idea-tree).
 *
 * Arbor's substrate is a tree of COMPETING SIBLINGS under a parent — distinct hypotheses for the same
 * goal, pruned if they fail, harvested if they win, with lessons propagating up. Atlas's discovery flow is
 * FLAT (one target = one file; the loop-back re-surfaces the SAME row). This producer is the missing piece:
 * given a parent target + the K candidate objectives the generator already samples (comprehension-samples /
 * U3 divergence readings), it materializes them as K parent-linked SIBLING child nodes so SEL1 (SELECT),
 * CB1 (constraints-block) and T2 (insight-backprop) finally have a real tree to read.
 *
 * NO REFRAGMENTATION: tree-nodes are a distinct KIND of row (node_kind/tree_status set, keyed by
 * parent+hypothesis-hash) — they do NOT collide with the discovery's file-keyed upsert, and the discovery
 * path is untouched. FLOOR-SAFE: flag-gated default-OFF (no producer call => flat ledger == today); the
 * hypothesis/insight fields are ADVISORY, walled off from every cert/merge/trust class by
 * AtlasLoopAdvisoryFirewallTest. Pétreo (AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS).
 */
final class AtlasLoopHypothesisTreeProducer
{
    public function __construct(
        private readonly ?AtlasLoopInsightBackpropService $backprop = null,
    ) {}

    /**
     * Pure: turn K hypotheses under a parent into deduplicated child specs. Deterministic, no DB.
     * Siblings sharing the same hypothesis text collapse to one (content-addressed by hash).
     *
     * @param  list<string>  $hypotheses
     * @return list<array{hypothesis:string, depth:int, node_kind:string, tree_status:string, key_suffix:string}>
     */
    public static function childSpecs(int $parentDepth, array $hypotheses): array
    {
        $depth = max(0, $parentDepth) + 1;
        $kind = $depth <= 1 ? AtlasLoopIdeaTreeAccessor::KIND_DIRECTION : AtlasLoopIdeaTreeAccessor::KIND_IMPLEMENTATION;

        $specs = [];
        $seen = [];
        foreach ($hypotheses as $h) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $h) ?? (string) $h);
            if ($text === '') {
                continue;
            }
            $hash = substr(sha1($text), 0, 8);
            if (isset($seen[$hash])) {
                continue; // competing siblings must be DISTINCT ideas, not rewordings
            }
            $seen[$hash] = true;
            $specs[] = [
                'hypothesis' => $text,
                'depth' => $depth,
                'node_kind' => $kind,
                'tree_status' => AtlasLoopIdeaTreeAccessor::STATUS_PENDING,
                'key_suffix' => '#h'.$hash,
            ];
        }

        return $specs;
    }

    /**
     * Materialize K sibling hypothesis nodes under $parent. Flag-gated default-OFF (returns [] when OFF =>
     * byte-identical). Fail-open per child. Returns the created child ids.
     *
     * @param  list<string>  $hypotheses
     * @return list<string>
     */
    public function materialize(AtlasLoopTarget $parent, array $hypotheses): array
    {
        if (! (bool) config('atlas.loop.idea_tree_enabled', false)) {
            return [];
        }

        $ids = [];
        foreach (self::childSpecs((int) ($parent->depth ?? 0), $hypotheses) as $spec) {
            try {
                $key = hash('sha256', $parent->campaign_id.'|'.$parent->target_path.$spec['key_suffix']);
                // idempotent: a re-run with the same hypothesis does not duplicate the sibling.
                $existing = AtlasLoopTarget::query()
                    ->where('campaign_id', $parent->campaign_id)
                    ->where('target_key', $key)
                    ->first();
                if ($existing instanceof AtlasLoopTarget) {
                    $ids[] = $existing->id;

                    continue;
                }
                $child = AtlasLoopTarget::query()->create([
                    'campaign_id' => $parent->campaign_id,
                    'schema_version' => 'atlas.loop.target.v1',
                    'target_path' => $parent->target_path,
                    'target_key' => $key,
                    'content_hash' => (string) $parent->content_hash,
                    'status' => AtlasLoopTarget::STATUS_CANDIDATE,
                    'score' => (float) $parent->score,
                    'self_contained_score' => (float) $parent->self_contained_score,
                    'improvement_score' => (float) $parent->improvement_score,
                    'novelty_score' => (float) $parent->novelty_score,
                    'signals' => is_array($parent->signals) ? $parent->signals : [],
                    'lineage' => ['hypothesis_child_of' => $parent->id, 'parent_target_id' => $parent->id],
                    'attempts' => 0,
                    'max_attempts' => (int) ($parent->max_attempts ?? 3),
                    'parent_target_id' => $parent->id,
                    'depth' => $spec['depth'],
                    'node_kind' => $spec['node_kind'],
                    'tree_status' => $spec['tree_status'],
                    'hypothesis' => ['text' => $spec['hypothesis']],
                ]);
                $ids[] = $child->id;
            } catch (Throwable) {
                // advisory production: a failed child never breaks the run
            }
        }

        return $ids;
    }

    /**
     * At a child node's terminal, fold its lesson up the tree (advisory). Flag-gated default-OFF.
     */
    public function onChildTerminal(string $childId): void
    {
        if (! (bool) config('atlas.loop.insight_backprop_enabled', false) || $this->backprop === null) {
            return;
        }
        $this->backprop->backpropagate($childId);
    }
}
