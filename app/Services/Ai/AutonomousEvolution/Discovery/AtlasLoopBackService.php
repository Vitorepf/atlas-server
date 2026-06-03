<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;

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
    ) {}

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

            return ['spawned' => 0, 'quarantined' => 0, 'requeued' => 1];
        }

        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_EXHAUSTED, 'attempt_cap_no_winner');

        return ['spawned' => 0, 'quarantined' => 0, 'requeued' => 0];
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
