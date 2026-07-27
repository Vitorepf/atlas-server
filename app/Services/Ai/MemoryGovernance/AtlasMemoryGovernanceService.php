<?php

namespace App\Services\Ai\MemoryGovernance;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\MemoryQueryInput;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class AtlasMemoryGovernanceService
{
    private const POSITIVE_FEEDBACK = ['useful'];

    private const NEGATIVE_FEEDBACK = ['not_useful', 'wrong_context', 'stale', 'too_much', 'corrected'];

    public function __construct(private readonly MemoryQueryInput $input) {}

    /**
     * @return array<string,mixed>
     */
    public function applyFeedbackGovernance(AtlasMemoryEntry $entry): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return [];
        }

        $feedback = AtlasMemoryEntryUsage::query()
            ->where('memory_entry_id', $entry->id)
            ->whereNotNull('feedback_action')
            ->get();

        $summary = $this->feedbackSummary($feedback);
        $metadata = $entry->metadata ?? [];
        $basePriority = (int) data_get($metadata, 'governance.base_priority', $entry->priority);
        data_set($metadata, 'governance.base_priority', $basePriority);
        data_set($metadata, 'governance.feedback', $summary);

        $status = $entry->status;
        $archivedAt = $entry->archived_at;
        if ($summary['stale_count'] >= 2) {
            $status = 'archived';
            $archivedAt = $entry->archived_at ?: now();
            data_set($metadata, 'governance.last_action', 'archived_by_stale_feedback');
        } elseif ($summary['wrong_context_count'] >= 2 || ($summary['negative_count'] >= 3 && $summary['health_score'] <= 40)) {
            $status = 'inactive';
            $archivedAt = null;
            data_set($metadata, 'governance.last_action', 'inactivated_by_negative_feedback');
        } elseif ($entry->status === 'active' && $summary['negative_count'] > 0) {
            data_set($metadata, 'governance.last_action', 'priority_degraded_by_feedback');
        }

        $entry->forceFill(array_merge([
            'priority' => $this->governedPriority($basePriority, $summary),
            'status' => $status,
            'archived_at' => $status === 'archived' ? $archivedAt : null,
            'metadata' => $metadata,
        ], $this->governanceTimestamp()))->save();

        return $summary + [
            'memory_entry_id' => $entry->id,
            'status' => $entry->refresh()->status,
            'priority' => $entry->priority,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function scan(array $filters = [], int $limit = 200, bool $dryRun = false): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return [
                'scanned' => 0,
                'duplicates' => [],
                'conflicts' => [],
                'near_duplicates' => null,
            ];
        }

        $entries = $this->queryEntries($filters)
            ->limit($this->input->governanceScanLimit($limit))
            ->get();

        $entries->each(fn (AtlasMemoryEntry $entry): bool => $this->refreshContentHash($entry, $dryRun));

        $duplicates = $this->detectDuplicates($entries, $dryRun);
        $conflicts = $this->detectConflicts($entries, $dryRun);

        return [
            'scanned' => $entries->count(),
            'duplicates' => $duplicates,
            'conflicts' => $conflicts,
            'near_duplicates' => $this->detectNearDuplicates($entries),
        ];
    }

    /**
     * D3 (Obra #18) — auto-relation hook: relate a JUST-WRITTEN memory against the only
     * entries that could be its duplicate/conflict — the ones sharing its
     * (memory_type, scope_type, scope_id) bucket, since both detectors group by exactly
     * those keys. A cheap indexed slice, never the full-corpus scan, so it is safe on
     * every write. Reuses the same detectors as scan(), so any relation is
     * updateOrCreate-deduped by the unique pair index. Fail-open: a relation-accrual
     * fault must NEVER break the memory write that triggered it.
     */
    public function relateNewEntry(AtlasMemoryEntry $entry): void
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_memory_entries')
                || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
                return;
            }

            $peers = AtlasMemoryEntry::query()
                ->where('memory_type', $entry->memory_type)
                ->where('scope_type', $entry->scope_type)
                ->when(
                    $entry->scope_id === null,
                    fn (Builder $q): Builder => $q->whereNull('scope_id'),
                    fn (Builder $q): Builder => $q->where('scope_id', $entry->scope_id),
                )
                ->where('status', 'active')
                ->get();

            if ($peers->count() < 2) {
                return; // only the new entry itself in its bucket → nothing to relate
            }

            $this->detectDuplicates($peers, false);
            $this->detectConflicts($peers, false);
        } catch (Throwable) {
            // fail-open: relation accrual must never break a memory write
        }
    }

    /**
     * WIRE-OBSERVE (Obra #7): near-duplicate Jaccard clusters computed alongside
     * the exact-hash duplicates (`detectDuplicates()` only catches identical
     * normalized content — this fills the near-dup gap). Observe-only: never
     * mutates entries, relations or the existing duplicates/conflicts verdicts.
     * Fail-open at the call: explicit null when the Python near-duplicate
     * runtime is unavailable or errors.
     *
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     * @return array<string,mixed>|null
     */
    private function detectNearDuplicates(Collection $entries): ?array
    {
        try {
            $rows = $entries
                ->map(fn (AtlasMemoryEntry $entry): array => [
                    'id' => $entry->id,
                    'tokens' => explode(' ', $this->normalizeText((string) $entry->body)),
                    'memory_type' => (string) $entry->memory_type,
                    'scope' => $entry->scope_type.':'.(string) $entry->scope_id,
                    'priority' => (int) $entry->priority,
                    'importance' => (int) $entry->importance,
                    'recency' => (int) ($entry->recorded_at?->timestamp ?? 0),
                ])
                ->values()
                ->all();

            return (new MemoryNearDuplicateDetector)->detect($rows);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(AtlasMemoryEntry $entry): array
    {
        $entry->load(['outgoingRelations.targetMemoryEntry', 'incomingRelations.sourceMemoryEntry']);

        return [
            'memory_entry_id' => $entry->id,
            'status' => $entry->status,
            'priority' => $entry->priority,
            'content_hash' => $entry->content_hash,
            'governance' => data_get($entry->metadata ?? [], 'governance', []),
            'outgoing_relations' => $entry->outgoingRelations
                ->map(fn (AtlasMemoryEntryRelation $relation): array => $this->relationPayload($relation))
                ->values()
                ->all(),
            'incoming_relations' => $entry->incomingRelations
                ->map(fn (AtlasMemoryEntryRelation $relation): array => $this->relationPayload($relation))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,AtlasMemoryEntryRelation>
     */
    public function listRelations(array $filters = [], int $limit = 50): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return collect();
        }

        $query = AtlasMemoryEntryRelation::query()
            ->with(['sourceMemoryEntry', 'targetMemoryEntry']);

        $types = array_values(array_filter(
            (array) ($filters['types'] ?? $filters['relation_type'] ?? []),
            fn (mixed $type): bool => is_string($type) && $type !== '',
        ));
        if ($types !== []) {
            $query->whereIn('relation_type', $types);
        }

        foreach (['status', 'source_memory_entry_id', 'target_memory_entry_id'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        return $query
            ->latest('updated_at')
            ->limit($this->input->relationLimit($limit))
            ->get();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function reviewRelation(AtlasMemoryEntryRelation $relation, array $data): AtlasMemoryEntryRelation
    {
        $status = in_array($data['status'] ?? $relation->status, AtlasMemoryEntryRelation::STATUSES, true)
            ? (string) ($data['status'] ?? $relation->status)
            : $relation->status;
        $metadata = is_array($relation->metadata) ? $relation->metadata : [];
        $history = array_values((array) ($metadata['review_history'] ?? []));
        $history[] = AtlasSecurity::redactArray([
            'reviewed_at' => now()->toJSON(),
            'reviewed_by' => is_scalar($data['reviewed_by'] ?? null) ? (string) $data['reviewed_by'] : null,
            'status' => $status,
            'resolution_action' => is_scalar($data['resolution_action'] ?? null) ? (string) $data['resolution_action'] : null,
            'note' => is_scalar($data['review_note'] ?? null) ? (string) $data['review_note'] : null,
            'source_status' => is_scalar($data['source_status'] ?? null) ? (string) $data['source_status'] : null,
            'target_status' => is_scalar($data['target_status'] ?? null) ? (string) $data['target_status'] : null,
        ]);
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $metadata['review_history'] = $history;
        $metadata['last_review'] = $history[array_key_last($history)];
        if (is_array($data['metadata'] ?? null)) {
            $metadata = array_merge($metadata, AtlasSecurity::redactArray($data['metadata']));
        }

        $relation->forceFill([
            'status' => $status,
            'reason' => is_string($data['reason'] ?? null) && trim((string) $data['reason']) !== ''
                ? AtlasSecurity::redactString(trim((string) $data['reason']))
                : $relation->reason,
            'metadata' => $metadata,
        ])->save();

        $relation->loadMissing(['sourceMemoryEntry', 'targetMemoryEntry']);
        $this->applyReviewedMemoryStatus($relation->sourceMemoryEntry, $data['source_status'] ?? null, $relation, 'source');
        $this->applyReviewedMemoryStatus($relation->targetMemoryEntry, $data['target_status'] ?? null, $relation, 'target');

        return $relation->refresh()->load(['sourceMemoryEntry', 'targetMemoryEntry']);
    }

    private function queryEntries(array $filters): Builder
    {
        $query = AtlasMemoryEntry::query()
            ->where('status', 'active')
            ->whereNull('archived_at');

        foreach (['scope_type', 'scope_id', 'project_id', 'task_id', 'engineering_run_id'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('memory_type', $types);
        }

        return $query->orderByDesc('priority')->latest('recorded_at');
    }

    /**
     * @param  Collection<int,AtlasMemoryEntryUsage>  $feedback
     * @return array<string,mixed>
     */
    private function feedbackSummary(Collection $feedback): array
    {
        $actions = $feedback->pluck('feedback_action')->filter()->countBy();
        $positiveCount = $feedback->whereIn('feedback_action', self::POSITIVE_FEEDBACK)->count();
        $negativeCount = $feedback->whereIn('feedback_action', self::NEGATIVE_FEEDBACK)->count();
        $ignoredCount = (int) ($actions['ignored_implicit'] ?? 0);
        $wrongContextCount = (int) ($actions['wrong_context'] ?? 0);
        $staleCount = (int) ($actions['stale'] ?? 0);
        $scorePenalty = ($negativeCount * 18) + ($wrongContextCount * 10) + ($staleCount * 12);
        $scoreBoost = $positiveCount * 8;
        $healthScore = max(0, min(100, 100 + $scoreBoost - $scorePenalty));

        return [
            'positive_count' => $positiveCount,
            'negative_count' => $negativeCount,
            'ignored_count' => $ignoredCount,
            'wrong_context_count' => $wrongContextCount,
            'stale_count' => $staleCount,
            'actions' => $actions->all(),
            'health_score' => $healthScore,
            'last_feedback_at' => $feedback->max('feedback_recorded_at')?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function governedPriority(int $basePriority, array $summary): int
    {
        $penalty = ((int) $summary['negative_count'] * 10)
            + ((int) $summary['wrong_context_count'] * 8)
            + ((int) $summary['stale_count'] * 12);
        $boost = (int) $summary['positive_count'] * 4;

        return max(0, min(100, $basePriority + $boost - $penalty));
    }

    private function refreshContentHash(AtlasMemoryEntry $entry, bool $dryRun): bool
    {
        if (! DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'content_hash')) {
            return false;
        }

        $hash = $this->contentHash($entry);
        if ($dryRun || $entry->content_hash === $hash) {
            return false;
        }

        $entry->forceFill(array_merge([
            'content_hash' => $hash,
        ], $this->governanceTimestamp()))->save();

        return true;
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     * @return array<int,array<string,mixed>>
     */
    private function detectDuplicates(Collection $entries, bool $dryRun): array
    {
        return $entries
            ->groupBy(fn (AtlasMemoryEntry $entry): string => implode('|', [
                $entry->memory_type,
                $entry->scope_type,
                (string) $entry->scope_id,
                $this->contentHash($entry),
            ]))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->flatMap(function (Collection $group) use ($dryRun): array {
                $canonical = $group
                    ->sortByDesc(fn (AtlasMemoryEntry $entry): string => sprintf('%03d|%d|%s', $entry->priority, $entry->importance, $entry->recorded_at?->timestamp ?? 0))
                    ->first();

                return $group
                    ->reject(fn (AtlasMemoryEntry $entry): bool => $entry->id === $canonical->id)
                    ->map(fn (AtlasMemoryEntry $entry): array => $this->duplicatePayload($entry, $canonical, $dryRun))
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasMemoryEntry>  $entries
     * @return array<int,array<string,mixed>>
     */
    private function detectConflicts(Collection $entries, bool $dryRun): array
    {
        return $entries
            ->filter(fn (AtlasMemoryEntry $entry): bool => $this->normalizedTitle($entry) !== '')
            ->groupBy(fn (AtlasMemoryEntry $entry): string => implode('|', [
                $entry->memory_type,
                $entry->scope_type,
                (string) $entry->scope_id,
                $this->normalizedTitle($entry),
            ]))
            ->filter(fn (Collection $group): bool => $group->map(fn (AtlasMemoryEntry $entry): string => $this->contentHash($entry))->unique()->count() > 1)
            ->flatMap(function (Collection $group) use ($dryRun): array {
                $ordered = $group->sortByDesc('priority')->values();
                $primary = $ordered->first();

                return $ordered
                    ->slice(1)
                    ->map(fn (AtlasMemoryEntry $entry): array => $this->conflictPayload($primary, $entry, $dryRun))
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicatePayload(AtlasMemoryEntry $duplicate, AtlasMemoryEntry $canonical, bool $dryRun): array
    {
        $relation = $this->relation($duplicate, $canonical, 'duplicate', 1.0, 'Conteudo normalizado identico no mesmo tipo e escopo.', $dryRun);

        if (! $dryRun) {
            $metadata = $duplicate->metadata ?? [];
            data_set($metadata, 'governance.duplicate_of_memory_entry_id', $canonical->id);
            data_set($metadata, 'governance.last_action', 'inactivated_as_duplicate');
            $duplicate->forceFill(array_merge([
                'status' => 'inactive',
                'archived_at' => null,
                'metadata' => $metadata,
            ], $this->governanceTimestamp()))->save();
        }

        return [
            'relation_id' => $relation?->id,
            'duplicate_memory_entry_id' => $duplicate->id,
            'canonical_memory_entry_id' => $canonical->id,
            'dry_run' => $dryRun,
            'reason' => 'Conteudo normalizado identico no mesmo tipo e escopo.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function conflictPayload(AtlasMemoryEntry $primary, AtlasMemoryEntry $conflicting, bool $dryRun): array
    {
        $relation = $this->relation($primary, $conflicting, 'conflict', 0.72, 'Mesmo titulo/tipo/escopo com conteudo diferente; requer revisao humana.', $dryRun);

        return [
            'relation_id' => $relation?->id,
            'source_memory_entry_id' => $primary->id,
            'target_memory_entry_id' => $conflicting->id,
            'dry_run' => $dryRun,
            'reason' => 'Mesmo titulo/tipo/escopo com conteudo diferente; requer revisao humana.',
        ];
    }

    private function relation(
        AtlasMemoryEntry $source,
        AtlasMemoryEntry $target,
        string $type,
        float $confidence,
        string $reason,
        bool $dryRun,
    ): ?AtlasMemoryEntryRelation {
        if ($dryRun || ! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return null;
        }

        return AtlasMemoryEntryRelation::query()->updateOrCreate([
            'source_memory_entry_id' => $source->id,
            'target_memory_entry_id' => $target->id,
            'relation_type' => $type,
        ], [
            'status' => 'open',
            'confidence' => $confidence,
            'reason' => $reason,
            'metadata' => [
                'source_content_hash' => $this->contentHash($source),
                'target_content_hash' => $this->contentHash($target),
                'detector' => $type === 'duplicate' ? 'exact_normalized_content' : 'same_title_different_content',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function relationPayload(AtlasMemoryEntryRelation $relation): array
    {
        return [
            'id' => $relation->id,
            'relation_type' => $relation->relation_type,
            'status' => $relation->status,
            'confidence' => $relation->confidence,
            'reason' => $relation->reason,
            'source_memory_entry_id' => $relation->source_memory_entry_id,
            'target_memory_entry_id' => $relation->target_memory_entry_id,
            'source_memory' => $relation->relationLoaded('sourceMemoryEntry') ? $this->memoryRelationSummary($relation->sourceMemoryEntry) : null,
            'target_memory' => $relation->relationLoaded('targetMemoryEntry') ? $this->memoryRelationSummary($relation->targetMemoryEntry) : null,
            'metadata' => $relation->metadata ?? [],
        ];
    }

    private function applyReviewedMemoryStatus(mixed $entry, mixed $status, AtlasMemoryEntryRelation $relation, string $role): void
    {
        if (! $entry instanceof AtlasMemoryEntry || ! in_array($status, AtlasMemoryEntry::STATUSES, true)) {
            return;
        }

        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        data_set($metadata, 'governance.last_relation_review_id', $relation->id);
        data_set($metadata, 'governance.last_relation_review_role', $role);
        data_set($metadata, 'governance.last_action', 'relation_review_'.$status);

        $entry->forceFill(array_merge([
            'status' => $status,
            'archived_at' => $status === 'archived' ? ($entry->archived_at ?: now()) : null,
            'metadata' => $metadata,
        ], $this->governanceTimestamp()))->save();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function memoryRelationSummary(mixed $entry): ?array
    {
        if (! $entry instanceof AtlasMemoryEntry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $entry->title,
            'status' => $entry->status,
            'priority' => $entry->priority,
            'safety' => $this->memorySafety($entry),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memorySafety(AtlasMemoryEntry $entry): array
    {
        $providerExportAllowed = $entry->external_ai_allowed === true
            && $entry->privacy_class !== 'secret'
            && $entry->redaction_status !== 'blocked';

        return [
            'schema_version' => 'atlas.memory_entry.safety.v1',
            'memory_eligible' => $entry->status === 'active',
            'context_eligible' => $entry->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $entry->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => $entry->privacy_class,
            'redaction_status' => $entry->redaction_status,
            'content_hash' => $entry->content_hash,
        ];
    }

    private function contentHash(AtlasMemoryEntry $entry): string
    {
        return hash('sha256', implode('|', [
            $entry->memory_type,
            $entry->scope_type,
            (string) $entry->scope_id,
            $this->normalizeText((string) $entry->body),
        ]));
    }

    private function normalizedTitle(AtlasMemoryEntry $entry): string
    {
        return $this->normalizeText((string) ($entry->title ?? ''));
    }

    private function normalizeText(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?: '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    /**
     * @return array<string,mixed>
     */
    private function governanceTimestamp(): array
    {
        return DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'governance_checked_at')
            ? ['governance_checked_at' => now()]
            : [];
    }
}
