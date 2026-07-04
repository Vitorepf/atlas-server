<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use DomainException;
use Generator;
use Throwable;

final class AtlasMaestroClosedLoopReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.closed_loop.receipt_ledger.v1';

    public function __construct(
        private readonly ?string $path = null,
        private readonly ?string $shapeLedgerPath = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordCycle(array $payload): array
    {
        $entry = [
            'schema' => self::SCHEMA,
            'timestamp' => (string) ($payload['timestamp'] ?? gmdate('c')),
            'decision_id' => (string) ($payload['decision_id'] ?? $this->generateDecisionId()),
            'input_hash' => $this->computeInputHash($payload),
            'output_hash' => $this->computeOutputHash($payload),
            'recorded_at' => (string) ($payload['timestamp'] ?? gmdate('c')),
            'ledger_snapshot_hash' => $this->ledgerSnapshotHash(),
            'mined_bucket_count' => max(0, (int) ($payload['mined_bucket_count'] ?? 0)),
            'guarded_pass_or_reject' => $this->passOrReject($payload['guarded_pass_or_reject'] ?? null),
            'feedback_block_sha256' => $this->feedbackHash((string) ($payload['feedback_block'] ?? '')),
            'promoted_rules' => $this->sanitizePromotedRules((array) ($payload['promoted_rules'] ?? [])),
            'replenisher_consumed' => (bool) ($payload['replenisher_consumed'] ?? false),
            'flag_enabled' => (bool) ($payload['flag_enabled'] ?? false),
            'quality_flags' => $this->sanitizePromotedRules((array) ($payload['quality_flags'] ?? [])),
            'next_action_recommendation' => trim((string) ($payload['next_action_recommendation'] ?? '')),
        ];
        $entry['outcome'] = $this->sanitizeOutcome($payload['outcome'] ?? null, $entry['guarded_pass_or_reject']);
        $entry['entry_hash'] = $this->entryHash($entry);

        try {
            // Chain linkage is derived from ledger POSITION at append time, not caller input — kept
            // out of entry_hash (see entryHash()) so identical payloads still produce identical
            // entry_hash regardless of where in the ledger they land. appendWith() hands us the
            // current tail INSIDE the write lock, so concurrent writers can't fork the chain.
            $this->store()->appendWith(function (?string $lastLine) use (&$entry): array {
                $previous = $lastLine !== null ? json_decode($lastLine, true) : null;
                $previous = is_array($previous) ? $previous : null;
                $entry['sequence'] = $previous !== null ? ((int) ($previous['sequence'] ?? 0)) + 1 : 1;
                $entry['previous_hash'] = $previous !== null ? (string) ($previous['entry_hash'] ?? '') : '';

                return $entry;
            });
        } catch (Throwable $exception) {
            throw new DomainException('closed_loop_receipt_ledger_append_failed', previous: $exception);
        }

        return $entry;
    }

    /**
     * Audit the full receipt chain and produce structured findings.
     *
     * @return array{receipt_chain_valid:bool,latest_hash:string,tamper_findings:list<string>,replay_order:list<int>}
     */
    public function auditReceiptChain(): array
    {
        $replayOrder = [];
        $tamperFindings = [];
        $latestHash = '';

        $verification = $this->verifyChain();
        if (! $verification['ok']) {
            $tamperFindings[] = sprintf(
                'chain_broken_at_sequence=%d reason=%s',
                $verification['broken_at_sequence'],
                $verification['reason'],
            );
        }

        $expectedPreviousHash = '';
        foreach ($this->stream() as $row) {
            $sequence = (int) ($row['sequence'] ?? 0);
            $replayOrder[] = $sequence;
            $entryHash = (string) ($row['entry_hash'] ?? '');
            $latestHash = $entryHash;

            // Verify per-entry hash integrity
            if (! $this->verifyEntry($row)) {
                $tamperFindings[] = sprintf('entry_hash_tampered_at_sequence=%d', $sequence);
            }

            // Verify chain linkage
            $previousHash = (string) ($row['previous_hash'] ?? '');
            if ($previousHash !== $expectedPreviousHash) {
                $tamperFindings[] = sprintf('previous_hash_linkage_broken_at_sequence=%d', $sequence);
            }
            $expectedPreviousHash = $entryHash;
        }

        return [
            'receipt_chain_valid' => $verification['ok'],
            'latest_hash' => $latestHash,
            'tamper_findings' => $tamperFindings,
            'replay_order' => $replayOrder,
        ];
    }

    /**
     * Generate a decision ID from the payload.
     */
    private function generateDecisionId(): string
    {
        return sprintf('decision_%s_%s', substr(uniqid('', true), 0, 12), substr(md5((string) mt_rand()), 0, 8));
    }

    /**
     * Compute a stable hash of the input payload (excluding volatile fields).
     */
    private function computeInputHash(array $payload): string
    {
        $stable = $payload;
        unset($stable['timestamp'], $stable['decision_id']);
        ksort($stable);

        return hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compute a stable hash of the output (outcome + recommendation + quality flags).
     */
    private function computeOutputHash(array $payload): string
    {
        $output = [
            'outcome' => $payload['outcome'] ?? '',
            'next_action_recommendation' => $payload['next_action_recommendation'] ?? '',
            'quality_flags' => $payload['quality_flags'] ?? [],
        ];
        ksort($output);

        return hash('sha256', (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Walks the full stream and validates BOTH per-entry hash integrity (verifyEntry) and the
     * previous_hash chain linkage between consecutive entries — a tamper anywhere in the stream
     * (content OR reordering) breaks the chain at the first affected entry.
     *
     * @return array{ok:bool, entries_checked:int, broken_at_sequence:int|null, reason:string|null}
     */
    public function verifyChain(): array
    {
        $expectedPreviousHash = '';
        $checked = 0;
        foreach ($this->stream() as $row) {
            $checked++;
            if (! $this->verifyEntry($row)) {
                return ['ok' => false, 'entries_checked' => $checked, 'broken_at_sequence' => (int) ($row['sequence'] ?? $checked), 'reason' => 'entry_hash_mismatch'];
            }
            if ((string) ($row['previous_hash'] ?? '') !== $expectedPreviousHash) {
                return ['ok' => false, 'entries_checked' => $checked, 'broken_at_sequence' => (int) ($row['sequence'] ?? $checked), 'reason' => 'previous_hash_mismatch'];
            }
            $expectedPreviousHash = (string) ($row['entry_hash'] ?? '');
        }

        return ['ok' => true, 'entries_checked' => $checked, 'broken_at_sequence' => null, 'reason' => null];
    }

    /**
     * Compact projection of the most recent recorded cycle — the minimal signal an external or
     * internal brain needs to decide its next move without replaying/re-deriving the full stream.
     *
     * @return array<string,mixed>|null
     */
    public function latestCycleSummary(): ?array
    {
        $last = $this->lastEntry();
        if ($last === null) {
            return null;
        }

        return [
            'schema' => self::SCHEMA.'.summary',
            'sequence' => (int) ($last['sequence'] ?? 0),
            'timestamp' => (string) ($last['timestamp'] ?? ''),
            'outcome' => (string) ($last['outcome'] ?? ''),
            'guarded_pass_or_reject' => (string) ($last['guarded_pass_or_reject'] ?? ''),
            'next_action_recommendation' => (string) ($last['next_action_recommendation'] ?? ''),
            'quality_flags' => (array) ($last['quality_flags'] ?? []),
            'mined_bucket_count' => (int) ($last['mined_bucket_count'] ?? 0),
            'entry_hash' => (string) ($last['entry_hash'] ?? ''),
        ];
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function stream(): Generator
    {
        yield from $this->store()->replay();
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->ledgerPath());
    }

    /**
     * Returns false when any audited field has been tampered with after recording.
     *
     * @param  array<string,mixed>  $entry
     */
    public function verifyEntry(array $entry): bool
    {
        $stored = (string) ($entry['entry_hash'] ?? '');
        if ($stored === '') {
            return false;
        }
        $copy = $entry;
        unset($copy['entry_hash']);

        return $stored === $this->entryHash($copy);
    }

    private function passOrReject(mixed $value): string
    {
        return $value === 'pass' ? 'pass' : 'reject';
    }

    private function feedbackHash(string $block): string
    {
        return hash('sha256', $block);
    }

    /**
     * Hashes AUDITED CONTENT only — chain-linkage/positional fields (sequence, previous_hash) are
     * deliberately excluded so two identical payloads always produce the same entry_hash regardless
     * of where in the ledger they land (see recordCycle()).
     *
     * @param  array<string,mixed>  $entry
     */
    private function entryHash(array $entry): string
    {
        unset($entry['entry_hash'], $entry['sequence'], $entry['previous_hash'], $entry['decision_id']);
        ksort($entry);

        return hash('sha256', (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sanitizeOutcome(mixed $value, string $guardedPassOrReject): string
    {
        $declared = trim((string) $value);

        return $declared !== '' ? $declared : ($guardedPassOrReject === 'pass' ? 'completed' : 'rejected');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastEntry(): ?array
    {
        $last = null;
        foreach ($this->stream() as $row) {
            $last = $row;
        }

        return $last;
    }

    /** @return list<string> */
    private function sanitizePromotedRules(array $rules): array
    {
        return array_values(array_filter(array_map('strval', $rules)));
    }

    private function ledgerSnapshotHash(): string
    {
        $path = $this->shapeLedgerPath ?? storage_path('atlas/loop/maestro/closed-loop/shape-ledger.jsonl');

        return hash('sha256', is_file($path) ? (string) file_get_contents($path) : '');
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('atlas/loop/maestro/closed-loop/receipts.jsonl');
    }
}
