<?php

namespace App\Services\Ai\Memory;

use App\Models\AiContextSnapshot;
use App\Models\AiTrace;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasMemoryUsageService
{
    public const SOURCE_TYPE_MEMORY_RECALL = 'memory_recall';

    public const SOURCE_TYPE_RECALLED_PRE_FILTER = 'recalled_pre_filter';

    public const DELIVERY_SURFACE_CONTEXT_PACK = 'context_pack';

    public function __construct(
        private readonly AtlasMemoryGovernanceService $governance,
    ) {}

    public function recordSnapshotUsages(AiTrace $trace, AiContextSnapshot $snapshot): void
    {
        if (! DatabaseTableAvailability::all(['atlas_memory_entry_usages', 'atlas_memory_entries'])) {
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
            $usagePayload = [
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
            ];
            $this->attachActor($usagePayload, [
                'session_id' => $trace->session_id,
                'created_by' => 'ai_context_snapshot_recorder',
                'provider' => $trace->provider,
            ]);
            AtlasMemoryEntryUsage::query()->updateOrCreate([
                'memory_entry_id' => $entry->id,
                'trace_id' => $trace->id,
                'context_snapshot_id' => $snapshot->id,
            ], $usagePayload);
        });
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<int,array<string,mixed>>  $recall
     * @param  array<string,mixed>  $metadata
     * @return array{audit_id:string|null,recorded_count:int}
     */
    public function recordRecallUsages(string $query, array $context, array $recall, array $metadata = []): array
    {
        if (! DatabaseTableAvailability::all(['atlas_memory_entry_usages', 'atlas_memory_entries'])) {
            return ['audit_id' => null, 'recorded_count' => 0];
        }

        $registryItems = collect($recall)
            ->filter(fn (mixed $item): bool => is_array($item)
                && ($item['source_ref_type'] ?? null) === 'atlas_memory_entry'
                && is_string($item['source_ref_id'] ?? null))
            ->values();

        if ($registryItems->isEmpty()) {
            return ['audit_id' => null, 'recorded_count' => 0];
        }

        $usedAt = now();
        $usageSourceType = is_string($metadata['usage_source_type'] ?? null) && $metadata['usage_source_type'] !== ''
            ? (string) $metadata['usage_source_type']
            : self::SOURCE_TYPE_MEMORY_RECALL;
        $deliverySurface = is_string($metadata['delivery_surface'] ?? null) && $metadata['delivery_surface'] !== ''
            ? (string) $metadata['delivery_surface']
            : null;
        $createdBy = is_string($metadata['created_by'] ?? null) && $metadata['created_by'] !== ''
            ? (string) $metadata['created_by']
            : 'atlas_hybrid_memory_retrieval';
        $auditId = 'recall:'.hash('sha256', json_encode([
            'query' => $query,
            'context' => $context,
            'source' => $metadata['source'] ?? 'atlas_memory_recall',
            'usage_source_type' => $usageSourceType,
            'delivery_surface' => $deliverySurface,
            'used_at' => $usedAt->toJSON(),
            'nonce' => (string) Str::uuid(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $recorded = 0;

        foreach ($registryItems as $index => $item) {
            $memoryId = (string) $item['source_ref_id'];
            $entry = AtlasMemoryEntry::query()->find($memoryId);
            if (! $entry) {
                continue;
            }

            $usageMetadata = [
                'query_hash' => hash('sha256', $query),
                'context_hash' => hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'source' => $metadata['source'] ?? 'atlas_memory_recall',
                'created_by' => $createdBy,
            ];
            if ($deliverySurface !== null) {
                $usageMetadata['delivery_surface'] = $deliverySurface;
            }

            $recallPayload = [
                'memory_entry_id' => $entry->id,
                'trace_id' => $this->uuidOrNull($context['trace_id'] ?? null),
                'thread_id' => $this->uuidOrNull($context['thread_id'] ?? null),
                'session_id' => $this->uuidOrNull($context['session_id'] ?? null),
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'scope_id' => $entry->scope_id,
                'source_type' => $usageSourceType,
                'source_id' => $auditId,
                'position' => (int) ($item['rank'] ?? ($index + 1)),
                'included_reason' => is_string($item['reason'] ?? null) ? $item['reason'] : null,
                'source_ref_json' => [
                    'type' => 'atlas_memory_entry',
                    'id' => $entry->id,
                    'lineage' => is_array($item['lineage'] ?? null) ? $item['lineage'] : [],
                    'freshness' => is_array($item['freshness'] ?? null) ? $item['freshness'] : [],
                    'audit_trail' => is_array($item['audit_trail'] ?? null) ? $item['audit_trail'] : [],
                ],
                'context_payload_json' => $this->recallPayloadForAudit($item),
                'metadata' => $usageMetadata,
                'used_at' => $usedAt,
            ];
            $this->attachActor($recallPayload, [
                'actor' => $metadata['actor'] ?? null,
                'session_id' => $context['session_id'] ?? null,
                'created_by' => $createdBy,
                'source' => $metadata['source'] ?? null,
                'worker' => $metadata['worker'] ?? null,
            ]);
            AtlasMemoryEntryUsage::query()->create($recallPayload);

            if ($usageSourceType === self::SOURCE_TYPE_MEMORY_RECALL) {
                $entry->forceFill(['last_used_at' => $usedAt])->save();
            }
            $recorded++;
        }

        return ['audit_id' => $auditId, 'recorded_count' => $recorded];
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
        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
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
     * MAXB-05 — write a mined_negative label that NEVER enters FEEDBACK_NEGATIVE /
     * archive-inactivate floors. Default-OFF via config flag.
     *
     * @param  array<string,mixed>  $context  provider-safe only (hashes/ids/kinds)
     */
    public function recordMinedNegative(
        string $memoryEntryId,
        string $miningSource,
        array $context = [],
    ): ?AtlasMemoryEntryUsage {
        if (! (bool) config('atlas.semantic_memory.mined_negative_feedback_enabled', false)) {
            return null;
        }

        if (! DatabaseTableAvailability::all(['atlas_memory_entry_usages', 'atlas_memory_entries'])) {
            return null;
        }

        $memoryEntryId = trim($memoryEntryId);
        if ($memoryEntryId === '') {
            return null;
        }

        $entry = AtlasMemoryEntry::query()->find($memoryEntryId);
        if ($entry === null) {
            return null;
        }

        $allowedSources = [
            'candidate_gate_reject',
            'digest_discard',
            'memory_forget',
            'curate_demote',
        ];
        $miningSource = trim($miningSource);
        if (! in_array($miningSource, $allowedSources, true)) {
            $miningSource = 'candidate_gate_reject';
        }

        $labelKind = is_string($context['label_kind'] ?? null) && $context['label_kind'] !== ''
            ? (string) $context['label_kind']
            : ($miningSource === 'candidate_gate_reject' ? 'admission' : 'retrieval_calibration');

        $queryContextHash = is_string($context['query_context_hash'] ?? null) && $context['query_context_hash'] !== ''
            ? (string) $context['query_context_hash']
            : null;

        $payload = [
            'memory_entry_id' => $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'source_type' => 'mined_negative:'.$miningSource,
            'source_id' => is_string($context['source_id'] ?? null) && $context['source_id'] !== ''
                ? (string) $context['source_id']
                : 'mined:'.hash('sha256', $entry->id.'|'.$miningSource.'|'.(string) ($entry->content_hash ?? '')),
            'position' => 0,
            'included_reason' => 'maxb05_mined_negative',
            'source_ref_json' => [
                'type' => 'atlas_memory_entry',
                'id' => $entry->id,
                'content_hash' => $entry->content_hash,
            ],
            'context_payload_json' => [
                'schema_version' => 'atlas.memory.mined_negative.v1',
                'mining_source' => $miningSource,
                'label_kind' => $labelKind,
            ],
            'metadata' => array_filter([
                'label_kind' => $labelKind,
                'mining_source' => $miningSource,
                'query_context_hash' => $queryContextHash,
                'content_hash' => $entry->content_hash,
                'created_by' => 'maxb05_mined_negative',
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'feedback_action' => AtlasMemoryEntryUsage::FEEDBACK_ACTION_MINED_NEGATIVE,
            'feedback_recorded_at' => now(),
            'used_at' => now(),
        ];

        // Never call recordFeedback() — that triggers applyFeedbackGovernance archive paths.
        return AtlasMemoryEntryUsage::query()->create($payload);
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

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function recallPayloadForAudit(array $item): array
    {
        return [
            'rank' => $item['rank'] ?? null,
            'source' => $item['source'] ?? null,
            'source_ref_type' => $item['source_ref_type'] ?? null,
            'source_ref_id' => $item['source_ref_id'] ?? null,
            'type' => $item['type'] ?? null,
            'scope' => $item['scope'] ?? null,
            'title' => $item['title'] ?? null,
            'summary' => $item['summary'] ?? null,
            'score' => $item['score'] ?? null,
            'reason' => $item['reason'] ?? null,
            'estimated_chars' => $item['estimated_chars'] ?? null,
            'lineage' => is_array($item['lineage'] ?? null) ? $item['lineage'] : [],
            'freshness' => is_array($item['freshness'] ?? null) ? $item['freshness'] : [],
            'audit_trail' => is_array($item['audit_trail'] ?? null) ? $item['audit_trail'] : [],
        ];
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * ASI-12 — stamp `actor` on the usage payload when the column exists.
     * v1 tables (no `actor` column) stay byte-identical.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $context
     */
    private function attachActor(array &$payload, array $context): void
    {
        try {
            if (! Schema::hasColumn('atlas_memory_entry_usages', 'actor')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $payload['actor'] = (new AtlasMemoryActorTagger)->derive($context);
    }
}
