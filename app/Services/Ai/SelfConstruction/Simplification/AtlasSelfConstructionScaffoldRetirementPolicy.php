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
    private const REQUIRED_EVIDENCE = [
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
            return [
                'decision' => 'keep',
                'missing_evidence' => [],
                'reason' => 'active_safety_or_visibility_role',
            ];
        }

        $missingEvidence = [];
        foreach (self::REQUIRED_EVIDENCE as $key) {
            if (! (bool) ($scaffold[$key] ?? false)) {
                $missingEvidence[] = $key;
            }
        }

        if ($missingEvidence !== []) {
            return [
                'decision' => 'needs_evidence',
                'missing_evidence' => $missingEvidence,
                'reason' => 'retirement_evidence_incomplete',
            ];
        }

        $mergeTarget = trim((string) ($scaffold['mergeable_with'] ?? ''));

        return [
            'decision' => $mergeTarget === '' ? 'retire' : 'merge',
            'missing_evidence' => [],
            'reason' => $mergeTarget === '' ? 'no_active_role_and_full_evidence' : 'consolidates_into:'.$mergeTarget,
        ];
    }
}
