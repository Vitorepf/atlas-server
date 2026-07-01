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
 *
 * Two additive, opt-in gates (no-op when the caller omits their input, so all prior behavior is
 * byte-identical without them):
 *   - live_usage_count vs live_usage_ceiling: still-heavily-used scaffolds WAIT rather than retire
 *     blindly, even with full evidence — usage naturally declines, so this is temporary, not a hold.
 *   - replacement_maturity_days vs REPLACEMENT_MATURITY_FLOOR_DAYS: a replacement_capability flag
 *     alone isn't proof of a MATURE replacement; a too-young replacement also WAITs.
 * A third gate is NOT opt-in: rollback_receipt missing AND it being the ONLY missing evidence item
 * escalates from the generic needs_evidence to an explicit BLOCK — rollback availability is the one
 * piece of evidence whose absence makes retirement advice actively destructive, not just incomplete.
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

    private const REPLACEMENT_MATURITY_FLOOR_DAYS = 14;

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

        // Live usage ceiling (opt-in): still-heavily-used scaffolds wait rather than retire blindly,
        // even with full evidence — no-op unless the caller supplies a positive ceiling.
        $liveUsageCeiling = (int) ($scaffold['live_usage_ceiling'] ?? 0);
        $liveUsageCount = (int) ($scaffold['live_usage_count'] ?? 0);
        if ($liveUsageCeiling > 0 && $liveUsageCount > $liveUsageCeiling) {
            return $this->result('wait', [], sprintf('live_usage_count=%d exceeds live_usage_ceiling=%d', $liveUsageCount, $liveUsageCeiling));
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
            // Rollback availability is the one evidence item whose absence makes retirement advice
            // actively destructive rather than merely incomplete — when it's the ONLY gap, block
            // explicitly instead of folding it into the generic needs_evidence bucket.
            if ($missingEvidence === ['rollback_receipt']) {
                return $this->result('block', $missingEvidence, 'missing_rollback_receipt_blocks_destructive_retirement');
            }

            return $this->result('needs_evidence', $missingEvidence, 'retirement_evidence_incomplete');
        }

        // Replacement maturity (opt-in): a replacement_capability flag alone isn't proof of a MATURE
        // replacement — no-op unless the caller supplies replacement_maturity_days.
        $replacementMaturityDays = $scaffold['replacement_maturity_days'] ?? null;
        if ($replacementMaturityDays !== null && (int) $replacementMaturityDays < self::REPLACEMENT_MATURITY_FLOOR_DAYS) {
            return $this->result('wait', [], sprintf('replacement_maturity_days=%d below floor=%d', (int) $replacementMaturityDays, self::REPLACEMENT_MATURITY_FLOOR_DAYS));
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
