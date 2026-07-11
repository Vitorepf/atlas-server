<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use InvalidArgumentException;

/**
 * Immutable Governor-issued capability for one MergeActuator effect.
 *
 * The actuator treats this as a pointer to a successfully persisted release-ledger
 * row, not as self-authenticating permission: act() must still replay the ledger
 * and verify the row/candidate before touching git.
 */
final readonly class AuthorizedMergeAction
{
    public const SCHEMA = 'atlas.engineering_kernel.authorized_merge_action.v2';

    /**
     * @param  list<string>  $files
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public string $taskPacketId,
        public string $candidateHash,
        public string $decisionHash,
        public string $releaseLedgerPath,
        public string $targetSha = '',
        public array $files = [],
        public string $riskLevel = '',
        public string $verificationHash = '',
        public string $rollbackHash = '',
        public string $changedFilesHash = '',
        public string $issuedAt = '',
        public string $expiresAt = '',
        public bool $revoked = false,
        public ?string $settlementLedgerPath = null,
        public array $metadata = [],
        public string $authorityHash = '',
        public string $canonicalEventId = '',
        public string $canonicalEventHash = '',
        public string $nonce = '',
        public string $baseCommit = '',
        public string $treeHash = '',
        public string $scopeHash = '',
        public string $leaseId = '',
        public int $fencingToken = 0,
        public string $orderHash = '',
        public string $deliveryId = '',
        public string $evidenceHash = '',
    ) {
        if ($this->action === '') {
            throw new InvalidArgumentException('authorized merge action: missing action');
        }
        if ($this->taskPacketId === '') {
            throw new InvalidArgumentException('authorized merge action: missing task_packet_id');
        }
        if ($this->candidateHash === '') {
            throw new InvalidArgumentException('authorized merge action: missing candidate_hash');
        }
        if ($this->decisionHash === '') {
            throw new InvalidArgumentException('authorized merge action: missing decision_hash');
        }
        if ($this->releaseLedgerPath === '') {
            throw new InvalidArgumentException('authorized merge action: missing release_ledger_path');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $metadata
     */
    public static function fromReleaseDecisionRow(
        array $row,
        string $action,
        string $releaseLedgerPath,
        string $targetSha = '',
        array $files = [],
        int $ttlSeconds = 300,
        ?string $settlementLedgerPath = null,
        array $metadata = [],
        array $canonicalBinding = [],
    ): self {
        $taskId = trim((string) ($row['task_packet_id'] ?? ''));
        $candidateHash = trim((string) ($row['candidate_hash'] ?? ''));
        $decisionHash = trim((string) ($row['decision_hash'] ?? ''));
        $issuedAt = trim((string) ($row['decided_at'] ?? date(DATE_ATOM)));
        $expiresAt = date(DATE_ATOM, strtotime($issuedAt) + max(1, $ttlSeconds));

        $authority = new self(
            action: $action,
            taskPacketId: $taskId,
            candidateHash: $candidateHash,
            decisionHash: $decisionHash,
            releaseLedgerPath: $releaseLedgerPath,
            targetSha: $targetSha,
            files: array_values(array_map('strval', $files)),
            riskLevel: (string) ($row['risk_level'] ?? ''),
            verificationHash: (string) ($row['verification_hash'] ?? ''),
            rollbackHash: (string) ($row['rollback_hash'] ?? ''),
            changedFilesHash: (string) ($row['changed_files_hash'] ?? ''),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            settlementLedgerPath: $settlementLedgerPath,
            metadata: $metadata,
            canonicalEventId: (string) ($canonicalBinding['event_id'] ?? ''),
            canonicalEventHash: (string) ($canonicalBinding['event_hash'] ?? ''),
            nonce: (string) ($canonicalBinding['nonce'] ?? ''),
            baseCommit: (string) ($canonicalBinding['base_commit'] ?? ''),
            treeHash: (string) ($canonicalBinding['tree_hash'] ?? ''),
            scopeHash: (string) ($canonicalBinding['scope_hash'] ?? ''),
            leaseId: (string) ($canonicalBinding['lease_id'] ?? ''),
            fencingToken: (int) ($canonicalBinding['fencing_token'] ?? 0),
            orderHash: (string) ($canonicalBinding['order_hash'] ?? ''),
            deliveryId: (string) ($canonicalBinding['delivery_id'] ?? ''),
            evidenceHash: (string) ($canonicalBinding['evidence_hash'] ?? ''),
        );

        if ((string) ($row['decision'] ?? '') !== AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED) {
            return $authority->withMetadata(['non_admitted_decision' => (string) ($row['decision'] ?? '')]);
        }

        return $authority->withAuthorityHash();
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            action: (string) ($payload['action'] ?? ''),
            taskPacketId: (string) ($payload['task_packet_id'] ?? ''),
            candidateHash: (string) ($payload['candidate_hash'] ?? ''),
            decisionHash: (string) ($payload['decision_hash'] ?? ''),
            releaseLedgerPath: (string) ($payload['release_ledger_path'] ?? ''),
            targetSha: (string) ($payload['target_sha'] ?? ''),
            files: array_values(array_map('strval', (array) ($payload['files'] ?? []))),
            riskLevel: (string) ($payload['risk_level'] ?? ''),
            verificationHash: (string) ($payload['verification_hash'] ?? ''),
            rollbackHash: (string) ($payload['rollback_hash'] ?? ''),
            changedFilesHash: (string) ($payload['changed_files_hash'] ?? ''),
            issuedAt: (string) ($payload['issued_at'] ?? ''),
            expiresAt: (string) ($payload['expires_at'] ?? ''),
            revoked: (bool) ($payload['revoked'] ?? false),
            settlementLedgerPath: isset($payload['settlement_ledger_path']) ? (string) $payload['settlement_ledger_path'] : null,
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            authorityHash: (string) ($payload['authority_hash'] ?? ''),
            canonicalEventId: (string) ($payload['canonical_event_id'] ?? ''),
            canonicalEventHash: (string) ($payload['canonical_event_hash'] ?? ''),
            nonce: (string) ($payload['nonce'] ?? ''),
            baseCommit: (string) ($payload['base_commit'] ?? ''),
            treeHash: (string) ($payload['tree_hash'] ?? ''),
            scopeHash: (string) ($payload['scope_hash'] ?? ''),
            leaseId: (string) ($payload['lease_id'] ?? ''),
            fencingToken: (int) ($payload['fencing_token'] ?? 0),
            orderHash: (string) ($payload['order_hash'] ?? ''),
            deliveryId: (string) ($payload['delivery_id'] ?? ''),
            evidenceHash: (string) ($payload['evidence_hash'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $this->action,
            'task_packet_id' => $this->taskPacketId,
            'candidate_hash' => $this->candidateHash,
            'decision_hash' => $this->decisionHash,
            'release_ledger_path' => $this->releaseLedgerPath,
            'target_sha' => $this->targetSha,
            'files' => $this->files,
            'risk_level' => $this->riskLevel,
            'verification_hash' => $this->verificationHash,
            'rollback_hash' => $this->rollbackHash,
            'changed_files_hash' => $this->changedFilesHash,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => $this->revoked,
            'settlement_ledger_path' => $this->settlementLedgerPath,
            'metadata' => $this->metadata,
            'authority_hash' => $this->authorityHash !== '' ? $this->authorityHash : $this->computeAuthorityHash(),
            'canonical_event_id' => $this->canonicalEventId,
            'canonical_event_hash' => $this->canonicalEventHash,
            'nonce' => $this->nonce,
            'base_commit' => $this->baseCommit,
            'tree_hash' => $this->treeHash,
            'scope_hash' => $this->scopeHash,
            'lease_id' => $this->leaseId,
            'fencing_token' => $this->fencingToken,
            'order_hash' => $this->orderHash,
            'delivery_id' => $this->deliveryId,
            'evidence_hash' => $this->evidenceHash,
        ];
    }

    public function dryRun(): bool
    {
        return ($this->metadata['dry_run'] ?? false) === true;
    }

    public function settlementLedgerPath(): string
    {
        return $this->settlementLedgerPath !== null && $this->settlementLedgerPath !== ''
            ? $this->settlementLedgerPath
            : $this->releaseLedgerPath;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return new self(
            action: $this->action,
            taskPacketId: $this->taskPacketId,
            candidateHash: $this->candidateHash,
            decisionHash: $this->decisionHash,
            releaseLedgerPath: $this->releaseLedgerPath,
            targetSha: $this->targetSha,
            files: $this->files,
            riskLevel: $this->riskLevel,
            verificationHash: $this->verificationHash,
            rollbackHash: $this->rollbackHash,
            changedFilesHash: $this->changedFilesHash,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            revoked: $this->revoked,
            settlementLedgerPath: $this->settlementLedgerPath,
            metadata: array_replace($this->metadata, $metadata),
            canonicalEventId: $this->canonicalEventId,
            canonicalEventHash: $this->canonicalEventHash,
            nonce: $this->nonce,
            baseCommit: $this->baseCommit,
            treeHash: $this->treeHash,
            scopeHash: $this->scopeHash,
            leaseId: $this->leaseId,
            fencingToken: $this->fencingToken,
            orderHash: $this->orderHash,
            deliveryId: $this->deliveryId,
            evidenceHash: $this->evidenceHash,
        )->withAuthorityHash();
    }

    public function withSettlementLedgerPath(string $path): self
    {
        return new self(
            action: $this->action,
            taskPacketId: $this->taskPacketId,
            candidateHash: $this->candidateHash,
            decisionHash: $this->decisionHash,
            releaseLedgerPath: $this->releaseLedgerPath,
            targetSha: $this->targetSha,
            files: $this->files,
            riskLevel: $this->riskLevel,
            verificationHash: $this->verificationHash,
            rollbackHash: $this->rollbackHash,
            changedFilesHash: $this->changedFilesHash,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            revoked: $this->revoked,
            settlementLedgerPath: $path,
            metadata: $this->metadata,
            canonicalEventId: $this->canonicalEventId,
            canonicalEventHash: $this->canonicalEventHash,
            nonce: $this->nonce,
            baseCommit: $this->baseCommit,
            treeHash: $this->treeHash,
            scopeHash: $this->scopeHash,
            leaseId: $this->leaseId,
            fencingToken: $this->fencingToken,
            orderHash: $this->orderHash,
            deliveryId: $this->deliveryId,
            evidenceHash: $this->evidenceHash,
        )->withAuthorityHash();
    }

    public function withRevoked(bool $revoked = true): self
    {
        return new self(
            action: $this->action,
            taskPacketId: $this->taskPacketId,
            candidateHash: $this->candidateHash,
            decisionHash: $this->decisionHash,
            releaseLedgerPath: $this->releaseLedgerPath,
            targetSha: $this->targetSha,
            files: $this->files,
            riskLevel: $this->riskLevel,
            verificationHash: $this->verificationHash,
            rollbackHash: $this->rollbackHash,
            changedFilesHash: $this->changedFilesHash,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            revoked: $revoked,
            settlementLedgerPath: $this->settlementLedgerPath,
            metadata: $this->metadata,
            canonicalEventId: $this->canonicalEventId,
            canonicalEventHash: $this->canonicalEventHash,
            nonce: $this->nonce,
            baseCommit: $this->baseCommit,
            treeHash: $this->treeHash,
            scopeHash: $this->scopeHash,
            leaseId: $this->leaseId,
            fencingToken: $this->fencingToken,
            orderHash: $this->orderHash,
            deliveryId: $this->deliveryId,
            evidenceHash: $this->evidenceHash,
        )->withAuthorityHash();
    }

    public function withExpiresAt(string $expiresAt): self
    {
        return new self(
            action: $this->action,
            taskPacketId: $this->taskPacketId,
            candidateHash: $this->candidateHash,
            decisionHash: $this->decisionHash,
            releaseLedgerPath: $this->releaseLedgerPath,
            targetSha: $this->targetSha,
            files: $this->files,
            riskLevel: $this->riskLevel,
            verificationHash: $this->verificationHash,
            rollbackHash: $this->rollbackHash,
            changedFilesHash: $this->changedFilesHash,
            issuedAt: $this->issuedAt,
            expiresAt: $expiresAt,
            revoked: $this->revoked,
            settlementLedgerPath: $this->settlementLedgerPath,
            metadata: $this->metadata,
            canonicalEventId: $this->canonicalEventId,
            canonicalEventHash: $this->canonicalEventHash,
            nonce: $this->nonce,
            baseCommit: $this->baseCommit,
            treeHash: $this->treeHash,
            scopeHash: $this->scopeHash,
            leaseId: $this->leaseId,
            fencingToken: $this->fencingToken,
            orderHash: $this->orderHash,
            deliveryId: $this->deliveryId,
            evidenceHash: $this->evidenceHash,
        )->withAuthorityHash();
    }

    public function computeAuthorityHash(): string
    {
        $payload = $this->toUnsignedArray();

        return hash('sha256', (string) json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function authorityHashValid(): bool
    {
        return $this->authorityHash === '' || hash_equals($this->computeAuthorityHash(), $this->authorityHash);
    }

    private function withAuthorityHash(): self
    {
        return new self(
            action: $this->action,
            taskPacketId: $this->taskPacketId,
            candidateHash: $this->candidateHash,
            decisionHash: $this->decisionHash,
            releaseLedgerPath: $this->releaseLedgerPath,
            targetSha: $this->targetSha,
            files: $this->files,
            riskLevel: $this->riskLevel,
            verificationHash: $this->verificationHash,
            rollbackHash: $this->rollbackHash,
            changedFilesHash: $this->changedFilesHash,
            issuedAt: $this->issuedAt,
            expiresAt: $this->expiresAt,
            revoked: $this->revoked,
            settlementLedgerPath: $this->settlementLedgerPath,
            metadata: $this->metadata,
            authorityHash: $this->computeAuthorityHash(),
            canonicalEventId: $this->canonicalEventId,
            canonicalEventHash: $this->canonicalEventHash,
            nonce: $this->nonce,
            baseCommit: $this->baseCommit,
            treeHash: $this->treeHash,
            scopeHash: $this->scopeHash,
            leaseId: $this->leaseId,
            fencingToken: $this->fencingToken,
            orderHash: $this->orderHash,
            deliveryId: $this->deliveryId,
            evidenceHash: $this->evidenceHash,
        );
    }

    /** @return array<string,mixed> */
    private function toUnsignedArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $this->action,
            'task_packet_id' => $this->taskPacketId,
            'candidate_hash' => $this->candidateHash,
            'decision_hash' => $this->decisionHash,
            'release_ledger_path' => $this->releaseLedgerPath,
            'target_sha' => $this->targetSha,
            'files' => $this->files,
            'risk_level' => $this->riskLevel,
            'verification_hash' => $this->verificationHash,
            'rollback_hash' => $this->rollbackHash,
            'changed_files_hash' => $this->changedFilesHash,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => $this->revoked,
            'settlement_ledger_path' => $this->settlementLedgerPath,
            'metadata' => $this->metadata,
            'canonical_event_id' => $this->canonicalEventId,
            'canonical_event_hash' => $this->canonicalEventHash,
            'nonce' => $this->nonce,
            'base_commit' => $this->baseCommit,
            'tree_hash' => $this->treeHash,
            'scope_hash' => $this->scopeHash,
            'lease_id' => $this->leaseId,
            'fencing_token' => $this->fencingToken,
            'order_hash' => $this->orderHash,
            'delivery_id' => $this->deliveryId,
            'evidence_hash' => $this->evidenceHash,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
