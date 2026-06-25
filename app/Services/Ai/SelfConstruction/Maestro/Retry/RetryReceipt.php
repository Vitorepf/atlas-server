<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Immutable retry receipt. Value object — no setters; serialization via toArray().
 */
final class RetryReceipt
{
    /**
     * @param  list<string>  $originalAllowedFiles
     * @param  list<string>  $reshapedAllowedFiles
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $taskPacketId,
        public readonly int $attemptIndex,
        public readonly string $reshapeFingerprint,
        public readonly array $originalAllowedFiles,
        public readonly array $reshapedAllowedFiles,
        public readonly string $decision,
        public readonly string $policyReason,
        public readonly string $giveBackEvidenceHash,
        public readonly int $seq,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'attempt_index' => $this->attemptIndex,
            'decision' => $this->decision,
            'give_back_evidence_hash' => $this->giveBackEvidenceHash,
            'original_allowed_files' => $this->originalAllowedFiles,
            'policy_reason' => $this->policyReason,
            'receipt_id' => $this->receiptId,
            'reshape_fingerprint' => $this->reshapeFingerprint,
            'reshaped_allowed_files' => $this->reshapedAllowedFiles,
            'seq' => $this->seq,
            'task_packet_id' => $this->taskPacketId,
        ];
    }
}
