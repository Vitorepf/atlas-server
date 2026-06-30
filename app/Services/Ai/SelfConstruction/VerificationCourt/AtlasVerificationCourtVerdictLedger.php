<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\VerificationCourt;

use RuntimeException;

/**
 * Append-only ledger for Verification Court verdicts. Every pass / fail / blocked candidate is
 * audited BEFORE Merge Governor admission. Mirror of the merge-governor decision ledger pattern.
 *
 * INVARIANTS:
 *   - APPEND-ONLY: fopen('a') + flock(LOCK_EX); existing rows NEVER overwritten.
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
        ];
        ksort($canonical);
        $verdictHash = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->alreadyRecorded($verdictHash)) {
            return ['status' => self::STATUS_ALREADY];
        }

        $row = $canonical + ['schema' => self::SCHEMA, 'verdict_hash' => $verdictHash];
        $this->appendOnly($row);

        return ['status' => self::STATUS_OK, 'row' => $row];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $out = [];
        foreach (file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byTaskPacketId(string $taskPacketId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['task_packet_id'] ?? '') === $taskPacketId));
    }

    private function alreadyRecorded(string $verdictHash): bool
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['verdict_hash'] ?? '') === $verdictHash) {
                return true;
            }
        }

        return false;
    }

    private function lastVerdictHash(): ?string
    {
        $rows = $this->all();
        if ($rows === []) {
            return null;
        }
        $h = (string) (end($rows)['verdict_hash'] ?? '');

        return $h !== '' ? $h : null;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendOnly(array &$row): void
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($this->ledgerPath, 'a');
        if ($fh === false) {
            throw new RuntimeException('verdict ledger cannot open '.$this->ledgerPath);
        }
        try {
            if (! flock($fh, LOCK_EX)) {
                throw new RuntimeException('verdict ledger cannot acquire LOCK_EX');
            }
            $prevHash = $this->lastVerdictHash();
            $row['previous_verdict_hash'] = $prevHash;
            $row['ledger_chain_hash'] = hash('sha256', ($prevHash ?? '').$row['verdict_hash']);
            fwrite($fh, (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($fh);
            @\fsync($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
