<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use Throwable;

/**
 * Results -> Sources, so the queue self-sustains for 24h with no new trust surface.
 *
 * Three deterministic rules, each spawned candidate re-entering the SAME admissibility
 * + RED gates downstream (no shortcut to a proposal):
 *   (1) NEIGHBOR — a proposed file's OTHER improvable surface re-enters as a fresh
 *       candidate, so the generator can find the next genuine improvement in it.
 *   (2) SIBLING_PATTERN — handled by re-running discovery each refill (it re-surfaces
 *       structurally-similar self-contained files as the proposed ones drain).
 *   (3) FAILURE_HYPOTHESIS — a no-winner Result: a self-containment / materialize
 *       failure QUARANTINES the target (never retried — prevents thrashing on a
 *       fundamentally un-grindable file); a genuine metric miss re-enters at reduced
 *       novelty until the attempt cap, then is exhausted.
 *
 * Pure routing + cheap re-scoring; NO provider call.
 */
final class AtlasLoopBackService
{
    /** Reasons that mean "this file can never be honestly ground" — quarantine, don't retry. */
    private const QUARANTINE_REASONS = ['materialize', 'not_red', 'invalid_task', 'self_contain', 'no_real_work', 'fabricated'];

    public function __construct(
        private readonly AtlasLoopTargetRepository $repository,
        private readonly ?AtlasLoopIdeaTreeAccessor $treeAccessor = null,
        private readonly ?AtlasLoopInsightBackpropService $insightBackprop = null,
        // ARBOR-GRAFT #2: the tree-producer used to turn a metric MISS into competing alternative-direction
        // siblings (failure-driven work-supply). Nullable + LAST (Laravel does not inject `?Type = null`);
        // wired by the explicit AppServiceProvider bind. Touched ONLY when failure_supply_enabled is ON.
        private readonly ?AtlasLoopHypothesisTreeProducer $treeProducer = null,
    ) {}

    /**
     * ARBOR-GRAFT TIER 0.1 (c) — close the tree's compounding loop. When a target that IS a tree-node
     * (has a parent edge) reaches a terminal, mirror the outcome into tree_status and fold its lesson up the
     * path-to-root so the next IDEATE (via CB1's constraints-block) is smarter. Winner => done; a permanent
     * un-grindable failure => prune the subtree (records the [Pruned: reason] lesson) + backprop it. ADVISORY:
     * only tree_status / node_insight (advisory fields, firewall-walled from every gate) are touched; the
     * grind/cert flow is unchanged. Flag-gated default-OFF; non-tree rows (parent null) are a no-op.
     */
    private function reflectTreeNode(AtlasLoopTarget $target, string $status, string $reason): void
    {
        if ($target->parent_target_id === null || ! (bool) config('atlas.loop.idea_tree_enabled', false)) {
            return;
        }
        try {
            if ($status === 'winner') {
                $target->forceFill(['tree_status' => AtlasLoopIdeaTreeAccessor::STATUS_DONE])->save();
            } elseif ($this->isQuarantineReason($reason) && $this->treeAccessor !== null) {
                $this->treeAccessor->pruneNode($target->id, $reason !== '' ? $reason : 'un_grindable');
            } else {
                return; // a transient requeue is not a terminal — no tree transition, no backprop yet
            }
            if ((bool) config('atlas.loop.insight_backprop_enabled', false)) {
                $this->insightBackprop?->backpropagate($target->id);
            }
        } catch (Throwable) {
            // advisory: never break loop-back
        }
    }

    /**
     * @param  array{target_id?:?string, status:string, reason?:?string}  $result
     * @return array{spawned:int, quarantined:int, requeued:int}
     */
    public function reflect(string $campaignId, array $result): array
    {
        $targetId = (string) ($result['target_id'] ?? '');
        $status = (string) ($result['status'] ?? '');
        $reason = (string) ($result['reason'] ?? '');

        $target = $targetId !== '' ? AtlasLoopTarget::query()->find($targetId) : null;
        if (! $target instanceof AtlasLoopTarget) {
            return ['spawned' => 0, 'quarantined' => 0, 'requeued' => 0];
        }

        $this->reflectTreeNode($target, $status, $reason); // ARBOR-GRAFT TIER 0.1 (c) — flag-OFF / non-tree => no-op

        // WINNER: this file produced a proposal — record it, then re-surface it as a
        // NEIGHBOR candidate so the generator can mine its next improvement.
        if ($status === 'winner') {
            $spawned = 0;
            if ((int) $target->attempts < (int) $target->max_attempts) {
                $target->forceFill([
                    'status' => AtlasLoopTarget::STATUS_CANDIDATE,
                    'novelty_score' => max(0.0, (float) $target->novelty_score - 0.34), // diminishing returns
                    'score' => max(0.0, (float) $target->score - 0.05),
                    'lineage' => array_merge((array) $target->lineage, ['neighbor_of' => $target->target_path]),
                    'reason' => 'neighbor_after_winner',
                    'claimed_by' => null,
                    'lease_expires_at' => null,
                ])->save();
                $spawned = 1;
            } else {
                $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_PROPOSED, 'attempt_cap_reached');
            }

            return ['spawned' => $spawned, 'quarantined' => 0, 'requeued' => 0];
        }

