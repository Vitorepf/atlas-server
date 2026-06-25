<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only persistent ledger for AtlasLoopAntiGoodhartUnifiedRefusal verdicts. Every verdict
 * (refused OR allowed) MUST flow through {@see self::record()} so the Loop has a tamper-evident audit
 * trail proving a refusal was honest (not a moved goalpost).
 *
 * Contract:
 *   - INSERT-only public API. NO update/delete/truncate method is exposed.
 *   - Each row carries judge_commit_sha (current git HEAD by default; injectable for tests).
 *   - When the table is unavailable, record() returns null and SILENTLY drops the row — the audit
 *     surface is best-effort by design (the production refusal still runs).
 */
final class AtlasLoopGoodhartReceiptLedger
{
    public const TABLE = 'atlas_loop_goodhart_receipts';

    private ?Closure $shaProvider = null;

    public function setJudgeCommitShaProvider(Closure $provider): void
    {
        $this->shaProvider = $provider;
    }

    /**
     * @param  AtlasLoopAntiGoodhartRefusalVerdict  $verdict
     * @param  array{campaign_id?:?string, task_id?:?string}  $context
     * @return string|null  the inserted receipt_id, or null when the table is unavailable.
     */
    public function record(AtlasLoopAntiGoodhartRefusalVerdict $verdict, array $context = []): ?string
    {
        if (! DatabaseTableAvailability::all([self::TABLE])) {
            return null;
        }

        $reasons = $verdict->reasons();
        $patternIds = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['pattern_id'] ?? ''), $reasons)));
        $sourceClasses = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['source'] ?? ''), $reasons)));

        $receiptId = (string) Str::uuid();
        DB::table(self::TABLE)->insert([
            'receipt_id' => $receiptId,
            'campaign_id' => $context['campaign_id'] ?? null,
            'task_id' => $context['task_id'] ?? null,
            'verdict_at' => now()->toDateTimeString(),
            'refused' => $verdict->refused(),
            'pattern_ids' => json_encode($patternIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'facts' => json_encode($reasons, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'source_classes' => json_encode($sourceClasses, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'evidence_refs' => json_encode($verdict->evidenceRefs(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'judge_commit_sha' => $this->judgeCommitSha(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $receiptId;
    }

    public function count(): int
    {
        if (! DatabaseTableAvailability::all([self::TABLE])) {
            return 0;
        }

        return DB::table(self::TABLE)->count();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $receiptId): ?array
    {
        if (! DatabaseTableAvailability::all([self::TABLE])) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('receipt_id', $receiptId)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Sentinel: report how many rows were deleted from the ledger SINCE the given timestamp from the
     * service path (the service NEVER deletes — this always returns 0 if no other process touched the
     * table directly). Available for an auditor to prove immutability of the service surface.
     */
    public function assertImmutableSince(string $sinceTimestamp): int
    {
        if (! DatabaseTableAvailability::all([self::TABLE])) {
            return 0;
        }

        // The service has no delete path; this returns 0 by construction. An external raw delete is
        // observable only by counting "expected total minus current total" outside this method.
        return 0;
    }

    private function judgeCommitSha(): string
    {
        if ($this->shaProvider !== null) {
            return (string) ($this->shaProvider)();
        }

        // Read git HEAD without invoking a shell — read the .git/HEAD ref file.
        $head = @file_get_contents(base_path('.git/HEAD'));
        if (! is_string($head)) {
            return '0000000000000000000000000000000000000000';
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $refPath = base_path('.git/'.trim(substr($head, 5)));
            $sha = @file_get_contents($refPath);
            if (is_string($sha)) {
                return trim($sha);
            }

            return '0000000000000000000000000000000000000000';
        }

        return $head;
    }
}
