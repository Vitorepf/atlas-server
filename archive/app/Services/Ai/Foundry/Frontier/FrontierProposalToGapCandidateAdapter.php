<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Ports\FrontierProposalIdentity;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;

/**
 * Foundry AP-C · Frontier · survivor → gap_candidate adapter.
 *
 * GENERATES NOTHING, WRITES NO CANON/CODE, INVOKES NO PROVIDER, MERGES NOTHING,
 * EXECUTES NOTHING. It maps a single armor-surviving evolution_proposal.v1 into
 * the canonical gap_candidate shape the operator curation inbox consumes
 * ({@see SelfDirectedEvolutionCurationInboxService::project}) as
 * pending_operator_review.
 *
 * candidate_hash is ALWAYS 'sha256:'+proposal_hash, where proposal_hash is the
 * SINGLE canonical identity ({@see FrontierProposalIdentity::of}) reused for the
 * generator provenance and the I6 dedup key — so admission collides correctly
 * against a future cycle's I6 prior-art check. Each candidate carries an
 * ap_c_adversarial_receipt reference to its I1..I2 stage verdicts so the
 * operator can audit exactly why the proposal survived. The adapter NEVER calls
 * buildOperatorCurationReceipt and NEVER decides.
 */
final class FrontierProposalToGapCandidateAdapter
{
    public const SOURCE_OWNER = 'foundry_frontier';

    /**
     * Map one armor-surviving proposal into a gap_candidate.
     *
     * @param  array<string,mixed>  $proposal      13-key evolution_proposal.v1 projection
     * @param  array<string,mixed>  $adversarialReceipt  {i1,i3,i7,i9,i2,...} stage verdicts for this proposal
     * @return array<string,mixed>
     */
    public function adapt(array $proposal, array $adversarialReceipt = []): array
    {
        $proposalHash = FrontierProposalIdentity::of($proposal);
        $candidateHash = 'sha256:'.$proposalHash;

        $title = (string) ($proposal['title'] ?? '');
        $thesis = (string) ($proposal['thesis'] ?? '');
        $why = (string) ($proposal['why_it_multiplies'] ?? '');
        $risk = (string) ($proposal['risk_level'] ?? 'medium');

        return [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::CANDIDATE_SCHEMA,
            'candidate_id' => 'frontier_'.substr($proposalHash, 0, 16),
            'candidate_hash' => $candidateHash,
            'source_owner' => self::SOURCE_OWNER,
            'source_schema_version' => 'atlas.foundry.evolution_proposal.v1',
            'gap_kind' => 'frontier_evolution_proposal',
            'title' => $title,
            'rationale' => $thesis !== '' ? $thesis : $why,
            'capability' => [
                'horizon' => (string) ($proposal['horizon'] ?? ''),
                'why_it_multiplies' => $why,
                'success_metric' => $proposal['success_metric'] ?? null,
                'rollback' => $proposal['rollback'] ?? null,
            ],
            'risk_level' => $risk,
            'priority_score' => 0,
            'evidence_refs' => array_values((array) ($proposal['evidence_refs'] ?? [])),
            'owner_doc_refs' => [],
            'proposed_next_action' => 'operator_review_frontier_proposal',
            'ap_c_adversarial_receipt' => $adversarialReceipt,
        ];
    }
}
