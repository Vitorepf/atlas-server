<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * What {@see AtlasMaestroPacketShapeOperatorReviewGate::present()} returns — the proposal plus the evidence
 * the operator needs to decide: a diff vs the most recent prior approved shape, the Cortex anchor citations,
 * and the proposal_hash that approve() will re-check for tamper detection.
 */
final class ReviewBundle
{
    /**
     * @param  array<string,mixed>  $proposal               the parsed ProposedPacketShape json (verbatim)
     * @param  array<string,mixed>|null  $priorApprovedShape  the most recent approved shape, or null
     * @param  array<string,mixed>  $diff                   structured diff: added/removed/changed keys
     * @param  list<string>  $cortexAnchorCitations         operator-facing anchor references (file:symbol)
     */
    public function __construct(
        public readonly string $shapeId,
        public readonly array $proposal,
        public readonly ?array $priorApprovedShape,
        public readonly array $diff,
        public readonly array $cortexAnchorCitations,
        public readonly string $proposalHash,
        public readonly bool $stale,
    ) {
    }
}
