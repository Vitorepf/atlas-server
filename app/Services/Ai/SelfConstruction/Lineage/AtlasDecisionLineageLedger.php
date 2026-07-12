<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Lineage;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ASI-11 (movements 1+2) — decision → {commit, memory, apply, outcome, receipt}
 * append-only lineage ledger.
 *
 * Fail-open by construction: the ledger is a CONSULTABLE index of what an
 * upstream decision touched. Missing table (early setup, sqlite test isolation)
 * ⇒ writes return `not_recorded` but never explode. Reads return an empty
 * closure — the executor then honestly reports `insufficient_signal` instead
 * of pretending to revert.
 *
 * PROVIDER-SAFE: only ids/hashes/short kind labels are persisted; nothing raw.
 */
final class AtlasDecisionLineageLedger
{
    public const KIND_COMMIT = 'commit';

    public const KIND_MEMORY = 'memory';

    public const KIND_APPLY = 'apply';

    public const KIND_OUTCOME = 'outcome';

    public const KIND_RECEIPT = 'receipt';

    public const KINDS = [
        self::KIND_COMMIT,
        self::KIND_MEMORY,
        self::KIND_APPLY,
        self::KIND_OUTCOME,
        self::KIND_RECEIPT,
    ];

    /**
     * Append one lineage row. Idempotent on (entity_kind, entity_ref) — the
     * same commit sha re-declared under the same decision returns
     * `already_recorded` without duplicating.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function append(
        string $decisionId,
        string $entityKind,
        string $entityRef,
        string $writer,
        ?string $obraId = null,
        ?string $entityScope = null,
        ?string $reverseHandle = null,
        array $meta = [],
    ): array {
        $decisionId = trim($decisionId);
        $entityRef = trim($entityRef);
        $writer = trim($writer);
        $entityKind = trim($entityKind);
        if ($decisionId === '' || $entityRef === '' || $writer === '') {
            return ['recorded' => false, 'reason' => 'incomplete_lineage_row'];
        }
        if (! in_array($entityKind, self::KINDS, true)) {
            return ['recorded' => false, 'reason' => 'unknown_entity_kind:'.$entityKind];
        }

        if (! DatabaseTableAvailability::has('atlas_decision_lineage_ledger')) {
            return ['recorded' => false, 'reason' => 'ledger_unavailable'];
        }

        $now = CarbonImmutable::now()->utc();
        $row = [
            'id' => (string) Str::uuid(),
            'decision_id' => $decisionId,
            'obra_id' => $obraId,
            'entity_kind' => $entityKind,
            'entity_ref' => $entityRef,
            'entity_scope' => $entityScope,
            'reverse_handle' => $reverseHandle,
            'writer' => $writer,
            'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'recorded_at' => $now,
        ];

        try {
            $inserted = DB::table('atlas_decision_lineage_ledger')->insertOrIgnore($row);
        } catch (\Throwable $e) {
            return ['recorded' => false, 'reason' => 'ledger_write_failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        if ($inserted === 0) {
            return ['recorded' => false, 'reason' => 'already_recorded', 'decision_id' => $decisionId, 'entity_kind' => $entityKind, 'entity_ref' => $entityRef];
        }

        return [
            'recorded' => true,
            'id' => $row['id'],
            'decision_id' => $decisionId,
            'entity_kind' => $entityKind,
            'entity_ref' => $entityRef,
            'writer' => $writer,
        ];
    }

    /**
     * Return the transitive closure of a decision — every recorded entity
     * grouped by kind, in insertion order.
     *
     * @return array{decision_id:string,obra_id:?string,entities:array{commit:list<array<string,mixed>>,memory:list<array<string,mixed>>,apply:list<array<string,mixed>>,outcome:list<array<string,mixed>>,receipt:list<array<string,mixed>>},count:int}
     */
    public function closure(string $decisionId): array
    {
        $out = [
            'decision_id' => $decisionId,
            'obra_id' => null,
            'entities' => [
                self::KIND_COMMIT => [],
                self::KIND_MEMORY => [],
                self::KIND_APPLY => [],
                self::KIND_OUTCOME => [],
                self::KIND_RECEIPT => [],
            ],
            'count' => 0,
        ];

        if (! DatabaseTableAvailability::has('atlas_decision_lineage_ledger')) {
            return $out;
        }

        $rows = DB::table('atlas_decision_lineage_ledger')
            ->where('decision_id', $decisionId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->all();

        foreach ($rows as $row) {
            $kind = (string) $row->entity_kind;
            if (! isset($out['entities'][$kind])) {
                continue;
            }
            $meta = [];
            if (! empty($row->meta)) {
                $decoded = json_decode((string) $row->meta, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $out['entities'][$kind][] = [
                'id' => (string) $row->id,
                'entity_ref' => (string) $row->entity_ref,
                'entity_scope' => $row->entity_scope !== null ? (string) $row->entity_scope : null,
                'reverse_handle' => $row->reverse_handle !== null ? (string) $row->reverse_handle : null,
                'writer' => (string) $row->writer,
                'recorded_at' => (string) $row->recorded_at,
                'meta' => $meta,
            ];
            $out['obra_id'] ??= $row->obra_id !== null ? (string) $row->obra_id : null;
            $out['count']++;
        }

        return $out;
    }

    /**
     * Count reversal receipts (`kind=apply` with reverse_handle) within a
     * time window. MAXK-08 reads this to compute the ephemeral runtime
     * ceiling.
     */
    public function countReversalsWithinDays(int $days): int
    {
        if ($days < 1 || ! DatabaseTableAvailability::has('atlas_decision_lineage_ledger')) {
            return 0;
        }

        return (int) DB::table('atlas_decision_lineage_ledger')
            ->where('entity_kind', self::KIND_APPLY)
            ->whereNotNull('reverse_handle')
            ->where('recorded_at', '>=', CarbonImmutable::now()->utc()->subDays($days))
            ->count();
    }

    /**
     * Landings-with-decision_id ÷ landings-in-window. Used by ASI-11 acceptance
     * (100% lineage coverage on new landings) and by MAXK-08 (denominator for
     * reversal rate).
     *
     * @return array{days:int,commits:int,covered:int,coverage_ratio:float}
     */
    public function commitCoverageWithinDays(int $days, int $totalCommits): array
    {
        $covered = 0;
        if (DatabaseTableAvailability::has('atlas_decision_lineage_ledger')) {
            $covered = (int) DB::table('atlas_decision_lineage_ledger')
                ->where('entity_kind', self::KIND_COMMIT)
                ->where('recorded_at', '>=', CarbonImmutable::now()->utc()->subDays(max(1, $days)))
                ->count();
        }

        $ratio = $totalCommits > 0 ? min(1.0, $covered / max(1, $totalCommits)) : 0.0;

        return [
            'days' => $days,
            'commits' => $totalCommits,
            'covered' => $covered,
            'coverage_ratio' => round($ratio, 4),
        ];
    }
}
