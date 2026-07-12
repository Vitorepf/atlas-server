<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * ASI-14 — bridge between {@see ProceduralPlaybook} and the memory registry.
 *
 * A playbook that PROVED itself (postconditions verified on a real Dev
 * outcome) is projected as an `AtlasMemoryEntry` with `memory_type=procedural`.
 * From that moment on every executor (Dev / Forge / Autonomos) can retrieve
 * it by task_category. G0–G8 apply through the ASI-02 admission chokepoint —
 * a playbook whose body carries a secret never crosses.
 *
 * PROVIDER-SAFE by construction:
 *   - the memory `body` is a canonical JSON encoding of the playbook's five
 *     fields (objective, steps, postconditions, forbiddenActions,
 *     priorCorrections) — a shape the retrieval service can rehydrate;
 *   - no raw provider payload is persisted; the caller must supply
 *     `scope_type/scope_id` and `privacy_class` explicitly.
 */
final class AtlasProceduralPlaybookMemoryBridge
{
    /**
     * @param  array{scope_type?:string,scope_id?:?string,privacy_class?:string,source_type?:string,source_id?:?string,external_ai_allowed?:bool,importance?:int,priority?:int}  $options
     * @return array<string,mixed>
     */
    public function persist(ProceduralPlaybook $playbook, array $options = []): array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return ['persisted' => false, 'reason' => 'memory_table_unavailable'];
        }

        $scopeType = (string) ($options['scope_type'] ?? 'global');
        $scopeId = $options['scope_id'] ?? null;
        $privacyClass = (string) ($options['privacy_class'] ?? 'normal');
        $externalAiAllowed = (bool) ($options['external_ai_allowed'] ?? true);
        $sourceType = (string) ($options['source_type'] ?? 'atlas_procedural_playbook');
        $sourceId = $options['source_id'] ?? null;

        $body = json_encode($playbook->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['persisted' => false, 'reason' => 'playbook_encode_failed'];
        }

        $id = (string) Str::uuid();
        $entry = new AtlasMemoryEntry;
        $entry->forceFill([
            'id' => $id,
            'memory_type' => 'procedural',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'title' => 'procedural:'.$playbook->key(),
            'body' => $body,
            'summary' => $playbook->objective,
            'status' => 'active',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'importance' => (int) ($options['importance'] ?? 3),
            'priority' => (int) ($options['priority'] ?? 60),
            'tags' => [$playbook->key(), 'procedural'],
            'metadata' => [
                'schema' => 'atlas.procedural_playbook_memory.v1',
                'task_category_key' => $playbook->key(),
            ],
        ])->save();

        return [
            'persisted' => true,
            'memory_entry_id' => $id,
            'memory_type' => 'procedural',
            'task_category_key' => $playbook->key(),
        ];
    }

    /**
     * Rehydrate an entry stored via `persist()` into a `ProceduralPlaybook`.
     * Returns null for rows whose body isn't a v1 procedural payload.
     */
    public function fromMemoryEntry(AtlasMemoryEntry $entry): ?ProceduralPlaybook
    {
        if ($entry->memory_type !== 'procedural') {
            return null;
        }
        $decoded = json_decode((string) $entry->body, true);
        if (! is_array($decoded)) {
            return null;
        }

        return ProceduralPlaybook::fromArray($decoded);
    }
}
