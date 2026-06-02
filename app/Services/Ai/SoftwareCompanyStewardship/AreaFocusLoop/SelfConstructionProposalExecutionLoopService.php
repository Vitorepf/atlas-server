<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S92 — Self-Construction Proposal Execution Loop counter (L7 Runtime Completion).
 *
 * This service is a pure read-model accountant for the L7 promotion criterion:
 * "10 self-construction proposals approved, 0 invariants breached".
 *
 * It NEVER approves nor applies a proposal. It only counts the honest outcome
 * of proposals that were already governed elsewhere (real divergence + evidence,
 * honest merge, green validation, an approval receipt). A proposal that lacks a
 * real divergence or evidence is rejected here (counts toward neither approved
 * nor implemented) so the loop cannot manufacture self-construction credit.
 */
final class SelfConstructionProposalExecutionLoopService
{
    private const SCHEMA_VERSION = 'atlas.loop.self_construction_proposal_execution.v1';

    /**
     * Required number of honestly-approved self-construction proposals for L7.
     */
    private const REQUIRED_APPROVED_PROPOSALS = 10;

    /**
     * @param  list<array<string, mixed>>  $proposals
     * @return array{
     *     schema_version: string,
     *     approved_count: int,
     *     implemented_count: int,
     *     reverted_count: int,
     *     pending_human_review_count: int,
     *     invariant_breach_count: int,
     *     rejected_count: int,
     *     required_approved_proposals: int,
     *     l7_self_construction_ready: bool,
     *     blockers: list<string>
     * }
     */
    public function summarize(array $proposals): array
    {
        $approvedCount = 0;
        $implementedCount = 0;
        $revertedCount = 0;
        $pendingHumanReviewCount = 0;
        $invariantBreachCount = 0;
        $rejectedCount = 0;

        foreach ($proposals as $proposal) {
            $proposal = is_array($proposal) ? $proposal : [];

            $hasRealDivergence = $this->hasRealDivergence($proposal);
            $hasEvidence = $this->hasEvidence($proposal);
            $approved = $this->isHonestlyApproved($proposal, $hasRealDivergence, $hasEvidence);

            if ($this->isInvariantBreach($proposal)) {
                $invariantBreachCount++;
            }

            if ($approved) {
                $approvedCount++;

                if ($this->isHonestlyImplemented($proposal)) {
                    $implementedCount++;
                }

                if ($this->isReverted($proposal)) {
                    $revertedCount++;
                }

                continue;
            }

            // Not approved: a proposal lacking real divergence/evidence is rejected.
            if (! $hasRealDivergence || ! $hasEvidence) {
                $rejectedCount++;
            }

            if ($this->isPendingHumanReview($proposal)) {
                $pendingHumanReviewCount++;
            }
        }

        $blockers = [];

        if ($approvedCount < self::REQUIRED_APPROVED_PROPOSALS) {
            $blockers[] = 'insufficient_approved_self_construction_proposals';
        }

        if ($invariantBreachCount > 0) {
            $blockers[] = 'invariant_breach_present';
        }

        $ready = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'approved_count' => $approvedCount,
            'implemented_count' => $implementedCount,
            'reverted_count' => $revertedCount,
            'pending_human_review_count' => $pendingHumanReviewCount,
            'invariant_breach_count' => $invariantBreachCount,
            'rejected_count' => $rejectedCount,
            'required_approved_proposals' => self::REQUIRED_APPROVED_PROPOSALS,
            'l7_self_construction_ready' => $ready,
            'blockers' => $blockers,
        ];
    }

    /**
     * approved_count increments ONLY after an honest merge, green validation and an
     * approval receipt — and only for a proposal with real divergence + evidence.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function isHonestlyApproved(array $proposal, bool $hasRealDivergence, bool $hasEvidence): bool
    {
        return $hasRealDivergence
            && $hasEvidence
            && $this->isHonestMerge($proposal)
            && $this->isGreenValidation($proposal)
            && $this->hasApprovalReceipt($proposal);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function hasRealDivergence(array $proposal): bool
    {
        return ($proposal['divergence_real'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function hasEvidence(array $proposal): bool
    {
        $evidence = $proposal['evidence_refs'] ?? [];

        if (! is_array($evidence)) {
            return false;
        }

        foreach ($evidence as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isHonestMerge(array $proposal): bool
    {
        return ($proposal['merged_honestly'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isGreenValidation(array $proposal): bool
    {
        return ($proposal['validation'] ?? null) === 'green';
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function hasApprovalReceipt(array $proposal): bool
    {
        $receipt = $proposal['approval_receipt'] ?? null;

        return is_string($receipt) && trim($receipt) !== '';
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isHonestlyImplemented(array $proposal): bool
    {
        return ($proposal['implemented'] ?? false) === true
            && ($proposal['reverted'] ?? false) !== true;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isReverted(array $proposal): bool
    {
        return ($proposal['reverted'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isPendingHumanReview(array $proposal): bool
    {
        return ($proposal['pending_human_review'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isInvariantBreach(array $proposal): bool
    {
        return ($proposal['invariant_breach'] ?? false) === true;
    }
}
