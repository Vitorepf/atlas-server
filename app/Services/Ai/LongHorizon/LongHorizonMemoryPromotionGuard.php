<?php

namespace App\Services\Ai\LongHorizon;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Support\TemporalTruth\TemporalTruthCanon;

/**
 * TEOS-I1 — Long-Horizon Memory Promotion Guard.
 *
 * Enforces 3 invariants whenever a memory delta is being promoted into a
 * long-horizon scope (`obra` / `long_horizon`):
 *
 *  1. **Evidence required.** The delta MUST carry at least one evidence ref
 *     (from `delta->evidence` or `overrides['evidence_refs']`). Empty
 *     evidence blocks promotion.
 *  2. **Operator review required.** The delta MUST either carry a non-null
 *     `operator_review_proposal_id` OR the caller MUST pass
 *     `operator_review_decision=approved` in `$overrides`. Auto-apply is
 *     forbidden for long-horizon scopes.
 *  3. **LAMI quarantine respected.** A delta whose source is the Local
 *     Agent Memory Ingestion pipeline (`source_type=local_agent_ingestion`
 *     OR `source_kind=local_agent`) cannot land directly in long-horizon
 *     memory — it has to flow through the proposal/review surface first.
 *     The guard checks for an explicit `lami_review_completed=true` flag
 *     on overrides as the canonical escape hatch.
 *
 * Optional rule:
 *  - `authority_level=inferred` is allowed only when operator review is
 *    explicitly approved (rule 2 still applies). Unknown authority levels
 *    are refused.
 *
 * Out of scope (intentionally): Strategic Forgetting, automatic
 * supersession, memory dedup. Those are TEOS-I2+ slices.
 */
class LongHorizonMemoryPromotionGuard
{
    /**
     * Evaluate the guard for a delta + override pair. Returns the list of
     * failed reasons; empty list means promotion is allowed.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<int,string>
     */
    public function evaluate(AiMemoryDelta $delta, array $overrides = []): array
    {
        $scope = $this->resolveScope($delta, $overrides);
        if (! in_array($scope, AtlasMemoryEntry::LONG_HORIZON_SCOPES, true)) {
            return [];
        }

        $reasons = [];

        if (! $this->hasEvidence($delta, $overrides)) {
            $reasons[] = LongHorizonMemoryPromotionRefusedException::REASON_MISSING_EVIDENCE_REFS;
        }

        if (! $this->hasOperatorReview($delta, $overrides)) {
            $reasons[] = LongHorizonMemoryPromotionRefusedException::REASON_MISSING_OPERATOR_REVIEW;
        }

        if ($this->isLocalAgentRaw($delta, $overrides)) {
            $reasons[] = LongHorizonMemoryPromotionRefusedException::REASON_LOCAL_AGENT_RAW_BLOCKED;
        }

        $authority = $this->resolveAuthorityLevel($delta, $overrides);
        if ($authority !== null && ! in_array($authority, TemporalTruthCanon::AUTHORITY_LEVELS, true)) {
            $reasons[] = LongHorizonMemoryPromotionRefusedException::REASON_UNKNOWN_AUTHORITY_LEVEL;
        }
        if ($authority === TemporalTruthCanon::AUTHORITY_INFERRED
            && ! $this->hasOperatorReview($delta, $overrides)
        ) {
            $reasons[] = LongHorizonMemoryPromotionRefusedException::REASON_LOW_AUTHORITY_REQUIRES_REVIEW;
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Throwing variant. Callers that want the legacy try/catch shape can use
     * this directly inside their promotion transaction.
     *
     * @param  array<string,mixed>  $overrides
     */
    public function assertPromotable(AiMemoryDelta $delta, array $overrides = []): void
    {
        $reasons = $this->evaluate($delta, $overrides);
        if ($reasons === []) {
            return;
        }
        throw LongHorizonMemoryPromotionRefusedException::withReasons(
            scope: $this->resolveScope($delta, $overrides),
            reasons: $reasons,
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function resolveScope(AiMemoryDelta $delta, array $overrides): string
    {
        if (isset($overrides['scope_type']) && is_string($overrides['scope_type']) && trim($overrides['scope_type']) !== '') {
            return strtolower(trim($overrides['scope_type']));
        }

        $deltaScope = (string) ($delta->scope ?? 'global');
        if ($deltaScope === '' || $deltaScope === 'global') {
            return 'global';
        }
        if (! str_contains($deltaScope, ':')) {
            return strtolower($deltaScope);
        }
        [$kind, $_] = explode(':', $deltaScope, 2);

        return strtolower($kind);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function hasEvidence(AiMemoryDelta $delta, array $overrides): bool
    {
        $deltaEvidence = (array) ($delta->evidence ?? []);
        $overrideEvidence = array_merge(
            (array) ($overrides['evidence_refs'] ?? []),
            (array) ($overrides['evidence'] ?? []),
        );
        $merged = array_filter(array_merge($deltaEvidence, $overrideEvidence), static function ($entry): bool {
            if (is_string($entry)) {
                return trim($entry) !== '';
            }

            return is_array($entry) && $entry !== [];
        });

        return $merged !== [];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function hasOperatorReview(AiMemoryDelta $delta, array $overrides): bool
    {
        $proposalId = $overrides['operator_review_proposal_id'] ?? null;
        if (is_string($proposalId) && trim($proposalId) !== '') {
            return true;
        }
        $decision = $overrides['operator_review_decision'] ?? null;
        if (is_string($decision) && strtolower($decision) === 'approved') {
            return true;
        }
        $forceReview = $overrides['operator_review_completed'] ?? null;
        if ($forceReview === true) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function isLocalAgentRaw(AiMemoryDelta $delta, array $overrides): bool
    {
        if (($overrides['lami_review_completed'] ?? false) === true) {
            return false;
        }
        $sourceType = strtolower((string) ($overrides['source_type'] ?? $delta->source_type ?? ''));
        $sourceKind = strtolower((string) ($overrides['source_kind'] ?? ''));

        return in_array($sourceType, [
            'local_agent_ingestion',
            'local_agent_memory_ingestion',
            'ai_local_agent_ingestion_candidate',
        ], true) || $sourceKind === 'local_agent';
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function resolveAuthorityLevel(AiMemoryDelta $delta, array $overrides): ?string
    {
        if (isset($overrides['authority_level']) && is_string($overrides['authority_level']) && trim($overrides['authority_level']) !== '') {
            return strtolower(trim($overrides['authority_level']));
        }
        $deltaAuthority = $delta->getAttribute('authority_level');
        if (is_string($deltaAuthority) && trim($deltaAuthority) !== '') {
            return strtolower(trim($deltaAuthority));
        }

        return null;
    }
}
