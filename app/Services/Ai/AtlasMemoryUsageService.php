<?php

namespace App\Services\Ai;

use App\Models\AiContextSnapshot;
use App\Models\AiTrace;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasMemoryUsageService
{
    public function __construct(
        private readonly AtlasMemoryGovernanceService $governance,
    ) {}

    public function recordSnapshotUsages(AiTrace $trace, AiContextSnapshot $snapshot): void
    {
        if (! Schema::hasTable('atlas_memory_entry_usages') || ! Schema::hasTable('atlas_memory_entries')) {
            return;
        }

        $refs = collect((array) ($trace->context_refs ?? []))
            ->filter(fn (mixed $ref): bool => is_array($ref) && ($ref['type'] ?? null) === 'atlas_memory_entry')
            ->values();
        if ($refs->isEmpty()) {
            return;
        }

        $registryItems = collect((array) data_get($snapshot->context_pack, 'memory.registry', []))
            ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null))
            ->keyBy(fn (array $item): string => (string) $item['id']);

        $refs->each(function (array $ref, int $index) use ($trace, $snapshot, $registryItems): void {
            $memoryId = is_string($ref['id'] ?? null) ? $ref['id'] : null;
            if (! $memoryId) {
                return;
            }

            $entry = AtlasMemoryEntry::query()->find($memoryId);
            if (! $entry) {
                return;
            }

            $registryItem = $registryItems->get($memoryId, []);
            AtlasMemoryEntryUsage::query()->updateOrCreate([
                'memory_entry_id' => $entry->id,
                'trace_id' => $trace->id,
                'context_snapshot_id' => $snapshot->id,
            ], [
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'source_type' => 'context_pack',
                'source_id' => $snapshot->id,
                'position' => $index + 1,
                'included_reason' => is_string($registryItem['reason'] ?? null) ? $registryItem['reason'] : null,
                'source_ref_json' => $ref,
                'context_payload_json' => is_array($registryItem) ? $registryItem : [],
                'metadata' => [
                    'provider' => $trace->provider,
                    'model' => $trace->model,
                    'prompt_hash' => $trace->prompt_hash,
                    'context_snapshot_id' => $snapshot->id,
                    'created_by' => 'ai_context_snapshot_recorder',
                ],
                'used_at' => $snapshot->created_at ?: now(),
            ]);
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function auditTrace(AiTrace $trace): array
    {
        $snapshot = AiContextSnapshot::query()
            ->where('trace_id', $trace->id)
            ->latest('created_at')
            ->first();

        $usages = $this->usagesForTrace($trace);

        return [
            'trace_id' => $trace->id,
            'context_snapshot_id' => $snapshot?->id,
            'context_refs_count' => is_array($trace->context_refs) ? count($trace->context_refs) : 0,
            'memory_usage_count' => $usages->count(),
            'memories' => $usages
                ->map(fn (AtlasMemoryEntryUsage $usage): array => $this->usagePayload($usage))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int,AtlasMemoryEntryUsage>
     */
    public function usagesForTrace(AiTrace $trace): Collection
    {
        if (! Schema::hasTable('atlas_memory_entry_usages')) {
            return collect();
        }

        return AtlasMemoryEntryUsage::query()
            ->with('memoryEntry')
            ->where('trace_id', $trace->id)
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function recordFeedback(AtlasMemoryEntryUsage $usage, array $data): AtlasMemoryEntryUsage
    {
        $score = isset($data['feedback_score']) ? (int) $data['feedback_score'] : null;

        $usage->forceFill([
            'feedback_action' => $data['feedback_action'] ?? $usage->feedback_action,
            'feedback_score' => $score,
            'feedback_comment' => $data['feedback_comment'] ?? $usage->feedback_comment,
            'feedback_recorded_at' => now(),
            'metadata' => array_merge($usage->metadata ?? [], [
                'feedback_source' => $data['feedback_source'] ?? 'api',
            ]),
        ])->save();

        $usage = $usage->refresh()->load('memoryEntry');
        if ($usage->memoryEntry) {
            $this->governance->applyFeedbackGovernance($usage->memoryEntry);
        }

        return $usage->refresh()->load('memoryEntry');
    }

    /**
     * @return array<string,mixed>
     */
    public function usagePayload(AtlasMemoryEntryUsage $usage): array
    {
        $entry = $usage->memoryEntry;

        return [
            'id' => $usage->id,
            'memory_entry_id' => $usage->memory_entry_id,
            'trace_id' => $usage->trace_id,
            'context_snapshot_id' => $usage->context_snapshot_id,
            'memory_type' => $usage->memory_type,
            'scope_type' => $usage->scope_type,
            'scope_id' => $usage->scope_id,
            'position' => $usage->position,
            'included_reason' => $usage->included_reason,
            'source_ref' => $usage->source_ref_json ?? [],
            'context_payload' => $usage->context_payload_json ?? [],
            'feedback_action' => $usage->feedback_action,
            'feedback_score' => $usage->feedback_score,
            'feedback_comment' => $usage->feedback_comment,
            'feedback_recorded_at' => $usage->feedback_recorded_at?->toJSON(),
            'used_at' => $usage->used_at?->toJSON(),
            'memory' => $entry ? [
                'id' => $entry->id,
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'title' => $entry->title,
                'summary' => $entry->summary,
                'body' => $entry->body,
                'priority' => $entry->priority,
                'importance' => $entry->importance,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'status' => $entry->status,
            ] : null,
        ];
    }
}
