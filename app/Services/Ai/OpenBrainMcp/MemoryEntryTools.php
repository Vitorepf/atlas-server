<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * GOD-DEBULK FASE C: entry-level memory mutation/read tool family extracted verbatim
 * from AtlasOpenBrainMcpService — atlas_memory_archive / _link / _supersede / _get.
 * atlas_memory_get gates every read through the same provider privacy decision +
 * blocked-read ledger event as before. Bodies byte-identical; façade delegates here.
 */
class MemoryEntryTools
{
    use OpenBrainMcpToolInput;

    public function __construct(
        private readonly AtlasMemoryPrivacyService $privacy,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function memoryArchive(array $arguments): array
    {
        $entryId = $this->string($arguments['memory_entry_id'] ?? null);
        if ($entryId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_archive', 'error' => 'memory_entry_id_required'];
        }

        $entry = AtlasMemoryEntry::find($entryId);
        if ($entry === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_archive', 'error' => 'memory_entry_not_found'];
        }

        $reason = $this->string($arguments['reason'] ?? null);
        $metadata = $entry->metadata ?? [];
        if ($reason !== null) {
            $metadata['archive_reason'] = $reason;
            $metadata['archived_by'] = 'mcp_tool';
        }

        $entry->update([
            'status' => 'archived',
            'archived_at' => now(),
            'metadata' => $metadata,
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_archive',
            'memory_entry_id' => (string) $entry->id,
            'status' => 'archived',
            'archived_at' => $entry->archived_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function memoryLink(array $arguments): array
    {
        $sourceId = $this->string($arguments['source_id'] ?? null);
        $targetId = $this->string($arguments['target_id'] ?? null);
        $type = $this->string($arguments['relation_type'] ?? null);

        if ($sourceId === null || $targetId === null || $type === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'source_target_type_required'];
        }

        if (! in_array($type, AtlasMemoryEntryRelation::TYPES, true)) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'invalid_relation_type'];
        }

        if ($sourceId === $targetId) {
            return ['ok' => false, 'tool' => 'atlas_memory_link', 'error' => 'cannot_link_to_self'];
        }

        $relation = AtlasMemoryEntryRelation::create([
            'source_memory_entry_id' => $sourceId,
            'target_memory_entry_id' => $targetId,
            'relation_type' => $type,
            'status' => 'open',
            'confidence' => AiValueNormalizer::finiteFloatOrNull($arguments['confidence'] ?? null) ?? 0.8,
            'reason' => $this->string($arguments['reason'] ?? null),
            'metadata' => ['source' => 'mcp_tool'],
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_link',
            'relation_id' => (string) $relation->id,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'relation_type' => $type,
            'status' => 'open',
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function memorySupersede(array $arguments): array
    {
        $oldId = $this->string($arguments['old_entry_id'] ?? null);
        $newId = $this->string($arguments['new_entry_id'] ?? null);

        if ($oldId === null || $newId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'old_and_new_entry_id_required'];
        }

        if ($oldId === $newId) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'cannot_supersede_self'];
        }

        $old = AtlasMemoryEntry::find($oldId);
        $new = AtlasMemoryEntry::find($newId);

        if ($old === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'old_entry_not_found'];
        }
        if ($new === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_supersede', 'error' => 'new_entry_not_found'];
        }

        $reason = $this->string($arguments['reason'] ?? null);
        $metadata = $old->metadata ?? [];
        if ($reason !== null) {
            $metadata['supersede_reason'] = $reason;
        }
        $metadata['superseded_by'] = (string) $new->id;
        $metadata['superseded_at'] = now()->toJSON();

        $old->update([
            'superseded_by_id' => $new->id,
            'status' => 'archived',
            'archived_at' => now(),
            'metadata' => $metadata,
        ]);

        return [
            'ok' => true,
            'tool' => 'atlas_memory_supersede',
            'old_entry_id' => (string) $old->id,
            'new_entry_id' => (string) $new->id,
            'old_status' => 'archived',
            'archived_at' => $old->archived_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function memoryGet(array $arguments): array
    {
        $entryId = $this->string($arguments['memory_entry_id'] ?? null);
        if ($entryId === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'memory_entry_id_required'];
        }

        $entry = AtlasMemoryEntry::find($entryId);
        if ($entry === null) {
            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'memory_entry_not_found'];
        }

        $privacyDecision = $this->privacy->providerDecision($entry);
        if (! (bool) $privacyDecision['allowed']) {
            $this->ledger->recordProviderMemoryBlocked($entry, $privacyDecision, 'open_brain_mcp', [
                'correlation_id' => $this->string($arguments['correlation_id'] ?? null) ?? $entry->trace_id,
                'trace_id' => $this->string($arguments['trace_id'] ?? null) ?? $entry->trace_id,
            ]);

            return ['ok' => false, 'tool' => 'atlas_memory_get', 'error' => 'not_provider_safe'];
        }

        $payload = [
            'id' => (string) $entry->id,
            'memory_type' => $entry->memory_type,
            'scope_type' => $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'title' => $this->privacy->providerTitle($entry),
            'body' => $this->privacy->providerBody($entry),
            'summary' => $this->privacy->providerSummary($entry),
            'tags' => $entry->tags ?? [],
            'metadata' => $entry->metadata ?? [],
            'status' => $entry->status,
            'privacy_class' => $entry->privacy_class,
            'recorded_at' => $entry->recorded_at?->toJSON(),
            'archived_at' => $entry->archived_at?->toJSON(),
            'superseded_by_id' => $entry->superseded_by_id,
        ];

        if ((bool) ($arguments['include_relations'] ?? false)) {
            $payload['outgoing_relations'] = $entry->outgoingRelations()->get()->map(fn ($r) => [
                'id' => (string) $r->id,
                'target_id' => (string) $r->target_memory_entry_id,
                'relation_type' => $r->relation_type,
                'status' => $r->status,
            ])->toArray();
            $payload['incoming_relations'] = $entry->incomingRelations()->get()->map(fn ($r) => [
                'id' => (string) $r->id,
                'source_id' => (string) $r->source_memory_entry_id,
                'relation_type' => $r->relation_type,
                'status' => $r->status,
            ])->toArray();
        }

        return [
            'ok' => true,
            'tool' => 'atlas_memory_get',
            'entry' => $payload,
            'generated_at' => now()->toJSON(),
        ];
    }
}
