<?php

namespace App\Services\Ai;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticCurationProposal;
use App\Services\Ai\Memory\MemoryQueryInput;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AtlasMemoryReviewQueueService
{
    public function __construct(private readonly MemoryQueryInput $input) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function queue(array $filters = [], int $limit = 50): array
    {
        $limit = $this->input->reviewQueueLimit($limit);
        $areas = $this->areas($filters);
        $items = collect();

        if (in_array('memory_privacy', $areas, true)) {
            $items = $items->merge($this->memoryPrivacyItems($filters, $limit));
        }
        if (in_array('verbatim_privacy', $areas, true)) {
            $items = $items->merge($this->verbatimPrivacyItems($filters, $limit));
        }
        if (in_array('relation', $areas, true)) {
            $items = $items->merge($this->relationItems($filters, $limit));
        }
        if (in_array('semantic_curation', $areas, true)) {
            $items = $items->merge($this->semanticCurationItems($filters, $limit));
        }
        if (in_array('memory_delta', $areas, true)) {
            $items = $items->merge($this->memoryDeltaItems($filters, $limit));
        }

        $ordered = $items
            ->sortByDesc(fn (array $item): string => sprintf('%03d|%s', (int) ($item['priority'] ?? 0), (string) ($item['updated_at'] ?? '')))
            ->values()
            ->take($limit);

        return [
            'generated_at' => now()->toJSON(),
            'areas' => $areas,
            'total' => $ordered->count(),
            'counts' => array_merge([
                'memory_privacy' => 0,
                'verbatim_privacy' => 0,
                'relation' => 0,
                'semantic_curation' => 0,
                'memory_delta' => 0,
            ], $ordered->countBy('kind')->all()),
            'items' => $ordered->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function memoryPrivacyItems(array $filters, int $limit): Collection
    {
        if (! $this->memoryPrivacyColumnsExist()) {
            return collect();
        }

        $query = AtlasMemoryEntry::query();
        if (! (bool) ($filters['include_inactive'] ?? false)) {
            $query->active();
        }
        $query->where(function (Builder $query): void {
            $query->whereNull('source_type')
                ->orWhere('source_type', '!=', 'atlas_verbatim_memory');
        });

        $this->applyScopeFilters($query, $filters);
        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '') {
            $query->where('privacy_class', $filters['privacy_class']);
        }

        $includeUnreviewed = (bool) ($filters['include_unreviewed'] ?? false);
        $privacyReviewedAtColumnExists = $this->privacyReviewedAtColumnExists();
        $query->where(function (Builder $query) use ($includeUnreviewed, $privacyReviewedAtColumnExists): void {
            $query->whereRaw('1 = 0')
                ->orWhereIn('privacy_class', ['private', 'sensitive', 'secret'])
                ->orWhere('external_ai_allowed', false)
                ->orWhere('redaction_status', 'redacted');

            if ($includeUnreviewed && $privacyReviewedAtColumnExists) {
                $query->orWhereNull('privacy_reviewed_at');
            }
        });

        return $query
            ->orderByDesc('priority')
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasMemoryEntry $entry): array => $this->memoryPrivacyItem($entry));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function verbatimPrivacyItems(array $filters, int $limit): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_verbatim_memories')) {
            return collect();
        }

        $query = AtlasVerbatimMemory::query();
        if (! (bool) ($filters['include_inactive'] ?? false)) {
            $query->active();
        }

        $this->applyScopeFilters($query, $filters);
        if (is_string($filters['privacy_class'] ?? null) && $filters['privacy_class'] !== '') {
            $query->where('privacy_class', $filters['privacy_class']);
        }

        $includeUnreviewed = (bool) ($filters['include_unreviewed'] ?? false);
        $query->where(function (Builder $query) use ($includeUnreviewed): void {
            $query->whereRaw('1 = 0')
                ->orWhereIn('privacy_class', ['private', 'sensitive', 'secret'])
                ->orWhere('external_ai_allowed', false)
                ->orWhere('redaction_status', 'redacted');

            if ($includeUnreviewed) {
                $query->orWhere(function (Builder $query): void {
                    $query->whereNull('metadata')
                        ->orWhereJsonLength('metadata->review_history', 0);
                });
            }
        });

        return $query
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasVerbatimMemory $memory): array => $this->verbatimPrivacyItem($memory));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function relationItems(array $filters, int $limit): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
            return collect();
        }

        $query = AtlasMemoryEntryRelation::query()
            ->with(['sourceMemoryEntry', 'targetMemoryEntry'])
            ->where('status', $this->relationStatus($filters));

        $types = array_values(array_filter(
            (array) ($filters['relation_types'] ?? $filters['relation_type'] ?? []),
            fn (mixed $type): bool => is_string($type) && in_array($type, AtlasMemoryEntryRelation::TYPES, true),
        ));
        if ($types !== []) {
            $query->whereIn('relation_type', $types);
        }

        $this->applyRelationScopeFilters($query, $filters);

        return $query
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasMemoryEntryRelation $relation): array => $this->relationItem($relation));
    }

    private function memoryPrivacyItem(AtlasMemoryEntry $entry): array
    {
        $unreviewed = $this->privacyReviewedAtColumnExists() && $entry->privacy_reviewed_at === null;
        $reason = $this->privacyReason(
            (string) ($entry->privacy_class ?? 'normal'),
            $entry->external_ai_allowed === false,
            (string) ($entry->redaction_status ?? 'clean'),
            $unreviewed,
        );
        $priority = $this->privacyPriority((string) ($entry->privacy_class ?? 'normal'), $entry->external_ai_allowed === false, (string) ($entry->redaction_status ?? 'clean'), $unreviewed);

        return [
            'id' => 'memory_privacy:'.$entry->id,
            'kind' => 'memory_privacy',
            'review_type' => 'privacy',
            'priority' => $priority,
            'severity' => $this->severity($priority),
            'reason' => $reason,
            'action_hint' => 'atlas memory privacy review '.$entry->id,
            'memory_entry_id' => $entry->id,
            'title' => $entry->redacted_title ?: $entry->title,
            'summary' => $entry->redacted_summary ?: $entry->summary,
            'scope' => $this->scopeLabel($entry->scope_type, $entry->scope_id),
            'project_id' => $entry->project_id,
            'task_id' => $entry->task_id,
            'engineering_run_id' => $entry->engineering_run_id,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'privacy_class' => $entry->privacy_class,
            'external_ai_allowed' => $entry->external_ai_allowed,
            'redaction_status' => $entry->redaction_status,
            'safety' => $this->memorySafety($entry),
            'status' => $entry->status,
            'created_at' => $entry->created_at?->toJSON(),
            'updated_at' => $entry->updated_at?->toJSON(),
        ];
    }

    private function verbatimPrivacyItem(AtlasVerbatimMemory $memory): array
    {
        $history = data_get($memory->metadata ?? [], 'review_history', []);
        $unreviewed = ! is_array($history) || $history === [];
        $reason = $this->privacyReason(
            (string) ($memory->privacy_class ?? 'normal'),
            $memory->external_ai_allowed === false,
            (string) ($memory->redaction_status ?? 'clean'),
            $unreviewed,
        );
        $priority = $this->privacyPriority((string) ($memory->privacy_class ?? 'normal'), $memory->external_ai_allowed === false, (string) ($memory->redaction_status ?? 'clean'), $unreviewed);

        return [
            'id' => 'verbatim_privacy:'.$memory->id,
            'kind' => 'verbatim_privacy',
            'review_type' => 'privacy',
            'priority' => $priority,
            'severity' => $this->severity($priority),
            'reason' => $reason,
            'action_hint' => 'atlas memory verbatim review '.$memory->id,
            'verbatim_memory_id' => $memory->id,
            'memory_entry_id' => $memory->memory_entry_id,
            'title' => $memory->title,
            'summary' => $memory->summary ?: Str::limit((string) $memory->redacted_text, 180),
            'scope' => $this->scopeLabel($memory->scope_type, $memory->scope_id),
            'project_id' => $memory->project_id,
            'task_id' => $memory->task_id,
            'engineering_run_id' => $memory->engineering_run_id,
            'source_type' => $memory->source_type,
            'source_id' => $memory->source_id,
            'privacy_class' => $memory->privacy_class,
            'external_ai_allowed' => $memory->external_ai_allowed,
            'redaction_status' => $memory->redaction_status,
            'safety' => $this->verbatimSafety($memory),
            'status' => $memory->status,
            'created_at' => $memory->created_at?->toJSON(),
            'updated_at' => $memory->updated_at?->toJSON(),
        ];
    }

    private function relationItem(AtlasMemoryEntryRelation $relation): array
    {
        $priority = $relation->relation_type === 'conflict' ? 90 : 65;

        return [
            'id' => 'relation:'.$relation->id,
            'kind' => 'relation',
            'review_type' => $relation->relation_type,
            'priority' => $priority,
            'severity' => $this->severity($priority),
            'reason' => $relation->reason,
            'action_hint' => 'atlas memory relations review '.$relation->id.' --status=resolved',
            'relation_id' => $relation->id,
            'relation_type' => $relation->relation_type,
            'source_memory_entry_id' => $relation->source_memory_entry_id,
            'target_memory_entry_id' => $relation->target_memory_entry_id,
            'source_memory' => $this->memorySummary($relation->sourceMemoryEntry),
            'target_memory' => $this->memorySummary($relation->targetMemoryEntry),
            'status' => $relation->status,
            'created_at' => $relation->created_at?->toJSON(),
            'updated_at' => $relation->updated_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function semanticCurationItems(array $filters, int $limit): Collection
    {
        if (! DatabaseTableAvailability::has('semantic_curation_proposals')) {
            return collect();
        }

        $query = SemanticCurationProposal::query()
            ->whereIn('status', $this->semanticCurationStatuses($filters));

        if (is_string($filters['source_type'] ?? null) && trim((string) $filters['source_type']) !== '') {
            $query->where('source_type', trim((string) $filters['source_type']));
        }

        return $query
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (SemanticCurationProposal $proposal): array => $this->semanticCurationItem($proposal));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function memoryDeltaItems(array $filters, int $limit): Collection
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            return collect();
        }

        $query = AiMemoryDelta::query()
            ->whereIn('status', $this->memoryDeltaStatuses($filters));

        if (is_string($filters['scope'] ?? null) && trim((string) $filters['scope']) !== '') {
            $query->where('scope', trim((string) $filters['scope']));
        }

        $types = array_values(array_filter((array) ($filters['types'] ?? $filters['type'] ?? []), 'is_string'));
        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        return $query
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiMemoryDelta $delta): array => $this->memoryDeltaItem($delta));
    }

    private function semanticCurationItem(SemanticCurationProposal $proposal): array
    {
        $pending = $proposal->status === 'pending';
        $priority = $pending ? 72 : 55;

        return [
            'id' => 'semantic_curation:'.$proposal->id,
            'kind' => 'semantic_curation',
            'review_type' => 'capture_to_open_brain',
            'priority' => $priority,
            'severity' => $this->severity($priority),
            'reason' => $proposal->reason,
            'action_hint' => 'review semantic curation proposal '.$proposal->id.' before memory/Open Brain promotion',
            'proposal_id' => $proposal->id,
            'source_type' => $proposal->source_type,
            'source_refs' => $proposal->source_refs ?? [],
            'proposed_note_type' => $proposal->proposed_note_type,
            'title' => $proposal->proposed_title,
            'summary' => $proposal->proposed_summary,
            'score' => $proposal->score,
            'status' => $proposal->status,
            'safety' => [
                'schema_version' => 'atlas.semantic_curation.review_queue_safety.v1',
                'memory_write_allowed' => false,
                'context_injection_allowed' => false,
                'embedding_allowed' => false,
                'provider_export_allowed' => false,
                'open_brain_context_allowed' => false,
                'operator_review_required' => true,
                'raw_capture_text_exposed' => false,
            ],
            'created_at' => $proposal->created_at?->toJSON(),
            'updated_at' => $proposal->updated_at?->toJSON(),
        ];
    }

    private function memoryDeltaItem(AiMemoryDelta $delta): array
    {
        $accepted = $delta->status === 'accepted';
        $priority = $accepted ? 78 : 68;

        return [
            'id' => 'memory_delta:'.$delta->id,
            'kind' => 'memory_delta',
            'review_type' => $accepted ? 'accepted_delta_promotion' : 'delta_review',
            'priority' => $priority,
            'severity' => $this->severity($priority),
            'reason' => $accepted ? 'accepted_delta_waiting_for_promotion' : 'pending_delta_requires_operator_review',
            'action_hint' => $accepted
                ? 'php artisan atlas:cli:memory promote '.$delta->id.' --json'
                : 'php artisan atlas:cli:memory show '.$delta->id.' --json',
            'memory_delta_id' => $delta->id,
            'delta_type' => $delta->type,
            'title' => Str::limit($delta->claim, 120, ''),
            'summary' => Str::limit($delta->claim, 260, ''),
            'scope' => $delta->scope,
            'confidence' => $delta->confidence,
            'requires_confirmation' => $delta->requires_confirmation,
            'status' => $delta->status,
            'safety' => [
                'schema_version' => 'atlas.memory_delta.review_queue_safety.v1',
                'memory_write_allowed' => $accepted,
                'context_injection_allowed' => false,
                'embedding_allowed' => false,
                'provider_export_allowed' => false,
                'open_brain_context_allowed' => false,
                'operator_review_required' => ! $accepted,
                'promotion_requires_receipt' => true,
            ],
            'created_at' => $delta->created_at?->toJSON(),
            'updated_at' => $delta->updated_at?->toJSON(),
        ];
    }

    private function memoryPrivacyColumnsExist(): bool
    {
        return DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'privacy_class')
            && DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'external_ai_allowed')
            && DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'redaction_status');
    }

    private function privacyReviewedAtColumnExists(): bool
    {
        return DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'privacy_reviewed_at');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,string>
     */
    private function areas(array $filters): array
    {
        $areas = array_values(array_filter(
            (array) ($filters['areas'] ?? $filters['area'] ?? []),
            'is_string',
        ));

        if ($areas === []) {
            return ['memory_privacy', 'verbatim_privacy', 'relation', 'semantic_curation', 'memory_delta'];
        }

        $aliases = [
            'memory' => 'memory_privacy',
            'registry' => 'memory_privacy',
            'privacy' => 'memory_privacy',
            'verbatim' => 'verbatim_privacy',
            'relation' => 'relation',
            'relations' => 'relation',
            'semantic' => 'semantic_curation',
            'semantic_curation' => 'semantic_curation',
            'curation' => 'semantic_curation',
            'capture' => 'semantic_curation',
            'capture_promotion' => 'semantic_curation',
            'delta' => 'memory_delta',
            'deltas' => 'memory_delta',
            'memory_delta' => 'memory_delta',
            'memory_deltas' => 'memory_delta',
        ];

        return collect($areas)
            ->map(fn (string $area): string => $aliases[$area] ?? $area)
            ->filter(fn (string $area): bool => in_array($area, ['memory_privacy', 'verbatim_privacy', 'relation', 'semantic_curation', 'memory_delta'], true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyScopeFilters(Builder $query, array $filters): Builder
    {
        foreach (['scope_type', 'scope_id', 'project_id', 'task_id', 'engineering_run_id'] as $column) {
            if (is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '') {
                $query->where($column, trim((string) $filters[$column]));
            }
        }

        return $query;
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyRelationScopeFilters(Builder $query, array $filters): Builder
    {
        $hasFilter = collect(['scope_type', 'scope_id', 'project_id', 'task_id', 'engineering_run_id'])
            ->contains(fn (string $column): bool => is_string($filters[$column] ?? null) && trim((string) $filters[$column]) !== '');

        if (! $hasFilter) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($filters): void {
            $query->whereHas('sourceMemoryEntry', fn (Builder $query): Builder => $this->applyScopeFilters($query, $filters))
                ->orWhereHas('targetMemoryEntry', fn (Builder $query): Builder => $this->applyScopeFilters($query, $filters));
        });
    }

    private function relationStatus(array $filters): string
    {
        $status = $filters['relation_status'] ?? 'open';

        return is_string($status) && in_array($status, AtlasMemoryEntryRelation::STATUSES, true) ? $status : 'open';
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,string>
     */
    private function semanticCurationStatuses(array $filters): array
    {
        $statuses = array_values(array_filter((array) ($filters['semantic_curation_statuses'] ?? $filters['semantic_curation_status'] ?? []), 'is_string'));

        return $statuses === []
            ? ['pending', 'postponed']
            : array_values(array_intersect($statuses, ['pending', 'accepted', 'edited', 'postponed']));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,string>
     */
    private function memoryDeltaStatuses(array $filters): array
    {
        $statuses = array_values(array_filter((array) ($filters['memory_delta_statuses'] ?? $filters['memory_delta_status'] ?? []), 'is_string'));

        return $statuses === []
            ? ['pending', 'accepted']
            : array_values(array_intersect($statuses, ['pending', 'accepted']));
    }

    private function privacyPriority(string $privacyClass, bool $blocked, string $redactionStatus, bool $unreviewed): int
    {
        $score = match ($privacyClass) {
            'secret' => 100,
            'sensitive' => 90,
            'private' => 75,
            default => 45,
        };

        if ($blocked) {
            $score += 8;
        }
        if ($redactionStatus === 'redacted') {
            $score += 10;
        }
        if ($unreviewed) {
            $score += 4;
        }

        return max(0, min(100, $score));
    }

    private function privacyReason(string $privacyClass, bool $blocked, string $redactionStatus, bool $unreviewed): string
    {
        $reasons = [];
        if ($privacyClass !== 'normal') {
            $reasons[] = 'privacy_class='.$privacyClass;
        }
        if ($blocked) {
            $reasons[] = 'external_ai_blocked';
        }
        if ($redactionStatus === 'redacted') {
            $reasons[] = 'redacted_content';
        }
        if ($unreviewed) {
            $reasons[] = 'not_reviewed';
        }

        return $reasons === [] ? 'review_optional' : implode(', ', $reasons);
    }

    private function severity(int $priority): string
    {
        return match (true) {
            $priority >= 85 => 'high',
            $priority >= 60 => 'medium',
            default => 'low',
        };
    }

    private function scopeLabel(?string $scopeType, ?string $scopeId): string
    {
        return $scopeId ? $scopeType.':'.$scopeId : (string) ($scopeType ?: 'global');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function memorySummary(mixed $entry): ?array
    {
        if (! $entry instanceof AtlasMemoryEntry) {
            return null;
        }

        return [
            'id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope' => $this->scopeLabel($entry->scope_type, $entry->scope_id),
            'title' => $entry->redacted_title ?: $entry->title,
            'summary' => $entry->redacted_summary ?: $entry->summary,
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

    /**
     * @return array<string,mixed>
     */
    private function verbatimSafety(AtlasVerbatimMemory $memory): array
    {
        $providerExportAllowed = $memory->external_ai_allowed === true
            && $memory->privacy_class !== 'secret'
            && $memory->redaction_status !== 'blocked'
            && trim((string) $memory->redacted_text) !== '';

        return [
            'schema_version' => 'atlas.verbatim_memory.safety.v1',
            'memory_eligible' => $memory->status === 'active',
            'context_eligible' => $memory->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $memory->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'verbatim_text_exposed' => false,
            'privacy_class' => $memory->privacy_class,
            'redaction_status' => $memory->redaction_status,
            'content_hash' => $memory->content_hash,
            'redacted_hash' => $memory->redacted_hash,
        ];
    }
}
