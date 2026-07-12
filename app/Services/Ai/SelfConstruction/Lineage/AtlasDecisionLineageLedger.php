<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Lineage;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
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
     * MAXI-06 — compute a provider-safe self-poisoning signal from the
     * candidate's ASI-11 provenance graph. Pure DB graph read; no provider call,
     * no raw memory body/summary/title leaves this method.
     *
     * @return array{
     *     provenance_traces_to_reverted: bool,
     *     provenance_cycle_detected: bool,
     *     self_poisoning_candidate_id: string,
     *     self_poisoning_ancestor_id: string,
     *     provenance_degraded_state: string,
     *     provenance_trace_refs: list<string>
     * }
     */
    public function provenanceSignalsForMemory(
        string $candidateMemoryId,
        ?AtlasMemoryConflictResolutionService $conflicts = null,
    ): array {
        $candidateMemoryId = $this->providerSafeRef($candidateMemoryId);
        $state = [
            'degraded_ancestor_id' => '',
            'degraded_state' => '',
            'cycle' => false,
            'trace_refs' => [],
            'visited_memory_ids' => [],
            'visited_decision_ids' => [],
        ];

        if ($candidateMemoryId !== '' && DatabaseTableAvailability::has('atlas_decision_lineage_ledger')) {
            $this->walkMemoryProvenance(
                memoryId: $candidateMemoryId,
                candidateMemoryId: $candidateMemoryId,
                conflicts: $conflicts ?? new AtlasMemoryConflictResolutionService,
                state: $state,
                depth: 0,
            );
        }

        $ancestorId = (string) $state['degraded_ancestor_id'];

        return [
            'provenance_traces_to_reverted' => $ancestorId !== '',
            'provenance_cycle_detected' => (bool) $state['cycle'],
            'self_poisoning_candidate_id' => $ancestorId !== '' ? 'self_poisoning:'.$ancestorId : '',
            'self_poisoning_ancestor_id' => $ancestorId,
            'provenance_degraded_state' => (string) $state['degraded_state'],
            'provenance_trace_refs' => array_values(array_unique((array) $state['trace_refs'])),
        ];
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

    /**
     * @param  array<string,mixed>  $state
     */
    private function walkMemoryProvenance(
        string $memoryId,
        string $candidateMemoryId,
        AtlasMemoryConflictResolutionService $conflicts,
        array &$state,
        int $depth,
    ): void {
        if ($this->provenanceWalkDone($state, $depth)) {
            return;
        }

        $memoryId = $this->providerSafeRef($memoryId);
        if ($memoryId === '') {
            return;
        }

        if ($depth > 0 && $memoryId === $candidateMemoryId) {
            $state['cycle'] = true;
            $state['trace_refs'][] = 'memory:'.$memoryId;

            return;
        }

        if (isset($state['visited_memory_ids'][$memoryId])) {
            return;
        }
        $state['visited_memory_ids'][$memoryId] = true;
        $state['trace_refs'][] = 'memory:'.$memoryId;

        if ($depth > 0) {
            $degraded = $conflicts->degradedStateForMemoryId($memoryId);
            if ($degraded !== null) {
                $state['degraded_ancestor_id'] = (string) $degraded['memory_id'];
                $state['degraded_state'] = (string) $degraded['state'];

                return;
            }
        }

        if (DatabaseTableAvailability::has('atlas_memory_entries')) {
            $entry = AtlasMemoryEntry::query()->find($memoryId);
            if ($entry instanceof AtlasMemoryEntry && is_array($entry->metadata)) {
                $this->walkRefs(
                    $this->extractProvenanceRefs($entry->metadata),
                    $candidateMemoryId,
                    $conflicts,
                    $state,
                    $depth + 1,
                );
            }
        }

        $rows = DB::table('atlas_decision_lineage_ledger')
            ->where('entity_kind', self::KIND_MEMORY)
            ->where('entity_ref', $memoryId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->all();

        foreach ($rows as $row) {
            if ($this->provenanceWalkDone($state, $depth)) {
                return;
            }

            $this->walkRefs(
                $this->extractProvenanceRefs($this->decodeMeta($row->meta ?? null)),
                $candidateMemoryId,
                $conflicts,
                $state,
                $depth + 1,
            );
        }
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function walkDecisionProvenance(
        string $decisionId,
        string $candidateMemoryId,
        AtlasMemoryConflictResolutionService $conflicts,
        array &$state,
        int $depth,
    ): void {
        if ($this->provenanceWalkDone($state, $depth)) {
            return;
        }

        $decisionId = $this->providerSafeRef($decisionId);
        if ($decisionId === '' || isset($state['visited_decision_ids'][$decisionId])) {
            return;
        }

        $state['visited_decision_ids'][$decisionId] = true;
        $state['trace_refs'][] = 'decision:'.$decisionId;

        foreach ($this->closure($decisionId)['entities'] as $kind => $rows) {
            foreach ($rows as $row) {
                if ($this->provenanceWalkDone($state, $depth)) {
                    return;
                }

                if ($kind === self::KIND_MEMORY) {
                    $this->walkMemoryProvenance(
                        (string) ($row['entity_ref'] ?? ''),
                        $candidateMemoryId,
                        $conflicts,
                        $state,
                        $depth + 1,
                    );
                }

                $this->walkRefs(
                    $this->extractProvenanceRefs(is_array($row['meta'] ?? null) ? $row['meta'] : []),
                    $candidateMemoryId,
                    $conflicts,
                    $state,
                    $depth + 1,
                );
            }
        }
    }

    /**
     * @param  array{memory_ids:list<string>,decision_ids:list<string>}  $refs
     * @param  array<string,mixed>  $state
     */
    private function walkRefs(
        array $refs,
        string $candidateMemoryId,
        AtlasMemoryConflictResolutionService $conflicts,
        array &$state,
        int $depth,
    ): void {
        foreach ($refs['memory_ids'] as $memoryId) {
            $this->walkMemoryProvenance($memoryId, $candidateMemoryId, $conflicts, $state, $depth);
            if ($this->provenanceWalkDone($state, $depth)) {
                return;
            }
        }

        foreach ($refs['decision_ids'] as $decisionId) {
            $this->walkDecisionProvenance($decisionId, $candidateMemoryId, $conflicts, $state, $depth);
            if ($this->provenanceWalkDone($state, $depth)) {
                return;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function provenanceWalkDone(array $state, int $depth): bool
    {
        return $depth > 64
            || (string) ($state['degraded_ancestor_id'] ?? '') !== ''
            || (bool) ($state['cycle'] ?? false);
    }

    /**
     * @return array{memory_ids:list<string>,decision_ids:list<string>}
     */
    private function extractProvenanceRefs(array $meta): array
    {
        $memoryKeys = [
            'memory_id',
            'memory_ids',
            'memory_ref',
            'memory_refs',
            'source_memory_id',
            'source_memory_ids',
            'source_memory_entry_id',
            'source_memory_entry_ids',
            'recalled_memory_id',
            'recalled_memory_ids',
            'derived_from_memory_id',
            'derived_from_memory_ids',
            'ancestor_memory_id',
            'ancestor_memory_ids',
            'provenance_memory_id',
            'provenance_memory_ids',
            'parent_memory_id',
            'parent_memory_ids',
        ];
        $decisionKeys = [
            'decision_id',
            'decision_ids',
            'source_decision_id',
            'source_decision_ids',
            'ancestor_decision_id',
            'ancestor_decision_ids',
            'provenance_decision_id',
            'provenance_decision_ids',
            'parent_decision_id',
            'parent_decision_ids',
        ];

        $refs = ['memory_ids' => [], 'decision_ids' => []];
        $this->collectProvenanceRefs($meta, $memoryKeys, $decisionKeys, $refs);

        $refs['memory_ids'] = array_values(array_unique($refs['memory_ids']));
        $refs['decision_ids'] = array_values(array_unique($refs['decision_ids']));

        return $refs;
    }

    /**
     * @param  list<string>  $memoryKeys
     * @param  list<string>  $decisionKeys
     * @param  array{memory_ids:list<string>,decision_ids:list<string>}  $refs
     */
    private function collectProvenanceRefs(mixed $value, array $memoryKeys, array $decisionKeys, array &$refs, ?string $key = null): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $normalizedKey = is_string($childKey) ? strtolower($childKey) : null;
                if ($normalizedKey !== null && in_array($normalizedKey, $memoryKeys, true)) {
                    $this->collectScalarRefs($childValue, $refs['memory_ids']);

                    continue;
                }
                if ($normalizedKey !== null && in_array($normalizedKey, $decisionKeys, true)) {
                    $this->collectScalarRefs($childValue, $refs['decision_ids']);

                    continue;
                }

                $this->collectProvenanceRefs($childValue, $memoryKeys, $decisionKeys, $refs, $normalizedKey);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $trimmed = trim($value);
        if (str_starts_with($trimmed, 'memory:')) {
            $refs['memory_ids'][] = $this->providerSafeRef(substr($trimmed, 7));
        } elseif (str_starts_with($trimmed, 'decision:')) {
            $refs['decision_ids'][] = $this->providerSafeRef(substr($trimmed, 9));
        } elseif ($key !== null && str_ends_with($key, '_ref')) {
            // Ref strings are only accepted with an explicit prefix above.
            return;
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function collectScalarRefs(mixed $value, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectScalarRefs($item, $out);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $ref = $this->providerSafeRef($value);
        if ($ref !== '') {
            $out[] = $ref;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (! is_string($meta) || trim($meta) === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function providerSafeRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || mb_strlen($ref) > 200) {
            return '';
        }

        return $ref;
    }
}
