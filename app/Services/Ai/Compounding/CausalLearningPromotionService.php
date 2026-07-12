<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use InvalidArgumentException;

final class CausalLearningPromotionService
{
    public function __construct(
        private readonly ?AtlasEvidenceLedger $ledger = null,
        private readonly ?CausalLearningPromotionOwner $owner = null,
    ) {}

    public function promote(CausalLearningCandidate $candidate, CausalLearningVerdict $verdict, string $nextVersion, array $evidenceArtifacts = []): CausalLearningPromotion
    {
        if ($verdict->verdict !== 'promote_reversible' || ! in_array($candidate->data['change_class'], ['routing', 'memory_policy', 'operational_policy'], true)) {
            throw new InvalidArgumentException('causal_policy_promotion_refused');
        }
        $expectedDecisionHash = CompoundingHash::make([
            'candidate' => $candidate->candidateHash,
            'verdict' => $verdict->verdict,
            'reason' => $verdict->reason,
        ]);
        if (! hash_equals($expectedDecisionHash, $verdict->decisionHash)) {
            throw new InvalidArgumentException('causal_verdict_stale_or_mismatched');
        }
        if ($verdict->claimEligible) {
            throw new InvalidArgumentException('causal_claim_authority_escalation');
        }
        if (date_create_immutable((string) $candidate->data['expiry']) <= new \DateTimeImmutable('now')) {
            throw new InvalidArgumentException('causal_policy_promotion_expired');
        }
        $binding = $this->bindingVerifier()->verify($candidate, $evidenceArtifacts);
        if (! $binding['admitted']) {
            throw new InvalidArgumentException('causal_evidence_binding_unresolved');
        }
        if ($candidate->data['reversible'] !== true || trim($candidate->data['rollback']) === '' || trim($nextVersion) === '') {
            throw new InvalidArgumentException('causal_policy_rollback_contract_invalid');
        }

        $ownerBinding = array_filter([
            'task_category' => trim((string) ($candidate->data['task_category'] ?? '')),
            'role' => trim((string) ($candidate->data['role'] ?? '')),
            'provider' => trim((string) ($candidate->data['provider'] ?? '')),
            'model' => trim((string) ($candidate->data['model'] ?? '')),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        $owner = $this->ownerForCandidate($candidate);
        $effect = $owner?->apply($candidate, $nextVersion);
        if ($effect !== null && ($effect['applied'] ?? false) !== true) {
            throw new InvalidArgumentException((string) ($effect['reason'] ?? 'causal_owner_application_refused'));
        }

        $promotion = new CausalLearningPromotion(
            status: 'promoted', candidateHash: $candidate->candidateHash, decisionHash: $verdict->decisionHash,
            scope: $candidate->data['scope'], activeVersion: $nextVersion, previousVersion: $candidate->data['baseline'],
            rollbackVersion: $candidate->data['rollback'], expiresAt: $candidate->data['expiry'],
            observationSchedule: $candidate->data['observation_schedule'], reason: 'causal_evidence_admitted',
            ownerBinding: $ownerBinding,
            owner: (string) ($effect['owner'] ?? ''),
            beforeState: $effect['before_state'] ?? [],
            afterState: $effect['after_state'] ?? [],
            effectReceiptHash: (string) ($effect['effect_receipt_hash'] ?? ''),
        );
        $this->record($promotion, 'causal.learning.promoted');

        return $promotion;
    }

    public function revoke(CausalLearningPromotion $promotion, string $reason): CausalLearningPromotion
    {
        if (trim($reason) === '') throw new InvalidArgumentException('causal_revoke_reason_required');

        $owner = $this->ownerForPromotion($promotion);
        $effect = $owner?->rollback($promotion);
        if ($effect !== null && ($effect['rolled_back'] ?? false) !== true) {
            throw new InvalidArgumentException((string) ($effect['reason'] ?? 'causal_owner_rollback_refused'));
        }

        $revoked = new CausalLearningPromotion(
            status: 'revoked', candidateHash: $promotion->candidateHash, decisionHash: $promotion->decisionHash,
            scope: $promotion->scope, activeVersion: $promotion->rollbackVersion, previousVersion: $promotion->activeVersion,
            rollbackVersion: $promotion->rollbackVersion, expiresAt: $promotion->expiresAt,
            observationSchedule: $promotion->observationSchedule, reason: $reason,
            ownerBinding: $promotion->ownerBinding,
            owner: (string) ($effect['owner'] ?? $promotion->owner),
            beforeState: $effect['before_state'] ?? $promotion->afterState,
            afterState: $effect['after_state'] ?? [],
            effectReceiptHash: (string) ($effect['effect_receipt_hash'] ?? ''),
        );
        $this->record($revoked, 'causal.learning.revoked');

        return $revoked;
    }

    private function record(CausalLearningPromotion $promotion, string $eventName): void
    {
        $ledger = $this->ledger;
        if ($ledger === null && function_exists('app')) {
            try { $ledger = app()->bound(AtlasEvidenceLedger::class) ? app(AtlasEvidenceLedger::class) : null; } catch (\Throwable) { $ledger = null; }
        }
        if (! $ledger instanceof AtlasEvidenceLedger) return;
        $eventId = 'causal-policy-'.substr(hash('sha256', $eventName.'|'.$promotion->candidateHash.'|'.$promotion->status), 0, 18);
        if ($ledger->eventById($eventId) !== null) return;
        $ledger->record(LedgerEventType::LearningProposed, [
            'event_name' => $eventName, 'candidate_hash' => $promotion->candidateHash, 'decision_hash' => $promotion->decisionHash,
            'status' => $promotion->status, 'scope' => $promotion->scope, 'active_version' => $promotion->activeVersion,
            'rollback_version' => $promotion->rollbackVersion, 'expires_at' => $promotion->expiresAt,
            'observation_schedule' => $promotion->observationSchedule, 'reason' => $promotion->reason,
            'claim_eligible' => false, 'owner' => $promotion->owner, 'owner_binding' => $promotion->ownerBinding,
            'before_state' => $promotion->beforeState, 'after_state' => $promotion->afterState,
            'effect_receipt_hash' => $promotion->effectReceiptHash,
        ], ['event_id' => $eventId, 'correlation_id' => $promotion->candidateHash, 'scope_type' => 'causal_learning',
            'scope_id' => $promotion->scope, 'emitter_stage' => 'atlas.compounding.causal_promotion']);
    }

    private function bindingVerifier(): CausalLearningEvidenceBindingVerifier
    {
        $ledger = $this->ledger;
        if ($ledger === null && function_exists('app')) {
            try {
                $ledger = app(AtlasEvidenceLedger::class);
            } catch (\Throwable) {
                $ledger = null;
            }
        }

        return new CausalLearningEvidenceBindingVerifier($ledger);
    }

    private function ownerForCandidate(CausalLearningCandidate $candidate): ?CausalLearningPromotionOwner
    {
        if ($this->owner !== null) {
            return $this->owner;
        }
        if ($candidate->data['change_class'] !== 'routing') {
            return null;
        }
        if (trim((string) ($candidate->data['task_category'] ?? '')) === ''
            || trim((string) ($candidate->data['role'] ?? '')) === ''
            || trim((string) ($candidate->data['provider'] ?? '')) === '') {
            return null;
        }

        return new CausalLearningRoutingPromotionOwner(new AtlasConductorRoutingMemory);
    }

    private function ownerForPromotion(CausalLearningPromotion $promotion): ?CausalLearningPromotionOwner
    {
        if ($this->owner !== null) {
            return $this->owner;
        }
        if ($promotion->owner === CausalLearningRoutingPromotionOwner::OWNER) {
            return new CausalLearningRoutingPromotionOwner(new AtlasConductorRoutingMemory);
        }

        return null;
    }
}
