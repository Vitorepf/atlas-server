<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Append-only JSONL ledger of every retry decision. Local-first under
 * storage/atlas/maestro/retry/. Receipt id is a deterministic sha256 over the canonical fields so
 * the dispatcher and Cortex evidence layer can cite it.
 *
 * INVARIANTS:
 *   - Append-only: never mutates prior rows.
 *   - Byte-deterministic on the canonical body (receipt_id derived without seq; seq is monotonic).
 *   - Query by task_packet_id returns rows in append order.
 */
final class AtlasMaestroGiveBackRetryReceiptLedger
{
    public const QUARANTINE_THRESHOLD = 3;

    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * @param  array<string,mixed>  $facts {
     *   task_packet_id, attempt_index, reshape_fingerprint,
     *   original_allowed_files, reshaped_allowed_files,
     *   decision, policy_reason, give_back_evidence_hash
     * }
     */
    public function append(array $facts): RetryReceipt
    {
        $taskPacketId = (string) ($facts['task_packet_id'] ?? '');
        $attemptIndex = (int) ($facts['attempt_index'] ?? 0);
        $reshapeFingerprint = (string) ($facts['reshape_fingerprint'] ?? '');
        $originalAllowedFiles = array_values((array) ($facts['original_allowed_files'] ?? []));
        $reshapedAllowedFiles = array_values((array) ($facts['reshaped_allowed_files'] ?? []));
        $decision = (string) ($facts['decision'] ?? '');
        $policyReason = (string) ($facts['policy_reason'] ?? '');
        $giveBackEvidenceHash = (string) ($facts['give_back_evidence_hash'] ?? '');

        $receiptId = $this->receiptId([
            'task_packet_id' => $taskPacketId,
            'attempt_index' => $attemptIndex,
            'reshape_fingerprint' => $reshapeFingerprint,
            'original_allowed_files' => $originalAllowedFiles,
            'reshaped_allowed_files' => $reshapedAllowedFiles,
            'decision' => $decision,
            'policy_reason' => $policyReason,
            'give_back_evidence_hash' => $giveBackEvidenceHash,
        ]);

        // Duplicate suppression — same canonical body → return existing without re-appending.
        $existing = $this->forTask($taskPacketId);
        foreach ($existing as $row) {
            if (($row['receipt_id'] ?? '') === $receiptId) {
                return new RetryReceipt(
                    receiptId: $receiptId,
                    taskPacketId: $taskPacketId,
                    attemptIndex: (int) ($row['attempt_index'] ?? $attemptIndex),
                    reshapeFingerprint: (string) ($row['reshape_fingerprint'] ?? $reshapeFingerprint),
                    originalAllowedFiles: array_values((array) ($row['original_allowed_files'] ?? $originalAllowedFiles)),
                    reshapedAllowedFiles: array_values((array) ($row['reshaped_allowed_files'] ?? $reshapedAllowedFiles)),
                    decision: (string) ($row['decision'] ?? $decision),
                    policyReason: (string) ($row['policy_reason'] ?? $policyReason),
                    giveBackEvidenceHash: (string) ($row['give_back_evidence_hash'] ?? $giveBackEvidenceHash),
                    seq: (int) ($row['seq'] ?? 1),
                );
            }
        }

        $seq = count($existing) + 1;
        $receipt = new RetryReceipt(
            receiptId: $receiptId,
            taskPacketId: $taskPacketId,
            attemptIndex: $attemptIndex,
            reshapeFingerprint: $reshapeFingerprint,
            originalAllowedFiles: $originalAllowedFiles,
            reshapedAllowedFiles: $reshapedAllowedFiles,
            decision: $decision,
            policyReason: $policyReason,
            giveBackEvidenceHash: $giveBackEvidenceHash,
            seq: $seq,
        );
        $this->appendLine($taskPacketId, $receipt);

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forTask(string $taskPacketId): array
    {
        return $this->readJsonl($this->pathFor($taskPacketId));
    }

    /**
     * Bounded export — returns at most $limit rows from the task ledger.
     *
     * @return list<array<string,mixed>>
     */
    public function export(string $taskPacketId, int $limit = 50): array
    {
        return array_slice($this->forTask($taskPacketId), 0, $limit);
    }

    /**
     * Aggregate all receipts whose task_packet_id starts with $family followed by '-' or equals it.
     *
     * @return list<array<string,mixed>>
     */
    public function forFamily(string $family): array
    {
        $root = self::$rootOverride ?? storage_path('atlas/maestro/retry');
        if (! is_dir($root)) {
            return [];
        }
        $safeFamily = preg_replace('/[^A-Za-z0-9._-]/', '_', $family) ?? '';
        $rows = [];
        foreach ((array) glob($root.'/*.jsonl') as $file) {
            $base = basename((string) $file, '.jsonl');
            if ($base === $safeFamily || str_starts_with($base, $safeFamily.'-')) {
                $rows = array_merge($rows, $this->readJsonl((string) $file));
            }
        }

        return $rows;
    }

    public function isQuarantined(string $taskPacketId): bool
    {
        return count($this->forTask($taskPacketId)) >= self::QUARANTINE_THRESHOLD;
    }

    /**
     * @param  array<string,mixed>  $canonical
     */
    public function receiptId(array $canonical): string
    {
        ksort($canonical);
        foreach ($canonical as &$v) {
            if (is_array($v)) {
                ksort($v);
            }
        }
        unset($v);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function appendLine(string $taskPacketId, RetryReceipt $receipt): void
    {
        $path = $this->pathFor($taskPacketId);
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('retry_receipt_mkdir_failed:'.$dir);
        }
        $line = (string) json_encode($receipt->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /** @return list<array<string,mixed>> */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $rows = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return $rows;
    }

    private function pathFor(string $taskPacketId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $taskPacketId) ?? 'unknown';
        $root = self::$rootOverride ?? storage_path('atlas/maestro/retry');

        return rtrim($root, '/').'/'.$safe.'.jsonl';
    }
}
