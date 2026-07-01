<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Decides whether a temporary scaffold, wrapper, or bridge organ can be
 * retired. Scaffolds that still gate safety, isolate risk, or provide
 * active runtime visibility are always kept, regardless of other evidence.
 * Everything else may only retire (or merge, when a merge target is named)
 * once consumers, replacement capability, replay proof, rollback receipt,
 * and docs-sync evidence are ALL present; missing any of them holds the
 * scaffold at needs_evidence rather than guessing.
 */
final class AtlasSelfConstructionScaffoldRetirementPolicy
{
    /** Canonical owner + parity proof + consumer rewrite plan + replay gates. */
    private const REQUIRED_EVIDENCE = [
        'canonical_owner',
        'consumers_mapped',
        'replacement_capability',
        'replay_proof',
        'rollback_receipt',
        'docs_sync',
    ];

    /**
     * @param  array<string,mixed>  $scaffold
     * @return array<string,mixed>
     */
    public function decide(array $scaffold): array
    {
        if ((bool) ($scaffold['gates_safety'] ?? false)
            || (bool) ($scaffold['isolates_risk'] ?? false)
            || (bool) ($scaffold['provides_active_runtime_visibility'] ?? false)
        ) {
            return $this->result('keep', [], 'active_safety_or_visibility_role');
        }

        // Dormant organs without runtime proof are never auto-deleted or auto-retired —
        // absence of proof is not evidence of safety to delete, so they hold at review.
        $isDormant = (bool) ($scaffold['dormant'] ?? false);
        $hasRuntimeProof = (bool) ($scaffold['runtime_proof'] ?? false);
        if ($isDormant && ! $hasRuntimeProof) {
            return $this->result('review', [], 'dormant_organ_without_runtime_proof_requires_manual_review');
        }

        $canonicalOwner = trim((string) ($scaffold['canonical_owner'] ?? ''));
        $missingEvidence = [];
        foreach (self::REQUIRED_EVIDENCE as $key) {
            if ($key === 'canonical_owner') {
                if ($canonicalOwner === '') {
                    $missingEvidence[] = $key;
                }

                continue;
            }
            if (! (bool) ($scaffold[$key] ?? false)) {
                $missingEvidence[] = $key;
            }
        }

        if ($missingEvidence !== []) {
            return $this->result('needs_evidence', $missingEvidence, 'retirement_evidence_incomplete');
        }

        $mergeTarget = trim((string) ($scaffold['mergeable_with'] ?? ''));
        $decision = $mergeTarget === '' ? 'retire' : 'merge';

        return $this->result(
            $decision,
            [],
            $mergeTarget === '' ? 'no_active_role_and_full_evidence' : 'consolidates_into:'.$mergeTarget,
        );
    }

    /**
     * @param  list<string>  $missingEvidence
     * @return array<string,mixed>
     */
    private function result(string $decision, array $missingEvidence, string $reason): array
    {
        $retireNow = $decision === 'retire';

        return [
            'decision' => $decision,
            'retire_now' => $retireNow,
            'missing_evidence' => $missingEvidence,
            'reason' => $reason,
            'deletion_evidence' => $retireNow ? self::REQUIRED_EVIDENCE : [],
        ];
    }
}