        // NO WINNER / FAILED: classify. A self-containment / materialize failure is a
        // permanent un-grindable target -> quarantine. A genuine metric miss re-enters.
        if ($this->isQuarantineReason($reason)) {
            $this->repository->quarantine($target->id, $reason !== '' ? $reason : 'un_grindable');

            return ['spawned' => 0, 'quarantined' => 1, 'requeued' => 0];
        }

        if ((int) $target->attempts < (int) $target->max_attempts) {
            $target->forceFill([
                'status' => AtlasLoopTarget::STATUS_CANDIDATE,
                'novelty_score' => max(0.0, (float) $target->novelty_score - 0.34),
                'score' => max(0.0, (float) $target->score - 0.1),
                'reason' => 'requeued_metric_miss',
                'claimed_by' => null,
                'lease_expires_at' => null,
            ])->save();

            // ARBOR-GRAFT #2 — a genuine metric miss is the loop's richest FREE signal: in addition to
            // re-queueing the same direction, fan out N orthogonal ALTERNATIVE directions as competing
            // sibling nodes so the tree gains supply exactly where it got stuck. Flag-OFF / non-tree => no-op.
            $this->materializeFailureSupply($target, $reason);

            return ['spawned' => 0, 'quarantined' => 0, 'requeued' => 1];
        }

        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_EXHAUSTED, 'attempt_cap_no_winner');

        return ['spawned' => 0, 'quarantined' => 0, 'requeued' => 0];
    }

    /**
     * ARBOR-GRAFT #2 — turn a metric MISS into competing alternative-direction supply. Frames N orthogonal
     * re-attacks of the same goal (deterministic, provider-free) and materializes them as parent-linked
     * SIBLING hypothesis nodes that re-enter the SAME admissibility + RED + cert gates (no shortcut to a
     * proposal). TWO-FLAG AND: needs BOTH failure_supply_enabled (this expansion) AND idea_tree_enabled (the
     * producer's substrate — its materialize() no-ops without it), so default OFF is byte-identical. Depth-
     * clamped so a deep node never fans out forever; fail-open so a fault never breaks loop-back. ADVISORY:
     * a frame never gates anything — the unchanged out-of-process cert still proves every expansion.
     */
    private function materializeFailureSupply(AtlasLoopTarget $target, string $reason): void
    {
        if ($this->treeProducer === null || ! (bool) config('atlas.loop.failure_supply_enabled', false)) {
            return;
        }
        // a miss DEEP in the tree must not spawn runaway descendants — only the shallow layer fans out.
        if ((int) ($target->depth ?? 0) >= (int) config('atlas.loop.failure_supply_max_depth', 1)) {
            return;
        }
        try {
            $objective = $this->failedObjectiveFor($target);
            if ($objective === '') {
                return;
            }
            $frames = AtlasLoopFailureHypothesisProducer::alternativeFrames(
                $objective,
                $reason !== '' ? $reason : 'requeued_metric_miss',
                max(1, (int) config('atlas.loop.failure_supply_frames', 3)),
            );
            if ($frames !== []) {
                // idempotent (content-addressed by hypothesis hash) => re-firing on each miss never duplicates.
                $this->treeProducer->materialize($target, $frames);
            }
        } catch (Throwable) {
            // advisory production: a failed supply expansion never breaks loop-back
        }
    }

    /** Best available description of what we were trying on this target, for the failure frames. */
    private function failedObjectiveFor(AtlasLoopTarget $target): string
    {
        $hyp = is_array($target->hypothesis ?? null) ? trim((string) ($target->hypothesis['text'] ?? '')) : '';
        if ($hyp !== '') {
            return $hyp;
        }
        $signals = is_array($target->signals) ? $target->signals : [];
        $last = trim((string) ($signals['last_objective'] ?? ''));
        if ($last !== '') {
            return $last;
        }

        return trim((string) $target->target_path);
    }

    private function isQuarantineReason(string $reason): bool
    {
        $reason = mb_strtolower($reason);
        foreach (self::QUARANTINE_REASONS as $needle) {
            if ($reason !== '' && str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }
}
