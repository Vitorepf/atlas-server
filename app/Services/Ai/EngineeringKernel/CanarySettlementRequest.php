<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CanarySettlementRequest
{
    private function __construct(
        public AuthorizedMergeAction $action,
        public string $landedEventId,
        public string $landedEventHash,
        public string $landedSha,
        public string $orderHash,
        public string $deliveryId,
        public string $evidenceHash,
        public string $provisionalOutcomeEventId,
        public string $provisionalOutcomeEventHash,
        public string $provisionalOutcomeHash,
        public string $observerIdentity,
    ) {
        foreach ([$landedEventHash, $orderHash, $evidenceHash, $provisionalOutcomeEventHash, $provisionalOutcomeHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('canary_settlement_hash_invalid');
            }
        }
        if ($landedEventId === '' || $provisionalOutcomeEventId === '' || preg_match('/^[a-f0-9]{40}$/', $landedSha) !== 1
            || $deliveryId === '' || $observerIdentity === '') {
            throw new InvalidArgumentException('canary_settlement_binding_invalid');
        }
    }

    public static function fromLanded(
        AuthorizedMergeAction $action,
        string $landedEventId,
        string $landedEventHash,
        string $landedSha,
        string $orderHash,
        string $deliveryId,
        string $evidenceHash,
        string $provisionalOutcomeEventId,
        string $provisionalOutcomeEventHash,
        string $provisionalOutcomeHash,
        string $observerIdentity,
    ): self {
        if ($action->baseCommit === '' || $action->treeHash === '' || $action->scopeHash === ''
            || $action->leaseId === '' || $action->fencingToken < 1 || $action->files === []) {
            throw new InvalidArgumentException('canary_settlement_action_incomplete');
        }

        return new self($action, $landedEventId, $landedEventHash, $landedSha, $orderHash, $deliveryId, $evidenceHash,
            $provisionalOutcomeEventId, $provisionalOutcomeEventHash, $provisionalOutcomeHash, $observerIdentity);
    }

    public static function fromCanonicalLanded(AuthorizedMergeAction $action, AtlasLedgerEvent $event,
        AtlasLedgerEvent $provisionalOutcomeEvent, string $orderHash, string $deliveryId, string $evidenceHash,
        string $observerIdentity): self
    {
        return self::fromLanded($action, (string) $event->event_id, self::canonicalEventHash($event),
            (string) data_get($event->payload, 'commit_sha'), $orderHash, $deliveryId, $evidenceHash,
            (string) $provisionalOutcomeEvent->event_id, self::canonicalEventHash($provisionalOutcomeEvent),
            (string) data_get($provisionalOutcomeEvent->payload, 'outcome_hash'), $observerIdentity);
    }

    public function matchesCanonicalLandedEvent(AtlasLedgerEvent $event): bool
    {
        return $event->event_id === $this->landedEventId && hash_equals(self::canonicalEventHash($event), $this->landedEventHash);
    }

    public function matchesProvisionalOutcomeEvent(AtlasLedgerEvent $event): bool
    {
        return $event->event_id === $this->provisionalOutcomeEventId
            && hash_equals(self::canonicalEventHash($event), $this->provisionalOutcomeEventHash);
    }

    private static function canonicalEventHash(AtlasLedgerEvent $event): string
    {
        $occurred = CarbonImmutable::parse($event->getAttribute('occurred_at'));

        return AtlasEvidenceLedger::computeEventHash(['event_id' => $event->event_id, 'event_type' => $event->event_type,
            'envelope_id' => $event->envelope_id, 'correlation_id' => $event->correlation_id, 'causation_id' => $event->causation_id,
            'scope_type' => $event->getAttribute('scope_type'), 'scope_id' => $event->getAttribute('scope_id'),
            'payload_hash' => $event->payload_hash, 'occurred_at' => $occurred->toISOString()]);
    }

    /** @return array<string,mixed> */
    public function binding(): array
    {
        return ['task_packet_id' => $this->action->taskPacketId, 'candidate_hash' => $this->action->candidateHash,
            'order_hash' => $this->orderHash, 'base_commit' => $this->action->baseCommit, 'tree_hash' => $this->action->treeHash,
            'delivery_id' => $this->deliveryId,
            'files' => $this->action->files, 'scope_hash' => $this->action->scopeHash, 'lease_id' => $this->action->leaseId,
            'fencing_token' => $this->action->fencingToken, 'evidence_hash' => $this->evidenceHash,
            'provisional_outcome_event_id' => $this->provisionalOutcomeEventId,
            'provisional_outcome_event_hash' => $this->provisionalOutcomeEventHash,
            'provisional_outcome_hash' => $this->provisionalOutcomeHash,
            'landed_event_id' => $this->landedEventId, 'landed_event_hash' => $this->landedEventHash,
            'landed_sha' => $this->landedSha, 'observer_identity' => $this->observerIdentity,
            'nonce' => $this->action->nonce];
    }

    public function idempotencyHash(): string
    {
        return CanonicalKernelPayload::hash($this->binding());
    }

    /**
     * P1b.2: SETTLE is bound to canary idempotency hash; LAND uses action.nonce.
     * Observer-minted settlement must carry both bindings.
     *
     * @return array{land_nonce:string,settle_idempotency_hash:string,landed_sha:string}
     */
    public function actSettlementBindings(): array
    {
        return [
            'land_nonce' => $this->action->nonce,
            'settle_idempotency_hash' => $this->idempotencyHash(),
            'landed_sha' => $this->landedSha,
        ];
    }
}
