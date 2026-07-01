<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only ledger for Verification Court verdicts. Every pass / fail / blocked candidate is
 * audited BEFORE Merge Governor admission. Mirror of the merge-governor decision ledger pattern.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: kernel JsonlReceiptStore (flock LOCK_EX); existing rows NEVER overwritten.
 *   - VALIDATES: task_packet_id, evidence_hash, replay_plan_hash, verdict (allowlist), reasons,
 *     replay_outcome_hash, decided_at — missing/invalid ⇒ throws and DOES NOT WRITE.
 *   - IDEMPOTENT: duplicate verdict_hash ⇒ status=already_recorded, no second row.
 *   - DETERMINISTIC verdict_hash = sha256 over canonical fields.
 */
final class AtlasVerificationCourtVerdictLedger
{
    public const SCHEMA = 'atlas.verificationcourt.verdict.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_ALREADY = 'already_recorded';

    public const ALLOWED_VERDICTS = [
        AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
        AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
        AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
    ];

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @param  array{
     *     task_packet_id:string,
     *     evidence_hash:string,
     *     replay_plan_hash:string,
     *     verdict:string,
     *     reasons:list<string>,
     *     replay_outcome_hash:string,
     *     decided_at:string
     * }  $payload
     * @return array{status:string, row?:array<string,mixed>}
     */
    public function append(array $payload): array
    {
        $taskId = (string) ($payload['task_packet_id'] ?? '');
        $evHash = (string) ($payload['evidence_hash'] ?? '');
        $planHash = (string) ($payload['replay_plan_hash'] ?? '');
        $verdict = (string) ($payload['verdict'] ?? '');
        $reasons = is_array($payload['reasons'] ?? null) ? array_values(array_map('strval', $payload['reasons'])) : null;
        $outcomeHash = (string) ($payload['replay_outcome_hash'] ?? '');
        $decidedAt = (string) ($payload['decided_at'] ?? '');
        $workerFeedReasonCodes = is_array($payload['worker_feed_reason_codes'] ?? null)
            ? array_values(array_unique(array_map('strval', $payload['worker_feed_reason_codes'])))
            : [];
        sort($workerFeedReasonCodes, SORT_STRING);
        $evidenceSnapshot = is_array($payload['evidence_snapshot'] ?? null) ? $payload['evidence_snapshot'] : null;
        $evidenceSnapshotHash = $evidenceSnapshot !== null
            ? hash('sha256', (string) json_encode($evidenceSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : null;

        if ($taskId === '') {
            throw new RuntimeException('verdict ledger: missing task_packet_id');
        }
        if ($evHash === '') {
            throw new RuntimeException('verdict ledger: missing evidence_hash');
        }
        if ($planHash === '') {
            throw new RuntimeException('verdict ledger: missing replay_plan_hash');
        }
        if (! in_array($verdict, self::ALLOWED_VERDICTS, true)) {
            throw new RuntimeException('verdict ledger: invalid verdict: '.($verdict === '' ? 'missing' : $verdict));
        }
        if ($reasons === null) {
            throw new RuntimeException('verdict ledger: missing reasons (use [] for none)');
        }
        // failed and blocked verdicts MUST carry diagnostic reasons — empty list is not enough evidence.
        if (in_array($verdict, [AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED, AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED], true) && $reasons === []) {
            throw new RuntimeException('verdict ledger: '.$verdict.' verdict requires non-empty diagnostic reasons');
        }
        if ($outcomeHash === '') {
            throw new RuntimeException('verdict ledger: missing replay_outcome_hash');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|\+00:00)$/', $decidedAt)) {
            throw new RuntimeException('verdict ledger: decided_at must be canonical UTC ISO-8601, got: '.$decidedAt);
        }

        sort($reasons, SORT_STRING);
        $canonical = [
            'task_packet_id' => $taskId,
            'evidence_hash' => $evHash,
            'replay_plan_hash' => $planHash,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'replay_outcome_hash' => $outcomeHash,
            'decided_at' => $decidedAt,
            'worker_feed_reason_codes' => $workerFeedReasonCodes,
            'evidence_snapshot_hash' => $evidenceSnapshotHash,
        ];
        $verdictHash = $this->hashCanonicalFields($canonical);

        $row = $canonical + ['schema' => self::SCHEMA, 'verdict_hash' => $verdictHash];

        // Idempotency AND chain derivation run INSIDE the store's write lock: the previous
        // verdict hash comes from the tail line handed to us under LOCK_EX, so concurrent
        // writers can neither duplicate a verdict nor fork the chain. Null return aborts.
        $written = $this->store()->appendWith(function (?string $lastLine) use ($verdictHash, &$row): ?array {
            foreach ($this->all() as $r) {
                if ((string) ($r['verdict_hash'] ?? '') === $verdictHash) {
                    return null;
                }
            }

            $last = $lastLine !== null ? json_decode($lastLine, true) : null;
            $prevHash = is_array($last) && (string) ($last['verdict_hash'] ?? '') !== '' ? (string) $last['verdict_hash'] : null;
            $row['previous_verdict_hash'] = $prevHash;
            $row['ledger_chain_hash'] = hash('sha256', ($prevHash ?? '').$verdictHash);

            return $row;
        });

        return $written === null ? ['status' => self::STATUS_ALREADY] : ['status' => self::STATUS_OK, 'row' => $row];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->store()->replay();
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->ledgerPath);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byTaskPacketId(string $taskPacketId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['task_packet_id'] ?? '') === $taskPacketId));
    }

    /**
     * Deterministic tamper scan over the append-only hash chain: recomputes each row's
     * verdict_hash from its own canonical fields (catches content edits), then walks the
     * chain verifying previous_verdict_hash points at the prior row's verdict_hash and
     * ledger_chain_hash matches (catches reordering, deletion, or a missing/forged link).
     *
     * @return array{ok:bool, tamper_reason:?string, tampered_row_index:?int, checked_row_count:int}
     */
    public function integrityScan(): array
    {
        $rows = $this->all();
        $previousExpectedHash = null;

        foreach ($rows as $index => $row) {
            $storedVerdictHash = (string) ($row['verdict_hash'] ?? '');
            if ($storedVerdictHash === '' || $this->hashCanonicalFields($this->canonicalHashFields($row)) !== $storedVerdictHash) {
                return $this->tamperResult($index, 'row_content_hash_mismatch', count($rows));
            }

            $storedPreviousHash = array_key_exists('previous_verdict_hash', $row) ? $row['previous_verdict_hash'] : null;
            if ($storedPreviousHash !== $previousExpectedHash) {
                return $this->tamperResult(
                    $index,
                    $index === 0 ? 'first_row_previous_verdict_hash_must_be_null' : 'previous_verdict_hash_missing_or_reordered',
                    count($rows),
                );
            }

            $expectedChainHash = hash('sha256', ($storedPreviousHash ?? '').$storedVerdictHash);
            $storedChainHash = (string) ($row['ledger_chain_hash'] ?? '');
            if ($storedChainHash === '' || $storedChainHash !== $expectedChainHash) {
                return $this->tamperResult($index, 'ledger_chain_hash_mismatch', count($rows));
            }

            $previousExpectedHash = $storedVerdictHash;
        }

        return ['ok' => true, 'tamper_reason' => null, 'tampered_row_index' => null, 'checked_row_count' => count($rows)];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{task_packet_id:string, evidence_hash:string, replay_plan_hash:string, verdict:string, reasons:list<string>, replay_outcome_hash:string, decided_at:string, worker_feed_reason_codes:list<string>, evidence_snapshot_hash:?string}
     */
    private function canonicalHashFields(array $row): array
    {
        return [
            'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
            'evidence_hash' => (string) ($row['evidence_hash'] ?? ''),
            'replay_plan_hash' => (string) ($row['replay_plan_hash'] ?? ''),
            'verdict' => (string) ($row['verdict'] ?? ''),
            'reasons' => is_array($row['reasons'] ?? null) ? array_values(array_map('strval', $row['reasons'])) : [],
            'replay_outcome_hash' => (string) ($row['replay_outcome_hash'] ?? ''),
            'decided_at' => (string) ($row['decided_at'] ?? ''),
            'worker_feed_reason_codes' => is_array($row['worker_feed_reason_codes'] ?? null) ? array_values(array_map('strval', $row['worker_feed_reason_codes'])) : [],
            'evidence_snapshot_hash' => isset($row['evidence_snapshot_hash']) ? (string) $row['evidence_snapshot_hash'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $canonical
     */
    private function hashCanonicalFields(array $canonical): string
    {
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{ok:bool, tamper_reason:?string, tampered_row_index:?int, checked_row_count:int}
     */
    private function tamperResult(int $index, string $reason, int $checkedRowCount): array
    {
        return ['ok' => false, 'tamper_reason' => $reason, 'tampered_row_index' => $index, 'checked_row_count' => $checkedRowCount];
    }

}
